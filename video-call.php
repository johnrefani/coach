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
    $redirect_url = $isMentor ? "mentor/forum-chat.php?view=forums" : ($isAdmin ? "admin/forum-chat.php?view=forums" : "mentee/forum-chat.php?view=forums");
    header("Location: " . $redirect_url);
    exit();
}
$forumId = intval($_GET['forum_id']);

/* --------------------------- ACCESS CHECK --------------------------- */
if (!$isAdmin && !$isMentor) {
    $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $forumId, $currentUserId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        header("Location: mentee/forum-chat.php?view=forums");
        exit();
    }
}

/* --------------------------- FETCH FORUM DETAILS --------------------------- */
$stmt = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$forumResult = $stmt->get_result();
if ($forumResult->num_rows === 0) {
    $redirect_url = $isMentor ? "mentor/forum-chat.php?view=forums" : ($isAdmin ? "admin/forum-chat.php?view=forums" : "mentee/forum-chat.php?view=forums");
    header("Location: " . $redirect_url);
    exit();
}
$forumDetails = $forumResult->fetch_assoc();

/* --------------------------- SESSION STATUS CHECK --------------------------- */
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$currentTime = date('H:i');
list($startTime, $endTimeStr) = explode(' - ', $forumDetails['time_slot']);
$endTime = date('H:i', strtotime($endTimeStr));
$isSessionOver = ($today > $forumDetails['session_date']) || ($today == $forumDetails['session_date'] && $currentTime > $endTime);

$checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND user_id = ?");
$checkLeft->bind_param("ii", $forumId, $currentUserId);
$checkLeft->execute();
$leftResult = $checkLeft->get_result();
$hasLeftSession = false;
if ($leftResult->num_rows > 0) {
    $participantStatus = $leftResult->fetch_assoc()['status'];
    $hasLeftSession = in_array($participantStatus, ['left', 'review']);
}

if ($isSessionOver || $hasLeftSession) {
    $redirect_url = $isMentor ? "mentor/forum-chat.php" : ($isAdmin ? "admin/forum-chat.php" : "mentee/forum-chat.php");
    header("Location: " . $redirect_url . "?view=forum&forum_id=" . $forumId . "&review=true");
    exit();
}

/* --------------------------- HANDLE CHAT POST --------------------------- */
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

/* --------------------------- FETCH PARTICIPANTS & MESSAGES --------------------------- */
$participants = [];
$stmt = $conn->prepare("SELECT u.username, CONCAT(u.first_name, ' ', u.last_name) as display_name, COALESCE(u.icon, 'uploads/img/default_pfp.png') as profile_picture, LOWER(u.user_type) as user_type FROM forum_participants fp JOIN users u ON fp.user_id = u.user_id WHERE fp.forum_id = ?");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['profile_picture'] = str_replace('../', '', $row['profile_picture']);
    $participants[] = $row;
}

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
<style>
#ws-status {
  position: fixed; top: 12px; left: 50%; transform: translateX(-50%);
  padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500;
  color: white; z-index: 1000; transition: all 0.3s ease;
}
.status-connected { background-color: #28a745; }
.status-disconnected { background-color: #dc3545; }
.status-connecting { background-color: #ffc107; color: #333; }
.tile-fullscreen-btn {
    position: absolute; top: 8px; right: 8px; z-index: 15; opacity: 0;
    pointer-events: none; transition: opacity 0.2s ease-in-out;
    width: 36px; height: 36px; font-size: 18px;
}
.video-container:hover .tile-fullscreen-btn { opacity: 1; pointer-events: auto; }
</style>
</head>
<body>
  <nav id="top-bar">
    <div class="left">
      <img src="uploads/img/LogoCoach.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
      <div>
        <div class="meeting-title"><?php echo htmlspecialchars($forumDetails['title'] ?? 'Video Meeting'); ?></div>
        <div style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($forumDetails['session_date'] ?? ''); ?> &middot; <?php echo htmlspecialchars($forumDetails['time_slot'] ?? ''); ?></div>
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

<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="https://unpkg.com/mediasoup-client@3.6.86/dist/mediasoup-client.min.js"></script>
<script>
const currentUser = <?php echo json_encode($currentUserUsername); ?>;
const displayName = <?php echo json_encode($displayName); ?>;
const profilePicture = <?php echo json_encode($profilePicture); ?>;
const forumId = <?php echo json_encode($forumId); ?>;
let participants = <?php echo json_encode($participants); ?>;

let socket, device, producerTransport;
let consumerTransports = new Map();
let producers = new Map();
let consumers = new Map();
let localStream = null;
let screenStream = null;
let isVideoOn = true;
let isAudioOn = true;
let isScreenSharing = false;
const statusIndicator = document.getElementById('ws-status');

/* -------------------- SIGNALING & INITIALIZATION -------------------- */
function initSocketIO() {
    const wsUrl = `https://${window.location.host}`;
    statusIndicator.textContent = 'Connecting...';
    statusIndicator.className = 'status-connecting';
    socket = io(wsUrl, { path: '/sfu-socket/socket.io' });

    socket.on('connect', async () => {
        statusIndicator.textContent = 'Connected';
        statusIndicator.className = 'status-connected';
        try {
            device = new mediasoupClient.Device();
        } catch (error) {
            console.error('Failed to create mediasoup device:', error);
            alert('Could not initialize video call client. Please refresh.');
            return;
        }
        socket.emit('join-forum', { forumId, username: currentUser, displayName, profilePicture, rtpCapabilities: device.rtpCapabilities });
    });

    socket.on('join-success', async (data) => {
        try {
            await device.load({ routerRtpCapabilities: data.rtpCapabilities });
            producerTransport = await createTransport(true);
            await getMedia();
            for (const { producerId, username, kind } of data.existingProducers) {
                await consumeRemoteStream(producerId, username, kind);
            }
        } catch (err) { console.error('Error during join-success:', err); }
    });

    socket.on('new-peer', (peer) => {
        if (!participants.find(p => p.username === peer.username)) participants.push(peer);
        addVideoStream(peer.username, null);
    });
    socket.on('new-producer', async (data) => await consumeRemoteStream(data.producerId, data.producerUsername, data.kind));
    socket.on('producer-closed', (data) => {
        const consumer = Array.from(consumers.values()).find(c => c.producerId === data.producerId);
        if (consumer) {
            if (consumer.kind === 'video') updateParticipantStatus(consumer.appData.username, 'toggle-video', false);
            consumer.close();
            consumers.delete(consumer.id);
        }
    });
    socket.on('peer-left', (data) => {
        removeVideoStream(data.username);
        removeVideoStream(`${data.username}-screen`);
        participants = participants.filter(p => p.username !== data.username);
    });
    socket.on('toggle-video', (d) => updateParticipantStatus(d.from, 'toggle-video', d.enabled));
    socket.on('toggle-audio', (d) => updateParticipantStatus(d.from, 'toggle-audio', d.enabled));
    socket.on('speaker-changed', (d) => updateActiveSpeaker(d.username));
    socket.on('disconnect', () => { statusIndicator.textContent = 'Disconnected'; statusIndicator.className = 'status-disconnected'; });
    socket.on('connect_error', (err) => { console.error('Socket connect error:', err); statusIndicator.textContent = 'Error'; statusIndicator.className = 'status-disconnected'; });
}

/* -------------------- MEDIASOUP TRANSPORTS & CONSUMERS -------------------- */
async function createTransport(isProducer) {
    return new Promise((resolve, reject) => {
        socket.emit('create-transport', isProducer, (data) => {
            if (data.error) return reject(new Error(data.error));
            const transport = isProducer ? device.createSendTransport(data) : device.createRecvTransport(data);
            transport.on('connect', ({ dtlsParameters }, cb) => {
                socket.emit('transport-connect', { transportId: transport.id, dtlsParameters });
                cb();
            });
            if (isProducer) {
                transport.on('produce', (params, cb, errb) => {
                    socket.emit('transport-produce', { ...params, transportId: transport.id }, ({ id, error }) => {
                        if (error) return errb(new Error(error));
                        cb({ id });
                    });
                });
            }
            resolve(transport);
        });
    });
}

async function consumeRemoteStream(producerId, username, kind) {
    const consumerTransport = await createTransport(false);
    consumerTransports.set(consumerTransport.id, consumerTransport);
    socket.emit('consume', { transportId: consumerTransport.id, producerId }, async (data) => {
        if (data.error) return console.error(`Consume error for ${username}:`, data.error);
        const consumer = await consumerTransport.consume({ ...data, appData: { username, kind } });
        consumers.set(consumer.id, consumer);
        const stream = new MediaStream([consumer.track]);
        if (kind === 'video') {
            addVideoStream(username, stream);
        } else if (kind === 'audio') {
            const audioEl = document.createElement('audio');
            audioEl.srcObject = stream;
            audioEl.autoplay = true;
            audioEl.id = `audio-${username}`;
            document.body.appendChild(audioEl);
        }
    });
}

/* -------------------- MEDIA & UI MANAGEMENT -------------------- */
async function getMedia() {
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        addVideoStream(currentUser, localStream, true);
        const audioTrack = localStream.getAudioTracks()[0];
        const videoTrack = localStream.getVideoTracks()[0];
        if (audioTrack) {
            const producer = await producerTransport.produce({ track: audioTrack, appData: { username: currentUser } });
            producers.set(producer.id, producer);
        }
        if (videoTrack) {
            const producer = await producerTransport.produce({ track: videoTrack, appData: { username: currentUser } });
            producers.set(producer.id, producer);
        }
    } catch (err) {
        alert(`Could not access camera/microphone: ${err.message}.`);
        addVideoStream(currentUser, null, true);
        isAudioOn = isVideoOn = false;
    }
    updateControlButtons();
}

function addVideoStream(username, stream, isLocal = false) {
    const grid = document.getElementById('video-grid');
    const containerId = `video-container-${username}`;
    let container = document.getElementById(containerId);
    if (!container) {
        container = document.createElement('div');
        container.id = containerId;
        container.className = 'video-container';
        if (username.includes('-screen')) container.classList.add('is-screen-share');
        grid.appendChild(container);
    } else {
        container.innerHTML = '';
    }
    const video = document.createElement('video');
    video.autoplay = true; video.playsInline = true; video.muted = isLocal;
    if (stream) video.srcObject = stream;
    container.appendChild(video);

    const pName = username.replace('-screen', '');
    const participant = participants.find(p => p.username === pName) || { display_name: pName, profile_picture: 'uploads/img/default_pfp.png' };
    
    const overlay = document.createElement('div');
    overlay.className = 'profile-overlay';
    overlay.innerHTML = `<img src="${participant.profile_picture}" alt="Profile" /><div class="name-tag">${participant.display_name}</div>`;
    container.appendChild(overlay);

    const label = document.createElement('div');
    label.className = 'video-label';
    label.innerHTML = `<ion-icon name="mic-outline"></ion-icon><span>${participant.display_name} ${username.includes('-screen') ? '(Screen)' : ''}</span>`;
    container.appendChild(label);

    if (!isLocal) {
        const btn = document.createElement('button');
        btn.className = 'control-btn tile-fullscreen-btn';
        btn.title = 'View Fullscreen';
        btn.innerHTML = `<ion-icon name="scan-outline"></ion-icon>`;
        btn.onclick = (e) => { e.stopPropagation(); document.fullscreenElement === container ? document.exitFullscreen() : container.requestFullscreen(); };
        container.appendChild(btn);
    }
    updateGridLayout();
    updateParticipantStatus(username, 'toggle-video', !!stream);
}

function removeVideoStream(username) {
    document.getElementById(`video-container-${username}`)?.remove();
    document.getElementById(`audio-${username}`)?.remove();
    updateGridLayout();
}

function updateParticipantStatus(username, type, enabled) {
    const container = document.getElementById(`video-container-${username}`);
    if (!container) return;
    if (type === 'toggle-video') {
        container.querySelector('.profile-overlay').style.display = enabled ? 'none' : 'flex';
        container.querySelector('video').style.display = enabled ? 'block' : 'none';
    } else if (type === 'toggle-audio') {
        container.querySelector('.video-label ion-icon').name = enabled ? 'mic-outline' : 'mic-off-outline';
    }
}

function updateGridLayout() {
    const grid = document.getElementById('video-grid');
    const count = grid.children.length;
    if (!count) return;
    grid.style.display = 'grid';
    grid.querySelectorAll('.video-container').forEach(t => { t.style.maxWidth = ''; t.style.maxHeight = ''; });

    if (count === 1) {
        grid.style.display = 'flex';
        const tile = grid.children[0];
        if (tile) { tile.style.maxWidth = 'min(80vw, 142vh)'; tile.style.maxHeight = '80vh'; }
        return;
    }
    let cols;
    if (count <= 2) cols = count;
    else if (count <= 4) cols = 2;
    else if (count <= 9) cols = 3;
    else if (count <= 16) cols = 4;
    else cols = 5;
    grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
}

function updateActiveSpeaker(username) {
    document.querySelectorAll('.video-container.active-speaker').forEach(c => c.classList.remove('active-speaker'));
    document.getElementById(`video-container-${username}`)?.classList.add('active-speaker');
}

/* -------------------- CONTROLS -------------------- */
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
    const p = Array.from(producers.values()).find(prod => prod.kind === 'audio');
    if (p) isAudioOn ? await p.resume() : await p.pause();
    socket.emit('toggle-audio', { from: currentUser, enabled: isAudioOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-audio', isAudioOn);
    updateControlButtons();
};

document.getElementById('toggle-video').onclick = async () => {
    isVideoOn = !isVideoOn;
    const p = Array.from(producers.values()).find(prod => prod.kind === 'video' && !prod.appData.isScreen);
    if (p) isVideoOn ? await p.resume() : await p.pause();
    socket.emit('toggle-video', { from: currentUser, enabled: isVideoOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-video', isVideoOn);
    updateControlButtons();
};

document.getElementById('toggle-screen').onclick = async () => {
    const screenProducer = Array.from(producers.values()).find(p => p.appData.isScreen);
    if (screenProducer) {
        await stopScreenShare(screenProducer.id);
    } else {
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            const track = screenStream.getVideoTracks()[0];
            const newProducer = await producerTransport.produce({ track, appData: { username: `${currentUser}-screen`, isScreen: true } });
            producers.set(newProducer.id, newProducer);
            addVideoStream(`${currentUser}-screen`, screenStream, true);
            isScreenSharing = true;
            track.onended = () => stopScreenShare(newProducer.id);
        } catch (err) { console.error('Screen share failed:', err); }
    }
    updateControlButtons();
};

async function stopScreenShare(producerId) {
    const p = producers.get(producerId);
    if (!p) return;
    p.close();
    producers.delete(producerId);
    removeVideoStream(`${currentUser}-screen`);
    if (screenStream) screenStream.getTracks().forEach(t => t.stop());
    screenStream = null;
    isScreenSharing = false;
    updateControlButtons();
}

document.getElementById('toggle-chat').onclick = () => document.getElementById('chat-sidebar').classList.toggle('hidden');
document.getElementById('close-chat-btn').onclick = () => document.getElementById('chat-sidebar').classList.add('hidden');
document.getElementById('end-call').onclick = () => {
    if (confirm('Are you sure you want to leave the call?')) {
        const url = `<?php echo $isAdmin ? 'admin/forum-chat.php' : ($isMentor ? 'mentor/forum-chat.php' : 'mentee/forum-chat.php'); ?>?view=forum&forum_id=${forumId}`;
        window.location.href = url;
    }
};

/* -------------------- CHAT & LIFECYCLE -------------------- */
const chatInput = document.getElementById('chat-message');
const sendBtn = document.getElementById('send-chat-btn');
const sendChatMessage = () => {
    const msg = chatInput.value.trim();
    if (!msg) return;
    const formData = new FormData();
    formData.append('action', 'video_chat');
    formData.append('message', msg);
    fetch('', { method: 'POST', body: new URLSearchParams(formData) })
        .then(res => { if (res.ok) chatInput.value = ''; })
        .catch(err => console.error('Chat send error:', err));
};
sendBtn.onclick = sendChatMessage;
chatInput.onkeypress = (e) => { if (e.key === 'Enter') { e.preventDefault(); sendChatMessage(); } };

const pollChat = () => {
    fetch(window.location.href).then(res => res.text()).then(html => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newChat = doc.getElementById('chat-messages');
        const oldChat = document.getElementById('chat-messages');
        if (newChat && oldChat && newChat.innerHTML !== oldChat.innerHTML) {
            oldChat.innerHTML = newChat.innerHTML;
            oldChat.scrollTop = oldChat.scrollHeight;
        }
    }).catch(err => console.error('Chat poll error:', err));
};

window.addEventListener('beforeunload', () => {
    if (socket?.connected) socket.disconnect();
    localStream?.getTracks().forEach(t => t.stop());
    screenStream?.getTracks().forEach(t => t.stop());
});

if (!navigator.mediaDevices?.getDisplayMedia) {
    document.getElementById('toggle-screen').style.display = 'none';
}

initSocketIO();
setInterval(pollChat, 3000);
</script>
</body>
</html>
