const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');
const { spawn } = require('child_process');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: {
      origin: "*", // In production, you should restrict this to your actual domain.
      methods: ["GET", "POST"]
    }
});

const SFU_CONFIG = {
    announcedIp: '174.138.18.220', 
    listenPort: process.env.PORT || 8080
};

const rooms = {};
let worker;

const mediaCodecs = [
  { kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 },
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000, preferredPayloadType: 96 },
  { kind: 'video', mimeType: 'video/H264', clockRate: 90000, preferredPayloadType: 102 }
];

async function createWorker() {
  worker = await mediasoup.createWorker({
    rtcMinPort: 40000,
    rtcMaxPort: 49999,
  });

  worker.on('died', () => {
    console.error('Mediasoup worker died, exiting now...');
    setTimeout(() => process.exit(1), 2000);
  });
}

async function getOrCreateRouter(forumId) {
  if (rooms[forumId] && rooms[forumId].router) {
    return rooms[forumId].router;
  }
  const router = await worker.createRouter({ mediaCodecs });
  rooms[forumId] = { router, peers: new Map(), audioLevels: new Map(), activeSpeaker: null };
  console.log(`Router created for forum ${forumId}`);
  return router;
}

io.on('connection', async (socket) => {
  console.log(`New client connected: ${socket.id}`);

  // **FIX: ADDED HANDLER** to provide router capabilities to the client
  socket.on('get-rtp-capabilities', async (forumId, callback) => {
    try {
      const router = await getOrCreateRouter(forumId);
      callback(router.rtpCapabilities);
    } catch (err) {
      console.error('Error getting RTP capabilities:', err);
      callback({ error: err.message });
    }
  });

  socket.on('join-forum', async ({ forumId, username, displayName, profilePicture, rtpCapabilities }) => {
    const router = await getOrCreateRouter(forumId);
    
    const peer = {
      id: socket.id,
      socket,
      username,
      displayName,
      profilePicture,
      transports: new Map(),
      producers: new Map(),
      consumers: new Map(),
      rtpCapabilities
    };
    rooms[forumId].peers.set(socket.id, peer);
    socket.forumId = forumId; // Associate forumId with the socket for later use
    
    socket.broadcast.to(forumId).emit('new-peer', {
        username: peer.username,
        displayName: peer.displayName,
        profilePicture: peer.profilePicture
    });

    socket.join(forumId);
    console.log(`User '${username}' joined forum '${forumId}'`);

    const existingProducers = [];
    rooms[forumId].peers.forEach(p => {
        p.producers.forEach(producer => {
            if (producer.appData.username !== username) {
                existingProducers.push({
                    producerId: producer.id,
                    username: producer.appData.username,
                    kind: producer.kind
                });
            }
        });
    });

    socket.emit('join-success', {
      rtpCapabilities: router.rtpCapabilities,
      existingProducers: existingProducers,
      selfUsername: username
    });
  });
  
  socket.on('create-transport', async (isProducer, callback) => {
    try {
      const transport = await rooms[socket.forumId].router.createWebRtcTransport({
        listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
        enableUdp: true,
        enableTcp: true,
        preferUdp: true,
      });

      transport.on('dtlsstatechange', (dtlsState) => {
        if (dtlsState === 'closed') {
          transport.close();
        }
      });
      
      const peer = rooms[socket.forumId].peers.get(socket.id);
      if (peer) {
          peer.transports.set(transport.id, transport);
      }

      callback({
        id: transport.id,
        iceParameters: transport.iceParameters,
        iceCandidates: transport.iceCandidates,
        dtlsParameters: transport.dtlsParameters
      });
    } catch (err) {
      console.error('Error creating transport:', err);
      callback({ error: err.message });
    }
  });
  
  socket.on('transport-connect', async ({ transportId, dtlsParameters }) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (transport) {
      await transport.connect({ dtlsParameters });
    }
  });

  socket.on('transport-produce', async ({ transportId, kind, rtpParameters, appData }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (!transport) {
      return callback({ error: 'Transport not found' });
    }
    
    const producer = await transport.produce({ kind, rtpParameters, appData }); // Pass appData here
    peer.producers.set(producer.id, producer);

    if (kind === 'audio') {
        producer.observer.on('volumes', (volumes) => {
            if (volumes.length > 0) {
                const { producer, volume } = volumes[0];
                const room = rooms[socket.forumId];
                if (room) {
                    room.audioLevels.set(producer.appData.username, volume);
                    const currentSpeaker = room.activeSpeaker;
                    const newSpeaker = getActiveSpeaker(room.audioLevels);
                    if (newSpeaker && newSpeaker !== currentSpeaker) {
                        room.activeSpeaker = newSpeaker;
                        io.to(socket.forumId).emit('speaker-changed', { username: newSpeaker });
                    }
                }
            }
        });
    }

    socket.broadcast.to(socket.forumId).emit('new-producer', {
      producerId: producer.id,
      producerUsername: producer.appData.username,
      producerKind: producer.kind
    });
    console.log(`User '${producer.appData.username}' is now producing ${kind} stream.`);
    callback({ id: producer.id });
  });

  socket.on('consume', async ({ transportId, producerId }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    
    if (!transport) {
        return callback({ error: 'Transport not found' });
    }

    const router = rooms[socket.forumId].router;
    const canConsume = router.canConsume({ producerId, rtpCapabilities: peer.rtpCapabilities });

    if (!canConsume) {
      return callback({ error: 'Cannot consume producer' });
    }
    
    const consumer = await transport.consume({
      producerId,
      rtpCapabilities: peer.rtpCapabilities,
      paused: false
    });

    consumer.on('producerclose', () => {
      console.log(`Producer for consumer ${consumer.id} closed`);
      consumer.close();
      peer.consumers.delete(consumer.id);
      socket.emit('producer-closed', { producerId: producerId });
    });
    
    peer.consumers.set(consumer.id, consumer);
    
    callback({
      id: consumer.id,
      producerId: consumer.producerId,
      kind: consumer.kind,
      rtpParameters: consumer.rtpParameters,
      type: consumer.type
    });
  });

  socket.on('screen-share-toggle', (data) => {
    socket.broadcast.to(data.forumId).emit('screen-share-toggle', { from: data.from, sharing: data.sharing });
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
    const forumId = socket.forumId;
    const room = rooms[forumId];
    if (!room) return;
    const peer = room.peers.get(socket.id);
    if (!peer) return;

    peer.transports.forEach(t => t.close());
    peer.producers.forEach(p => p.close());
    peer.consumers.forEach(c => c.close());
    room.peers.delete(socket.id);

    socket.broadcast.to(forumId).emit('peer-left', { username: peer.username });
    
    if (room.peers.size === 0) {
      console.log(`Forum ${forumId} is empty. Closing router.`);
      room.router.close();
      delete rooms[forumId];
    }
  });

  socket.on('toggle-video', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-video', { from: data.from, enabled: data.enabled });
  });

  socket.on('toggle-audio', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-audio', { from: data.from, enabled: data.enabled });
  });
});

function getActiveSpeaker(audioLevels) {
    if (audioLevels.size === 0) return null;
    let maxVolume = -Infinity;
    let activeSpeaker = null;

    for (const [username, volume] of audioLevels.entries()) {
        if (volume > -50 && volume > maxVolume) { // -50dB is a reasonable threshold
            maxVolume = volume;
            activeSpeaker = username;
        }
    }
    return activeSpeaker;
}

server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});

