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

// Create a global mediasoup Server
const mediasoupServer = mediasoup.Server({
    logLevel: 'warn',
    rtcMinPort: 40000,
    rtcMaxPort: 49999
});

// Map to store rooms per forumId
const rooms = new Map();

// Map to store peers per socket.id
const socketPeers = new Map();

function getOrCreateRoom(forumId) {
    let room = rooms.get(forumId);
    if (!room) {
        room = mediasoupServer.Room(mediaCodecs);
        rooms.set(forumId, room);
        console.log(`Room created for forum ${forumId}`);

        // Set up room-level event listeners for notifications
        room.on('newpeer', (peer) => {
            console.log(`New peer joined: ${peer.name}`);
            // Custom map for peers (using peer.id as key)
            room.peers = room.peers || new Map();
            room.peers.set(peer.id, peer);

            // Send 'newpeer' notification to existing peers
            for (const otherPeer of room.peers.values()) {
                if (otherPeer.id === peer.id || !otherPeer.socket) continue;
                otherPeer.socket.emit('notification', {
                    method: 'newpeer',
                    data: {
                        peerName: peer.name,
                        appData: peer.appData
                    }
                });
            }

            // Set up peer-level event listeners
            peer.on('newproducer', (producer) => {
                console.log(`New producer for ${peer.name}: ${producer.id}`);
                for (const otherPeer of room.peers.values()) {
                    if (otherPeer.id === peer.id || !otherPeer.socket) continue;
                    otherPeer.socket.emit('notification', {
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
            });

            peer.on('close', () => {
                console.log(`Peer closed: ${peer.name}`);
                for (const otherPeer of room.peers.values()) {
                    if (otherPeer.id === peer.id || !otherPeer.socket) continue;
                    otherPeer.socket.emit('notification', {
                        method: 'peerclosed',
                        data: { peerName: peer.name }
                    });
                }
                room.peers.delete(peer.id);
            });
        });

        room.on('close', () => {
            console.log(`Room closed for forum ${forumId}`);
            rooms.delete(forumId);
        });
    }
    return room;
}

io.on('connection', (socket) => {
    console.log(`Client connected [socketId:${socket.id}]`);

    socket.on('queryRoom', (request, callback) => {
        try {
            const { forumId } = request.appData || {};
            if (!forumId) throw new Error('forumId is required');
            const room = getOrCreateRoom(forumId);
            callback(null, { rtpCapabilities: room.rtpCapabilities });
        } catch (err) {
            console.error('Error in queryRoom:', err);
            callback(err.message);
        }
    });

    socket.on('join', (request, callback) => {
        try {
            const { forumId, displayName, profilePicture } = request.appData || {};
            if (!forumId) throw new Error('forumId is required');
            const room = getOrCreateRoom(forumId);

            const protocolRequest = {
                method: 'join',
                target: 'room',
                id: Date.now(),
                data: {
                    peerName: request.peerName,
                    rtpCapabilities: request.rtpCapabilities,
                    appData: request.appData
                }
            };

            room.receiveRequest(protocolRequest)
                .then((response) => {
                    // Get the peer after join
                    const peer = room.getPeerByName(request.peerName);
                    if (!peer) throw new Error('Peer not found after join');
                    peer.socket = socket;
                    socketPeers.set(socket.id, peer);
                    console.log(`Peer ${peer.name} joined room ${forumId}`);
                    callback(null, response);
                })
                .catch((err) => {
                    console.error('Error in join:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error in join:', err);
            callback(err.message);
        }
    });

    socket.on('createTransport', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                method: 'createTransport',
                target: 'peer',
                id: Date.now(),
                options: {},
                dtlsParameters: {},  // Empty as per v2 example; connect is separate
                appData: { direction: request.direction }
            };

            peer.receiveRequest(protocolRequest)
                .then((response) => {
                    console.log(`Transport created for ${peer.name} [${request.direction}]`);
                    callback(null, response);
                })
                .catch((err) => {
                    console.error('Error creating transport:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error creating transport:', err);
            callback(err.message);
        }
    });

    socket.on('connectTransport', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                method: 'connectTransport',
                target: 'peer',
                id: Date.now(),
                data: {
                    transportId: request.id,
                    dtlsParameters: request.dtlsParameters
                }
            };

            peer.receiveRequest(protocolRequest)
                .then(() => {
                    console.log(`Transport connected for ${peer.name}: ${request.id}`);
                    callback(null);
                })
                .catch((err) => {
                    console.error('Error connecting transport:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error connecting transport:', err);
            callback(err.message);
        }
    });

    socket.on('createProducer', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                method: 'createProducer',
                target: 'peer',
                id: Date.now(),
                data: {
                    transportId: request.transportId,
                    kind: request.kind,
                    rtpParameters: request.rtpParameters,
                    appData: request.appData
                }
            };

            peer.receiveRequest(protocolRequest)
                .then((response) => {
                    console.log(`Producer created for ${peer.name} [${request.kind}]: ${response.id}`);
                    callback(null, response);
                })
                .catch((err) => {
                    console.error('Error creating producer:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error creating producer:', err);
            callback(err.message);
        }
    });

    socket.on('createConsumer', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                method: 'createConsumer',
                target: 'peer',
                id: Date.now(),
                data: {
                    transportId: request.transportId,
                    producerId: request.producerId,
                    kind: request.kind,
                    rtpParameters: request.rtpParameters  // Ignored if not needed in v2
                }
            };

            peer.receiveRequest(protocolRequest)
                .then((response) => {
                    console.log(`Consumer created for ${peer.name} [${request.kind}]: ${response.id}`);
                    callback(null, response);
                })
                .catch((err) => {
                    console.error('Error creating consumer:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error creating consumer:', err);
            callback(err.message);
        }
    });

    socket.on('resumeConsumer', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                method: 'resumeConsumer',
                target: 'peer',
                id: Date.now(),
                data: {
                    consumerId: request.consumerId
                }
            };

            peer.receiveRequest(protocolRequest)
                .then(() => {
                    console.log(`Consumer resumed for ${peer.name}: ${request.consumerId}`);
                    callback(null);
                })
                .catch((err) => {
                    console.error('Error resuming consumer:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error resuming consumer:', err);
            callback(err.message);
        }
    });

    socket.on('disconnect', () => {
        console.log(`Client disconnected [socketId:${socket.id}]`);
        const peer = socketPeers.get(socket.id);
        if (peer) {
            peer.close();
            socketPeers.delete(socket.id);
        }
    });
});

server.listen(SFU_CONFIG.listenPort, () => {
    console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
});
