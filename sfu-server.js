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
        const { forumId, displayName, profilePicture } = request.appData;
        room = await getOrCreateRoom(forumId);

        peer = {
            id: socket.id,
            name: request.peerName,
            appData: request.appData,
            rtpCapabilities: request.rtpCapabilities,
            recvTransportId: null,
            recvTransportConnected: false,
            transports: new Map(),
            producers: new Map(),
            consumers: new Map(),
            socket: socket
        };

        const peersInRoom = Array.from(room.peers.values())
          .map(p => ({ name: p.name, appData: p.appData }));

        // Notify existing peers about new peer
        for (const existingPeer of room.peers.values()) {
            existingPeer.socket.emit('notification', {
                notification: true, // Added
                target: 'room',
                method: 'newpeer',
                data: {
                    peerName: peer.name,
                    appData: peer.appData
                }
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
      
      peer.transports.set(transport.id, transport);
      
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
      const transport = peer.transports.get(request.id);
      if (!transport) throw new Error(`Transport with id "${request.id}" not found`);

      await transport.connect({ dtlsParameters: request.dtlsParameters });
      
      console.log(`Transport connected for ${peer.name}: ${request.id}`);
      
      if (request.direction === 'recv') {
        peer.recvTransportConnected = true;
        peer.rtpCapabilities = request.rtpCapabilities || peer.rtpCapabilities; // Ensure capabilities are set
        console.log(`Recv transport connected for ${peer.name} - notifying existing producers`);
        for (const otherPeer of room.peers.values()) {
          if (otherPeer.id === peer.id) continue;
          for (const producer of otherPeer.producers.values()) {
            try {
              if (!peer.rtpCapabilities) throw new Error(`No rtpCapabilities for ${peer.name}`);
              peer.socket.emit('notification', {
                notification: true,
                target: 'peer',
                method: 'newproducer',
                data: {
                  id: producer.id,
                  kind: producer.kind,
                  rtpParameters: producer.rtpParameters,
                  appData: producer.appData
                }
              });
              console.log(`Sent newproducer ${producer.id} [${producer.kind}] to ${peer.name}`);
            } catch (err) {
                console.error(`Error notifying initial producer ${producer.id} to ${peer.name}:`, err.message);
            }
          }
        }
      }
      
      callback(null);
    } catch (err) {
        console.error('Error connecting transport:', err.message);
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
        appData: request.appData
      });
      
      peer.producers.set(producer.id, producer);
      console.log(`Producer created for ${peer.name} [${request.kind}]: ${producer.id}`);
      
      for (const otherPeer of room.peers.values()) {
        if (otherPeer.id === peer.id || !otherPeer.recvTransportConnected) continue;
        try {
          otherPeer.socket.emit('notification', {
            notification: true, // Added
            target: 'peer',
            method: 'newproducer',
            data: {
              id: producer.id,
              kind: producer.kind,
              rtpParameters: producer.rtpParameters,
              appData: producer.appData
            }
          });
          console.log(`Sent newproducer ${producer.id} to ${otherPeer.name}`);
        } catch (err) {
          console.error(`Error notifying producer to ${otherPeer.name}:`, err);
        }
      }
      
      callback(null, { id: producer.id });
    } catch(err) {
        console.error('Error creating producer:', err);
        callback(err.toString());
    }
  });

  socket.on('createConsumer', async (request, callback) => {
    try {
      const transport = peer.transports.get(request.transportId);
      if (!transport) throw new Error(`Transport with id "${request.transportId}" not found`);

      const producer = room.router.producers.get(request.producerId);
      if (!producer) throw new Error(`Producer with id "${request.producerId}" not found`);

      const consumer = await transport.consume({
        producerId: request.producerId,
        rtpCapabilities: peer.rtpCapabilities,
        paused: true
      });

      peer.consumers.set(consumer.id, consumer);

      console.log(`Consumer created for ${peer.name} [${request.kind}]: ${consumer.id} from producer ${request.producerId}`);

      callback(null, {
        id: consumer.id,
        producerId: request.producerId,
        kind: consumer.kind,
        rtpParameters: consumer.rtpParameters
      });
    } catch (err) {
        console.error('Error creating consumer:', err);
        callback(err.toString());
    }
  });

  socket.on('resumeConsumer', async (request, callback) => {
    try {
      const consumer = peer.consumers.get(request.consumerId);
      if (!consumer) throw new Error(`Consumer with id "${request.consumerId}" not found`);

      await consumer.resume();
      console.log(`Consumer resumed for ${peer.name}: ${request.consumerId}`);
      callback(null);
    } catch (err) {
        console.error('Error resuming consumer:', err);
        callback(err.toString());
    }
  });

  socket.on('disconnect', () => {
    console.log(`Client disconnected [socketId:${socket.id}]`);
    if (peer && room) {
        for (const otherPeer of room.peers.values()) {
            if (otherPeer.id !== peer.id) {
                otherPeer.socket.emit('notification', {
                    notification: true, // Added
                    target: 'room',
                    method: 'peerclosed',
                    data: { peerName: peer.name }
                });
            }
        }
        for (const producer of peer.producers.values()) producer.close();
        for (const consumer of peer.consumers.values()) consumer.close();
        for (const transport of peer.transports.values()) transport.close();
        room.peers.delete(peer.id);
    }
  });
});

// Start the server
server.listen(SFU_CONFIG.listenPort, () => {
  console.log(`SFU signaling server running on port ${SFU_CONFIG.listenPort}`);
  createWorker();
});
