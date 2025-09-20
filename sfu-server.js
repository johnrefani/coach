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
    announcedIp: '174.138.18.220',
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

                // Auto create consumers for other peers
                for (const otherPeer of room.peers.values()) {
                    if (otherPeer.id === peer.id) continue;
                    const recvTransport = otherPeer.transports.find(t => t.appData && t.appData.direction === 'recv');
                    if (!recvTransport) continue;

                    try {
                        const consumer = recvTransport.consume(producer, { paused: true });
                        console.log(`Consumer created for ${otherPeer.name} [${producer.kind}]: ${consumer.id}`);
                        otherPeer.socket.emit('notification', {
                            method: 'newconsumer',
                            data: {
                                id: consumer.id,
                                producerId: producer.id,
                                kind: consumer.kind,
                                rtpParameters: consumer.rtpParameters,
                                appData: producer.appData
                            }
                        });
                    } catch (err) {
                        console.error('Error creating consumer for ' + otherPeer.name, err);
                    }
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

    socket.on('mediasoup-request', (request, callback) => {
        try {
            const peer = socketPeers.get(socket.id);
            const forumId = peer ? peer.appData.forumId : request.appData ? request.appData.forumId : null;
            if (!forumId) throw new Error('forumId is required');
            const room = rooms.get(forumId);
            if (!room) throw new Error('Room not found');

            let target = request.target === 'room' ? room : peer;
            if (!target) throw new Error('Target not found');

            target.receiveRequest(request)
                .then((response) => {
                    console.log(`Request processed: ${request.method}`);
                    callback(null, response);
                })
                .catch((err) => {
                    console.error('Error processing request:', err);
                    callback(err.message);
                });
        } catch (err) {
            console.error('Error in mediasoup-request:', err);
            callback(err.message);
        }
    });

    socket.on('mediasoup-notify', (notification) => {
        try {
            const peer = socketPeers.get(socket.id);
            const forumId = peer ? peer.appData.forumId : notification.appData ? notification.appData.forumId : null;
            if (!forumId) throw new Error('forumId is required');
            const room = rooms.get(forumId);
            if (!room) throw new Error('Room not found');

            let target = notification.target === 'room' ? room : peer;
            if (!target) throw new Error('Target not found');

            target.receiveRequest(notification)
                .catch((err) => {
                    console.error('Error processing notification:', err);
                });
        } catch (err) {
            console.error('Error in mediasoup-notify:', err);
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
