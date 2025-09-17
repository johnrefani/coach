const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: {
      origin: "*", // In production, restrict this to your actual domain.
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
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000 },
];

async function createWorker() {
  worker = await mediasoup.createWorker({
    rtcMinPort: 40000,
    rtcMaxPort: 49999,
  });
  console.log(`Mediasoup worker created with pid ${worker.pid}`);
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
  rooms[forumId] = { router, peers: new Map() };
  console.log(`Router created for forum ${forumId}`);
  return router;
}

io.on('connection', (socket) => {
  console.log(`New client connected: ${socket.id}`);

  // **FIX: STEP 1 - A dedicated event to provide server capabilities to the client.**
  // The client will call this immediately after connecting.
  socket.on('getRouterRtpCapabilities', async ({ forumId }, callback) => {
    try {
        const router = await getOrCreateRouter(forumId);
        callback(router.rtpCapabilities);
    } catch (e) {
        console.error('Error getting router capabilities:', e);
        callback({ error: e.message });
    }
  });

  socket.on('join-forum', async ({ forumId, username, displayName, profilePicture, rtpCapabilities }) => {
    console.log(`User '${username}' is joining forum '${forumId}'`);
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
    socket.forumId = forumId;
    
    socket.join(forumId);

    const existingProducers = [];
    rooms[forumId].peers.forEach(p => {
        if (p.id === socket.id) return;
        p.producers.forEach(producer => {
            existingProducers.push({
                producerId: producer.id,
                username: producer.appData.username,
                kind: producer.kind
            });
        });
    });
    
    // Announce new user to others after collecting existing producers
    socket.broadcast.to(forumId).emit('new-peer', {
        username: peer.username,
        displayName: peer.displayName,
        profilePicture: peer.profilePicture
    });

    socket.emit('join-success', {
      existingProducers: existingProducers,
    });
  });
  
  socket.on('create-transport', async ({ isProducer }, callback) => {
    try {
      const router = rooms[socket.forumId].router;
      const transport = await router.createWebRtcTransport({
        listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
        enableUdp: true,
        enableTcp: true,
        preferUdp: true,
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
    if (!transport) return callback({ error: 'Transport not found' });
    
    const producer = await transport.produce({ kind, rtpParameters, appData });
    peer.producers.set(producer.id, producer);

    socket.broadcast.to(socket.forumId).emit('new-producer', {
      producerId: producer.id,
      producerUsername: producer.appData.username,
      producerKind: kind
    });

    console.log(`User '${appData.username}' is now producing ${kind} stream.`);
    callback({ id: producer.id });
  });

  socket.on('consume', async ({ transportId, producerId, rtpCapabilities }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    
    if (!transport) return callback({ error: 'Transport not found' });

    const router = rooms[socket.forumId].router;
    if (!router.canConsume({ producerId, rtpCapabilities })) {
      return callback({ error: 'Cannot consume producer' });
    }
    
    const consumer = await transport.consume({
      producerId,
      rtpCapabilities,
      paused: false
    });

    consumer.on('producerclose', () => {
      console.log(`Producer for consumer ${consumer.id} closed`);
      socket.emit('producer-closed', { producerId });
      peer.consumers.delete(consumer.id);
      consumer.close();
    });
    
    peer.consumers.set(consumer.id, consumer);
    
    callback({
      id: consumer.id,
      producerId: consumer.producerId,
      kind: consumer.kind,
      rtpParameters: consumer.rtpParameters,
    });
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
    const forumId = socket.forumId;
    if (!forumId || !rooms[forumId]) return;

    const peer = rooms[forumId].peers.get(socket.id);
    if (!peer) return;

    peer.producers.forEach(p => p.close());
    peer.transports.forEach(t => t.close());
    rooms[forumId].peers.delete(socket.id);

    socket.broadcast.to(forumId).emit('peer-left', { username: peer.username });
    
    if (rooms[forumId].peers.size === 0) {
      console.log(`Forum ${forumId} is empty. Closing router.`);
      rooms[forumId].router.close();
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

server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});
