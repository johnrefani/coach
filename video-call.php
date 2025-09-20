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
if (isset($_SESSION['admin_username']) && is_string($_SESSION['admin_username']) && !empty($_SESSION['admin_username'])) {
    $currentUserUsername = $_SESSION['admin_username'];
} elseif (isset($_SESSION['applicant_username']) && is_string($_SESSION['applicant_username']) && !empty($_SESSION['applicant_username'])) {
    $currentUserUsername = $_SESSION['applicant_username'];
} elseif (isset($_SESSION['username']) && is_string($_SESSION['username']) && !empty($_SESSION['username'])) {
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
$profilePicture = !empty($newIcon) ? $newIcon : 'Uploads/img/default_pfp.png';

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
           COALESCE(u.icon, 'Uploads/img/default_pfp.png') as profile_picture,
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
<link rel="icon" href="Uploads/coachicon.svg" type="image/svg+xml" />
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
      <img src="Uploads/img/LogoCoach.png" alt="Logo" style="width:36px;height:36px;object-fit:contain;">
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
const consumers = new Map();

async function initSocketAndDevice() {
    try {
        statusIndicator.textContent = 'Connecting...';
        statusIndicator.className = 'status-connecting';

        // Create device first
        device = new mediasoupClient.Device();
        
        const wsUrl = `wss://${window.location.host}`;
        socket = io(wsUrl, {
            transports: ['websocket']
        });

        socket.on('connect', async () => {
            statusIndicator.textContent = 'Connected';
            statusIndicator.className = 'status-connected';
            console.log('Socket connected');

            try {
                // Get router capabilities first
                socket.emit('getRouterRtpCapabilities', { forumId }, async (err, routerRtpCapabilities) => {
                    if (err) {
                        console.error('Error getting router capabilities:', err);
                        alert('Failed to get router capabilities: ' + err);
                        return;
                    }

                    try {
                        // Load the device with router capabilities
                        await device.load({ routerRtpCapabilities });
                        console.log('Device loaded successfully');

                        // Now join the room
                        socket.emit('join', {
                            forumId,
                            peerName: currentUser,
                            rtpCapabilities: device.rtpCapabilities,
                            appData: { displayName, profilePicture }
                        }, async (err, response) => {
                            if (err) {
                                console.error('Error joining room:', err);
                                alert('Failed to join room: ' + err);
                                return;
                            }

                            console.log('Joined room successfully', response);
                            const { peers } = response;

                            // Create receive transport
                            await createRecvTransport();
                            
                            // Handle existing peers
                            for (const peer of peers) {
                                handlePeer(peer);
                            }
                            
                            // Get local media and create send transport
                            await getMedia();
                        });
                    } catch (err) {
                        console.error('Error loading device:', err);
                        alert('Failed to load device: ' + err.message);
                    }
                });
            } catch (err) {
                console.error('Error in socket connect handler:', err);
                statusIndicator.textContent = 'Error';
                statusIndicator.className = 'status-disconnected';
                alert('Connection error: ' + err.message);
            }
        });

        socket.on('disconnect', () => {
            statusIndicator.textContent = 'Disconnected';
            statusIndicator.className = 'status-disconnected';
            console.log('Socket disconnected');
        });

        socket.on('connect_error', (err) => {
            console.error('Socket connection error:', err);
            statusIndicator.textContent = 'Error';
            statusIndicator.className = 'status-disconnected';
            alert('Socket connection error: ' + err.message);
        });

        socket.on('notification', async (notification) => {
            console.log('Received notification:', notification.method, notification);
            try {
                if (notification.method === 'newpeer') {
                    console.log(`New peer joined: ${notification.data.peerName}`);
                    handlePeer(notification.data);
                } else if (notification.method === 'newproducer') {
                    await handleNewProducer(notification.data);
                } else if (notification.method === 'producerclosed') {
                    console.log(`Producer closed: ${notification.data.producerId}`);
                    removeVideoStream(notification.data.peerName);
                    removeVideoStream(notification.data.peerName + '-screen');
                } else if (notification.method === 'peerclosed') {
                    console.log(`Peer closed: ${notification.data.peerName}`);
                    removeVideoStream(notification.data.peerName);
                    removeVideoStream(notification.data.peerName + '-screen');
                    participants = participants.filter(p => p.username !== notification.data.peerName);
                }
            } catch (err) {
                console.error('Error processing notification:', err.message);
            }
        });

    } catch (err) {
        console.error('Initialization error:', err);
        alert('Failed to initialize: ' + err.message);
    }
}

async function createRecvTransport() {
    return new Promise((resolve, reject) => {
        socket.emit('createWebRtcTransport', { 
            forumId,
            direction: 'recv' 
        }, async (err, transportParams) => {
            if (err) {
                console.error('Error creating recv transport:', err);
                reject(err);
                return;
            }
            
            try {
                recvTransport = device.createRecvTransport(transportParams);
                console.log('Recv transport created:', recvTransport.id);

                recvTransport.on('connect', ({ dtlsParameters }, callback, errback) => {
                    socket.emit('connectTransport', {
                        transportId: recvTransport.id,
                        dtlsParameters,
                        forumId
                    }, (err) => {
                        if (err) {
                            console.error('Error connecting recv transport:', err);
                            errback(err);
                        } else {
                            callback();
                        }
                    });
                });

                recvTransport.on('connectionstatechange', (state) => {
                    console.log(`Recv transport ${recvTransport.id} connection state: ${state}`);
                });

                resolve();
            } catch (err) {
                console.error('Error creating recv transport:', err);
                reject(err);
            }
        });
    });
}

async function createSendTransport() {
    return new Promise((resolve, reject) => {
        socket.emit('createWebRtcTransport', { 
            forumId,
            direction: 'send' 
        }, async (err, transportParams) => {
            if (err) {
                console.error('Error creating send transport:', err);
                reject(err);
                return;
            }
            
            try {
                sendTransport = device.createSendTransport(transportParams);
                console.log('Send transport created:', sendTransport.id);

                sendTransport.on('connect', ({ dtlsParameters }, callback, errback) => {
                    socket.emit('connectTransport', {
                        transportId: sendTransport.id,
                        dtlsParameters,
                        forumId
                    }, (err) => {
                        if (err) {
                            console.error('Error connecting send transport:', err);
                            errback(err);
                        } else {
                            callback();
                        }
                    });
                });

                sendTransport.on('produce', async ({ kind, rtpParameters, appData }, callback, errback) => {
                    try {
                        socket.emit('produce', {
                            transportId: sendTransport.id,
                            kind,
                            rtpParameters,
                            appData,
                            forumId
                        }, (err, data) => {
                            if (err) {
                                console.error('Error producing:', err);
                                errback(err);
                            } else {
                                callback({ id: data.id });
                            }
                        });
                    } catch (err) {
                        console.error('Error in produce event:', err);
                        errback(err);
                    }
                });

                sendTransport.on('connectionstatechange', (state) => {
                    console.log(`Send transport ${sendTransport.id} connection state: ${state}`);
                });

                resolve();
            } catch (err) {
                console.error('Error creating send transport:', err);
                reject(err);
            }
        });
    });
}

function handlePeer(peer) {
    console.log(`Handling peer: ${peer.name || peer.peerName}`);
    const peerName = peer.name || peer.peerName;
    if (!participants.find(p => p.username === peerName)) {
        participants.push({
            username: peerName,
            display_name: peer.appData.displayName || peerName,
            profile_picture: peer.appData.profilePicture || 'Uploads/img/default_pfp.png'
        });
    }
    addVideoStream(peerName, null); // Add empty tile
}

async function handleNewProducer(data) {
    console.log(`New producer for ${data.peerName}:`, data);
    if (!recvTransport) {
        console.error('No recvTransport available for consumer creation');
        return;
    }
    
    try {
        socket.emit('consume', {
            transportId: recvTransport.id,
            producerId: data.id,
            rtpCapabilities: device.rtpCapabilities,
            forumId
        }, async (err, consumerParams) => {
            if (err) {
                console.error(`Error creating consumer for ${data.peerName} [${data.kind}]:`, err);
                return;
            }
            
            try {
                const consumer = await recvTransport.consume(consumerParams);
                consumers.set(consumer.id, consumer);
                
                socket.emit('resumeConsumer', { 
                    consumerId: consumer.id,
                    forumId
                }, (err) => {
                    if (err) console.error(`Resume failed for consumer ${consumer.id}:`, err);
                    else console.log(`Consumer ${consumer.id} resumed for ${data.peerName}`);
                });
                
                await handleConsumer(consumer, data.peerName, data.kind);
            } catch (consumeErr) {
                console.error(`Error consuming for ${data.peerName} [${data.kind}]:`, consumeErr.message);
            }
        });
    } catch (err) {
        console.error(`Failed to request consumer for ${data.peerName} [${data.kind}]:`, err.message);
    }
}

async function handleConsumer(consumer, username, kind) {
    const track = consumer.track;
    const stream = new MediaStream();
    stream.addTrack(track);

    if (kind === 'video') {
        addVideoStream(username, stream);
    } else if (kind === 'audio') {
        let audioEl = document.getElementById(`audio-${username}`);
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.id = `audio-${username}`;
            audioEl.autoplay = true;
            audioEl.playsInline = true;
            document.body.appendChild(audioEl);
        }
        audioEl.srcObject = stream;
        try {
            await audioEl.play();
        } catch (e) {
            console.error(`Audio play failed for ${username}:`, e);
        }
    }
}

async function getMedia() {
    try {
        console.log('Getting media...');
        localStream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true },
            video: { width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        console.log('Local stream obtained:', localStream);
        
        addVideoStream(currentUser, localStream, true);
        
        await createSendTransport();
        
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) {
            audioProducer = await sendTransport.produce({ track: audioTrack });
            console.log('Audio producer created:', audioProducer.id);
        }
        
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) {
            videoProducer = await sendTransport.produce({ track: videoTrack });
            console.log('Video producer created:', videoProducer.id);
        }

        updateControlButtons();
    } catch (err) {
        console.error('Error getting media:', err);
        alert('Could not access your camera or microphone. Showing profile tile only.');
        addVideoStream(currentUser, null, true);
        isAudioOn = false;
        isVideoOn = false;
        updateControlButtons();
    }
}

// ... (the rest of your client-side functions remain the same)

// Update the cleanup function
function cleanup() {
    if (audioProducer) {
        socket.emit('closeProducer', { 
            producerId: audioProducer.id,
            forumId
        });
    }
    if (videoProducer) {
        socket.emit('closeProducer', { 
            producerId: videoProducer.id,
            forumId
        });
    }
    if (screenProducer) {
        socket.emit('closeProducer', { 
            producerId: screenProducer.id,
            forumId
        });
    }
    if (sendTransport) {
        socket.emit('closeTransport', { 
            transportId: sendTransport.id
        });
    }
    if (recvTransport) {
        socket.emit('closeTransport', { 
            transportId: recvTransport.id
        });
    }
    for (const consumerId of consumers.keys()) {
        socket.emit('closeConsumer', { 
            consumerId
        });
    }
    if (socket) socket.disconnect();
    if (localStream) localStream.getTracks().forEach(t => t.stop());
}

// Update the startScreenShare function
async function startScreenShare() {
    try {
        const screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
        console.log('Screen stream obtained');
        const track = screenStream.getVideoTracks()[0];
        
        screenProducer = await sendTransport.produce({ 
            track,
            appData: { source: 'screen' }
        });
        console.log('Screen producer created:', screenProducer.id);

        addVideoStream(currentUser + '-screen', screenStream);

        if (videoProducer) await videoProducer.pause();
        if (localStream?.getVideoTracks()[0]) localStream.getVideoTracks()[0].enabled = false;
        isVideoOn = false;
        updateControlButtons();
        updateParticipantStatus(currentUser, 'toggle-video', false);

        track.onended = () => stopScreenShare();
        isScreenSharing = true;
    } catch (err) {
        console.error('Screen share failed:', err);
        isScreenSharing = false;
        updateControlButtons();
    }
}

// Update the stopScreenShare function
async function stopScreenShare() {
    if (!screenProducer) return;
    
    socket.emit('closeProducer', { 
        producerId: screenProducer.id,
        forumId
    });
    screenProducer = null;
    
    removeVideoStream(currentUser + '-screen');
    
    if (videoProducer) await videoProducer.resume();
    if (localStream?.getVideoTracks()[0]) localStream.getVideoTracks()[0].enabled = true;
    isVideoOn = true;
    updateParticipantStatus(currentUser, 'toggle-video', true);
    
    isScreenSharing = false;
    updateControlButtons();
}

document.addEventListener('DOMContentLoaded', () => {
    initSocketAndDevice();
    setInterval(pollChatMessages, 3000);
});
</script>
</body>
</html>
