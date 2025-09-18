<?php
session_start();

/* --------------------------- DB CONNECTION --------------------------- */
require 'connection/db_connection.php';

/* --------------------------- SESSION CHECK --------------------------- */
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username']) && !isset($_SESSION['applicant_username'])) {
    header("Location: login.php");
    exit();
}

/* --------------------------- UNIFIED USER FETCHING (NEW) --------------------------- */
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
    $redirect_url = "mentee/forum-chat.php?view=forums";
    if ($isMentor) {
        $redirect_url = "mentor/forum-chat.php?view=forums";
    } elseif ($isAdmin) {
        $redirect_url = "admin/forum-chat.php?view=forums";
    }
    header("Location: " . $redirect_url);
    exit();
}
$forumId = intval($_GET['forum_id']);

/* --------------------------- ACCESS CHECK (UPDATED) --------------------------- */
if (!$isAdmin && !$isMentor) {
    $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $forumId, $currentUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        header("Location: forum-chat.php?view=forums");
        exit();
    }
}

/* --------------------------- FETCH FORUM --------------------------- */
$stmt = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $redirect_url = "mentee/forum-chat.php?view=forums";
    if ($isMentor) {
        $redirect_url = "mentor/forum-chat.php?view=forums";
    } elseif ($isAdmin) {
        $redirect_url = "admin/forum-chat.php?view=forums";
    }
    header("Location: " . $redirect_url);
    exit();
}
$forumDetails = $res->fetch_assoc();

/* --------------------------- SESSION STATUS (UPDATED) --------------------------- */
$today = date('Y-m-d');
$currentTime = date('H:i');
list($startTime, $endTimeStr) = explode(' - ', $forumDetails['time_slot']);
$endTime = date('H:i', strtotime($endTimeStr));
$isSessionOver = ($today > $forumDetails['session_date']) ||
    ($today == $forumDetails['session_date'] && $currentTime > $endTime);

$hasLeftSession = false;
$checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND user_id = ?");
$checkLeft->bind_param("ii", $forumId, $currentUserId);
$checkLeft->execute();
$leftResult = $checkLeft->get_result();
if ($leftResult->num_rows > 0) {
    $participantStatus = $leftResult->fetch_assoc()['status'];
    $hasLeftSession = in_array($participantStatus, ['left', 'review']);
}

if ($isSessionOver || $hasLeftSession) {
    $redirect_url = "mentee/forum-chat.php";
    if ($isMentor) {
        $redirect_url = "mentor/forum-chat.php";
    } elseif ($isAdmin) {
        $redirect_url = "admin/forum-chat.php";
    }
    header("Location: " . $redirect_url . "?view=forum&forum_id=" . $forumId . "&review=true");
    exit();
}

/* --------------------------- HANDLE CHAT POST (UPDATED) --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'video_chat') {
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, display_name, message, is_admin, is_mentor, chat_type, forum_id) VALUES (?, ?, ?, ?, ?, 'forum', ?)");
        $isAdminBit = $isAdmin ? 1 : 0;
        $isMentorBit = $isMentor ? 1 : 0;
        $stmt->bind_param("issiii", $currentUserId, $displayName, $message, $isAdminBit, $isMentorBit, $forumId);
        $stmt->execute();
    }
    exit();
}

/* --------------------------- PARTICIPANTS (UPDATED & SIMPLIFIED) --------------------------- */
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
/* --------------------------- MESSAGES --------------------------- */
$messages = [];
$stmt = $conn->prepare("SELECT * FROM chat_messages WHERE chat_type = 'forum' AND forum_id = ? ORDER BY timestamp ASC LIMIT 200");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $messages[] = $row;
}
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

<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mediasoup-client@2.0.4/dist/mediasoup-client.min.js"></script>

<style>
#ws-status {
  position: fixed;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  color: white;
  z-index: 1000;
  transition: all 0.3s ease;
}
.status-connected { background-color: #28a745; }
.status-disconnected { background-color: #dc3545; }
.status-connecting { background-color: #ffc107; color: #333; }

.tile-fullscreen-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 15;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease-in-out;
    width: 36px;
    height: 36px;
    font-size: 18px;
}

.video-container:hover .tile-fullscreen-btn {
    opacity: 1;
    pointer-events: auto;
}

@media (max-width: 768px) {
  #ws-status {
    top: calc(16px + var(--safe-area-top));
    left: 50%;
    right: calc(16px + var(--safe-area-right));
    transform: none;
    width: 12px;
    height: 12px;
    padding: 0;
    border-radius: 50%;
    font-size: 0;
    overflow: hidden;
  }
}
</style>
</head>
<body>
  <nav id="top-bar">
    <div class="left">
      <img src="uploads/img/LogoCoach.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <div>
        <div class="meeting-title"><?php echo htmlspecialchars($forumDetails['title'] ?? 'Video Meeting'); ?></div>
        <div style="font-size:12px;color:var(--muted)"><?php date_default_timezone_set('Asia/Manila'); echo htmlspecialchars($forumDetails['session_date'] ?? ''); ?> &middot; <?php echo htmlspecialchars($forumDetails['time_slot'] ?? ''); ?></div>
      </div>
    </div>
    <div class="right">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="font-size:13px;color:var(--muted)"><?php echo date('g:i A'); ?></div>
        <img class="profile" src="<?php echo htmlspecialchars($profilePicture); ?>" alt="User">
      </div>
    </div>
  </nav>

  <div id="ws-status" class="status-connecting">Connecting...</div>

  <div class="app-shell">
    <div id="video-area" role="main">
      <div id="video-grid" aria-live="polite"></div>

      <div id="controls-bar" aria-hidden="false">
        <button id="toggle-audio" class="control-btn" title="Mute / Unmute"><ion-icon name="mic-outline"></ion-icon></button>
        <button id="toggle-video" class="control-btn" title="Camera On / Off"><ion-icon name="videocam-outline"></ion-icon></button>
        <button id="toggle-screen" class="control-btn" title="Share Screen"><ion-icon name="desktop-outline"></ion-icon></button>
        <button id="toggle-chat" class="control-btn" title="Chat"><ion-icon name="chatbubbles-outline"></ion-icon></button>
        <button id="end-call" class="control-btn end-call" title="Leave call"><ion-icon name="call-outline"></ion-icon></button>
      </div>
    </div>

    <aside id="chat-sidebar" class="hidden">
      <div id="chat-header">
        <span class="chat-title">In-call messages</span>
        <button id="close-chat-btn" title="Close chat"><ion-icon name="close-outline"></ion-icon></button>
      </div>
      <div id="chat-messages">
        <?php foreach ($messages as $msg): ?>
          <div class="message">
            <div class="sender"><?php echo htmlspecialchars($msg['display_name']); ?></div>
            <div class="content"><?php echo htmlspecialchars($msg['message']); ?></div>
            <div class="timestamp"><?php echo date('M d, g:i a', strtotime($msg['timestamp'])); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="chat-input">
        <input type="text" id="chat-message" placeholder="Send a message..." />
        <button id="send-chat-btn"><ion-icon name="send-outline"></ion-icon></button>
      </div>
    </aside>
  </div>

<script>
/* -------------------- SERVER-SIDE DATA -------------------- */
const currentUser = <?php echo json_encode($currentUserUsername); ?>;
const displayName = <?php echo json_encode($displayName); ?>;
const profilePicture = <?php echo json_encode($profilePicture); ?>;
const forumId = <?php echo json_encode($forumId); ?>;
let participants = <?php echo json_encode($participants); ?>;

/* -------------------- SFU STATE -------------------- */
let socket;
let room;
let sendTransport;
let recvTransport;
let audioProducer;
let videoProducer;
let screenProducer;
let localStream;
let isVideoOn = true;
let isAudioOn = true;
let isScreenSharing = false;
const statusIndicator = document.getElementById('ws-status');

// Promise to wait for sendTransport to be ready
let sendTransportReady = null;

function setupTransportEvents(transport, direction) {
    transport.on('connect', ({ dtlsParameters }, callback, errback) => {
        console.log(`Transport ${direction} connect event fired`);
        socket.emit('connectTransport', {
            id: transport.id,
            direction,
            dtlsParameters
        }, (err) => {
            if (err) {
                console.error(`Transport ${direction} connect failed:`, err);
                errback(err);
            } else {
                console.log(`Transport ${direction} connected`);
                callback();
            }
        });
    });

    transport.on('newicecandidate', ({ candidate }) => {
        console.log(`New ICE candidate for ${direction} transport:`, candidate);
        socket.emit('newTransportIceCandidate', {
            transportId: transport.id,
            direction,
            candidate
        });
    });

    transport.on('connectionstatechange', (state) => {
        console.log(`Transport ${direction} state: ${state}`);
        if (state === 'failed') {
            console.error(`Transport ${direction} failed`);
        }
    });
}

async function waitForTransport(direction) {
    return new Promise((resolve) => {
        const checkTransport = () => {
            const transport = direction === 'send' ? sendTransport : recvTransport;
            if (transport && !transport.closed) {
                console.log(`${direction} transport ready:`, transport.id);
                resolve(transport);
            } else {
                setTimeout(checkTransport, 100);
            }
        };
        checkTransport();
    });
}

async function initSocketAndRoom() {
    const wsUrl = `https://${window.location.host}`;
    socket = io(wsUrl, { path: '/sfu-socket/socket.io' });
    
    room = new mediasoupClient.Room();

    statusIndicator.textContent = 'Connecting...';
    statusIndicator.className = 'status-connecting';

    socket.on('connect', async () => {
        statusIndicator.textContent = 'Connected';
        statusIndicator.className = 'status-connected';
        try {
            console.log('Socket connected, joining room...');
            const peers = await room.join(currentUser, { displayName, profilePicture, forumId });
            console.log('Successfully joined the room! Peers:', peers);
            
            recvTransport = room.createTransport('recv');
            setupTransportEvents(recvTransport, 'recv');
            console.log('Recv transport created:', recvTransport.id);
            
            sendTransportReady = new Promise((resolve) => {
                sendTransport = room.createTransport('send');
                setupTransportEvents(sendTransport, 'send');
                console.log('Send transport created:', sendTransport.id);
                resolve();
            });
            
            for (const peer of peers) {
                console.log('Initial peer:', peer.name);
                handlePeer(peer);
            }
            await sendTransportReady;
            await getMedia();
        } catch (err) {
            console.error('Error joining room:', err);
            alert(`Could not join room: ${err.message}`);
        }
    });

    socket.on('disconnect', () => {
        statusIndicator.textContent = 'Disconnected';
        statusIndicator.className = 'status-disconnected';
    });

    socket.on('connect_error', (err) => {
        console.error('Socket connection error:', err);
        statusIndicator.textContent = 'Error';
        statusIndicator.className = 'status-disconnected';
    });

    socket.on('notification', (notification) => {
        try {
            if (!notification || !notification.method || !notification.target) {
                throw new Error('Invalid notification format');
            }
            console.log('Received notification:', notification.method, notification);
            room.receiveNotification(notification);
        } catch (err) {
            console.error('Error processing notification:', err, notification);
        }
    });
    
    room.on('request', (request, callback, errback) => {
        if (request.method === 'queryRoom') {
            request.appData = { forumId };
        }
        socket.emit(request.method, request, (err, data) => {
            if (err) {
                console.error(`Request ${request.method} failed:`, err);
                errback(err);
            } else {
                callback(data);
            }
        });
    });

    room.on('notify', (notification) => {
        socket.emit(notification.method, notification);
    });

    room.on('newpeer', (peer) => {
        console.log(`New peer joined: ${peer.name}`, peer);
        handlePeer(peer);
    });

    room.on('peerclosed', (peerName) => {
        console.log(`Peer closed: ${peerName}`);
        removeVideoStream(peerName);
        participants = participants.filter(p => p.username !== peerName);
        updateGridLayout();
    });

    room.on('close', (origin, appData) => {
        console.log(`Room closed [origin:${origin}]`, appData);
        if (origin === 'remote') {
            alert('The meeting has been closed by the server.');
            cleanup();
        }
    });
}

function handlePeer(peer) {
    console.log(`Handling peer: ${peer.name}, appData:`, peer.appData);
    if (!participants.find(p => p.username === peer.name)) {
        participants.push({
            username: peer.name,
            displayName: peer.appData.displayName || peer.name,
            profilePicture: peer.appData.profilePicture || 'Uploads/img/default_pfp.png'
        });
    }

    addVideoStream(peer.name, null); // Add empty tile

    peer.on('newproducer', async (data) => {
        console.log(`New producer for ${peer.name}:`, data);
        if (!recvTransport || recvTransport.closed) {
            console.warn('Recv transport not ready, skipping consumer');
            return;
        }
        try {
            const consumer = await recvTransport.createConsumer(data);
            console.log(`Consumer created for ${peer.name} [${data.kind}]:`, consumer.id);
            handleConsumer(consumer, peer.name);
        } catch (err) {
            console.error(`Failed to create consumer for ${peer.name}:`, err);
        }
    });
}

function handleConsumer(consumer, username) {
    const { kind } = consumer;
    const track = consumer.track;
    console.log(`Handling consumer for ${username} [${kind}]:`, consumer.id);
    const stream = new MediaStream();
    stream.addTrack(track);

    if (kind === 'video') {
        addVideoStream(username, stream);
    } else if (kind === 'audio') {
        let audioEl = document.getElementById(`audio-${username}`);
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.id = `audio-${username}`;
            document.body.appendChild(audioEl);
        }
        audioEl.srcObject = stream;
        audioEl.play().catch(e => console.error(`Audio play failed for ${username}:`, e));
    }

    consumer.resume();
}

async function getMedia() {
    try {
        console.log('Getting media...');
        await waitForTransport('send');
        if (!room.canSend('audio') || !room.canSend('video')) {
            console.warn('Cannot send audio or video');
            addVideoStream(currentUser, null, true);
            return;
        }
        
        try {
            localStream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
                video: { width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            console.log('Local stream obtained:', localStream);
        } catch (videoErr) {
            console.warn('Failed to get video+audio stream:', videoErr.name, videoErr.message);
            localStream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
                video: false
            });
            console.log('Audio-only stream obtained:', localStream);
        }
        
        addVideoStream(currentUser, localStream, true);
        
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack && room.canSend('audio')) {
            if (!sendTransport || typeof sendTransport.produce !== 'function') {
                throw new Error('sendTransport is not properly initialized');
            }
            audioProducer = await sendTransport.produce(audioTrack);
            console.log('Audio producer created:', audioProducer.id);
        } else {
            console.warn('No audio track or cannot send audio');
            isAudioOn = false;
        }
        
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack && room.canSend('video')) {
            if (!sendTransport || typeof sendTransport.produce !== 'function') {
                throw new Error('sendTransport is not properly initialized');
            }
            videoProducer = await sendTransport.produce(videoTrack);
            console.log('Video producer created:', videoProducer.id);
        } else {
            console.warn('No video track or cannot send video');
            isVideoOn = false;
        }

        updateControlButtons();
    } catch (err) {
        console.error('Error getting media:', err.name, err.message, err);
        alert(`Could not access your camera or microphone: ${err.name} - ${err.message}. Showing profile tile only.`);
        addVideoStream(currentUser, null, true);
        isAudioOn = false;
        isVideoOn = false;
        updateControlButtons();
    }
}

function updateGridLayout() {
    const grid = document.getElementById('video-grid');
    if (!grid) return;

    const participantCount = grid.children.length;
    const tiles = grid.querySelectorAll('.video-container');

    grid.style.display = 'grid';
    tiles.forEach(tile => {
        tile.style.maxWidth = '';
        tile.style.maxHeight = '';
    });

    if (participantCount === 0) {
        grid.style.gridTemplateColumns = '';
        return;
    }

    if (participantCount === 1) {
        grid.style.display = 'flex';
        grid.style.gridTemplateColumns = '';
        if (tiles[0]) {
            tiles[0].style.maxWidth = 'min(80vw, 142vh)';
            tiles[0].style.maxHeight = '80vh';
        }
        return;
    }

    let columns;
    switch (participantCount) {
        case 2:  columns = 2; break;
        case 3:  columns = 3; break;
        case 4:  columns = 2; break;
        case 5: case 6:  columns = 3; break;
        case 7: case 8:  columns = 4; break;
        case 9:  columns = 3; break;
        case 10: case 11: case 12: columns = 4; break;
        case 13: case 14: case 15: columns = 5; break;
        case 16: columns = 4; break;
        default: columns = 5; break;
    }
    grid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
}

function updateActiveSpeaker(activeUsername) {
    document.querySelectorAll('.video-container').forEach(container => {
        container.classList.remove('active-speaker');
    });
    if (activeUsername) {
        const activeContainer = document.getElementById(`video-container-${activeUsername}`);
        if (activeContainer) {
            activeContainer.classList.add('active-speaker');
        }
    }
}

function addVideoStream(username, stream, isLocal = false) {
    console.log(`Adding/updating video stream for ${username}, stream:`, stream);
    const grid = document.getElementById('video-grid');
    let container = document.getElementById(`video-container-${username}`);

    const isNewContainer = !container;
    if (isNewContainer) {
        container = document.createElement('div');
        container.id = `video-container-${username}`;
        container.className = 'video-container';
        grid.appendChild(container);
        console.log(`Created new container for ${username}`);
    }
    
    const existingVideo = container.querySelector('video');
    if (existingVideo) existingVideo.remove();
    
    const video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;
    if (isLocal) video.muted = true;

    if (stream && stream.getVideoTracks().length > 0) {
        video.srcObject = stream;
        video.onloadedmetadata = () => video.play().catch(e => console.error(`Video play failed for ${username}:`, e));
        console.log(`Attached stream to video for ${username}`);
    }
    container.appendChild(video);

    if (isNewContainer) {
        const participantName = username.replace('-screen', '');
        const participant = participants.find(p => p.username === participantName) || { displayName: participantName, profilePicture: 'Uploads/img/default_pfp.png' };

        const overlay = document.createElement('div');
        overlay.className = 'profile-overlay';
        overlay.innerHTML = `<img src="${participant.profilePicture}" alt="Profile" /><div class="name-tag">${participant.displayName}</div>`;
        container.appendChild(overlay);
        
        const label = document.createElement('div');
        label.className = 'video-label';
        label.innerHTML = `<ion-icon name="mic-outline"></ion-icon><span>${participant.displayName}</span>`;
        container.appendChild(label);

        const fullscreenBtn = document.createElement('button');
        fullscreenBtn.className = 'control-btn tile-fullscreen-btn';
        fullscreenBtn.title = 'View Fullscreen';
        fullscreenBtn.innerHTML = `<ion-icon name="scan-outline"></ion-icon>`;
        container.appendChild(fullscreenBtn);

        fullscreenBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (document.fullscreenElement === container) {
                document.exitFullscreen();
            } else {
                container.requestFullscreen().catch(err => {
                    console.error(`Error attempting to enable full-screen mode for tile: ${err.message}`);
                });
            }
        });
    }
    
    updateGridLayout();
    const hasVideo = stream && stream.getVideoTracks().length > 0 && stream.getVideoTracks()[0].enabled;
    updateParticipantStatus(username, 'toggle-video', hasVideo);
}

function removeVideoStream(username) {
    const el = document.getElementById(`video-container-${username}`);
    if (el) el.remove();
    const audioEl = document.getElementById(`audio-${username}`);
    if (audioEl) audioEl.remove();
    updateGridLayout();
}

function updateParticipantStatus(username, type, enabled) {
    const container = document.getElementById(`video-container-${username}`);
    if (!container) return;

    if (type === 'toggle-video') {
        const overlay = container.querySelector('.profile-overlay');
        const video = container.querySelector('video');
        if (overlay) overlay.style.display = enabled ? 'none' : 'flex';
        if (video) video.style.display = enabled ? 'block' : 'none';
    } else if (type === 'toggle-audio') {
        const micIcon = container.querySelector('.video-label ion-icon');
        if (micIcon) micIcon.setAttribute('name', enabled ? 'mic-outline' : 'mic-off-outline');
    }
}

function updateControlButtons() {
    const audioBtn = document.getElementById('toggle-audio');
    audioBtn.innerHTML = `<ion-icon name="${isAudioOn ? 'mic-outline' : 'mic-off-outline'}"></ion-icon>`;
    audioBtn.classList.toggle('toggled-off', !isAudioOn);

    const videoBtn = document.getElementById('toggle-video');
    videoBtn.innerHTML = `<ion-icon name="${isVideoOn ? 'videocam-outline' : 'videocam-off-outline'}"></ion-icon>`;
    videoBtn.classList.toggle('toggled-off', !isVideoOn);

    const screenBtn = document.getElementById('toggle-screen');
    screenBtn.classList.toggle('active', isScreenSharing);
}

document.getElementById('toggle-audio').onclick = () => {
    isAudioOn = !isAudioOn;
    const audioTrack = localStream?.getAudioTracks()[0];
    if (audioTrack) audioTrack.enabled = isAudioOn;
    if (audioProducer) {
        isAudioOn ? audioProducer.resume() : audioProducer.pause();
    }
    updateParticipantStatus(currentUser, 'toggle-audio', isAudioOn);
    updateControlButtons();
};

document.getElementById('toggle-video').onclick = () => {
    isVideoOn = !isVideoOn;
    const videoTrack = localStream?.getVideoTracks()[0];
    if (videoTrack) videoTrack.enabled = isVideoOn;
    if (videoProducer && !isScreenSharing) {
        isVideoOn ? videoProducer.resume() : videoProducer.pause();
    }
    updateParticipantStatus(currentUser, 'toggle-video', isVideoOn);
    updateControlButtons();
};

document.getElementById('toggle-screen').onclick = async () => {
    if (isScreenSharing) {
        await stopScreenShare();
    } else {
        await startScreenShare();
    }
};

async function startScreenShare() {
    try {
        await waitForTransport('send');
        const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
        console.log('Screen stream obtained');
        const track = screenStream.getVideoTracks()[0];
        screenProducer = await sendTransport.produce(track);
        console.log('Screen producer created:', screenProducer.id);

        addVideoStream(currentUser + '-screen', screenStream);

        if (videoProducer) videoProducer.pause();
        if (localStream?.getVideoTracks()[0]) localStream.getVideoTracks()[0].enabled = false;
        isVideoOn = false;
        updateControlButtons();
        updateParticipantStatus(currentUser, 'toggle-video', false);

        track.onended = () => stopScreenShare();
        isScreenSharing = true;
    } catch (err) {
        console.error("Screen share failed:", err);
        isScreenSharing = false;
        updateControlButtons();
    }
}

async function stopScreenShare() {
    if (!screenProducer) return;
    
    screenProducer.close();
    screenProducer = null;
    
    removeVideoStream(currentUser + '-screen');
    
    if (videoProducer) videoProducer.resume();
    if (localStream?.getVideoTracks()[0]) localStream.getVideoTracks()[0].enabled = true;
    isVideoOn = true;
    updateParticipantStatus(currentUser, 'toggle-video', true);
    
    isScreenSharing = false;
    updateControlButtons();
}

document.getElementById('toggle-chat').onclick = () => document.getElementById('chat-sidebar').classList.toggle('hidden');
document.getElementById('close-chat-btn').onclick = () => document.getElementById('chat-sidebar').classList.add('hidden');
document.getElementById('end-call').onclick = () => {
    if (confirm('Are you sure you want to leave the call?')) {
        cleanup();
        window.location.href = `<?php echo $isAdmin ? 'admin/forum-chat.php' : ($isMentor ? 'mentor/forum-chat.php' : 'mentee/forum-chat.php'); ?>?view=forum&forum_id=${forumId}`;
    }
};

const chatInput = document.getElementById('chat-message');
const sendChatBtn = document.getElementById('send-chat-btn');

function sendChatMessage() {
    const msg = chatInput.value.trim();
    if (!msg) return;
    const formData = new FormData();
    formData.append('action', 'video_chat');
    formData.append('forum_id', forumId);
    formData.append('message', msg);
    fetch('', { method: 'POST', body: new URLSearchParams(formData) })
        .then(response => { if (response.ok) chatInput.value = ''; })
        .catch(error => console.error('Error sending chat message:', error));
}
sendChatBtn.onclick = sendChatMessage;
chatInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
});

function pollChatMessages() {
    fetch(window.location.href).then(response => response.text()).then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newMessagesContainer = doc.getElementById('chat-messages');
        const currentMessagesContainer = document.getElementById('chat-messages');
        if (newMessagesContainer && currentMessagesContainer) {
            if (newMessagesContainer.innerHTML !== currentMessagesContainer.innerHTML) {
                currentMessagesContainer.innerHTML = newMessagesContainer.innerHTML;
                currentMessagesContainer.scrollTop = currentMessagesContainer.scrollHeight;
            }
        }
    }).catch(err => console.error('Error polling chat:', err));
}

function cleanup() {
    if (audioProducer) audioProducer.close();
    if (videoProducer) videoProducer.close();
    if (screenProducer) screenProducer.close();
    if (room) room.leave();
    if (socket) socket.disconnect();
    if (localStream) localStream.getTracks().forEach(t => t.stop());
}

window.addEventListener('beforeunload', cleanup);

if (!('getDisplayMedia' in navigator.mediaDevices)) {
    document.getElementById('toggle-screen').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    initSocketAndRoom();
    setInterval(pollChatMessages, 3000);
});
</script>
</body>
</html>
