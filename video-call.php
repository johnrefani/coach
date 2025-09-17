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
/* --- The rest of your PHP setup is unchanged and should be correct. --- */
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
</style>
</head>
<body>
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

      <div id="controls-bar" aria-hidden="false">
        <button id="toggle-audio" class="control-btn" title="Mute / Unmute"><ion-icon name="mic-outline"></ion-icon></button>
        <button id="toggle-video" class="control-btn" title="Camera On / Off"><ion-icon name="videocam-outline"></ion-icon></button>
        <button id="toggle-screen" class="control-btn" title="Share Screen"><ion-icon name="desktop-outline"></ion-icon></button>
        <button id="toggle-chat" class="control-btn" title="Chat"><ion-icon name="chatbubbles-outline"></ion-icon></button>
        <button id="end-call" class="control-btn end-call" title="Leave call"><ion-icon name="call-outline"></ion-icon></button>
      </div>
    </div>

    <aside id="chat-sidebar" class="hidden">
      <!-- Chat content unchanged -->
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

    socket.on('connect', async () => {
        console.log('Connected to signaling server.');
        statusIndicator.textContent = 'Connected';
        statusIndicator.className = 'status-connected';
        
        try {
            // **FIXED FLOW - STEP 1: Get Router capabilities from the server first.**
            socket.emit('getRouterRtpCapabilities', { forumId }, async (routerRtpCapabilities) => {
                if (!routerRtpCapabilities || routerRtpCapabilities.error) {
                    console.error('Could not get router RTP capabilities:', routerRtpCapabilities.error);
                    alert('Failed to connect to the video service. Please refresh.');
                    return;
                }
                
                // **FIXED FLOW - STEP 2: Create and load the mediasoup Device.**
                // This must happen after we have the server's configuration.
                device = new mediasoupClient.Device();
                await device.load({ routerRtpCapabilities });
                console.log('Device loaded successfully.');

                // **FIXED FLOW - STEP 3: Now we can formally join the forum.**
                // We send our device's capabilities to the server.
                socket.emit('join-forum', {
                    forumId,
                    username: currentUser,
                    displayName,
                    profilePicture,
                    rtpCapabilities: device.rtpCapabilities
                });

                // **FIXED FLOW - STEP 4: The server will respond with 'join-success'.**
                // The handler for that event will then create our media transports.
            });
        } catch (error) {
            console.error('Initialization failed:', error);
            alert('Could not initialize video call client. Please refresh.');
        }
    });
    
    // This event fires AFTER the device is loaded and we have joined.
    // This is where we create our transports to send and receive media.
    socket.on('join-success', async (data) => {
        console.log('Joined forum successfully. Existing producers:', data.existingProducers);
        
        // Create the transport for SENDING our media (mic/camera)
        await createTransport(true);

        // Get user's mic/camera AND start producing. THIS WILL SHOW YOUR TILE.
        await getMediaAndProduce();

        // Consume media from anyone who was already in the call
        for (const { producerId, username, kind } of data.existingProducers) {
            await consumeRemoteStream(producerId, username, kind);
        }
    });

    // --- OTHER SOCKET LISTENERS (UNCHANGED) ---
    socket.on('new-peer', (peerInfo) => {
        console.log('New peer joined:', peerInfo);
        if (!participants.find(p => p.username === peerInfo.username)) {
            participants.push(peerInfo);
        }
        addVideoStream(peerInfo.username, null);
    });

    socket.on('new-producer', async (data) => {
        console.log('New producer available:', data);
        await consumeRemoteStream(data.producerId, data.producerUsername, data.producerKind);
    });

    socket.on('producer-closed', (data) => {
        const consumerToClose = [...consumers.values()].find(c => c.producerId === data.producerId);
        if (consumerToClose) {
            const username = consumerToClose.appData.username;
            removeVideoStream(username);
            consumerToClose.close();
            consumers.delete(consumerToClose.id);
        }
    });

    socket.on('peer-left', (data) => {
        console.log(`Peer '${data.username}' left the call.`);
        removeVideoStream(data.username);
        removeVideoStream(`${data.username}-screen`);
    });
    
    socket.on('toggle-video', (data) => updateParticipantStatus(data.from, 'toggle-video', data.enabled));
    socket.on('toggle-audio', (data) => updateParticipantStatus(data.from, 'toggle-audio', data.enabled));

    socket.on('disconnect', () => {
        statusIndicator.textContent = 'Disconnected';
        statusIndicator.className = 'status-disconnected';
    });
    socket.on('connect_error', (err) => {
        console.error('Socket connection error:', err);
        statusIndicator.textContent = 'Error';
        statusIndicator.className = 'status-disconnected';
    });
}

async function createTransport(isProducer) {
    return new Promise((resolve, reject) => {
        socket.emit('create-transport', { isProducer }, async (data) => {
            if (data.error) return reject(data.error);
            
            const transport = isProducer 
                ? device.createSendTransport(data)
                : device.createRecvTransport(data);

            transport.on('connect', ({ dtlsParameters }, callback) => {
                socket.emit('transport-connect', { transportId: transport.id, dtlsParameters });
                callback();
            });

            if (isProducer) {
                transport.on('produce', async ({ kind, rtpParameters, appData }, callback) => {
                    socket.emit('transport-produce', { transportId: transport.id, kind, rtpParameters, appData }, ({ id, error }) => {
                        if (error) return reject(error);
                        callback({ id });
                    });
                });
                producerTransport = transport; // Store the producer transport
            }
            resolve(transport);
        });
    });
}

async function consumeRemoteStream(producerId, username, kind) {
    const rtpCapabilities = device.rtpCapabilities;
    let consumerTransport = [...consumerTransports.values()].find(t => !t.closed);
    if (!consumerTransport) {
        consumerTransport = await createTransport(false);
        consumerTransports.set(consumerTransport.id, consumerTransport);
    }
    
    socket.emit('consume', { transportId: consumerTransport.id, producerId, rtpCapabilities }, async (data) => {
        if (data.error) return console.error('Error consuming stream:', data.error);
        
        const consumer = await consumerTransport.consume({ ...data, appData: { username, kind } });
        consumers.set(consumer.id, consumer);
        
        const stream = new MediaStream([consumer.track]);
        
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

async function getMediaAndProduce() {
    console.log('Requesting user media...');
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        console.log('Media stream acquired successfully.');
        addVideoStream(currentUser, localStream, true); // This shows your local video tile
        
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack && producerTransport) {
            const audioProducer = await producerTransport.produce({ track: audioTrack, appData: { username: currentUser } });
            producers.set(audioProducer.id, audioProducer);
        }

        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack && producerTransport) {
            const videoProducer = await producerTransport.produce({ track: videoTrack, appData: { username: currentUser } });
            producers.set(videoProducer.id, videoProducer);
        }
        updateControlButtons();
        
    } catch (err) {
        console.error('Error accessing media devices:', err);
        alert(`Could not access camera/microphone: ${err.message}.`);
        addVideoStream(currentUser, null, true); // Show profile pic tile even on error
        isAudioOn = false;
        isVideoOn = false;
        updateControlButtons();
    }
}

function addVideoStream(username, stream, isLocal = false) {
    const grid = document.getElementById('video-grid');
    let container = document.getElementById(`video-container-${username}`);

    if (!container) {
        container = document.createElement('div');
        container.id = `video-container-${username}`;
        container.className = 'video-container';
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
    }
    container.appendChild(video);

    const participantInfo = participants.find(p => p.username === username) || { display_name: displayName, profile_picture: profilePicture };

    const overlay = document.createElement('div');
    overlay.className = 'profile-overlay';
    overlay.innerHTML = `<img src="${participantInfo.profile_picture}" alt="Profile" /><div class="name-tag">${participantInfo.display_name}</div>`;
    container.appendChild(overlay);

    const label = document.createElement('div');
    label.className = 'video-label';
    label.innerHTML = `<ion-icon name="mic-outline"></ion-icon><span>${participantInfo.display_name}</span>`;
    container.appendChild(label);
    
    const isVideoEnabled = stream && stream.getVideoTracks()[0] && stream.getVideoTracks()[0].enabled;
    updateParticipantStatus(username, 'toggle-video', isVideoEnabled);
    updateGridLayout();
}

function removeVideoStream(username) {
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
        container.querySelector('.profile-overlay').style.display = enabled ? 'none' : 'flex';
        container.querySelector('video').style.display = enabled ? 'block' : 'none';
    } else if (type === 'toggle-audio') {
        container.querySelector('.video-label ion-icon').name = enabled ? 'mic-outline' : 'mic-off-outline';
    }
}

function updateGridLayout() {
    const grid = document.getElementById('video-grid');
    const participantCount = grid.children.length;
    let columns = Math.ceil(Math.sqrt(participantCount));
    grid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
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
    const audioProducer = [...producers.values()].find(p => p.kind === 'audio');
    if (audioProducer) {
        isAudioOn ? await audioProducer.resume() : await audioProducer.pause();
    }
    socket.emit('toggle-audio', { from: currentUser, enabled: isAudioOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-audio', isAudioOn);
    updateControlButtons();
};

document.getElementById('toggle-video').onclick = async () => {
    isVideoOn = !isVideoOn;
    const videoProducer = [...producers.values()].find(p => p.kind === 'video');
    if (videoProducer) {
        isVideoOn ? await videoProducer.resume() : await videoProducer.pause();
    }
    // **BUG FIX:** Was incorrectly sending `isAudioOn`. Now sends `isVideoOn`.
    socket.emit('toggle-video', { from: currentUser, enabled: isVideoOn, forumId });
    updateParticipantStatus(currentUser, 'toggle-video', isVideoOn);
    updateControlButtons();
};

document.getElementById('end-call').onclick = () => {
    if (confirm('Are you sure you want to leave the call?')) {
        window.location.href = `<?php echo $isAdmin ? 'admin/forum-chat.php' : ($isMentor ? 'mentor/forum-chat.php' : 'mentee/forum-chat.php'); ?>?view=forum&forum_id=${forumId}`;
    }
};

window.addEventListener('beforeunload', () => {
    if (socket) socket.disconnect();
});

initSocketIO();

</script>
</body>
</html>
