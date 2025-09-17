const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');
const { spawn } = require('child_process');

const app = express();
const server = http.createServer(app);

// FIX: Initialize socket.io with the specific path the client is connecting to.
// This ensures the server and client are on the same page for the WebSocket handshake.
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
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000, preferredPayloadType: 96 },
  // { kind: 'video', mimeType: 'video/H264', clockRate: 90000, preferredPayloadType: 102 } // H264 can sometimes have issues
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

  // **FIX 1: NEW HANDLER** - The first thing a client does is get the router's capabilities.
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
    // Gather producers from other peers already in the room
    for (const otherPeer of rooms[forumId].peers.values()) {
        if (otherPeer.id !== socket.id) {
            for (const producer of otherPeer.producers.values()) {
                existingProducers.push({
                    producerId: producer.id,
                    username: producer.appData.username,
                    kind: producer.kind
                });
            }
        }
    }

    // This event tells the client they've joined and who is already producing media.
    socket.emit('join-success', {
      existingProducers: existingProducers,
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
        if (dtlsState === 'closed') transport.close();
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
  socket.on('transport-connect', async ({ transportId, dtlsParameters }, callback) => {
    const peer = rooms[socket.forumId]?.peers.get(socket.id);
    const transport = peer?.transports.get(transportId);
    if (transport) {
      await transport.connect({ dtlsParameters });
      callback();
    } else {
        console.error(`transport-connect failed: transport ${transportId} not found`);
    }
  });

  // Start producing a stream
  socket.on('transport-produce', async ({ transportId, kind, rtpParameters, appData }, callback) => {
    const peer = rooms[socket.forumId]?.peers.get(socket.id);
    const transport = peer?.transports.get(transportId);
    if (!transport) {
      return callback({ error: 'Transport not found' });
    }
    
    const producer = await transport.produce({ kind, rtpParameters, appData });
    peer.producers.set(producer.id, producer);

    if (kind === 'audio') {
        const audioLevelObserver = await rooms[socket.forumId].router.createAudioLevelObserver({ maxEntries: 1, threshold: -80, interval: 800 });
        audioLevelObserver.on('volumes', (volumes) => {
            const { producer, volume } = volumes[0];
            io.to(socket.forumId).emit('active-speaker', { username: producer.appData.username, volume });
        });
        await audioLevelObserver.addProducer({ producerId: producer.id });
    }

    // **FIX 2: MORE INFORMATIVE BROADCAST** - Tell everyone who is producing what.
    socket.broadcast.to(socket.forumId).emit('new-producer', {
      producerId: producer.id,
      username: producer.appData.username,
      kind: producer.kind
    });
    console.log(`User '${producer.appData.username}' is now producing ${kind}.`);
    callback({ id: producer.id });
  });

  // Start consuming a remote stream
  socket.on('consume', async ({ transportId, producerId, rtpCapabilities }, callback) => {
    const peer = rooms[socket.forumId]?.peers.get(socket.id);
    const transport = peer?.transports.get(transportId);
    
    if (!transport) {
        return callback({ error: 'Transport not found' });
    }

    const router = rooms[socket.forumId].router;
    if (!router.canConsume({ producerId, rtpCapabilities })) {
      return callback({ error: 'Cannot consume producer' });
    }
    
    try {
        const consumer = await transport.consume({
          producerId,
          rtpCapabilities,
          paused: true // Start paused, client will resume
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
        });
    } catch (error) {
        console.error('Consume error:', error);
        callback({ error: error.message });
    }
  });

  socket.on('resume-consumer', async ({ consumerId }, callback) => {
    const peer = rooms[socket.forumId]?.peers.get(socket.id);
    const consumer = peer?.consumers.get(consumerId);
    if(consumer) {
        await consumer.resume();
    }
    if (callback) callback();
  });

  // Handle client disconnecting
  socket.on('disconnect', () => {
    console.log(`Client disconnected: ${socket.id}`);
    const forumId = socket.forumId;
    if (!forumId || !rooms[forumId]) return;

    const peer = rooms[forumId].peers.get(socket.id);
    if (!peer) return;

    // Close all producers and transports for this peer
    peer.producers.forEach(p => p.close());
    peer.transports.forEach(t => t.close());
    
    rooms[forumId].peers.delete(socket.id);

    // Let others know this peer has left
    socket.broadcast.to(forumId).emit('peer-left', { username: peer.username });
    
    // If the room is empty, clean it up
    if (rooms[forumId].peers.size === 0) {
      console.log(`Forum ${forumId} is empty. Closing router.`);
      rooms[forumId].router.close();
      delete rooms[forumId];
    }
  });

  // Forward video/audio toggle state to other clients
  socket.on('toggle-video', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-video', { from: data.from, enabled: data.enabled });
  });

  socket.on('toggle-audio', (data) => {
      socket.broadcast.to(data.forumId).emit('toggle-audio', { from: data.from, enabled: data.enabled });
  });

  socket.on('speaker-changed', (data) => {
      socket.broadcast.to(data.forumId).emit('speaker-changed', { username: data.username });
  });
});

function getActiveSpeaker(audioLevels) {
    if (audioLevels.size === 0) return null;
    let maxVolume = -Infinity;
    let activeSpeaker = null;

    //-50 is a good threshold for speaking vs background noise
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
