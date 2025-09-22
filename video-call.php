<?php
session_start();

/* --------------------------- DB CONNECTION --------------------------- */
require 'connection/db_connection.php';

/* --------------------------- SESSION CHECK & USER FETCHING --------------------------- */
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username']) && !isset($_SESSION['applicant_username'])) {
    header("Location: login.php");
    exit();
}

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
$displayName = trim($userData['first_name'] . ' ' . $userData['last_name']);
$profilePicture = !empty($userData['icon']) ? str_replace('../', '', $userData['icon']) : 'Uploads/img/default_pfp.png';
$userType = $userData['user_type'];
$isAdmin = in_array($userType, ['Admin', 'Super Admin']);
$isMentor = ($userType === 'Mentor');

/* --------------------------- FORUM FETCHING & ACCESS CHECKS --------------------------- */
if (!isset($_GET['forum_id'])) {
    header("Location: login.php"); 
    exit();
}
$forumId = intval($_GET['forum_id']);
$stmt = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    header("Location: login.php");
    exit();
}
$forumDetails = $res->fetch_assoc();

/* --------------------------- JWT DISABLED --------------------------- */
$jwtToken = null;

/* --------------------------- HANDLE CHAT POST --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'video_chat') {
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, display_name, message, is_admin, is_mentor, chat_type, forum_id) VALUES (?, ?, ?, ?, ?, 'forum', ?)");
        $isAdminBit = $isAdmin ? 1 : 0;
        $isMentorBit = $isMentor ? 1 : 0;
        $stmt->bind_param("issiii", $userData['user_id'], $displayName, $message, $isAdminBit, $isMentorBit, $forumId);
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
        <div style="font-size:12px;color:var(--muted)">
          <?php date_default_timezone_set('Asia/Manila'); echo htmlspecialchars($forumDetails['session_date'] ?? ''); ?> · 
          <?php echo htmlspecialchars($forumDetails['time_slot'] ?? ''); ?>
        </div>
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
        <div id="jitsi-container" style="width: 100%; height: 100%;"></div>
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

<!-- ✅ Use your own Jitsi instance -->
<script src="https://meet.coach-hub.online/external_api.js"></script>

<script>
    const displayName = <?php echo json_encode($displayName); ?>;
    const forumId = <?php echo json_encode($forumId); ?>;
    const forumTitle = <?php echo json_encode($forumDetails['title'] ?? 'Video Meeting'); ?>;
    const isAdmin = <?php echo json_encode($isAdmin); ?>;
    const isMentor = <?php echo json_encode($isMentor); ?>;

    document.addEventListener('DOMContentLoaded', () => {
        const roomName = `CoachHubOnlineForumSession${forumId}`;
        const domain = "meet.coach-hub.online";  // ✅ point to your own server

        const options = {
            roomName: roomName,
            width: '100%',
            height: '100%',
            parentNode: document.querySelector('#jitsi-container'),
            userInfo: { displayName: displayName },
            configOverwrite: {
                prejoinPageEnabled: false,
                startWithAudioMuted: false,
                startWithVideoMuted: false,
                subject: forumTitle,
                enableWelcomePage: false,
                disableModeratorIndicator: true,
                enableLobby: false,
                requireDisplayName: false,
                startWithModeratorMuted: false,
                toolbarButtons: [
                    'microphone', 'camera', 'desktop', 'hangup', 'profile',
                    'chat', 'settings', 'raisehand', 'videoquality', 'tileview'
                ]
            },
            interfaceConfigOverwrite: {
                SHOW_JITSI_WATERMARK: false,
                SHOW_WATERMARK_FOR_GUESTS: false
            }
        };

        const api = new JitsiMeetExternalAPI(domain, options);

        api.addEventListener('videoConferenceJoined', (event) => {
            console.log('Joined conference with role:', event.role);
        });

        api.addEventListener('videoConferenceLeft', () => {
            const redirectUrl = isAdmin 
                ? 'admin/forum-chat.php' 
                : (isMentor ? 'mentor/forum-chat.php' : 'mentee/forum-chat.php');
            window.location.href = `${redirectUrl}?view=forum&forum_id=${forumId}`;
        });
    });

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

    setInterval(pollChatMessages, 5000);
</script>
</body>
</html>
