const express = require("express");
const http = require("http");
const socketIo = require("socket.io");
const MediaServer = require("medooze-media-server");

const app = express();
const server = http.createServer(app);

const io = socketIo(server, {
    path: "/sfu-socket/socket.io",
    cors: { origin: "*", methods: ["GET", "POST"] },
});

const SFU_CONFIG = {
    ip: "0.0.0.0",
    announcedIp: "174.138.18.220", // replace with your droplet's public IP
    listenPort: process.env.PORT || 8080,
    rtcMinPort: 40000,
    rtcMaxPort: 49999,
};

// Initialize Medooze
try {
    MediaServer.enableLog(false);
    MediaServer.enableDebug(false);
    console.log("✅ Medooze Media Server initialized successfully");
} catch (err) {
    console.error("❌ Failed to initialize Medooze:", err.message);
    process.exit(1);
}

const rooms = new Map(); // forumId -> { endpoint, transports, peers }

function getOrCreateRoom(forumId) {
    if (!rooms.has(forumId)) {
        const endpoint = MediaServer.createEndpoint(SFU_CONFIG.ip);
        rooms.set(forumId, {
            endpoint,
            transports: new Map(),
            peers: new Map(),
        });
        console.log(`📡 Room created for forum ${forumId}`);
    }
    return rooms.get(forumId);
}

io.on("connection", (socket) => {
    console.log(`👤 Client connected [${socket.id}]`);

    socket.on("queryRoom", ({ appData }, callback) => {
        try {
            const { forumId } = appData || {};
            if (!forumId) throw new Error("forumId is required");

            getOrCreateRoom(forumId);

            callback(null, {
                rtpCapabilities: MediaServer.getDefaultCapabilities(),
            });
        } catch (err) {
            console.error("Error in queryRoom:", err.message);
            callback(err.message, null);
        }
    });

    socket.on("join", ({ peerName, appData }, callback) => {
        try {
            const { forumId } = appData || {};
            if (!forumId) throw new Error("forumId is required");

            const room = getOrCreateRoom(forumId);
            socket.appData = { forumId, peerName };
            room.peers.set(socket.id, { socket, transports: [], streams: [] });

            // Notify existing peers
            for (const peer of room.peers.values()) {
                if (peer.socket.id !== socket.id) {
                    peer.socket.emit("notification", {
                        method: "newpeer",
                        data: { peerName },
                    });
                }
            }

            console.log(`👥 ${peerName} joined forum ${forumId}`);
            callback(null, { peers: Array.from(room.peers.keys()) });
        } catch (err) {
            console.error("Error in join:", err.message);
            callback(err.message, null);
        }
    });

    socket.on("createTransport", ({ direction }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = getOrCreateRoom(forumId);

            const transport = room.endpoint.createTransport({
                listenIp: { ip: SFU_CONFIG.ip, announcedIp: SFU_CONFIG.announcedIp },
                portMin: SFU_CONFIG.rtcMinPort,
                portMax: SFU_CONFIG.rtcMaxPort,
                udp: true,
                tcp: true,
                preferUdp: true,
            });

            room.transports.set(transport.id, transport);
            room.peers.get(socket.id).transports.push(transport);

            callback(null, {
                id: transport.id,
                ice: transport.getLocalCandidates(),
                dtls: transport.getLocalDtlsParameters(),
            });
        } catch (err) {
            console.error("Error in createTransport:", err.message);
            callback(err.message, null);
        }
    });

    socket.on("connectTransport", ({ id, dtls }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = getOrCreateRoom(forumId);
            const transport = room.transports.get(id);
            if (!transport) throw new Error("Transport not found");

            transport.setRemoteDtlsParameters(dtls);
            callback(null);
        } catch (err) {
            console.error("Error in connectTransport:", err.message);
            callback(err.message);
        }
    });

    socket.on("createProducer", ({ transportId, rtpParameters }, callback) => {
        try {
            const { forumId } = socket.appData || {};
            const room = getOrCreateRoom(forumId);
            const transport = room.transports.get(transportId);
            if (!transport) throw new Error("Transport not found");

            const incomingStream = transport.createIncomingStream(rtpParameters);
            room.peers.get(socket.id).streams.push(incomingStream);

            // Forward this stream to other peers
            for (const [peerId, peer] of room.peers.entries()) {
                if (peerId !== socket.id) {
                    for (const t of peer.transports) {
                        const outgoing = t.createOutgoingStream({
                            rtp: MediaServer.getDefaultCapabilities(),
                        });
                        outgoing.attachTo(incomingStream);
                        peer.socket.emit("notification", {
                            method: "newstream",
                            data: { id: outgoing.getId() },
                        });
                    }
                }
            }

            console.log(`🎤 Producer created for ${socket.appData.peerName}`);
            callback(null, { id: incomingStream.getId() });
        } catch (err) {
            console.error("Error in createProducer:", err.message);
            callback(err.message, null);
        }
    });

    socket.on("disconnect", () => {
        console.log(`❌ Client disconnected [${socket.id}]`);
        const { forumId } = socket.appData || {};
        const room = rooms.get(forumId);
        if (!room) return;

        const peer = room.peers.get(socket.id);
        if (peer) {
            peer.transports.forEach((t) => t.close());
            peer.streams.forEach((s) => s.stop());
            room.peers.delete(socket.id);

            // Notify remaining peers
            for (const otherPeer of room.peers.values()) {
                otherPeer.socket.emit("notification", {
                    method: "peerclosed",
                    data: { peerName: socket.appData.peerName },
                });
            }
        }
    });
});

server.listen(SFU_CONFIG.listenPort, () => {
    console.log(`🚀 SFU server running on port ${SFU_CONFIG.listenPort}`);
});
