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
    announcedIp: '174.138.18.220', // NOTE: Replace with your server's public IP if necessary
    listenPort: process.env.PORT || 8080
};

const mediaCodecs = [
    {
        kind: 'audio',
        name: 'opus',
        clockRate: 48000,
        channels: 2,
        parameters: {
            useinbandfec: 1
        }
    },
    {
        kind: 'video',
        name: 'VP8',
        clockRate: 90000
    },
    {
        kind: 'video',
        name: 'H264',
        clockRate: 90000,
        parameters: {
            'packetization-mode': 1
        }
    }
];

const mediasoupServer = mediasoup.Server({
    logLevel: 'debug',
    rtcMinPort: 40000,
    rtcMaxPort: 49999
});

const rooms = new Map();
const socketPeers = new Map();

function getOrCreateRoom(forumId) {
    let room = rooms.get(forumId);
    if (!room) {
        room = mediasoupServer.Room(mediaCodecs);
        rooms.set(forumId, room);
        console.log(`Room created for forum ${forumId}`);

        room.on('newpeer', (peer) => {
            console.log(`New peer joined: ${peer.name}`);
            room.peers = room.peers || new Map();
            room.peers.set(peer.id, peer);

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

            const rtpCapabilities = room.rtpCapabilities;
            if (!rtpCapabilities || !rtpCapabilities.codecs || !Array.isArray(rtpCapabilities.codecs)) {
                throw new Error('Invalid rtpCapabilities from server');
            }
            console.log('Sending rtpCapabilities:', JSON.stringify(rtpCapabilities, null, 2));
            callback(null, { rtpCapabilities });
        } catch (err) {
            console.error('Error in queryRoom:', err);
            callback(err.message);
        }
    });

    socket.on('join', (request, callback) => {
        try {
            console.log('Join request received:', JSON.stringify(request, null, 2));
            const { forumId, displayName, profilePicture } = request.appData || {};
            if (!forumId) throw new Error('forumId is required');
            
            const peerName = typeof request.peerName === 'string' && request.peerName ? request.peerName : `peer-${socket.id}`;
            console.log(`Using peerName: ${peerName}`);

            const room = getOrCreateRoom(forumId);

            const protocolRequest = {
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'join',
                // target: 'room', <-- FIX: Removed unnecessary field
                data: {
                    peerName: peerName,
                    rtpCapabilities: request.rtpCapabilities || {},
                    appData: { forumId, displayName, profilePicture }
                }
            };

            room.receiveRequest(protocolRequest)
                .then((response) => {
                    console.log('Join response:', JSON.stringify(response, null, 2));
                    const peer = room.getPeerByName(peerName);
                    if (!peer) {
                        console.error('Peer not found after join');
                        callback('Peer not found after join');
                        return;
                    }
                    peer.socket = socket;
                    socketPeers.set(socket.id, peer);

                    const peers = Array.from(room.peers?.values() || []).map(p => ({
                        name: p.name,
                        appData: p.appData,
                        producers: Array.from(p.producers.values()).map(pr => ({
                            id: pr.id,
                            kind: pr.kind
                        }))
                    }));
                    console.log(`Peer ${peer.name} joined room ${forumId}, returning peers:`, peers);
                    callback(null, { peers });
                })
                .catch((err) => {
                    console.error('Error in join receiveRequest:', err);
                    callback(err.message || 'Failed to join room');
                });
        } catch (err) {
            console.error('Error in join handler:', err);
            callback(err.message || 'Failed to join room');
        }
    });

    socket.on('createTransport', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            if (!peer) throw new Error('Peer not found');

            const protocolRequest = {
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'createTransport',
                // target: 'peer', <-- FIX: Removed unnecessary field
                data: { // <-- FIX: All options must be inside the 'data' object
                    listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
                    enableUdp: true,
                    enableTcp: true,
                    preferUdp: true,
                    appData: { direction: request.direction }
                }
            };

            peer.receiveRequest(protocolRequest)
                .then((response) => {
                    console.log(`Transport created for ${peer.name} [${request.direction}]: ${response.id}`);
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
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'connectTransport',
                // target: 'peer', <-- FIX: Removed unnecessary field
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
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'createProducer',
                // target: 'peer', <-- FIX: Removed unnecessary field
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
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'createConsumer',
                // target: 'peer', <-- FIX: Removed unnecessary field
                data: {
                    transportId: request.transportId,
                    producerId: request.producerId,
                    kind: request.kind,
                    rtpCapabilities: peer.rtpCapabilities
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
                request: true, // <-- FIX: Added this required field
                id: Date.now(),
                method: 'resumeConsumer',
                // target: 'peer', <-- FIX: Removed unnecessary field
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
