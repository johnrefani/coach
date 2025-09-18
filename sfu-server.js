const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

const SFU_CONFIG = {
    announcedIp: '174.138.18.220', // Your server's public IP
    listenPort: process.env.PORT || 8080
};

const rooms = new Map();
let worker;

const mediaCodecs = [
    { kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 },
    { kind: 'video', mimeType: 'video/VP8', clockRate: 90000 },
    { kind: 'video', mimeType: 'video/H264', clockRate: 90000 }
];

async function createWorker() {
    worker = await mediasoup.createWorker({
        logLevel: 'warn',
        rtcMinPort: 40000,
        rtcMaxPort: 49999,
    });

    worker.on('died', () => {
        console.error('Mediasoup worker died, exiting in 2 seconds...');
        setTimeout(() => process.exit(1), 2000);
    });

    console.log(`Mediasoup worker created [pid:${worker.pid}]`);
}

async function getOrCreateRoom(forumId) {
    let room = rooms.get(forumId);
    if (!room) {
        const router = await worker.createRouter({ mediaCodecs });
        room = {
            id: forumId,
            router,
            peers: new Map()
        };
        rooms.set(forumId, room);
        console.log(`Room created for forum ${forumId}`);
    }
    return room;
}

// Socket.IO connection handling
io.on('connection', (socket) => {
    console.log(`Client connected [socketId:${socket.id}]`);

    let peer = null;
    let room = null;

    socket.on('queryRoom', async (request, callback) => {
        try {
            const { forumId } = request.appData;
            const targetRoom = await getOrCreateRoom(forumId);
            callback(null, { rtpCapabilities: targetRoom.router.rtpCapabilities });
        } catch (err) {
            console.error('Error in queryRoom:', err);
            callback(err.toString());
        }
    });

    socket.on('join', async (request, callback) => {
        try {
            const { forumId } = request.appData;
            room = await getOrCreateRoom(forumId);

            peer = {
                id: socket.id,
                name: request.peerName,
                appData: request.appData,
                rtpCapabilities: null,
                recvTransportId: null,
                recvTransportConnected: false,
                transports: new Map(),
                producers: new Map(),
                consumers: new Map(),
                socket: socket
            };

            const peersInRoom = Array.from(room.peers.values())
                .map(p => ({ name: p.name, appData: p.appData }));

            // Notify existing peers about new peer
            for (const existingPeer of room.peers.values()) {
                existingPeer.socket.emit('notification', {
                    target: 'room',
                    // FIX 1: Changed 'newPeer' to 'newpeer' to match the client listener [cite: 64, 150]
                    method: 'newpeer',
                    data: {
                        peerName: peer.name,
                        appData: peer.appData
                    }
                });
            }

            room.peers.set(socket.id, peer);

            console.log(`Peer ${peer.name} joined room ${forumId}`);
            callback(null, { peers: peersInRoom });
        } catch (err) {
            console.error('Error in join:', err);
            callback(err.toString());
        }
    });

    socket.on('createTransport', async (request, callback) => {
        try {
            const transport = await room.router.createWebRtcTransport({
                listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
                enableUdp: true,
                enableTcp: true,
                preferUdp: true,
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
        } catch (err) {
            console.error('Error creating transport:', err);
            callback(err.toString());
        }
    });

    socket.on('connectTransport', async (request, callback) => {
        try {
            const transport = peer.transports.get(request.id);
            if (!transport) throw new Error(`Transport with id "${request.id}" not found`);

            await transport.connect({ dtlsParameters: request.dtlsParameters });

            console.log(`Transport connected for ${peer.name}: ${request.id}`);

            if (request.direction === 'recv') {
                peer.recvTransportConnected = true;
                peer.rtpCapabilities = request.rtpCapabilities;
                console.log(`Recv transport connected for ${peer.name} - notifying about existing producers`);

                // Notify this new peer of all existing producers
                for (const otherPeer of room.peers.values()) {
                    if (otherPeer.id === peer.id) continue;
                    for (const producer of otherPeer.producers.values()) {
                        try {
                            // FIX 2: Send notification to the NEW peer (`peer.socket`), not the existing one (`otherPeer.socket`)[cite: 157, 174].
                            // FIX 3: Changed 'newProducer' to 'newproducer' to match client listener[cite: 68, 157].
                            peer.socket.emit('notification', {
                                target: 'peer',
                                method: 'newproducer',
                                data: {
                                    id: producer.id,
                                    kind: producer.kind,
                                    rtpParameters: producer.rtpParameters,
                                    appData: producer.appData
                                }
                            });
                            console.log(`Sent existing producer ${producer.id} from ${otherPeer.name} to new peer ${peer.name}`);
                        } catch (err) {
                            console.error(`Error notifying initial producer to ${peer.name}:`, err);
                        }
                    }
                }
            }
            callback(null);
        } catch (err) {
            console.error('Error connecting transport:', err);
            callback(err.toString());
        }
    });

    socket.on('createProducer', async (request, callback) => {
        try {
            const transport = peer.transports.get(request.transportId);
            if (!transport) throw new Error(`Transport with id "${request.transportId}" not found`);

            const producer = await transport.produce({
                kind: request.kind,
                rtpParameters: request.rtpParameters,
                appData: request.appData
            });

            peer.producers.set(producer.id, producer);
            console.log(`Producer created for ${peer.name} [${request.kind}]: ${producer.id}`);

            // Notify all other connected peers
            for (const otherPeer of room.peers.values()) {
                if (otherPeer.id === peer.id || !otherPeer.recvTransportConnected) continue;
                try {
                    // FIX 3 (cont.): Changed 'newProducer' to 'newproducer' to match client listener[cite: 68, 162].
                    otherPeer.socket.emit('notification', {
                        target: 'peer',
                        method: 'newproducer',
                        data: {
                            id: producer.id,
                            kind: producer.kind,
                            rtpParameters: producer.rtpParameters,
                            appData: producer.appData
                        }
                    });
                    console.log(`Sent new producer ${producer.id} to ${otherPeer.name}`);
                } catch (err) {
                    console.error(`Error notifying new producer to ${otherPeer.name}:`, err);
                }
            }

            callback(null, { id: producer.id });
        } catch (err) {
            console.error('Error creating producer:', err);
            callback(err.toString());
        }
    });

    socket.on('disconnect', () => {
        console.log(`Client disconnected [socketId:${socket.id}]`);
        if (peer && room) {
            // Notify remaining peers of closure
            for (const otherPeer of room.peers.values()) {
                if (otherPeer.id !== peer.id) {
                    otherPeer.socket.emit('notification', {
                        target: 'room',
                        // FIX 4: Changed 'peerClosed' to 'peerclosed' to match client listener[cite: 65, 166].
                        method: 'peerclosed',
                        data: { peerName: peer.name }
                    });
                }
            }
            room.peers.delete(peer.id);
        }
    });
});

// --- Start the server ---
server.listen(SFU_CONFIG.listenPort, () => {
    console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
    createWorker();
});
