const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
  cors: {
    origin: "*",
    methods: ["GET", "POST"]
  }
});

const SFU_CONFIG = {
  announcedIp: 174.138.18.220 || '127.0.0.1',
  listenPort: process.env.PORT || 8080
};

const mediaCodecs = [
  {
    kind: 'audio',
    mimeType: 'audio/opus',
    clockRate: 48000,
    channels: 2
  },
  {
    kind: 'video',
    mimeType: 'video/VP8',
    clockRate: 90000,
    parameters: {
      'x-google-start-bitrate': 1000
    }
  },
  {
    kind: 'video',
    mimeType: 'video/H264',
    clockRate: 90000,
    parameters: {
      'packetization-mode': 1,
      'profile-level-id': '42e01f',
      'level-asymmetry-allowed': 1
    }
  }
];

let worker;
let nextMediasoupWorkerId = 0;

// Initialize mediasoup worker
async function initMediasoupWorker() {
  worker = await mediasoup.createWorker({
    logLevel: 'warn',
    logTags: ['info', 'ice', 'dtls', 'rtp', 'srtp', 'rtcp'],
    rtcMinPort: 40000,
    rtcMaxPort: 49999
  });

  console.log(`Mediasoup worker created (pid: ${worker.pid})`);

  worker.on('died', () => {
    console.error('Mediasoup worker died, exiting in 2 seconds...');
    setTimeout(() => process.exit(1), 2000);
  });

  return worker;
}

const rooms = new Map();
const socketPeers = new Map();

function getOrCreateRoom(forumId) {
  let room = rooms.get(forumId);
  
  if (!room) {
    room = {
      id: forumId,
      router: null,
      peers: new Map(),
      createdAt: Date.now()
    };
    
    rooms.set(forumId, room);
    console.log(`Room created for forum ${forumId}`);
    
    // Initialize router for this room
    initRoomRouter(room).then(() => {
      console.log(`Router created for room ${forumId}`);
    }).catch(err => {
      console.error(`Error creating router for room ${forumId}:`, err);
    });
    
    // Clean up room after 1 hour of inactivity
    setTimeout(() => {
      if (rooms.get(forumId) === room) {
        if (room.router) {
          room.router.close();
        }
        rooms.delete(forumId);
        console.log(`Room for forum ${forumId} cleaned up`);
      }
    }, 3600000);
  }
  
  return room;
}

async function initRoomRouter(room) {
  if (!room.router) {
    room.router = await worker.createRouter({ mediaCodecs });
    console.log(`Router created for room ${room.id}`);
  }
  return room.router;
}

io.on('connection', (socket) => {
  console.log(`Client connected [socketId:${socket.id}]`);

  socket.on('getRouterRtpCapabilities', (data, callback) => {
    try {
      const { forumId } = data;
      if (!forumId) throw new Error('forumId is required');
      
      const room = getOrCreateRoom(forumId);
      if (!room.router) {
        throw new Error('Router not ready yet');
      }
      
      callback(null, room.router.rtpCapabilities);
    } catch (err) {
      console.error('Error getting router capabilities:', err);
      callback(err.message);
    }
  });

  socket.on('join', async (data, callback) => {
    try {
      console.log('Join request received:', data);
      const { forumId, peerName, rtpCapabilities, appData } = data;
      if (!forumId || !peerName) {
        throw new Error('forumId and peerName are required');
      }
      
      const room = getOrCreateRoom(forumId);
      
      // Wait for router to be ready
      if (!room.router) {
        await initRoomRouter(room);
      }
      
      // Check if peer already exists
      if (room.peers.has(peerName)) {
        throw new Error('Peer name already in use');
      }
      
      const peer = {
        id: socket.id,
        name: peerName,
        socket: socket,
        rtpCapabilities: rtpCapabilities,
        appData: appData,
        transports: new Map(),
        producers: new Map(),
        consumers: new Map()
      };
      
      room.peers.set(peerName, peer);
      socketPeers.set(socket.id, peer);
      
      // Notify other peers about new peer
      room.peers.forEach((otherPeer, name) => {
        if (name !== peerName && otherPeer.socket) {
          otherPeer.socket.emit('notification', {
            method: 'newpeer',
            data: {
              peerName: peer.name,
              appData: peer.appData
            }
          });
          
          // Send existing producers to new peer
          otherPeer.producers.forEach((producer) => {
            socket.emit('notification', {
              method: 'newproducer',
              data: {
                id: producer.id,
                kind: producer.kind,
                rtpParameters: producer.rtpParameters,
                appData: producer.appData,
                peerName: otherPeer.name
              }
            });
          });
        }
      });
      
      // Return list of existing peers
      const peers = Array.from(room.peers.values())
        .filter(p => p.name !== peerName)
        .map(p => ({
          name: p.name,
          appData: p.appData
        }));
      
      callback(null, { 
        peers,
        routerRtpCapabilities: room.router.rtpCapabilities
      });
      
    } catch (err) {
      console.error('Error in join handler:', err);
      callback(err.message);
    }
  });

  socket.on('createWebRtcTransport', async (data, callback) => {
    try {
      const { forumId, direction } = data;
      if (!forumId) throw new Error('forumId is required');
      
      const room = rooms.get(forumId);
      if (!room || !room.router) throw new Error('Room or router not found');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const transport = await room.router.createWebRtcTransport({
        listenIps: [
          { 
            ip: '0.0.0.0', 
            announcedIp: SFU_CONFIG.announcedIp
          }
        ],
        enableUdp: true,
        enableTcp: true,
        preferUdp: true,
        initialAvailableOutgoingBitrate: 1000000,
        appData: { direction }
      });
      
      peer.transports.set(transport.id, transport);
      
      callback(null, {
        id: transport.id,
        iceParameters: transport.iceParameters,
        iceCandidates: transport.iceCandidates,
        dtlsParameters: transport.dtlsParameters
      });
      
    } catch (err) {
      console.error('Error creating transport:', err);
      callback(err.message);
    }
  });

  socket.on('connectTransport', async (data, callback) => {
    try {
      const { transportId, dtlsParameters, forumId } = data;
      if (!transportId || !dtlsParameters || !forumId) {
        throw new Error('transportId, dtlsParameters, and forumId are required');
      }
      
      const room = rooms.get(forumId);
      if (!room) throw new Error('Room not found');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const transport = peer.transports.get(transportId);
      if (!transport) throw new Error('Transport not found');
      
      await transport.connect({ dtlsParameters });
      console.log(`Transport connected: ${transportId}`);
      callback(null);
      
    } catch (err) {
      console.error('Error connecting transport:', err);
      callback(err.message);
    }
  });

  socket.on('produce', async (data, callback) => {
    try {
      const { transportId, kind, rtpParameters, appData, forumId } = data;
      if (!transportId || !kind || !rtpParameters || !forumId) {
        throw new Error('transportId, kind, rtpParameters, and forumId are required');
      }
      
      const room = rooms.get(forumId);
      if (!room) throw new Error('Room not found');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const transport = peer.transports.get(transportId);
      if (!transport) throw new Error('Transport not found');
      
      const producer = await transport.produce({
        kind,
        rtpParameters,
        appData
      });
      
      peer.producers.set(producer.id, producer);
      console.log(`Producer created: ${producer.id} for peer: ${peer.name}`);
      
      // Notify other peers about new producer
      room.peers.forEach((otherPeer, name) => {
        if (name !== peer.name && otherPeer.socket) {
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
      
      callback(null, { id: producer.id });
      
    } catch (err) {
      console.error('Error producing:', err);
      callback(err.message);
    }
  });

  socket.on('consume', async (data, callback) => {
    try {
      const { transportId, producerId, rtpCapabilities, forumId } = data;
      if (!transportId || !producerId || !rtpCapabilities || !forumId) {
        throw new Error('transportId, producerId, rtpCapabilities, and forumId are required');
      }
      
      const room = rooms.get(forumId);
      if (!room) throw new Error('Room not found');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const transport = peer.transports.get(transportId);
      if (!transport) throw new Error('Transport not found');
      
      // Find the producer
      let producer;
      for (const otherPeer of room.peers.values()) {
        producer = otherPeer.producers.get(producerId);
        if (producer) break;
      }
      
      if (!producer) throw new Error('Producer not found');
      
      // Check if the consumer can be created
      if (!room.router.canConsume({
        producerId: producer.id,
        rtpCapabilities: peer.rtpCapabilities
      })) {
        throw new Error('Cannot consume this producer');
      }
      
      const consumer = await transport.consume({
        producerId: producer.id,
        rtpCapabilities: peer.rtpCapabilities,
        paused: false
      });
      
      peer.consumers.set(consumer.id, consumer);
      
      consumer.on('transportclose', () => {
        console.log(`Consumer transport closed: ${consumer.id}`);
        peer.consumers.delete(consumer.id);
      });
      
      consumer.on('producerclose', () => {
        console.log(`Producer closed, closing consumer: ${consumer.id}`);
        socket.emit('notification', {
          method: 'producerclosed',
          data: { consumerId: consumer.id }
        });
        peer.consumers.delete(consumer.id);
      });
      
      callback(null, {
        id: consumer.id,
        producerId: producer.id,
        kind: consumer.kind,
        rtpParameters: consumer.rtpParameters,
        type: consumer.type
      });
      
    } catch (err) {
      console.error('Error consuming:', err);
      callback(err.message);
    }
  });

  socket.on('resumeConsumer', async (data, callback) => {
    try {
      const { consumerId, forumId } = data;
      if (!consumerId || !forumId) {
        throw new Error('consumerId and forumId are required');
      }
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const consumer = peer.consumers.get(consumerId);
      if (!consumer) throw new Error('Consumer not found');
      
      await consumer.resume();
      callback(null);
      
    } catch (err) {
      console.error('Error resuming consumer:', err);
      callback(err.message);
    }
  });

  socket.on('closeProducer', async (data, callback) => {
    try {
      const { producerId, forumId } = data;
      if (!producerId || !forumId) {
        throw new Error('producerId and forumId are required');
      }
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const producer = peer.producers.get(producerId);
      if (!producer) throw new Error('Producer not found');
      
      producer.close();
      peer.producers.delete(producerId);
      
      // Notify other peers about producer closure
      const room = rooms.get(forumId);
      if (room) {
        room.peers.forEach((otherPeer, name) => {
          if (name !== peer.name && otherPeer.socket) {
            otherPeer.socket.emit('notification', {
              method: 'producerclosed',
              data: { producerId: producer.id, peerName: peer.name }
            });
          }
        });
      }
      
      callback(null);
      
    } catch (err) {
      console.error('Error closing producer:', err);
      callback(err.message);
    }
  });

  socket.on('closeConsumer', async (data, callback) => {
    try {
      const { consumerId } = data;
      if (!consumerId) throw new Error('consumerId is required');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const consumer = peer.consumers.get(consumerId);
      if (!consumer) throw new Error('Consumer not found');
      
      consumer.close();
      peer.consumers.delete(consumerId);
      
      callback(null);
      
    } catch (err) {
      console.error('Error closing consumer:', err);
      callback(err.message);
    }
  });

  socket.on('closeTransport', async (data, callback) => {
    try {
      const { transportId } = data;
      if (!transportId) throw new Error('transportId is required');
      
      const peer = socketPeers.get(socket.id);
      if (!peer) throw new Error('Peer not found');
      
      const transport = peer.transports.get(transportId);
      if (!transport) throw new Error('Transport not found');
      
      transport.close();
      peer.transports.delete(transportId);
      
      callback(null);
      
    } catch (err) {
      console.error('Error closing transport:', err);
      callback(err.message);
    }
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected [socketId:${socket.id}]`);
    
    const peer = socketPeers.get(socket.id);
    if (peer) {
      // Notify other peers about disconnection
      const room = Array.from(rooms.values()).find(r => r.peers.has(peer.name));
      if (room) {
        room.peers.delete(peer.name);
        
        room.peers.forEach((otherPeer) => {
          if (otherPeer.socket) {
            otherPeer.socket.emit('notification', {
              method: 'peerclosed',
              data: { peerName: peer.name }
            });
          }
        });
      }
      
      // Clean up peer resources
      peer.transports.forEach(transport => transport.close());
      peer.producers.forEach(producer => producer.close());
      peer.consumers.forEach(consumer => consumer.close());
      
      socketPeers.delete(socket.id);
    }
  });
});

// Initialize mediasoup and start server
async function startServer() {
  try {
    await initMediasoupWorker();
    
    server.listen(SFU_CONFIG.listenPort, () => {
      console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
      console.log(`Announced IP: ${SFU_CONFIG.announcedIp}`);
    });
  } catch (err) {
    console.error('Failed to initialize mediasoup worker:', err);
    process.exit(1);
  }
}

startServer();
