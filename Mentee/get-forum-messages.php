<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// SESSION CHECK
if (!isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

if (!isset($_GET['forum_id'])) {
    echo "<p>No forum selected</p>";
    exit();
}

$forumId = $_GET['forum_id'];

// Fetch messages for the forum
$messages = [];
$stmt = $conn->prepare("
    SELECT * FROM chat_messages 
    WHERE chat_type = 'forum' AND forum_id = ? 
    ORDER BY timestamp ASC 
    LIMIT 100
");
$stmt->bind_param("i", $forumId);
$stmt->execute();
$messagesResult = $stmt->get_result();

if ($messagesResult->num_rows > 0) {
    while ($row = $messagesResult->fetch_assoc()) {
        ?>
        <div class="message <?php echo $row['is_admin'] ? 'admin' : 'user'; ?>">
            <div class="sender">
                <?php if ($row['is_admin']): ?>
                    <ion-icon name="shield-outline"></ion-icon>
                <?php else: ?>
                    <ion-icon name="person-outline"></ion-icon>
                <?php endif; ?>
                <?php echo htmlspecialchars($row['display_name']); ?>
            </div>
            <div class="content"><?php echo htmlspecialchars($row['message']); ?></div>
            <div class="timestamp"><?php echo date('M d, g:i a', strtotime($row['timestamp'])); ?></div>
        </div>
        <?php
    }
} else {
    echo "<p class='no-messages'>No messages in this forum yet.</p>";
}
?>
