<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// Update chat_messages table to add required columns if they don't exist
$updateSql = "ALTER TABLE chat_messages 
              ADD COLUMN IF NOT EXISTS is_mentor TINYINT(1) DEFAULT 0,
              ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL,
              ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) NULL";
$conn->query($updateSql);

// Create session_participants table if it doesn't exist (to track who has left a session)
$conn->query("
CREATE TABLE IF NOT EXISTS session_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    status ENUM('active', 'left', 'review') DEFAULT 'active',
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (forum_id, username)
)");

// SESSION CHECK
if (!isset($_SESSION['applicant_username'])) {
    header("Location: login_mentor.php");
    exit();
}

// Get mentor's information
$applicantUsername = $_SESSION['applicant_username'];
$sql = "SELECT CONCAT(First_Name, ' ', Last_Name) AS Mentor_Name, Mentor_Icon FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['mentor_name'] = $row['Mentor_Name'];
    $displayName = $row['Mentor_Name'];

    // Check if Mentor_Icon exists and is not empty
    if (isset($row['Mentor_Icon']) && !empty($row['Mentor_Icon'])) {
        $_SESSION['mentor_icon'] = $row['Mentor_Icon'];
        $mentorIcon = $row['Mentor_Icon'];
    } else {
        $_SESSION['mentor_icon'] = "img/default_pfp.png";
        $mentorIcon = "img/default_pfp.png";
    }
} else {
    $_SESSION['mentor_name'] = "Unknown Mentor";
    $_SESSION['mentor_icon'] = "img/default_pfp.png";
    $displayName = "Unknown Mentor";
    $mentorIcon = "img/default_pfp.png";
}

// Create tables if they don't exist
$conn->query("
CREATE TABLE IF NOT EXISTS forum_chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    course_title VARCHAR(200) NOT NULL,
    session_date DATE NOT NULL,
    time_slot VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    max_users INT DEFAULT 10
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS forum_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (forum_id, username)
)
");

// Handle leaving a chat
if (isset($_GET['action']) && $_GET['action'] === 'leave_chat' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    
    // Update the session_participants table to mark user as having left
    $checkParticipant = $conn->prepare("SELECT id FROM session_participants WHERE forum_id = ? AND username = ?");
    $checkParticipant->bind_param("is", $forumId, $applicantUsername);
    $checkParticipant->execute();
    $participantResult = $checkParticipant->get_result();
    
    if ($participantResult->num_rows > 0) {
        // Update existing record
        $updateStatus = $conn->prepare("UPDATE session_participants SET status = 'left' WHERE forum_id = ? AND username = ?");
        $updateStatus->bind_param("is", $forumId, $applicantUsername);
        $updateStatus->execute();
    } else {
        // Insert new record
        $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'left')");
        $insertStatus->bind_param("is", $forumId, $applicantUsername);
        $insertStatus->execute();
    }
    
    // Redirect to forums list
    header("Location: forum-chat-mentor.php?view=forums");
    exit();
}

// Handle message submission for forum chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forum_id']) && isset($_POST['action']) && $_POST['action'] === 'forum_chat') {
    $message = trim($_POST['message']);
    $forumId = $_POST['forum_id'];
    
    // Check if user is in review mode or has left the session
    $checkStatus = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
    $checkStatus->bind_param("is", $forumId, $applicantUsername);
    $checkStatus->execute();
    $statusResult = $checkStatus->get_result();
    
    if ($statusResult->num_rows > 0) {
        $participantStatus = $statusResult->fetch_assoc()['status'];
        if ($participantStatus === 'left' || $participantStatus === 'review') {
            // User has left the session or is in review mode, redirect back
            header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }
    
    // Check if session is still active
    $sessionCheck = $conn->prepare("
        SELECT * FROM forum_chats WHERE id = ?
    ");
    $sessionCheck->bind_param("i", $forumId);
    $sessionCheck->execute();
    $sessionResult = $sessionCheck->get_result();
    
    if ($sessionResult->num_rows > 0) {
        $session = $sessionResult->fetch_assoc();
        $today = date('Y-m-d');
        $currentTime = date('H:i');
        
        // Extract time range from time_slot (format: "10:00 AM - 11:00 AM")
        $timeRange = explode(' - ', $session['time_slot']);
        $endTime = date('H:i', strtotime($timeRange[1]));
        
        // Check if session is still active
        $isSessionActive = ($today <= $session['session_date']) || 
                          ($today == $session['session_date'] && $currentTime <= $endTime);
        
        if (!$isSessionActive) {
            // Session is over, redirect to review mode
            header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }
    
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
        $isMentor = 1; // This is a mentor message
        $isAdmin = 0; // Not an admin message
        
        // Insert message into database
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, is_mentor, chat_type, forum_id, file_name, file_path) VALUES (?, ?, ?, ?, ?, 'forum', ?, ?, ?)");
        $stmt->bind_param("sssiiiss", $applicantUsername, $displayName, $message, $isAdmin, $isMentor, $forumId, $fileName, $filePath);
        $stmt->execute();
    }
    
    // Redirect to prevent form resubmission
    header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Handle joining a forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['forum_id']) && $_POST['action'] === 'join_forum') {
    $forumId = $_POST['forum_id'];
    
    // Check if forum exists
    $stmt = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $forum = $result->fetch_assoc();
        
        // Check if mentor is already in the forum
        $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
        $stmt->bind_param("is", $forumId, $applicantUsername);
        $stmt->execute();
        $participantResult = $stmt->get_result();
        
        // Check if user has left the session before
        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
        $checkLeft->bind_param("is", $forumId, $applicantUsername);
        $checkLeft->execute();
        $leftResult = $checkLeft->get_result();
        $hasLeft = false;
        
        if ($leftResult->num_rows > 0) {
            $participantStatus = $leftResult->fetch_assoc()['status'];
            $hasLeft = ($participantStatus === 'left');
        }
        
        // Check if session is active or over
        $today = date('Y-m-d');
        $currentTime = date('H:i');
        
        // Extract time range from time_slot (format: "10:00 AM - 11:00 AM")
        $timeRange = explode(' - ', $forum['time_slot']);
        $endTime = date('H:i', strtotime($timeRange[1]));
        
        $isSessionOver = ($today > $forum['session_date']) || 
                        ($today == $forum['session_date'] && $currentTime > $endTime);
        
        if ($participantResult->num_rows === 0 && !$hasLeft) {
            // Add mentor to forum
            $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
            $stmt->bind_param("is", $forumId, $applicantUsername);
            $stmt->execute();
            
            if ($isSessionOver) {
                // Session is over, add to session_participants with review status
                $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'review')");
                $insertStatus->bind_param("is", $forumId, $applicantUsername);
                $insertStatus->execute();
                
                // Redirect to review mode
                header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId . "&review=true");
                exit();
            } else {
                // Session is active, add to session_participants with active status
                $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'active')");
                $insertStatus->bind_param("is", $forumId, $applicantUsername);
                $insertStatus->execute();
                
                // Redirect to forum chat
                header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId);
                exit();
            }
        } elseif ($hasLeft) {
            // User has left the session, redirect to review mode
            header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        } else {
            // User is already in the forum, check if session is active
            if ($isSessionOver) {
                // Session is over, redirect to review mode
                header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId . "&review=true");
                exit();
            } else {
                // Session is active, redirect to forum chat
                header("Location: forum-chat-mentor.php?view=forum&forum_id=" . $forumId);
                exit();
            }
        }
    } else {
        $error = "Forum not found";
    }
}

// Determine which view to show
$view = isset($_GET['view']) ? $_GET['view'] : 'forums';

// Fetch forums for listing
$forums = [];
$forumsResult = $conn->query("
SELECT f.*, COUNT(fp.id) as current_users 
FROM forum_chats f 
LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
GROUP BY f.id 
ORDER BY f.session_date ASC, f.time_slot ASC
");
if ($forumsResult && $forumsResult->num_rows > 0) {
    while ($row = $forumsResult->fetch_assoc()) {
        $forums[] = $row;
    }
}

// Fetch forum details if viewing a specific forum
$forumDetails = null;
$forumParticipants = [];
$isReviewMode = false;
$hasLeftSession = false;

if ($view === 'forum' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    $stmt = $conn->prepare("
        SELECT f.*, COUNT(fp.id) as current_users 
        FROM forum_chats f 
        LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
        WHERE f.id = ? 
        GROUP BY f.id
    ");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $forumDetails = $result->fetch_assoc();
        
        // Add mentor to forum participants if not already there
        $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
        $stmt->bind_param("is", $forumId, $applicantUsername);
        $stmt->execute();
        $participantResult = $stmt->get_result();
        
        if ($participantResult->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
            $stmt->bind_param("is", $forumId, $applicantUsername);
            $stmt->execute();
        }
        
        // Check if session is over or user is in review mode
        $today = date('Y-m-d');
        $currentTime = date('H:i');
        $timeRange = explode(' - ', $forumDetails['time_slot']);
        $endTime = date('H:i', strtotime($timeRange[1]));
        
        $isSessionOver = ($today > $forumDetails['session_date']) || 
                         ($today == $forumDetails['session_date'] && $currentTime > $endTime);
        
        // Check if user has explicitly left this session
        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
        $checkLeft->bind_param("is", $forumId, $applicantUsername);
        $checkLeft->execute();
        $leftResult = $checkLeft->get_result();
        
        if ($leftResult->num_rows > 0) {
            $participantStatus = $leftResult->fetch_assoc()['status'];
            $hasLeftSession = ($participantStatus === 'left');
        }
        
        // Set review mode if session is over, user has left, or review parameter is set
        $isReviewMode = $isSessionOver || $hasLeftSession || (isset($_GET['review']) && $_GET['review'] === 'true');
        
        // If in review mode and not already marked as such, update status
        if ($isReviewMode && !$hasLeftSession) {
            if ($leftResult->num_rows > 0) {
                $updateStatus = $conn->prepare("UPDATE session_participants SET status = 'review' WHERE forum_id = ? AND username = ?");
                $updateStatus->bind_param("is", $forumId, $applicantUsername);
                $updateStatus->execute();
            } else {
                $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'review')");
                $insertStatus->bind_param("is", $forumId, $applicantUsername);
                $insertStatus->execute();
            }
        }
        
        // Fetch participants
        $stmt = $conn->prepare("
            SELECT fp.username, 
                   CASE 
                     WHEN a.Admin_Username IS NOT NULL THEN a.Admin_Name
                     WHEN app.Applicant_Username IS NOT NULL THEN CONCAT(app.First_Name, ' ', app.Last_Name)
                     ELSE CONCAT(mp.First_Name, ' ', mp.Last_Name)
                   END as display_name,
                   CASE 
                     WHEN a.Admin_Username IS NOT NULL THEN 'admin'
                     WHEN app.Applicant_Username IS NOT NULL THEN 'mentor'
                     ELSE 'mentee'
                   END as user_type
            FROM forum_participants fp
            LEFT JOIN admins a ON fp.username = a.Admin_Username
            LEFT JOIN applications app ON fp.username = app.Applicant_Username
            LEFT JOIN mentee_profiles mp ON fp.username = mp.Username
            WHERE fp.forum_id = ?
        ");
        $stmt->bind_param("i", $forumId);
        $stmt->execute();
        $participantsResult = $stmt->get_result();
        
        if ($participantsResult->num_rows > 0) {
            while ($row = $participantsResult->fetch_assoc()) {
                $forumParticipants[] = $row;
            }
        }
    } else {
        // Forum not found, redirect to forums list
        header("Location: forum-chat-mentor.php?view=forums");
        exit();
    }
}

// Fetch messages for the current forum
$messages = [];
if ($view === 'forum' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    $stmt = $conn->prepare("
        SELECT * FROM chat_messages 
        WHERE chat_type = 'forum' AND forum_id = ? 
        ORDER BY timestamp ASC 
        LIMIT 500
    ");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $messagesResult = $stmt->get_result();
    
    if ($messagesResult->num_rows > 0) {
        while ($row = $messagesResult->fetch_assoc()) {
            $messages[] = $row;
        }
    }
}

// Determine return URL
$returnUrl = "mentor-sessions.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view === 'forums' ? 'Forums' : 'Forum Chat'; ?> - COACH</title>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/mentor-forum-chat.css" />
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body>
    <!-- Header -->
    <header class="chat-header">
        <h1><?php echo $view === 'forums' ? 'Available Sessions' : htmlspecialchars($forumDetails['title']); ?></h1>
        <div class="actions">
            <button onclick="window.location.href='<?php echo $returnUrl; ?>'">
                <ion-icon name="exit-outline"></ion-icon>
                Back to Dashboard
            </button>
        </div>
    </header>

    <?php if (isset($error)): ?>
        <div class="error-message">
            <ion-icon name="alert-circle-outline"></ion-icon>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'forums'): ?>
        <!-- Forums List View -->
        <div class="forums-container">
            <div class="forums-header">
                <h2>Available Sessions</h2>
            </div>
            
            <div class="forums-grid">
                <?php if (empty($forums)): ?>
                    <p>No sessions available yet.</p>
                <?php else: ?>
                    <?php foreach ($forums as $forum): ?>
                        <?php
                        // Determine session status
                        $today = date('Y-m-d');
                        $currentTime = date('H:i');
                        $timeRange = explode(' - ', $forum['time_slot']);
                        $startTime = date('H:i', strtotime($timeRange[0]));
                        $endTime = date('H:i', strtotime($timeRange[1]));
                        
                        $sessionStatus = '';
                        $statusClass = '';
                        
                        if ($today < $forum['session_date']) {
                            $sessionStatus = 'Upcoming';
                            $statusClass = 'status-upcoming';
                        } elseif ($today == $forum['session_date'] && $currentTime < $endTime) {
                            $sessionStatus = 'Active';
                            $statusClass = 'status-active';
                        } else {
                            $sessionStatus = 'Ended';
                            $statusClass = 'status-ended';
                        }
                        
                        // Check if user has left this session
                        $hasLeft = false;
                        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
                        $checkLeft->bind_param("is", $forum['id'], $applicantUsername);
                        $checkLeft->execute();
                        $leftResult = $checkLeft->get_result();
                        
                        if ($leftResult->num_rows > 0) {
                            $participantStatus = $leftResult->fetch_assoc()['status'];
                            $hasLeft = ($participantStatus === 'left');
                        }
                        
                        $isSessionOver = ($sessionStatus === 'Ended');
                        ?>
                        <div class="forum-card">
                            <h3>
                                <?php echo htmlspecialchars($forum['title']); ?>
                                <span class="session-status <?php echo $statusClass; ?>"><?php echo $sessionStatus; ?></span>
                            </h3>
                            <div class="details">
                                <p>
                                    <ion-icon name="book-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['course_title']); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span><?php echo date('F j, Y', strtotime($forum['session_date'])); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['time_slot']); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="people-outline"></ion-icon>
                                    <span>Participants: <?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                                </p>
                            </div>
                            
                            <div class="capacity">
                                <div class="capacity-bar">
                                    <div class="capacity-fill" style="width: <?php echo ($forum['current_users'] / $forum['max_users']) * 100; ?>%;"></div>
                                </div>
                                <span class="capacity-text"><?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                            </div>
                            
                            <div class="actions">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="join_forum">
                                    <input type="hidden" name="forum_id" value="<?php echo $forum['id']; ?>">
                                    
                                    <?php if ($isSessionOver || $hasLeft): ?>
                                        <button type="submit" class="review-btn">
                                            <ion-icon name="eye-outline"></ion-icon>
                                            Review
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="join-btn">
                                            <ion-icon name="enter-outline"></ion-icon>
                                            Join Session
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($view === 'forum' && $forumDetails): ?>
        <!-- Forum Chat View -->
        <div class="chat-container">
            <!-- Forum Info -->
            <div class="forum-info">
                <?php if (!$isReviewMode && !$hasLeftSession): ?>
                    <a href="forum-chat-mentor.php?view=forum&forum_id=<?php echo $forumDetails['id']; ?>&action=leave_chat" class="leave-chat-btn" onclick="return confirm('Are you sure you want to leave this chat? You will only be able to view messages in read-only mode after leaving.')">
                        <ion-icon name="exit-outline"></ion-icon>
                        Leave Chat
                    </a>
                <?php else: ?>
                    <a href="forum-chat-mentor.php" class="leave-chat-btn">
                        <ion-icon name="arrow-back-outline"></ion-icon>
                        Back to Sessions
                    </a>
                <?php endif; ?>
                
                <h2><?php echo htmlspecialchars($forumDetails['title']); ?></h2>
                <div class="details">
                    <div class="detail">
                        <ion-icon name="book-outline"></ion-icon>
                        <span><?php echo htmlspecialchars($forumDetails['course_title']); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <span><?php echo date('F j, Y', strtotime($forumDetails['session_date'])); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="time-outline"></ion-icon>
                        <span><?php echo htmlspecialchars($forumDetails['time_slot']); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="people-outline"></ion-icon>
                        <span><?php echo count($forumParticipants); ?>/<?php echo $forumDetails['max_users']; ?> participants</span>
                    </div>
                    <?php
                    // Determine session status
                    $today = date('Y-m-d');
                    $currentTime = date('H:i');
                    $timeRange = explode(' - ', $forumDetails['time_slot']);
                    $startTime = date('H:i', strtotime($timeRange[0]));
                    $endTime = date('H:i', strtotime($timeRange[1]));
                    
                    $sessionStatus = '';
                    $statusClass = '';
                    
                    if ($today < $forumDetails['session_date']) {
                        $sessionStatus = 'Upcoming';
                        $statusClass = 'status-upcoming';
                    } elseif ($today == $forumDetails['session_date'] && $currentTime < $endTime) {
                        $sessionStatus = 'Active';
                        $statusClass = 'status-active';
                    } else {
                        $sessionStatus = 'Ended';
                        $statusClass = 'status-ended';
                    }
                    ?>
                    <div class="detail">
                        <ion-icon name="information-circle-outline"></ion-icon>
                        <span>Status: <span class="session-status <?php echo $statusClass; ?>"><?php echo $sessionStatus; ?></span></span>
                    </div>
                </div>
                
                <div class="participants">
                    <h3>Participants</h3>
                    <div class="participant-list">
                        <?php foreach ($forumParticipants as $participant): ?>
                            <div class="participant <?php echo $participant['user_type']; ?>">
                                <?php if ($participant['user_type'] === 'admin'): ?>
                                    <ion-icon name="shield-outline"></ion-icon>
                                <?php elseif ($participant['user_type'] === 'mentor'): ?>
                                    <ion-icon name="school-outline"></ion-icon>
                                <?php else: ?>
                                    <ion-icon name="person-outline"></ion-icon>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($participant['display_name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            
            <div class="messages-area" id="messages">
                <div>
                    <?php if ($isReviewMode || $hasLeftSession): ?>
                <div class="review-mode-banner">
                    <?php if ($hasLeftSession): ?>
                        <strong>Review Mode:</strong> You have left this session. You can review the conversation but cannot send new messages.
                    <?php else: ?>
                        <strong>Review Mode:</strong> This session has ended. You can review the conversation but cannot send new messages.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
                </div>
                <div class="video-call">
                    <?php if (!$isReviewMode && !$hasLeftSession && $sessionStatus === 'Active'): ?>
                        <a href="video-call.php?forum_id=<?php echo $forumDetails['id']; ?>" class="join-video-btn bg-blue-500 text-white px-4 py-2 rounded">
                            <ion-icon name="videocam-outline"></ion-icon>
                            Join Video Call
                        </a>
                    <?php endif; ?>
                </div>
                <div class="message-box">
                    <?php if (empty($messages)): ?>
                        <p class="no-messages">No messages yet in this forum. Start the conversation!</p>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="message <?php echo $msg['is_admin'] ? 'admin' : ($msg['is_mentor'] ? 'mentor' : 'user'); ?>">
                                <div class="sender">
                                    <?php if ($msg['is_admin']): ?>
                                        <ion-icon name="shield-outline"></ion-icon>
                                    <?php elseif ($msg['is_mentor']): ?>
                                        <ion-icon name="school-outline"></ion-icon>
                                    <?php else: ?>
                                        <ion-icon name="person-outline"></ion-icon>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($msg['display_name']); ?>
                                </div>
                                <div class="content"><?php echo htmlspecialchars($msg['message']); ?></div>
                                <?php if (isset($msg['file_name']) && $msg['file_name']): ?>
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
                <div class="message-input">
                     <?php if (!$isReviewMode && !$hasLeftSession): ?>
                        <form class="message-form" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="forum_chat">
                            <input type="hidden" name="forum_id" value="<?php echo $forumDetails['id']; ?>">
                            
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
                    <?php else: ?>
                        <div class="message-form" style="opacity: 0.7;">
                            <div class="file-upload-container">
                                <label class="file-upload-btn" style="cursor: not-allowed; background-color: #f5f5f5;">
                                    <ion-icon name="attach-outline"></ion-icon>
                                    Attach File
                                </label>
                                <span class="file-name">You cannot send files in review mode</span>
                            </div>
                            
                            <div class="message-input-container">
                                <input type="text" placeholder="You cannot send messages in review mode" disabled>
                                <button disabled style="background-color: #ccc;">
                                    <ion-icon name="send-outline"></ion-icon>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div> 
        </div>
    <?php endif; ?>

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
        <?php if ($view === 'forum'): ?>
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
        <?php endif; ?>
        
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
