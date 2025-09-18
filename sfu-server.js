// sfu-server.js
const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const mediasoup = require('mediasoup');

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
  path: '/sfu-socket/socket.io',
  cors: {
    origin: "*",
    methods: ["GET", "POST"]
  }
});

const SFU_CONFIG = {
  announcedIp: '174.138.18.220', // your public server IP
  listenPort: process.env.PORT || 8080
};

const rooms = new Map();
let worker;

const mediaCodecs = [
  { kind: 'audio', mimeType: 'audio/opus', clockRate: 48000, channels: 2 },
  { kind: 'video', mimeType: 'video/VP8', clockRate: 90000 },
  { kind: 'video', mimeType: 'video/H264', clockRate: 90000 }
];

async function createWorker() {
  worker = await mediasoup.createWorker({
    logLevel: 'warn',
    rtcMinPort: 40000,
    rtcMaxPort: 49999,
  });

  worker.on('died', () => {
    console.error('Mediasoup worker died, exiting in 2 seconds...');
    setTimeout(() => process.exit(1), 2000);
  });
  console.log(`Mediasoup worker created [pid:${worker.pid}]`);
}

async function getOrCreateRoom(forumId) {
  let room = rooms.get(forumId);
  if (!room) {
    const router = await worker.createRouter({ mediaCodecs });
    room = {
      id: forumId,
      router,
      peers: new Map()
    };
    rooms.set(forumId, room);
    console.log(`Room created for forum ${forumId}`);
  }
  return room;
}

io.on('connection', (socket) => {
  console.log(`Client connected [socketId:${socket.id}]`);

  let peer = null;
  let room = null;

  // ===== Query Room Capabilities =====
  socket.on('queryRoom', async (request, callback) => {
    try {
      const { forumId } = request.appData;
      const targetRoom = await getOrCreateRoom(forumId);
      callback(null, { rtpCapabilities: targetRoom.router.rtpCapabilities });
    } catch (err) {
      console.error('Error in queryRoom:', err);
      callback(err.toString());
    }
  });

  // ===== Join Room =====
  socket.on('join', async (request, callback) => {
    try {
      const { forumId } = request.appData;
      room = await getOrCreateRoom(forumId);

      peer = {
        id: socket.id,
        name: request.peerName,
        appData: request.appData,
        transports: new Map(), // id -> transport
        producers: new Map(),
        consumers: new Map(),
        socket: socket
      };

      const peersInRoom = Array.from(room.peers.values())
        .map(p => ({ name: p.name, appData: p.appData }));

      // Notify existing peers
      for (const existingPeer of room.peers.values()) {
        existingPeer.socket.emit('notification', {
          method: 'newPeer',
          data: { name: peer.name, appData: peer.appData }
        });
      }

      room.peers.set(socket.id, peer);
      console.log(`Peer ${peer.name} joined room ${forumId}`);
      callback(null, { peers: peersInRoom });
    } catch (err) {
      console.error('Error in join:', err);
      callback(err.toString());
    }
  });

  // ===== Create Transport =====
  socket.on('createTransport', async (request, callback) => {
    try {
      const { direction } = request;
      const transport = await room.router.createWebRtcTransport({
        listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
        enableUdp: true,
        enableTcp: true,
        preferUdp: true,
      });

      peer.transports.set(direction, transport); // store by direction
      console.log(`Transport created for ${peer.name} [${direction}]: ${transport.id}`);

      callback(null, {
        id: transport.id,
        iceParameters: transport.iceParameters,
        iceCandidates: transport.iceCandidates,
        dtlsParameters: transport.dtlsParameters
      });
    } catch (err) {
      console.error('Error creating transport:', err);
      callback(err.toString());
    }
  });

  // ===== Connect Transport =====
  socket.on('connectTransport', async ({ dtlsParameters, direction }, callback) => {
    try {
      const transport = peer.transports.get(direction);
      await transport.connect({ dtlsParameters });
      callback({ connected: true });

      // If it's a recv transport, send existing producers
      if (direction === 'recv') {
        for (const otherPeer of room.peers.values()) {
          if (otherPeer.id === peer.id) continue;
          for (const producer of otherPeer.producers.values()) {
            peer.socket.emit('notification', {
              method: 'newProducer',
              data: { producerId: producer.id, kind: producer.kind }
            });
          }
        }
      }
    } catch (err) {
      console.error('connectTransport error', err);
      callback({ error: err.message });
    }
  });

  // ===== Create Producer =====
  socket.on('createProducer', async ({ kind, rtpParameters, appData }, callback) => {
    try {
      const transport = peer.transports.get('send');
      const producer = await transport.produce({ kind, rtpParameters, appData });
      peer.producers.set(producer.id, producer);

      // Notify all other peers
      for (const otherPeer of room.peers.values()) {
        if (otherPeer.id === peer.id) continue;
        otherPeer.socket.emit('notification', {
          method: 'newProducer',
          data: { producerId: producer.id, kind: producer.kind }
        });
      }

      callback({ id: producer.id });
    } catch (err) {
      console.error('createProducer error', err);
      callback({ error: err.message });
    }
  });

  // ===== Create Consumer =====
  socket.on('createConsumer', async ({ producerId, rtpCapabilities }, callback) => {
    try {
      if (!room.router.canConsume({ producerId, rtpCapabilities })) {
        throw new Error('Cannot consume');
      }

      const transport = peer.transports.get('recv');
      const consumer = await transport.consume({
        producerId,
        rtpCapabilities,
        paused: false,
      });

      peer.consumers.set(consumer.id, consumer);

      consumer.on('transportclose', () => {
        peer.consumers.delete(consumer.id);
      });

      callback({
        id: consumer.id,
        producerId,
        kind: consumer.kind,
        rtpParameters: consumer.rtpParameters,
      });
    } catch (err) {
      console.error('createConsumer error', err);
      callback({ error: err.message });
    }
  });

  // ===== Disconnect =====
  socket.on('disconnect', () => {
    console.log(`Client disconnected [socketId:${socket.id}]`);
    if (peer && room) {
      for (const otherPeer of room.peers.values()) {
        if (otherPeer.id !== peer.id) {
          otherPeer.socket.emit('notification', {
            method: 'peerClosed',
            data: { peerName: peer.name }
          });
        }
      }
      room.peers.delete(peer.id);
    }
  });
});

server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});
