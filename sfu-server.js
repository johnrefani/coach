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
    // Change to '127.0.0.1' for local testing
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

  // --- Handlers for mediasoup-client v2 Room API ---
  
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

  socket.on('load', (request, callback) => {
    try {
      peer.rtpCapabilities = request.rtpCapabilities;
      console.log(`Peer ${peer.name} loaded with RTP capabilities`);
      callback(null);
    } catch (err) {
      console.error('Error in load:', err);
      callback(err.toString());
    }
  });

  socket.on('join', async (request, callback) => {
    try {
        const { forumId, displayName, profilePicture } = request.appData;
        room = await getOrCreateRoom(forumId);

        peer = {
            id: socket.id,
            name: request.peerName,
            appData: request.appData,
            rtpCapabilities: null,
            recvTransportId: null,
            recvTransportConnected: false,
            transports: new Map(),
            producers: new Map(),
            consumers: new Map(),
            socket: socket
        };

        const peersInRoom = Array.from(room.peers.values())
          .map(p => ({ name: p.name, appData: p.appData }));

        // Notify existing peers about the new peer
        for (const existingPeer of room.peers.values()) {
            existingPeer.socket.emit('notification', {
                method: 'newPeer',
                name: peer.name,
                appData: peer.appData
            });
        }
        
        room.peers.set(socket.id, peer);

        console.log(`Peer ${peer.name} joined room ${forumId}`);
        callback(null, { peers: peersInRoom });
    } catch(err) {
        console.error('Error in join:', err);
        callback(err.toString());
    }
  });
  
  socket.on('createTransport', async (request, callback) => {
    try {
      const transport = await room.router.createWebRtcTransport({
          listenIps: [{ ip: '0.0.0.0', announcedIp: SFU_CONFIG.announcedIp }],
          enableUdp: true,
          enableTcp: true,
          preferUdp: true,
      });
      
      const transportData = {
        transport,
        direction: request.direction
      };
      
      peer.transports.set(transport.id, transportData);
      
      if (request.direction === 'recv') {
        peer.recvTransportId = transport.id;
      }
      
      console.log(`Transport created for ${peer.name} [${request.direction}]: ${transport.id}`);
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
      const transportData = peer.transports.get(request.id);
      if (!transportData) throw new Error(`Transport with id "${request.id}" not found`);

      const { transport, direction } = transportData;

      await transport.connect({ dtlsParameters: request.dtlsParameters });
      
      console.log(`Transport connected for ${peer.name} [${direction}]: ${request.id}`);
      
      if (direction === 'recv') {
        peer.recvTransportConnected = true;
        console.log(`Recv transport connected for ${peer.name} - creating initial consumers`);
        // Create initial consumers for all existing producers
        for (const otherPeer of room.peers.values()) {
          if (otherPeer.id === peer.id) continue;
          for (const producer of otherPeer.producers.values()) {
            try {
              const consumer = await transport.consume({
                producerId: producer.id,
                rtpCapabilities: peer.rtpCapabilities
              });
              peer.consumers.set(consumer.id, consumer);
              // Notify this peer with newConsumer
              socket.emit('notification', {
                method: 'newConsumer',
                id: consumer.id,
                producerId: producer.id,
                kind: consumer.kind,
                rtpParameters: consumer.rtpParameters,
                producerPaused: consumer.producerPaused
              });
              console.log(`Initial consumer created for ${peer.name} from producer ${producer.id}`);
            } catch (err) {
              console.error(`Error creating initial consumer for ${peer.name}:`, err);
            }
          }
        }
      }
      
      callback(null);
    } catch (err) {
      console.error('Error connecting transport:', err);
      callback(err.toString());
    }
  });
  
  socket.on('createProducer', async (request, callback) => {
    try {
      const transportData = peer.transports.get(request.transportId);
      if (!transportData) throw new Error(`Transport with id "${request.transportId}" not found`);

      const { transport } = transportData;

      const producer = await transport.produce({
        kind: request.kind,
        rtpParameters: request.rtpParameters,
        appData: request.appData
      });
      
      peer.producers.set(producer.id, producer);
      console.log(`Producer created for ${peer.name} [${request.kind}]: ${producer.id}`);
      
      // Create consumers for all other connected peers
      for (const otherPeer of room.peers.values()) {
        if (otherPeer.id === peer.id || !otherPeer.recvTransportConnected || !otherPeer.rtpCapabilities) continue;
        try {
          const otherTransportData = otherPeer.transports.get(otherPeer.recvTransportId);
          const otherTransport = otherTransportData.transport;
          const consumer = await otherTransport.consume({
            producerId: producer.id,
            rtpCapabilities: otherPeer.rtpCapabilities
          });
          otherPeer.consumers.set(consumer.id, consumer);
          // Notify the other peer
          otherPeer.socket.emit('notification', {
            method: 'newConsumer',
            id: consumer.id,
            producerId: producer.id,
            kind: consumer.kind,
            rtpParameters: consumer.rtpParameters,
            producerPaused: consumer.producerPaused
          });
          console.log(`Consumer created for ${otherPeer.name} from new producer ${producer.id}`);
        } catch (err) {
          console.error(`Error creating consumer for ${otherPeer.name}:`, err);
        }
      }
      
      callback(null, { id: producer.id });
    } catch(err) {
      console.error('Error creating producer:', err);
      callback(err.toString());
    }
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected [socketId:${socket.id}]`);
    if (peer && room) {
        // Notify remaining peers
        for (const otherPeer of room.peers.values()) {
            if (otherPeer.id !== peer.id) {
                otherPeer.socket.emit('notification', {
                    method: 'peerClosed',
                    name: peer.name
                });
            }
        }
        room.peers.delete(peer.id);
    }
  });

});

// --- Start the server ---
server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});
