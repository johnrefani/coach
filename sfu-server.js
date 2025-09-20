const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const { MediaServer } = require('medooze-media-server');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: { origin: '*', methods: ['GET', 'POST'] }
});

const SFU_CONFIG = {
    ip: '0.0.0.0',
    announcedIp: '174.138.18.220', // Verify this matches your droplet’s public IP
    listenPort: process.env.PORT || 8080,
    rtcMinPort: 40000,
    rtcMaxPort: 49999
};

// Initialize Medooze Media Server with error handling
let mediaServer;
try {
    MediaServer.enableLog(true); // Enable logging for debugging
    MediaServer.enableDebug(true); // Enable debug mode
    mediaServer = MediaServer; // Use MediaServer directly (not a constructor in newer versions)
    console.log('MediaServer initialized successfully');
} catch (err) {
    console.error('Failed to initialize MediaServer:', err.message);
    process.exit(1); // Exit gracefully to avoid PM2 restart loop
}

const rooms = new Map(); // Map forumId to Endpoint
const socketPeers = new Map(); // Map socket.id to peer info

function getOrCreateRoom(forumId) {
    let room = rooms.get(forumId);
    if (!room) {
        try {
            room = mediaServer.createEndpoint(SFU_CONFIG.ip);
            rooms.set(forumId, { endpoint: room, peers: new Map() });
            console.log(`Room created for forum ${forumId}`);
        } catch (err) {
            console.error(`Error creating room for forum ${forumId}:`, err.message);
            return null;
        }
    }
    return room;
}

io.on('connection', (socket) => {
    console.log(`Client connected [socketId:${socket.id}]`);
    socket.on('error', (err) => {
        console.error(`Socket error [${socket.id}]:`, err.message);
    });

    socket.on('queryRoom', ({ appData }, callback) => {
        try {
            const { forumId } = appData || {};
            if (!forumId) throw new Error('forumId is required');
            const room = getOrCreateRoom(forumId);
            if (!room) throw new Error('Failed to create or retrieve room');
            callback(null, { rtpCapabilities: mediaServer.capabilities });
        } catch (err) {
            console.error('Error in queryRoom:', err.message);
            callback(err.message);
        }
    });

    socket.on('join', ({ peerName, rtpCapabilities, appData }, callback) => {
        try {
            const { forumId, displayName, profilePicture } = appData || {};
            if (!forumId) throw new Error('forumId is required');
            const room = getOrCreateRoom(forumId);
            if (!room) throw new Error('Room not found');

            socket.appData = { forumId, displayName, profilePicture };
            socket.peerName = peerName || `peer-${socket.id}`;
            room.peers.set(socket.id, { peerName: socket.peerName, socket });
            socketPeers.set(socket.id, { peerName: socket.peerName, appData });

            // Notify other peers
            for (const otherSocket of io.sockets.sockets.values()) {
                if (otherSocket.id === socket.id || otherSocket.appData?.forumId !== forumId) continue;
                otherSocket.emit('notification', {
                    method: 'newpeer',
                    data: { peerName: socket.peerName, appData }
                });
            }

            const peers = Array.from(room.peers.values()).map(p => ({
                name: p.peerName,
                appData: socketPeers.get(p.socket.id)?.appData || {}
            }));
            console.log(`Peer ${socket.peerName} joined room ${forumId}, peers:`, peers);
            callback(null, { peers });
        } catch (err) {
            console.error('Error in join:', err.message);
            callback(err.message);
        }
    });

    socket.on('createTransport', ({ direction }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            if (!forumId) throw new Error('forumId is required');
            const room = rooms.get(forumId);
            if (!room) throw new Error('Room not found');

            const transport = room.endpoint.createTransport({
                listenIp: { ip: SFU_CONFIG.ip, announcedIp: SFU_CONFIG.announcedIp },
                udp: true,
                tcp: true,
                preferUdp: true,
                dtls: true,
                portMin: SFU_CONFIG.rtcMinPort,
                portMax: SFU_CONFIG.rtcMaxPort
            });
            transport.appData = { direction };
            socketPeers.get(socket.id).transports = socketPeers.get(socket.id).transports || [];
            socketPeers.get(socket.id).transports.push(transport);

            callback(null, {
                id: transport.id,
                iceParameters: transport.iceParameters,
                iceCandidates: transport.iceCandidates,
                dtlsParameters: transport.dtlsParameters
            });
        } catch (err) {
            console.error('Error creating transport:', err.message);
            callback(err.message);
        }
    });

    socket.on('connectTransport', ({ id, dtlsParameters }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = rooms.get(forumId);
            const transport = room.endpoint.getTransport(id);
            if (!transport) throw new Error('Transport not found');
            transport.connect({ dtlsParameters });
            console.log(`Transport connected [${id}]`);
            callback(null);
        } catch (err) {
            console.error('Error connecting transport:', err.message);
            callback(err.message);
        }
    });

    socket.on('createProducer', ({ transportId, kind, rtpParameters, appData }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = rooms.get(forumId);
            const transport = room.endpoint.getTransport(transportId);
            if (!transport) throw new Error('Transport not found');
            const producer = transport.produce({ kind, rtpParameters, appData });
            socketPeers.get(socket.id).producers = socketPeers.get(socket.id).producers || [];
            socketPeers.get(socket.id).producers.push(producer);

            // Notify other peers
            for (const otherSocket of io.sockets.sockets.values()) {
                if (otherSocket.id === socket.id || otherSocket.appData?.forumId !== forumId) continue;
                otherSocket.emit('notification', {
                    method: 'newproducer',
                    data: { id: producer.id, kind, rtpParameters, peerName: socket.peerName, appData }
                });
            }
            console.log(`Producer created [${producer.id}, ${kind}] for ${socket.peerName}`);
            callback(null, { id: producer.id });
        } catch (err) {
            console.error('Error creating producer:', err.message);
            callback(err.message);
        }
    });

    socket.on('createConsumer', ({ transportId, producerId, kind, rtpParameters }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = rooms.get(forumId);
            const transport = room.endpoint.getTransport(transportId);
            if (!transport) throw new Error('Transport not found');
            const consumer = transport.consume({ producerId, rtpParameters });
            socketPeers.get(socket.id).consumers = socketPeers.get(socket.id).consumers || [];
            socketPeers.get(socket.id).consumers.push(consumer);

            console.log(`Consumer created [${consumer.id}, ${kind}] for ${socket.peerName}`);
            callback(null, {
                id: consumer.id,
                producerId,
                kind,
                rtpParameters: consumer.rtpParameters
            });
        } catch (err) {
            console.error('Error creating consumer:', err.message);
            callback(err.message);
        }
    });

    socket.on('resumeConsumer', ({ consumerId }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = rooms.get(forumId);
            const consumer = socketPeers.get(socket.id).consumers?.find(c => c.id === consumerId);
            if (!consumer) throw new Error('Consumer not found');
            consumer.resume();
            console.log(`Consumer resumed [${consumerId}]`);
            callback(null);
        } catch (err) {
            console.error('Error resuming consumer:', err.message);
            callback(err.message);
        }
    });

    socket.on('disconnect', () => {
        console.log(`Client disconnected [socketId:${socket.id}]`);
        const peer = socketPeers.get(socket.id);
        if (peer) {
            const { forumId } = socket.appData || {};
            const room = rooms.get(forumId);
            if (room) {
                peer.transports?.forEach(t => t.close());
                peer.producers?.forEach(p => p.close());
                peer.consumers?.forEach(c => c.close());
                room.peers.delete(socket.id);
                for (const otherSocket of io.sockets.sockets.values()) {
                    if (otherSocket.id === socket.id || otherSocket.appData?.forumId !== forumId) continue;
                    otherSocket.emit('notification', {
                        method: 'peerclosed',
                        data: { peerName: socket.peerName }
                    });
                }
            }
            socketPeers.delete(socket.id);
        }
    });
});

server.listen(SFU_CONFIG.listenPort, () => {
    console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
});

// Handle uncaught exceptions to prevent PM2 restart loops
process.on('uncaughtException', (err) => {
    console.error('Uncaught Exception:', err.message);
    process.exit(1);
});
