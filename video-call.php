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
<script src="https://unpkg.com/mediasoup-client@3.6.86/dist/mediasoup-client.min.js"></script>

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
            // **FIX Step 2:** Once we receive the capabilities, THEN we initialize our device.
            initializeDeviceAndJoin(rtpCapabilities);
        });
    });
    
    // **This is a new helper function to contain the logic that MUST run after capabilities are received.**
    async function initializeDeviceAndJoin(rtpCapabilities) {
        try {
            // **FIX Step 3:** Create the mediasoup device. This is now safe.
            device = new mediasoupClient.Device();
            
            // **FIX Step 4:** Load the device with the server's capabilities.
            await device.load({ routerRtpCapabilities: rtpCapabilities });
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
    
    // This event now correctly fires AFTER the device is loaded and we have joined.
    socket.on('join-success', async (data) => {
        console.log('Joined forum successfully. Existing producers:', data.existingProducers);
        // Create the transport for sending our media (mic/camera)
        producerTransport = await createTransport(true);
        // Get user's mic/camera
        await getMedia();
        // Consume media from anyone who was already in the call
        for (const { producerId, username, kind } of data.existingProducers) {
            await consumeRemoteStream(producerId, username, kind);
        }
    });

    // --- ALL OTHER SOCKET LISTENERS FROM YOUR ORIGINAL FILE ARE UNCHANGED ---

    socket.on('new-peer', (peerInfo) => {
        console.log('New peer joined:', peerInfo);
        if (!participants.find(p => p.username === peerInfo.username)) {
            participants.push(peerInfo);
        }
        addVideoStream(peerInfo.username, null);
    });

    socket.on('new-producer', async (data) => {
        console.log('New producer available:', data);
        await consumeRemoteStream(data.producerId, data.producerUsername, data.kind);
    });

    socket.on('producer-closed', (data) => {
        console.log(`Producer ${data.producerId} closed.`);
        const consumerToClose = Array.from(consumers.values()).find(c => c.producerId === data.producerId);
        if (consumerToClose) {
            const username = consumerToClose.appData.username;
            const kind = consumerToClose.kind;
            
            consumerToClose.close();
            consumers.delete(consumerToClose.id);
            
            if (kind === 'video') {
                if(username.includes('-screen')) {
                   removeVideoStream(username);
                } else {
                   updateParticipantStatus(username, 'toggle-video', false);
                }
            }
        }
    });

    socket.on('peer-left', (data) => {
        console.log(`Peer '${data.username}' left the call.`);
        removeVideoStream(data.username);
        removeVideoStream(`${data.username}-screen`);
        participants = participants.filter(p => p.username !== data.username);
    });
    
    socket.on('toggle-video', (data) => {
        updateParticipantStatus(data.from, 'toggle-video', data.enabled);
    });
    
    socket.on('toggle-audio', (data) => {
        updateParticipantStatus(data.from, 'toggle-audio', data.enabled);
    });
    
    socket.on('speaker-changed', (data) => {
        updateActiveSpeaker(data.username);
    });

    socket.on('disconnect', () => {
        console.warn('Socket disconnected. Reconnecting...');
        statusIndicator.textContent = 'Disconnected';
        statusIndicator.className = 'status-disconnected';
    });

    socket.on('connect_error', (err) => {
        console.error('Socket connection error:', err);
        statusIndicator.textContent = 'Error';
        statusIndicator.className = 'status-disconnected';
    });
}

// --- ALL FUNCTIONS BELOW THIS LINE ARE IDENTICAL TO YOUR ORIGINAL FILE ---

async function createTransport(isProducer) {
    return new Promise((resolve, reject) => {
        socket.emit('create-transport', { isProducer }, (data) => {
            if (data.error) {
                console.error('Error creating transport:', data.error);
                return reject(data.error);
            }
            
            const transport = isProducer 
                ? device.createSendTransport(data)
                : device.createRecvTransport(data);

            transport.on('connect', ({ dtlsParameters }, callback, errback) => {
                socket.emit('transport-connect', { transportId: transport.id, dtlsParameters }, () => {
                    callback();
                });
            });

            if (isProducer) {
                transport.on('produce', async ({ kind, rtpParameters, appData }, callback, errback) => {
                     try {
                        socket.emit('transport-produce', { transportId: transport.id, kind, rtpParameters, appData }, ({ id, error }) => {
                            if (error) {
                                errback(error);
                                return;
                            }
                            callback({ id });
                        });
                    } catch (error) {
                        errback(error);
                    }
                });
            }
            resolve(transport);
        });
    });
}

async function consumeRemoteStream(producerId, username, kind) {
    let consumerTransport = Array.from(consumerTransports.values()).find(t => t.appData.username === username);
    if (!consumerTransport) {
        consumerTransport = await createTransport(false);
        consumerTransport.appData = { username }; 
        consumerTransports.set(consumerTransport.id, consumerTransport);
    }

    socket.emit('consume', {
        transportId: consumerTransport.id,
        producerId,
        rtpCapabilities: device.rtpCapabilities
    }, async ({ id, producerId, kind, rtpParameters, error }) => {
        if (error) {
            console.error('Error consuming stream:', error);
            return;
        }
        
        const consumer = await consumerTransport.consume({
            id,
            producerId,
            kind,
            rtpParameters,
            appData: { username, kind }
        });
        
        consumers.set(consumer.id, consumer);
        
        const stream = new MediaStream();
        stream.addTrack(consumer.track);
        
        if (kind === 'video') {
            addVideoStream(username, stream);
        } else if (kind === 'audio') {
            const audioElem = document.createElement('audio');
            audioElem.srcObject = stream;
            audioElem.autoplay = true;
            audioElem.id = `audio-${username}`;
            document.body.appendChild(audioElem);
        }
    });
}

async function getMedia() {
    console.log('Requesting user media (camera and microphone)...');
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        console.log('Media stream acquired successfully.');
        addVideoStream(currentUser, localStream, true); 
        
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
        updateControlButtons();
        
    } catch (err) {
        console.error('Error accessing media devices:', err.name, err.message);
        alert(`Could not access camera/microphone: ${err.message}. You can still watch and listen.`);
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
    const activeContainer = document.getElementById(`video-container-${activeUsername}`);
    if (activeContainer) {
        activeContainer.classList.add('active-speaker');
    }
}

function addVideoStream(username, stream, isLocal = false) {
    console.log(`Adding video stream for: ${username}`);
    const grid = document.getElementById('video-grid');
    let container = document.getElementById(`video-container-${username}`);
    const isScreen = username.includes('-screen');

    if (!container) {
        container = document.createElement('div');
        container.id = `video-container-${username}`;
        container.className = 'video-container';
        if (isScreen) {
            container.classList.add('is-screen-share');
        }
        grid.appendChild(container);
    } else {
        container.innerHTML = '';
    }

    const video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;
    if (isLocal) video.muted = true;

    if (stream) {
        video.srcObject = stream;
        video.onloadedmetadata = () => video.play().catch(e => console.error('Video play failed:', e));
    }
    container.appendChild(video);

    const participantName = username.replace('-screen', '');
    const participant = participants.find(p => p.username === participantName) || { display_name: participantName, profile_picture: 'uploads/img/default_pfp.png' };

    const overlay = document.createElement('div');
    overlay.className = 'profile-overlay';
    overlay.innerHTML = `<img src="${participant.profile_picture}" alt="Profile" /><div class="name-tag">${participant.display_name}</div>`;
    container.appendChild(overlay);

    const label = document.createElement('div');
    label.className = 'video-label';
    label.innerHTML = `<ion-icon name="mic-outline"></ion-icon><span>${participant.display_name} ${isScreen ? '(Screen)' : ''}</span>`;
    container.appendChild(label);

    if (!(isLocal && isScreen)) {
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
    updateParticipantStatus(username, 'toggle-video', stream && stream.getVideoTracks()[0] && stream.getVideoTracks()[0].enabled);
}

function removeVideoStream(username) {
    console.log(`Removing video stream for: ${username}`);
    const el = document.getElementById(`video-container-${username}`);
    if (el) el.remove();
    const audioEl = document.getElementById(`audio-${username}`);
    if(audioEl) audioEl.remove();
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

document.addEventListener('fullscreenchange', () => {
    document.querySelectorAll('.tile-fullscreen-btn').forEach(btn => {
        btn.innerHTML = `<ion-icon name="scan-outline"></ion-icon>`;
        btn.title = 'View Fullscreen';
    });

    if (document.fullscreenElement && document.fullscreenElement.classList.contains('video-container')) {
        const btn = document.fullscreenElement.querySelector('.tile-fullscreen-btn');
        if (btn) {
            btn.innerHTML = `<ion-icon name="contract-outline"></ion-icon>`;
            btn.title = 'Exit Fullscreen';
        }
    }
});

document.getElementById('toggle-audio').onclick = async () => {
    isAudioOn = !isAudioOn;
    const audioProducer = Array.from(producers.values()).find(p => p.kind === 'audio' && !p.appData.username.includes('-screen'));
    if (audioProducer) {
        if (isAudioOn) await audioProducer.resume();
        else await audioProducer.pause();
    }
    socket.emit('toggle-audio', { from: currentUser, enabled: isAudioOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-audio', isAudioOn);
    updateControlButtons();
};

document.getElementById('toggle-video').onclick = async () => {
    isVideoOn = !isVideoOn;
    const videoProducer = Array.from(producers.values()).find(p => p.kind === 'video' && !p.appData.username.includes('-screen'));
    if (videoProducer) {
         if (isVideoOn) await videoProducer.resume();
         else await videoProducer.pause();
    }
    socket.emit('toggle-video', { from: currentUser, enabled: isVideoOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-video', isVideoOn);
    updateControlButtons();
};

document.getElementById('toggle-screen').onclick = async () => {
    if (!isScreenSharing) {
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            const screenVideoTrack = screenStream.getVideoTracks()[0];
            const videoProducer = Array.from(producers.values()).find(p => p.kind === 'video' && !p.appData.username.includes('-screen'));
            
            if (videoProducer) {
                await videoProducer.replaceTrack({ track: screenVideoTrack });
            } else {
                const newProducer = await producerTransport.produce({
                    track: screenVideoTrack,
                    appData: { username: currentUser }
                });
                producers.set(newProducer.id, newProducer);
            }
            
            const localVideoEl = document.querySelector(`#video-container-${currentUser} video`);
            if (localVideoEl) {
                localVideoEl.srcObject = screenStream;
            }
            
            isScreenSharing = true;
            updateControlButtons();
            screenVideoTrack.onended = () => stopScreenShare();

        } catch (err) {
            console.error('Screen share failed:', err);
            isScreenSharing = false;
            updateControlButtons();
        }
    } else {
        await stopScreenShare();
    }
};

async function stopScreenShare() {
    if (!isScreenSharing) return;
    
    if(screenStream) {
        screenStream.getTracks().forEach(track => track.stop());
        screenStream = null;
    }
    
    const videoProducer = Array.from(producers.values()).find(p => p.kind === 'video' && !p.appData.username.includes('-screen'));
    const cameraTrack = localStream?.getVideoTracks()[0];
    
    if (videoProducer && cameraTrack && cameraTrack.readyState === 'live') {
        await videoProducer.replaceTrack({ track: cameraTrack });
        
        const localVideoEl = document.querySelector(`#video-container-${currentUser} video`);
        if (localVideoEl) {
            localVideoEl.srcObject = localStream;
        }
    } else if (videoProducer) {
        await videoProducer.pause();
        updateParticipantStatus(currentUser, 'toggle-video', false);
    }

    isScreenSharing = false;
    updateControlButtons();
}

document.getElementById('toggle-chat').onclick = () => {
    document.getElementById('chat-sidebar').classList.toggle('hidden');
};
document.getElementById('close-chat-btn').onclick = () => {
    document.getElementById('chat-sidebar').classList.add('hidden');
};

document.getElementById('end-call').onclick = () => {
    if (confirm('Are you sure you want to leave the call?')) {
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
    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
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
        })
        .catch(err => console.error('Error polling for chat messages:', err));
}

function cleanup() {
    console.log('Cleaning up connections...');
    if (socket && socket.connected) {
        socket.disconnect();
    }
    if (localStream) localStream.getTracks().forEach(t => t.stop());
    if (screenStream) screenStream.getTracks().forEach(t => t.stop());
}
window.addEventListener('beforeunload', cleanup);

console.log(`Initializing video call for user '${currentUser}' in forum '${forumId}'`);

if (!('getDisplayMedia' in navigator.mediaDevices)) {
    console.warn('Screen sharing is not supported in this browser.');
    document.getElementById('toggle-screen').style.display = 'none';
}

initSocketIO();
setInterval(pollChatMessages, 3000);
</script>
</body>
</html>

