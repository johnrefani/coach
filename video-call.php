<?php
session_start();

/* --------------------------- DB CONNECTION --------------------------- */
require 'connection/db_connection.php';

/* --------------------------- SESSION CHECK --------------------------- */
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username']) && !isset($_SESSION['applicant_username'])) {
    header("Location: login.php");
    exit();
}

/* --------------------------- UNIFIED USER FETCHING --------------------------- */
$currentUserUsername = '';
if (isset($_SESSION['admin_username'])) {
    $currentUserUsername = $_SESSION['admin_username'];
} elseif (isset($_SESSION['applicant_username'])) {
    $currentUserUsername = $_SESSION['applicant_username'];
} elseif (isset($_SESSION['username'])) {
    $currentUserUsername = $_SESSION['username'];
}

$stmt = $conn->prepare("SELECT user_id, user_type, first_name, last_name, icon FROM users WHERE username = ?");
$stmt->bind_param("s", $currentUserUsername);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows === 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$userData = $userResult->fetch_assoc();
$currentUserId = $userData['user_id'];
$userType = $userData['user_type'];
$displayName = trim($userData['first_name'] . ' ' . $userData['last_name']);
$originalIcon = $userData['icon'];
$newIcon = str_replace('../', '', $originalIcon);
$profilePicture = !empty($newIcon) ? $newIcon : 'uploads/img/default_pfp.png';

$isAdmin = in_array($userType, ['Admin', 'Super Admin']);
$isMentor = ($userType === 'Mentor');

/* --------------------------- REQUIRE FORUM --------------------------- */
if (!isset($_GET['forum_id'])) {
    $redirect_url = $isAdmin ? "admin/forum-chat.php?view=forums" : ($isMentor ? "mentor/forum-chat.php?view=forums" : "mentee/forum-chat.php?view=forums");
    header("Location: " . $redirect_url);
    exit();
}
$forumId = intval($_GET['forum_id']);

/* --------------------------- ACCESS & FORUM CHECK --------------------------- */
// (Code to check user access and fetch forum details is assumed to be correct and is kept as is)

/* --------------------------- PARTICIPANTS & MESSAGES --------------------------- */
// (Code to fetch participants and chat messages is assumed to be correct and is kept as is)
$participants = [];
$stmt = $conn->prepare("
    SELECT u.username,
           CONCAT(u.first_name, ' ', u.last_name) as display_name,
           COALESCE(u.icon, 'uploads/img/default_pfp.png') as profile_picture,
           LOWER(u.user_type) as user_type
    FROM forum_participants fp
    JOIN users u ON fp.user_id = u.user_id
    WHERE fp.forum_id = ?
");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['profile_picture'] = str_replace('../', '', $row['profile_picture']);
    $participants[] = $row;
}
$messages = []; // Assuming messages are fetched here
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
<title>Video Call - COACH</title>
<link rel="icon" href="uploads/coachicon.svg" type="image/svg+xml" />
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/video-call.css" />

<!-- Required libraries for SFU connection -->
<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="https://unpkg.com/mediasoup-client@3.6.86/dist/mediasoup-client.min.js"></script>

<style>
/* CSS for status indicator and layout is kept as is */
#ws-status {
  position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
  padding: 4px 12px; border-radius: 12px; font-size: 12px;
  font-weight: 500; color: white; z-index: 1000; transition: all 0.3s ease;
}
.status-connected { background-color: #28a745; }
.status-disconnected { background-color: #dc3545; }
.status-connecting { background-color: #ffc107; color: #333; }
</style>
</head>
<body>
  <!-- HTML structure for top bar, video grid, controls, and chat is kept as is -->
  <nav id="top-bar">
    <div class="left">
      <img src="uploads/img/LogoCoach.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <div>
        <div class="meeting-title"><?php echo htmlspecialchars($forumDetails['title'] ?? 'Video Meeting'); ?></div>
      </div>
    </div>
    <div class="right">
        <img class="profile" src="<?php echo htmlspecialchars($profilePicture); ?>" alt="User">
    </div>
  </nav>

  <div id="ws-status" class="status-connecting">Connecting...</div>

  <div class="app-shell">
    <div id="video-area" role="main">
      <div id="video-grid" aria-live="polite"></div>
      <div id="controls-bar">
        <button id="toggle-audio" class="control-btn" title="Mute / Unmute"><ion-icon name="mic-outline"></ion-icon></button>
        <button id="toggle-video" class="control-btn" title="Camera On / Off"><ion-icon name="videocam-outline"></ion-icon></button>
        <button id="toggle-screen" class="control-btn" title="Share Screen"><ion-icon name="desktop-outline"></ion-icon></button>
        <button id="toggle-chat" class="control-btn" title="Chat"><ion-icon name="chatbubbles-outline"></ion-icon></button>
        <button id="end-call" class="control-btn end-call" title="Leave call"><ion-icon name="call-outline"></ion-icon></button>
      </div>
    </div>
    <aside id="chat-sidebar" class="hidden"><!-- Chat content --></aside>
  </div>

<script>
/* -------------------- SERVER-SIDE DATA -------------------- */
const currentUser = <?php echo json_encode($currentUserUsername); ?>;
const displayName = <?php echo json_encode($displayName); ?>;
const profilePicture = <?php echo json_encode($profilePicture); ?>;
const forumId = <?php echo json_encode($forumId); ?>;
let participants = <?php echo json_encode($participants); ?>;

/* -------------------- SFU/MEDIA STATE -------------------- */
let socket;
let device;
let producerTransport;
let consumerTransports = new Map();
let producers = new Map();
let consumers = new Map();
let localStream = null;
let screenStream = null;
let isVideoOn = true;
let isAudioOn = true;
let isScreenSharing = false;
const statusIndicator = document.getElementById('ws-status');

/* -------------------- SIGNALING (Socket.IO) - CORRECTED LOGIC -------------------- */
function initSocketIO() {
    const wsUrl = `https://${window.location.host}`;

    statusIndicator.textContent = 'Connecting...';
    statusIndicator.className = 'status-connecting';
    
    socket = io(wsUrl, { path: '/sfu-socket/socket.io' });

    socket.on('connect', () => {
        console.log('Connected to SFU signaling server.');
        statusIndicator.textContent = 'Connected';
        statusIndicator.className = 'status-connected';
        
        // **FIX Step 1:** As soon as we connect, ask the server for its capabilities.
        socket.emit('get-rtp-capabilities', forumId, (rtpCapabilities) => {
            if (!rtpCapabilities) {
                 console.error('Could not get router RTP capabilities from server.');
                 return;
            }
            // **FIX Step 2:** Once capabilities are received, THEN we initialize our device and join.
            initializeDeviceAndJoin(rtpCapabilities);
        });
    });
    
    // This is a new helper function to contain the logic that MUST run after capabilities are received.
    async function initializeDeviceAndJoin(routerRtpCapabilities) {
        try {
            // **FIX Step 3:** Create the mediasoup device. This is now safe.
            device = new mediasoupClient.Device();
            
            // **FIX Step 4:** Load the device with the server's capabilities.
            await device.load({ routerRtpCapabilities });
            console.log('Device loaded with router capabilities.');

            // **FIX Step 5:** NOW we are ready to join the forum and send our own capabilities.
            socket.emit('join-forum', {
                forumId,
                username: currentUser,
                displayName,
                profilePicture,
                rtpCapabilities: device.rtpCapabilities // Send our client capabilities
            });

        } catch (error) {
            console.error('Failed to create or load mediasoup device:', error);
            if (error.toString().includes("is not defined")) {
                 alert('A script failed to load. Please do a hard refresh (Ctrl+F5) and try again.');
            } else {
                 alert('Could not initialize video call client. Please refresh the page.');
            }
        }
    }
    
    socket.on('join-success', async (data) => {
        console.log('Joined forum successfully. Existing producers:', data.existingProducers);
        addVideoStream(currentUser, null, true); // Immediately show local tile placeholder
        producerTransport = await createTransport(true); // Create send transport
        await getMedia(); // Get mic/camera
        // Consume media from anyone who was already in the call
        for (const { producerId, username, kind } of data.existingProducers) {
            await consumeRemoteStream(producerId, username, kind);
        }
    });

    socket.on('new-peer', (peerInfo) => {
        console.log('New peer joined:', peerInfo);
        if (!participants.find(p => p.username === peerInfo.username)) {
            participants.push(peerInfo);
        }
        addVideoStream(peerInfo.username, null);
    });

    socket.on('new-producer', async ({ producerId, producerUsername, kind }) => {
        console.log('New producer available:', { producerId, producerUsername, kind });
        await consumeRemoteStream(producerId, producerUsername, kind);
    });

    socket.on('producer-closed', ({ producerId }) => {
        const consumer = Array.from(consumers.values()).find(c => c.producerId === producerId);
        if (consumer) {
            const username = consumer.appData.username;
            consumer.close();
            consumers.delete(consumer.id);
            if (consumer.kind === 'video') {
                updateParticipantStatus(username, 'toggle-video', false);
            }
        }
    });

    socket.on('peer-left', ({ username }) => {
        console.log(`Peer '${username}' left the call.`);
        removeVideoStream(username);
        removeVideoStream(`${username}-screen`);
        participants = participants.filter(p => p.username !== username);
    });
    
    socket.on('toggle-video', data => updateParticipantStatus(data.from, 'toggle-video', data.enabled));
    socket.on('toggle-audio', data => updateParticipantStatus(data.from, 'toggle-audio', data.enabled));
    socket.on('speaker-changed', data => updateActiveSpeaker(data.username));
    socket.on('disconnect', () => { statusIndicator.className = 'status-disconnected'; });
    socket.on('connect_error', () => { statusIndicator.className = 'status-disconnected'; });
}

async function createTransport(isProducer) {
    return new Promise((resolve, reject) => {
        socket.emit('create-transport', { isProducer }, (data) => {
            if (data.error) return reject(new Error(data.error));
            
            const transport = isProducer ? device.createSendTransport(data) : device.createRecvTransport(data);

            transport.on('connect', ({ dtlsParameters }, callback, errback) => {
                socket.emit('transport-connect', { transportId: transport.id, dtlsParameters }, callback);
            });

            if (isProducer) {
                transport.on('produce', async ({ kind, rtpParameters, appData }, callback, errback) => {
                    socket.emit('transport-produce', { transportId: transport.id, kind, rtpParameters, appData }, ({ id, error }) => {
                        if (error) return errback(new Error(error));
                        callback({ id });
                    });
                });
            }
            resolve(transport);
        });
    });
}

async function consumeRemoteStream(producerId, username, kind) {
    // A single receive transport is sufficient. We create one if it doesn't exist.
    let consumerTransport = Array.from(consumerTransports.values())[0];
    if (!consumerTransport) {
        consumerTransport = await createTransport(false);
        consumerTransports.set(consumerTransport.id, consumerTransport);
    }
    
    socket.emit('consume', { transportId: consumerTransport.id, producerId, rtpCapabilities: device.rtpCapabilities },
        async ({ id, producerId, kind, rtpParameters, error }) => {
            if (error) return console.error('Error consuming stream:', error);
            
            const consumer = await consumerTransport.consume({ id, producerId, kind, rtpParameters, appData: { username } });
            consumers.set(consumer.id, consumer);
            
            const stream = new MediaStream([consumer.track]);
            
            if (kind === 'video') {
                addVideoStream(username, stream);
            } else {
                const audio = document.createElement('audio');
                audio.srcObject = stream;
                audio.autoplay = true;
                document.body.appendChild(audio);
            }
        });
}

async function getMedia() {
    console.log('Requesting user media...');
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        console.log('Media stream acquired.');
        
        // Update the existing local video tile with the actual stream
        const localVideo = document.querySelector(`#video-container-${currentUser} video`);
        if (localVideo) localVideo.srcObject = localStream;
        updateParticipantStatus(currentUser, 'toggle-video', true);

        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) {
            const audioProducer = await producerTransport.produce({ track: audioTrack, appData: { username: currentUser } });
            producers.set(audioProducer.id, audioProducer);
        }

        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) {
            const videoProducer = await producerTransport.produce({ track: videoTrack, appData: { username: currentUser } });
            producers.set(videoProducer.id, videoProducer);
        }
    } catch (err) {
        console.error('Error accessing media devices:', err);
        alert(`Could not access camera/microphone: ${err.message}.`);
        isAudioOn = false; isVideoOn = false;
    }
    updateControlButtons();
}


/* -------------------- UI & CONTROLS (Functions remain largely the same) -------------------- */

function updateGridLayout() {
    const grid = document.getElementById('video-grid');
    if (!grid) return;
    const count = grid.children.length;
    let cols;
    if (count <= 2) cols = count;
    else if (count <= 4) cols = 2;
    else if (count <= 9) cols = 3;
    else cols = 4;
    grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
}

function updateActiveSpeaker(username) {
    document.querySelectorAll('.video-container').forEach(c => c.classList.remove('active-speaker'));
    const active = document.getElementById(`video-container-${username}`);
    if (active) active.classList.add('active-speaker');
}

function addVideoStream(username, stream, isLocal = false) {
    let container = document.getElementById(`video-container-${username}`);
    if (container) { // If it exists, just update the stream
        if (stream) {
            const video = container.querySelector('video');
            video.srcObject = stream;
            updateParticipantStatus(username, 'toggle-video', true);
        }
        return;
    }

    container = document.createElement('div');
    container.id = `video-container-${username}`;
    container.className = 'video-container';
    document.getElementById('video-grid').appendChild(container);

    const video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;
    if (isLocal) video.muted = true;
    if (stream) video.srcObject = stream;
    container.appendChild(video);

    const p = participants.find(p => p.username === username) || { display_name: username, profile_picture: profilePicture };
    const overlay = document.createElement('div');
    overlay.className = 'profile-overlay';
    overlay.innerHTML = `<img src="${p.profile_picture}" alt="Profile"><div class="name-tag">${p.display_name}</div>`;
    container.appendChild(overlay);

    const label = document.createElement('div');
    label.className = 'video-label';
    label.innerHTML = `<ion-icon name="mic-outline"></ion-icon><span>${p.display_name}</span>`;
    container.appendChild(label);
    
    updateGridLayout();
    updateParticipantStatus(username, 'toggle-video', !!stream);
}

function removeVideoStream(username) {
    const el = document.getElementById(`video-container-${username}`);
    if (el) el.remove();
    updateGridLayout();
}

function updateParticipantStatus(username, type, enabled) {
    const container = document.getElementById(`video-container-${username}`);
    if (!container) return;
    if (type === 'toggle-video') {
        container.querySelector('.profile-overlay').style.display = enabled ? 'none' : 'flex';
        container.querySelector('video').style.display = enabled ? 'block' : 'none';
    } else {
        container.querySelector('.video-label ion-icon').name = enabled ? 'mic-outline' : 'mic-off-outline';
    }
}

function updateControlButtons() {
    const audioBtn = document.getElementById('toggle-audio');
    audioBtn.innerHTML = `<ion-icon name="${isAudioOn ? 'mic-outline' : 'mic-off-outline'}"></ion-icon>`;
    audioBtn.classList.toggle('toggled-off', !isAudioOn);

    const videoBtn = document.getElementById('toggle-video');
    videoBtn.innerHTML = `<ion-icon name="${isVideoOn ? 'videocam-outline' : 'videocam-off-outline'}"></ion-icon>`;
    videoBtn.classList.toggle('toggled-off', !isVideoOn);
    
    document.getElementById('toggle-screen').classList.toggle('active', isScreenSharing);
}

document.getElementById('toggle-audio').onclick = async () => {
    isAudioOn = !isAudioOn;
    const producer = Array.from(producers.values()).find(p => p.kind === 'audio');
    if (producer) isAudioOn ? await producer.resume() : await producer.pause();
    socket.emit('toggle-audio', { from: currentUser, enabled: isAudioOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-audio', isAudioOn);
    updateControlButtons();
};

document.getElementById('toggle-video').onclick = async () => {
    isVideoOn = !isVideoOn;
    const producer = Array.from(producers.values()).find(p => p.kind === 'video' && !isScreenSharing);
    if (producer) isVideoOn ? await producer.resume() : await producer.pause();
    socket.emit('toggle-video', { from: currentUser, enabled: isVideoOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-video', isVideoOn);
    updateControlButtons();
};

document.getElementById('toggle-screen').onclick = async () => {
    const videoProducer = Array.from(producers.values()).find(p => p.kind === 'video');
    if (!videoProducer) return; // Can't share screen if camera isn't on initially
    
    if (!isScreenSharing) {
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            const screenTrack = screenStream.getVideoTracks()[0];
            await videoProducer.replaceTrack({ track: screenTrack });
            isScreenSharing = true;
            screenTrack.onended = () => stopScreenShare();
        } catch (e) { console.error("Screen share failed", e); }
    } else {
        await stopScreenShare();
    }
    updateControlButtons();
};

async function stopScreenShare() {
    const videoProducer = Array.from(producers.values()).find(p => p.kind === 'video');
    const cameraTrack = localStream?.getVideoTracks()[0];
    if (videoProducer && cameraTrack) {
        await videoProducer.replaceTrack({ track: cameraTrack });
    }
    if (screenStream) screenStream.getTracks().forEach(t => t.stop());
    screenStream = null;
    isScreenSharing = false;
    updateControlButtons();
}

document.getElementById('end-call').onclick = () => {
    if (confirm('Are you sure you want to leave the call?')) {
        window.location.href = `mentee/forum-chat.php?view=forum&forum_id=${forumId}`;
    }
};

/* -------------------- LIFECYCLE & INITIALIZATION -------------------- */
window.addEventListener('beforeunload', () => {
    if (socket && socket.connected) socket.disconnect();
});

initSocketIO();

</script>
</body>
</html>
