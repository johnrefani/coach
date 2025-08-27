<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// Update chat_messages table to add file columns if they don't exist
$updateSql = "ALTER TABLE chat_messages 
              ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL,
              ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) NULL";
$conn->query($updateSql);

// SESSION CHECK
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

// Determine if user is admin or mentee
$isAdmin = isset($_SESSION['admin_username']);
$currentUser = $isAdmin ? $_SESSION['admin_username'] : $_SESSION['username'];

// Get user's first name for display
if ($isAdmin) {
    $stmt = $conn->prepare("SELECT Admin_Name FROM admins WHERE Admin_Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $displayName = $row['Admin_Name'];
    } else {
        $displayName = $currentUser;
    }
} else {
    $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM mentee_profiles WHERE Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $displayName = $row['First_Name'] . ' ' . $row['Last_Name'];
    } else {
        $displayName = $currentUser;
    }
}

// Get channel ID from URL
$channelId = isset($_GET['channel']) ? intval($_GET['channel']) : 1; // Default to general channel (ID 1)

// Handle message submission for group chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'group_chat') {
    $message = trim($_POST['message']);
    
    // Check if a file was uploaded
    $fileName = null;
    $filePath = null;
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $uploadDir = 'uploads/chat_files/';
        
        // Create directory if it doesn't exist
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = $_FILES['file']['name'];
        $tempName = $_FILES['file']['tmp_name'];
        $fileSize = $_FILES['file']['size'];
        $fileType = $_FILES['file']['type'];
        
        // Generate unique filename
        $uniqueName = uniqid() . '_' . $fileName;
        $filePath = $uploadDir . $uniqueName;
        
        // Move the uploaded file
        if (move_uploaded_file($tempName, $filePath)) {
            // File uploaded successfully
            $fileName = $fileName;
            $filePath = $filePath;
        }
    }
    
    if (!empty($message) || $fileName) {
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, chat_type, forum_id, file_name, file_path) VALUES (?, ?, ?, ?, 'group', ?, ?, ?)");
        $stmt->bind_param("sssiiis", $currentUser, $displayName, $message, $isAdmin, $channelId, $fileName, $filePath);
        $stmt->execute();
    }
    
    // Redirect to prevent form resubmission
    header("Location: group-chat-admin.php?channel=" . $channelId);
    exit();
}

// Create tables if they don't exist
$conn->query("
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(70) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_admin TINYINT(1) DEFAULT 0,
    chat_type ENUM('group', 'forum') DEFAULT 'group',
    forum_id INT NULL,
    file_name VARCHAR(255) NULL,
    file_path VARCHAR(255) NULL
)
");

// Get channel details
$channelName = "General";
$stmt = $conn->prepare("SELECT name FROM chat_channels WHERE id = ?");
$stmt->bind_param("i", $channelId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $channelName = $row['name'];
}

// Fetch messages for the group chat
$messages = [];
$stmt = $conn->prepare("
    SELECT * FROM chat_messages 
    WHERE chat_type = 'group' AND forum_id = ? 
    ORDER BY timestamp ASC 
    LIMIT 100
");
$stmt->bind_param("i", $channelId);
$stmt->execute();
$messagesResult = $stmt->get_result();
if ($messagesResult->num_rows > 0) {
    while ($row = $messagesResult->fetch_assoc()) {
        $messages[] = $row;
    }
}

// Determine return URL based on user type
$returnUrl = $isAdmin ? "admin-sessions.php" : "group-chat.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($channelName); ?> Channel - COACH</title>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/group-chat-admin.css"/>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body>
    <!-- Header -->
    <header class="chat-header">
        <h1>#<?php echo htmlspecialchars($channelName); ?> Channel</h1>
        <div class="actions">
            <button onclick="window.location.href='<?php echo $returnUrl; ?>'">
                <ion-icon name="exit-outline"></ion-icon>
                Leave Chat
            </button>
        </div>
    </header>

    <!-- Group Chat View -->
    <div class="chat-container">
        <div class="messages-area" id="messages">
            <?php if (empty($messages)): ?>
                <p class="no-messages">No messages yet. Be the first to say hello!</p>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message <?php echo $msg['is_admin'] ? 'admin' : 'user'; ?>">
                        <div class="sender">
                            <?php if ($msg['is_admin']): ?>
                                <ion-icon name="shield-outline"></ion-icon>
                            <?php else: ?>
                                <ion-icon name="person-outline"></ion-icon>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($msg['display_name']); ?>
                        </div>
                        <div class="content"><?php echo htmlspecialchars($msg['message']); ?></div>
                        <?php if ($msg['file_name']): ?>
                            <div class="file-attachment">
                                <ion-icon name="document-outline"></ion-icon>
                                <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank" download>
                                    <?php echo htmlspecialchars($msg['file_name']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="timestamp"><?php echo date('M d, g:i a', strtotime($msg['timestamp'])); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <form class="message-form" method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="group_chat">
            
            <div class="file-upload-container">
                <label for="file-upload" class="file-upload-btn">
                    <ion-icon name="attach-outline"></ion-icon>
                    Attach File
                </label>
                <input type="file" id="file-upload" name="file" style="display: none;" onchange="updateFileName(this)">
                <span class="file-name" id="file-name"></span>
            </div>
            
            <div class="message-input-container">
                <input type="text" name="message" placeholder="Type your message..." autocomplete="off">
                <button type="submit">
                    <ion-icon name="send-outline"></ion-icon>
                </button>
            </div>
        </form>
    </div>

    <script>
        // Scroll to bottom of messages
        function scrollToBottom() {
            const messagesContainer = document.getElementById('messages');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }
        
        // Call on page load
        window.onload = function() {
            scrollToBottom();
        };
        
        // Update file name when file is selected
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : '';
            document.getElementById('file-name').textContent = fileName;
        }
        
        // Auto-refresh for chat (every 5 seconds)
        setInterval(function() {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const newMessages = doc.getElementById('messages').innerHTML;
                    const currentMessages = document.getElementById('messages').innerHTML;
                    
                    if (newMessages !== currentMessages) {
                        document.getElementById('messages').innerHTML = newMessages;
                        scrollToBottom();
                    }
                }
            };
            xhr.send();
        }, 5000);
        
        // Function to toggle dark mode
        function toggleDarkMode() {
            document.body.classList.toggle('dark');
            localStorage.setItem('darkMode', document.body.classList.contains('dark'));
        }
        
        // Check for saved dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
        }
    </script>
</body>
</html>
