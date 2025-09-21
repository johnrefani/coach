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

/* --------------------------- ACCESS CHECK --------------------------- */
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

/* --------------------------- SESSION STATUS --------------------------- */
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
      <div style="display:flex;align-items:center;gap:16px;">
        <button id="toggle-chat" class="control-btn" title="Chat">
            <ion-icon name="chatbubbles-outline"></ion-icon>
        </button>

        <div style="font-size:13px;color:var(--muted);"><?php echo date('g:i A'); ?></div>
        <img class="profile" src="<?php echo htmlspecialchars($profilePicture); ?>" alt="User">
      </div>
    </div>
  </nav>

  <div class="app-shell">
    <div id="video-area" role="main">
        <div id="jitsi-container" style="width: 100%; height: 100%;">
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

<script src='https://meet.jit.si/external_api.js'></script>
<script>
    /* -------------------- SERVER-SIDE DATA (Still needed) -------------------- */
    const displayName = <?php echo json_encode($displayName); ?>;
    const forumId = <?php echo json_encode($forumId); ?>;
    const forumTitle = <?php echo json_encode($forumDetails['title'] ?? 'Video Meeting'); ?>;
    const isAdmin = <?php echo json_encode($isAdmin); ?>;
    const isMentor = <?php echo json_encode($isMentor); ?>;

    /* -------------------- JITSI MEET INITIALIZATION -------------------- */
    document.addEventListener('DOMContentLoaded', () => {
        // A unique room name is created using your forum's ID to ensure privacy.
        // The "COACH" prefix helps avoid conflicts with public rooms.
        const roomName = `COACHForumSession${forumId}`; 
        
        const options = {
            roomName: roomName,
            width: '100%',
            height: '100%',
            parentNode: document.querySelector('#jitsi-container'),
            // Pre-fill the user's name in the Jitsi call
            userInfo: {
                displayName: displayName
            },
            // Configuration to customize the Jitsi interface
            configOverwrite: {
                startWithAudioMuted: false,
                startWithVideoMuted: false,
                prejoinPageEnabled: false, // Disables the "pre-join" screen for a faster start
                subject: forumTitle,       // Sets the meeting title
            },
            // Interface customizations to hide unwanted buttons
            interfaceConfigOverwrite: {
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false,
                TOOLBAR_BUTTONS: [
                    'microphone', 'camera', 'desktop', 'hangup', 'profile', 
                    'chat', 'settings', 'raisehand', 'videoquality', 'tileview'
                ],
            }
        };

        const api = new JitsiMeetExternalAPI("meet.jit.si", options);

        // This event listener handles when the user clicks the "hangup" button in Jitsi
        api.addEventListener('videoConferenceLeft', (event) => {
            console.log('User has left the Jitsi call.');
            // Redirect back to the forum page after leaving the call
            const redirectUrl = isAdmin 
                ? 'admin/forum-chat.php' 
                : (isMentor ? 'mentor/forum-chat.php' : 'mentee/forum-chat.php');
            
            window.location.href = `${redirectUrl}?view=forum&forum_id=${forumId}`;
        });

        // You can use this to keep your chat sidebar in sync with Jitsi's chat
        api.addEventListener('chatUpdated', (event) => {
            if (event.isOpen) {
                 // Jitsi chat opened, so hide our custom one to avoid confusion
                 document.getElementById('chat-sidebar').classList.add('hidden');
            }
        });
    });

    /* -------------------- YOUR EXISTING CHAT LOGIC (Kept as is) -------------------- */
    document.getElementById('toggle-chat').onclick = () => {
        document.getElementById('chat-sidebar').classList.toggle('hidden');
    };
    document.getElementById('close-chat-btn').onclick = () => {
        document.getElementById('chat-sidebar').classList.add('hidden');
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

    setInterval(pollChatMessages, 3000);
</script>
</body>
</html>
