const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);

// Initialize socket.io with the specific path the client is connecting to.
const io = socketIo(server, {
    path: '/sfu-socket/socket.io',
    cors: {
      origin: "*", // In production, you should restrict this to your actual domain.
      methods: ["GET", "POST"]
    }
});

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
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000, preferredPayloadType: 96 }
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

  // **FIX ADDED HERE**: Handle the client's request for router capabilities.
  // This must happen BEFORE the client tries to join.
  socket.on('get-rtp-capabilities', async (forumId, callback) => {
    try {
        const router = await getOrCreateRouter(forumId);
        // Send the capabilities back to the requesting client
        callback(router.rtpCapabilities);
    } catch (e) {
        console.error(`Error getting RTP capabilities for forum ${forumId}:`, e);
        // Signal an error to the client by sending back null
        callback(null);
    }
  });

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
    
    // Announce new user to others in the same forum
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
  socket.on('create-transport', async ({ isProducer }, callback) => {
    try {
      const router = rooms[socket.forumId].router;
      const transport = await router.createWebRtcTransport({
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
  
  // Connect a transport after it's been created on the client side
  socket.on('transport-connect', async ({ transportId, dtlsParameters }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (!transport) {
      return callback({ error: 'Transport not found' });
    }
    await transport.connect({ dtlsParameters });
    callback();
  });

  // Start producing a stream (sending media to the server)
  socket.on('transport-produce', async ({ transportId, kind, rtpParameters, appData }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    if (!transport) {
      return callback({ error: 'Transport not found' });
    }
    
    const producer = await transport.produce({ kind, rtpParameters, appData });
    peer.producers.set(producer.id, producer);

    if (kind === 'audio') {
        producer.on('volumes', (volumes) => {
            const { producer, volume } = volumes[0];
            rooms[socket.forumId].audioLevels.set(producer.appData.username, volume);
            const currentSpeaker = rooms[socket.forumId].activeSpeaker;
            const newSpeaker = getActiveSpeaker(rooms[socket.forumId].audioLevels);
            if (newSpeaker && newSpeaker !== currentSpeaker) {
                rooms[socket.forumId].activeSpeaker = newSpeaker;
                io.to(socket.forumId).emit('speaker-changed', { username: newSpeaker });
            }
        });
    }

    // Broadcast new producer to all other peers in the forum
    socket.broadcast.to(socket.forumId).emit('new-producer', {
      producerId: producer.id,
      producerUsername: producer.appData.username,
      kind: producer.kind
    });

    console.log(`User '${producer.appData.username}' is now producing ${kind} stream.`);
    callback({ id: producer.id });
  });

  // Start consuming a remote stream (receiving media from the server)
  socket.on('consume', async ({ transportId, producerId, rtpCapabilities }, callback) => {
    const peer = rooms[socket.forumId].peers.get(socket.id);
    const transport = peer.transports.get(transportId);
    
    if (!transport || !getProducerById(socket.forumId, producerId)) {
      return callback({ error: 'Transport or Producer not found' });
    }

    const router = rooms[socket.forumId].router;
    if (!router.canConsume({ producerId, rtpCapabilities })) {
      return callback({ error: 'Cannot consume producer' });
    }
    
    const consumer = await transport.consume({
      producerId,
      rtpCapabilities,
      paused: false // Start unpaused
    });

    consumer.on('producerclose', () => {
      consumer.close();
      peer.consumers.delete(consumer.id);
      socket.emit('producer-closed', { producerId: producerId });
    });
    
    peer.consumers.set(consumer.id, consumer);
    
    callback({
      id: consumer.id,
      producerId: consumer.producerId,
      kind: consumer.kind,
      rtpParameters: consumer.rtpParameters
    });
  });

  // Handle client disconnecting
  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
    const forumId = socket.forumId;
    if (!forumId || !rooms[forumId]) return;

    const peer = rooms[forumId].peers.get(socket.id);
    if (!peer) return;

    peer.transports.forEach(t => t.close());
    peer.producers.forEach(p => p.close());
    peer.consumers.forEach(c => c.close());
    rooms[forumId].peers.delete(socket.id);

    // Notify other peers that this user has left
    socket.broadcast.to(forumId).emit('peer-left', { username: peer.username });
    
    // If the room is now empty, clean it up
    if (rooms[forumId].peers.size === 0) {
      console.log(`Forum ${forumId} is empty. Closing router.`);
      rooms[forumId].router.close();
      delete rooms[forumId];
    }
  });

  // Relay media toggle events
  socket.on('toggle-video', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-video', { from: data.from, enabled: data.enabled });
  });

  socket.on('toggle-audio', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-audio', { from: data.from, enabled: data.enabled });
  });
});

// Helper function to find a producer across all peers in a room
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

// Helper function for active speaker detection
function getActiveSpeaker(audioLevels) {
    if (audioLevels.size === 0) return null;
    let maxVolume = -Infinity;
    let activeSpeaker = null;

    // A simple algorithm to find the loudest participant
    // -50 dB is a threshold to ignore background noise
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
