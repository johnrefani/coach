const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');
const { spawn } = require('child_process');

const app = express();
const server = http.createServer(app);
const io = socketIo(server);

// Configuration for your SFU server
const SFU_CONFIG = {
    // The IP address announced to clients for WebRTC connections
    announcedIp: '174.138.18.220', 
    listenPort: process.env.PORT || 8080
};

// A map to store active forums and their mediasoup routers
const rooms = {};
let worker;

const mediaCodecs = [
  { kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 },
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000, preferredPayloadType: 96 },
  { kind: 'video', mimeType: 'video/H264', clockRate: 90000, preferredPayloadType: 102 }
];

// Mediasoup worker management
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

// Function to create or get a router for a forum
async function getOrCreateRouter(forumId) {
  if (rooms[forumId] && rooms[forumId].router) {
    return rooms[forumId].router;
  }
  const router = await worker.createRouter({ mediaCodecs });
  rooms[forumId] = { router, peers: new Map(), audioLevels: new Map(), activeSpeaker: null };
  console.log(`Router created for forum ${forumId}`);
  return router;
}

// Socket.IO signaling logic
io.on('connection', async (socket) => {
  console.log(`New client connected: ${socket.id}`);

  socket.on('join-forum', async ({ forumId, username, displayName, profilePicture, rtpCapabilities }) => {
    const router = await getOrCreateRouter(forumId);
    
    // Store peer info
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
    
    // Announce new user to others
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
  
  // Create transport for a client (send or receive)
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
  
  // Connect a transport after it's been created
  socket.on('transport-connect', async ({ transportId, dtlsParameters }) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (transport) {
      await transport.connect({ dtlsParameters });
    }
  });

  // Start producing a stream
  socket.on('transport-produce', async ({ transportId, kind, rtpParameters, appData }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (!transport) {
      return callback({ error: 'Transport not found' });
    }
    
    const producer = await transport.produce({ kind, rtpParameters });
    producer.appData.username = appData.username; // Correctly get username from appData
    peer.producers.set(producer.id, producer);

    // If it's an audio producer, set up level monitoring for active speaker detection
    if (kind === 'audio') {
        const audioLevelObserver = producer.observer.on('volumes', (volumes) => {
            if (volumes.length > 0) {
                const { producer, volume } = volumes[0];
                rooms[socket.forumId].audioLevels.set(producer.appData.username, volume);
                
                const currentSpeaker = rooms[socket.forumId].activeSpeaker;
                const newSpeaker = getActiveSpeaker(rooms[socket.forumId].audioLevels);
                
                if (newSpeaker && newSpeaker !== currentSpeaker) {
                    rooms[socket.forumId].activeSpeaker = newSpeaker;
                    io.to(socket.forumId).emit('speaker-changed', { username: newSpeaker });
                }
            }
        });
    }

    // Broadcast new producer to all other peers
    socket.broadcast.to(socket.forumId).emit('new-producer', {
      producerId: producer.id,
      producerUsername: producer.appData.username,
      producerKind: producer.kind
    });
    console.log(`User '${producer.appData.username}' is now producing ${kind} stream.`);
    callback({ id: producer.id });
  });

  // Start consuming a remote stream
  socket.on('consume', async ({ transportId, producerId }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    const remoteProducer = getProducerById(socket.forumId, producerId);
    
    if (!transport || !remoteProducer) {
        return callback({ error: 'Transport or Producer not found' });
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

  // Handle client disconnecting
  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
    const peer = rooms[socket.forumId]?.peers.get(socket.id);
    if (!peer) return;

    peer.transports.forEach(t => t.close());
    peer.producers.forEach(p => p.close());
    peer.consumers.forEach(c => c.close());
    rooms[socket.forumId].peers.delete(socket.id);

    socket.broadcast.to(socket.forumId).emit('peer-left', { username: peer.username });
    
    if (rooms[socket.forumId].peers.size === 0) {
      console.log(`Forum ${socket.forumId} is empty. Closing router.`);
      rooms[socket.forumId].router.close();
      delete rooms[socket.forumId];
    }
  });

  // Handle video/audio toggles
  socket.on('toggle-video', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-video', { from: data.from, enabled: data.enabled });
  });

  socket.on('toggle-audio', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-audio', { from: data.from, enabled: data.enabled });
  });
});

function getProducerById(forumId, producerId) {
    const peers = rooms[forumId]?.peers;
    if (!peers) return null;
    for (const peer of peers.values()) {
        if (peer.producers.has(producerId)) {
            return peer.producers.get(producerId);
        }
    }
    return null;
}

function getActiveSpeaker(audioLevels) {
    if (audioLevels.size === 0) return null;
    let maxVolume = -Infinity;
    let activeSpeaker = null;

    for (const [username, volume] of audioLevels.entries()) {
        if (volume > -50 && volume > maxVolume) {
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