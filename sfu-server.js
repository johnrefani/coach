const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: {
        origin: '*',
        methods: ['GET', 'POST']
    }
});

const SFU_CONFIG = {
    announcedIp: '174.138.18.220', // Replace with your server's public IP
    listenPort: process.env.PORT || 8080
};

const mediaCodecs = [
    { kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 },
    { kind: 'video', mimeType: 'video/VP8', clockRate: 90000 },
    { kind: 'video', mimeType: 'video/H264', clockRate: 90000 }
];

// Map to store workers and routers per forumId
const workers = new Map();
const routers = new Map();
let workerIndex = 0;

async function createWorker() {
    const worker = await mediasoup.createWorker({
        logLevel: 'warn',
        rtcMinPort: 40000,
        rtcMaxPort: 49999
    });

    worker.on('died', () => {
        console.error('Worker died, exiting in 2 seconds...');
        setTimeout(() => process.exit(1), 2000);
    });

    return worker;
}

async function getOrCreateRouter(forumId) {
    let router = routers.get(forumId);
    if (!router) {
        // Get or create a worker
        let worker = workers.get(workerIndex);
        if (!worker) {
            worker = await createWorker();
            workers.set(workerIndex, worker);
            workerIndex = (workerIndex + 1) % 10; // Rotate through 10 workers
        }

        router = await worker.createRouter({ mediaCodecs });
        router.peers = new Map(); // Custom map to track peers
        routers.set(forumId, router);
        console.log(`Router created for forum ${forumId}`);

        router.on('close', () => {
            console.log(`Router closed for forum ${forumId}`);
            routers.delete(forumId);
        });
    }
    return router;
}

io.on('connection', (socket) => {
    console.log(`Client connected [socketId:${socket.id}]`);

    let peer = null;
    let router = null;

    socket.on('queryRoom', async (request, callback) => {
        try {
            const { forumId } = request.appData || {};
            if (!forumId) throw new Error('forumId is required');
            router = await getOrCreateRouter(forumId);
            callback(null, { rtpCapabilities: router.rtpCapabilities });
        } catch (err) {
            console.error('Error in queryRoom:', err);
            callback(err.message);
        }
    });

    socket.on('join', async (request, callback) => {
        try {
            const { forumId, displayName, profilePicture } = request.appData || {};
            if (!forumId) throw new Error('forumId is required');
            router = await getOrCreateRouter(forumId);

            peer = {
                id: socket.id,
                name: request.peerName,
                appData: { forumId, displayName, profilePicture },
                rtpCapabilities: request.rtpCapabilities || {},
                socket: socket,
                producers: new Map(),
                consumers: new Map(),
                transports: new Map()
            };
            router.peers.set(socket.id, peer);

            const peersInRoom = Array.from(router.peers.values())
                .map(p => ({ name: p.name, appData: p.appData }));

            // Notify existing peers about new peer
            for (const existingPeer of router.peers.values()) {
                if (existingPeer.id === peer.id) continue;
                existingPeer.socket.emit('notification', {
                    notification: true,
                    target: 'room',
                    method: 'newpeer',
                    data: {
                        peerName: peer.name,
                        appData: peer.appData
                    }
                });
            }

            // Send existing producers to new peer
            for (const existingPeer of router.peers.values()) {
                if (existingPeer.id === peer.id) continue;
                for (const producer of existingPeer.producers.values()) {
                    socket.emit('notification', {
                        notification: true,
                        target: 'peer',
                        method: 'newproducer',
                        data: {
                            id: producer.id,
                            kind: producer.kind,
                            rtpParameters: producer.rtpParameters,
                            appData: producer.appData,
                            peerName: existingPeer.name
                        }
                    });
                }
            }

            console.log(`Peer ${peer.name} joined room ${forumId}`);
            callback(null, { peers: peersInRoom });
        } catch (err) {
            console.error('Error in join:', err);
            callback(err.message);
        }
    });

    socket.on('createTransport', async (request, callback) => {
        try {
            if (!peer || !router) throw new Error('Peer or router not initialized');
            const transport = await router.createWebRtcTransport({
                listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
                enableUdp: true,
                enableTcp: true,
                preferUdp: true
            });

            peer.transports.set(transport.id, transport);

            if (request.direction === 'recv') {
                peer.recvTransportId = transport.id;
            }

            console.log(`Transport created for ${peer.name} [${request.direction}]: ${transport.id}`);
            callback(null, {
                id: transport.id,
                iceParameters: transport.iceParameters,
                iceCandidates: transport.iceCandidates,
                dtlsParameters: transport.dtlsParameters
            });

            transport.on('close', () => {
                peer.transports.delete(transport.id);
                console.log(`Transport closed: ${transport.id}`);
            });
        } catch (err) {
            console.error('Error creating transport:', err);
            callback(err.message);
        }
    });

    socket.on('connectTransport', async (request, callback) => {
        try {
            if (!peer) throw new Error('Peer not initialized');
            const transport = peer.transports.get(request.id);
            if (!transport) throw new Error(`Transport with id "${request.id}" not found`);

            await transport.connect({ dtlsParameters: request.dtlsParameters });

            console.log(`Transport connected for ${peer.name}: ${request.id}`);

            if (request.direction === 'recv') {
                peer.recvTransportConnected = true;
                for (const otherPeer of router.peers.values()) {
                    if (otherPeer.id === peer.id) continue;
                    for (const producer of otherPeer.producers.values()) {
                        socket.emit('notification', {
                            notification: true,
                            target: 'peer',
                            method: 'newproducer',
                            data: {
                                id: producer.id,
                                kind: producer.kind,
                                rtpParameters: producer.rtpParameters,
                                appData: producer.appData,
                                peerName: otherPeer.name
                            }
                        });
                    }
                }
            }

            callback(null);
        } catch (err) {
            console.error('Error connecting transport:', err);
            callback(err.message);
        }
    });

    socket.on('createProducer', async (request, callback) => {
        try {
            if (!peer) throw new Error('Peer not initialized');
            const transport = peer.transports.get(request.transportId);
            if (!transport) throw new Error(`Transport with id "${request.transportId}" not found`);

            const producer = await transport.produce({
                kind: request.kind,
                rtpParameters: request.rtpParameters,
                appData: request.appData
            });

            peer.producers.set(producer.id, producer);
            console.log(`Producer created for ${peer.name} [${request.kind}]: ${producer.id}`);

            for (const otherPeer of router.peers.values()) {
                if (otherPeer.id === peer.id || !otherPeer.recvTransportConnected) continue;
                otherPeer.socket.emit('notification', {
                    notification: true,
                    target: 'peer',
                    method: 'newproducer',
                    data: {
                        id: producer.id,
                        kind: producer.kind,
                        rtpParameters: producer.rtpParameters,
                        appData: producer.appData,
                        peerName: peer.name
                    }
                });
            }

            callback(null, { id: producer.id });

            producer.on('close', () => {
                peer.producers.delete(producer.id);
                console.log(`Producer closed: ${producer.id}`);
            });
        } catch (err) {
            console.error('Error creating producer:', err);
            callback(err.message);
        }
    });

    socket.on('createConsumer', async (request, callback) => {
        try {
            if (!peer) throw new Error('Peer not initialized');
            const transport = peer.transports.get(request.transportId);
            if (!transport) throw new Error(`Transport with id "${request.transportId}" not found`);

            const consumer = await transport.consume({
                producerId: request.producerId,
                rtpCapabilities: peer.rtpCapabilities,
                paused: true
            });

            peer.consumers.set(consumer.id, consumer);

            console.log(`Consumer created for ${peer.name} [${request.kind}]: ${consumer.id}`);

            callback(null, {
                id: consumer.id,
                producerId: request.producerId,
                kind: consumer.kind,
                rtpParameters: consumer.rtpParameters
            });

            consumer.on('close', () => {
                peer.consumers.delete(consumer.id);
                console.log(`Consumer closed: ${consumer.id}`);
            });
        } catch (err) {
            console.error('Error creating consumer:', err);
            callback(err.message);
        }
    });

    socket.on('resumeConsumer', async (request, callback) => {
        try {
            if (!peer) throw new Error('Peer not initialized');
            const consumer = peer.consumers.get(request.consumerId);
            if (!consumer) throw new Error(`Consumer with id "${request.consumerId}" not found`);

            await consumer.resume();
            console.log(`Consumer resumed for ${peer.name}: ${request.consumerId}`);
            callback(null);
        } catch (err) {
            console.error('Error resuming consumer:', err);
            callback(err.message);
        }
    });

    socket.on('disconnect', () => {
        console.log(`Client disconnected [socketId:${socket.id}]`);
        if (peer && router) {
            console.log(`Peer ${peer.name} leaving room ${router.id}`);

            for (const producer of peer.producers.values()) producer.close();
            for (const consumer of peer.consumers.values()) consumer.close();
            for (const transport of peer.transports.values()) transport.close();

            router.peers.delete(peer.id);

            for (const otherPeer of router.peers.values()) {
                otherPeer.socket.emit('notification', {
                    notification: true,
                    target: 'room',
                    method: 'peerclosed',
                    data: { peerName: peer.name }
                });
            }

            // Close router if empty
            if (router.peers.size === 0) {
                router.close();
            }
        }
    });
});

server.listen(SFU_CONFIG.listenPort, () => {
    console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
});
