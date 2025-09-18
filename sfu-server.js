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
    announcedIp: '174.138.18.220',
    listenPort: process.env.PORT || 8080
};

// Mediasoup room and worker state
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

// Socket.IO connection handling
io.on('connection', (socket) => {
  console.log(`Client connected [socketId:${socket.id}]`);

  let peer = null;
  let room = null;

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

  socket.on('join', async (request, callback) => {
    try {
        const { forumId } = request.appData;
        room = await getOrCreateRoom(forumId);

        peer = {
            id: socket.id,
            name: request.peerName,
            appData: request.appData,
            rtpCapabilities: request.rtpCapabilities,
            transports: new Map(),
            producers: new Map(),
            consumers: new Map()
        };

        const peersInRoom = Array.from(room.peers.values())
          .map(p => ({
            name: p.name,
            appData: p.appData,
            consumers: Array.from(p.producers.values()).map(prod => ({
              id: prod.id,
              kind: prod.kind,
              rtpParameters: prod.rtpParameters,
              appData: prod.appData
            }))
          }));

        for (const existingPeer of room.peers.values()) {
            existingPeer.socket.emit('notification', {
                method: 'newPeer',
                name: peer.name,
                appData: peer.appData
            });
        }

        peer.socket = socket;
        room.peers.set(socket.id, peer);

        callback(null, { peers: peersInRoom });
    } catch(err) {
        console.error('Error in join:', err);
        callback(err.toString());
    }
  });

  socket.on('createTransport', async (request, callback) => {
    try {
        if (!room) throw new Error('Not joined in a room yet');
        const transport = await room.router.createWebRtcTransport({
            listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
            enableUdp: true,
            enableTcp: true,
            preferUdp: true,
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
        callback(err.toString());
    }
  });

  socket.on('connectTransport', async (request, callback) => {
    try {
      const transport = peer.transports.get(request.id);
      if (!transport) throw new Error(`Transport with id "${request.id}" not found`);

      await transport.connect({ dtlsParameters: request.dtlsParameters });
      callback(null);
    } catch (err) {
      console.error('Error connecting transport:', err);
      callback(err.toString());
    }
  });

  socket.on('createProducer', async (request, callback) => {
    try {
      const transport = peer.transports.get(request.transportId);
      if (!transport) throw new Error(`Transport with id "${request.transportId}" not found`);

      const producer = await transport.produce({
        kind: request.kind,
        rtpParameters: request.rtpParameters,
        appData: { ...request.appData, peerName: peer.name }
      });

      peer.producers.set(producer.id, producer);

      // **FIX 1:** Notify other peers that there's a new stream available for them TO CONSUME.
      for (const otherPeer of room.peers.values()) {
        if (otherPeer.id === peer.id) continue;
        otherPeer.socket.emit('notification', {
          method: 'newConsumer', // <-- This is the correct method name
          peerName: peer.name,
          id: producer.id,
          kind: producer.kind,
          rtpParameters: producer.rtpParameters,
          appData: producer.appData
        });
      }

      callback(null, { id: producer.id });
    } catch(err) {
      console.error('Error creating producer:', err);
      callback(err.toString());
    }
  });

  socket.on('enableConsumer', async (request, callback) => {
    try {
      const consumerPeer = room.peers.get(socket.id);
      if (!consumerPeer) throw new Error('Consumer peer not found');

      const transport = Array.from(consumerPeer.transports.values()).find(t => t.appData.direction !== 'send');
      if (!transport) throw new Error('Receiving transport not found');

      if (!room.router.canConsume({ producerId: request.id, rtpCapabilities: consumerPeer.rtpCapabilities })) {
          return callback(`Client cannot consume producer ${request.id}`);
      }

      const consumer = await transport.consume({
        producerId: request.id,
        rtpCapabilities: consumerPeer.rtpCapabilities,
        paused: true
      });

      consumerPeer.consumers.set(consumer.id, consumer);

      callback(null, {
          id: consumer.id,
          producerId: request.id,
          kind: consumer.kind,
          rtpParameters: consumer.rtpParameters,
          paused: consumer.producerPaused,
      });
    } catch(err) {
        console.error('Error enabling consumer:', err);
        callback(err.toString());
    }
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected [socketId:${socket.id}]`);
    if (peer && room) {
        room.peers.delete(peer.id);
        for (const otherPeer of room.peers.values()) {
            otherPeer.socket.emit('notification', {
                method: 'peerClosed',
                name: peer.name
            });
        }
    }
  });

});

server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});

