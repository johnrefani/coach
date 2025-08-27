<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SESSION CHECK
if (!isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

// Get admin's name for display
$currentUser = $_SESSION['admin_username'];
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

// Create tables if they don't exist
$conn->query("
CREATE TABLE IF NOT EXISTS chat_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_general TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(70) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_admin TINYINT(1) DEFAULT 0,
    chat_type ENUM('group', 'forum') DEFAULT 'group',
    forum_id INT NULL
)
");

// Handle forum deletion - THIS IS THE NEW CODE
if (isset($_GET['delete_forum'])) {
    $forumId = $_GET['delete_forum'];
    
    // First, delete forum participants
    $stmt = $conn->prepare("DELETE FROM forum_participants WHERE forum_id = ?");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    
    // Delete forum messages
    $stmt = $conn->prepare("DELETE FROM chat_messages WHERE chat_type = 'forum' AND forum_id = ?");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    
    // Finally, delete the forum itself
    $stmt = $conn->prepare("DELETE FROM forum_chats WHERE id = ?");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    
    $success = "Forum deleted successfully!";
}

// Create default general channel if it doesn't exist
$result = $conn->query("SELECT id FROM chat_channels WHERE is_general = 1");
if ($result->num_rows === 0) {
    $conn->query("INSERT INTO chat_channels (name, description, is_general) VALUES ('general', 'General discussion channel', 1)");
}

// Handle channel creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_channel') {
    $channelName = trim($_POST['channel_name']);
    $channelDescription = trim($_POST['channel_description']);
    
    if (!empty($channelName)) {
        $stmt = $conn->prepare("INSERT INTO chat_channels (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $channelName, $channelDescription);
        $stmt->execute();
        
        $success = "Channel created successfully!";
    }
}

// Handle channel update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_channel') {
    $channelId = $_POST['channel_id'];
    $channelName = trim($_POST['channel_name']);
    $channelDescription = trim($_POST['channel_description']);
    
    if (!empty($channelName)) {
        $stmt = $conn->prepare("UPDATE chat_channels SET name = ?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $channelName, $channelDescription, $channelId);
        $stmt->execute();
        
        $success = "Channel updated successfully!";
    }
}

// Handle channel deletion
if (isset($_GET['delete_channel'])) {
    $channelId = $_GET['delete_channel'];
    
    // Check if it's not the general channel
    $stmt = $conn->prepare("SELECT is_general FROM chat_channels WHERE id = ?");
    $stmt->bind_param("i", $channelId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['is_general'] == 0) {
            // Delete channel
            $stmt = $conn->prepare("DELETE FROM chat_channels WHERE id = ?");
            $stmt->bind_param("i", $channelId);
            $stmt->execute();
            
            $success = "Channel deleted successfully!";
        } else {
            $error = "Cannot delete the general channel!";
        }
    }
}

// Get all channels
$channels = [];
$result = $conn->query("SELECT * FROM chat_channels ORDER BY is_general DESC, name ASC");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $channels[] = $row;
    }
}

// Get all forums
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

// Get all mentees
$mentees = [];
$menteesResult = $conn->query("SELECT Mentee_ID, Username, First_Name, Last_Name FROM mentee_profiles ORDER BY First_Name, Last_Name");
if ($menteesResult && $menteesResult->num_rows > 0) {
    while ($row = $menteesResult->fetch_assoc()) {
        $mentees[] = $row;
    }
}

// Get message counts for each channel
$channelMessageCounts = [];
foreach ($channels as $channel) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE chat_type = 'group' AND forum_id = ?");
    $stmt->bind_param("i", $channel['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $channelMessageCounts[$channel['id']] = $row['count'];
}

// Get message counts for each forum
$forumMessageCounts = [];
foreach ($forums as $forum) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE chat_type = 'forum' AND forum_id = ?");
    $stmt->bind_param("i", $forum['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $forumMessageCounts[$forum['id']] = $row['count'];
}

// Get participants for each forum
$forumParticipants = [];
foreach ($forums as $forum) {
    $stmt = $conn->prepare("
        SELECT fp.username, 
               CASE 
                 WHEN a.Admin_Username IS NOT NULL THEN a.Admin_Name
                 ELSE CONCAT(mp.First_Name, ' ', mp.Last_Name)
               END as display_name,
               CASE WHEN a.Admin_Username IS NOT NULL THEN 1 ELSE 0 END as is_admin
        FROM forum_participants fp
        LEFT JOIN admins a ON fp.username = a.Admin_Username
        LEFT JOIN mentee_profiles mp ON fp.username = mp.Username
        WHERE fp.forum_id = ?
    ");
    $stmt->bind_param("i", $forum['id']);
    $stmt->execute();
    $participantsResult = $stmt->get_result();
    $participants = [];
    if ($participantsResult->num_rows > 0) {
        while ($row = $participantsResult->fetch_assoc()) {
            $participants[] = $row;
        }
    }
    $forumParticipants[$forum['id']] = $participants;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css"/>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Sessions - COACH</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        /* Additional Styles */
        .container {
            padding: 20px;
            margin-top: 60px;
        }
        
        .section-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: var(--text-color);
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
            color: var(--text-color-light);
        }
        
        .tab.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background-color: var(--dash-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        .card-actions button,
        .card-actions a {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-actions button:hover,
        .card-actions a:hover {
            color: #5a2460;
        }
        
        .card-content {
            margin-bottom: 15px;
        }
        
        .card-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--text-color-light);
            font-size: 0.9rem;
        }
        
        .card-detail ion-icon {
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }
        
        .card-stats {
            display: flex;
            gap: 15px;
        }
        
        .stat {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color-light);
            font-size: 0.8rem;
        }
        
        .card-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .card-button:hover {
            background-color: #5a2460;
        }
        
        .create-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .create-button:hover {
            background-color: #5a2460;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: visibility 0s linear 0.25s, opacity 0.25s;
        }
        
        .modal-overlay.active {
            visibility: visible;
            opacity: 1;
            transition-delay: 0s;
        }
        
        .modal-content {
            background-color: var(--dash-color);
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            font-size: 1.2rem;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-color-light);
        }
        
        .modal-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-group label {
            font-size: 0.9rem;
            color: var(--text-color);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 1rem;
            background-color: var(--dash-color);
            color: var(--text-color);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .modal-actions button {
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .modal-actions .cancel-btn {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .modal-actions .submit-btn {
            background-color: var(--primary-color);
            border: none;
            color: white;
        }
        
        .participants-list {
            margin-top: 10px;
        }
        
        .participant {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .participant:last-child {
            border-bottom: none;
        }
        
        .participant-name {
            flex: 1;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        
        .participant-badge {
            background-color: #e6f5f0;
            color: #2e7d32;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
        }
        
        .participant-badge.admin {
            background-color: #f0e6f5;
            color: var(--primary-color);
        }
        
        .alert {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.success {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .alert.error {
            background-color: #ffebee;
            color: #c62828;
        }
        
        .alert ion-icon {
            font-size: 20px;
        }
        
        @media (max-width: 768px) {
            .card-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                text-align: center;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
        </span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
        <ul class="navLinks">
            <li class="navList">
                <a href="CoachAdmin.php"> <ion-icon name="home-outline"></ion-icon>
                    <span class="links">Home</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminCourses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminMentees.php"> <ion-icon name="person-outline"></ion-icon>
                    <span class="links">Mentees</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminMentors.php"> <ion-icon name="people-outline"></ion-icon>
                    <span class="links">Mentors</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminSession.php"> <ion-icon name="calendar-outline"></ion-icon>
                    <span class="links">Sessions</span>
                </a>
            </li>
            <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
            <li class="navList active">
                <a href="admin-sessions.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
                    <span class="links">Channels</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminActivities.php"> <ion-icon name="clipboard"></ion-icon>
                    <span class="links">Activities</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminResource.php"> <ion-icon name="library-outline"></ion-icon>
                    <span class="links">Resource Library</span>
                </a>
            </li>
        </ul>

    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px;">
          <ion-icon name="log-out-outline"></ion-icon>
          Logout
        </a>
      </li>
    </ul>
  </div>
</nav>

    <section class="dashboard">
        <div class="top">
            <ion-icon class="navToggle" name="menu-outline"></ion-icon>
            <img src="img/logo.png" alt="Logo">
        </div>

        <div class="container">
            <h1 class="section-title">Manage Sessions</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert success">
                    <ion-icon name="checkmark-circle-outline"></ion-icon>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert error">
                    <ion-icon name="alert-circle-outline"></ion-icon>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="tabs">
                <div class="tab active" data-tab="channels">Public Channel</div>
                <div class="tab" data-tab="forums">Private Channels</div>
            </div>
            
                <div class="card-grid">
                    <?php foreach ($channels as $channel): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">#<?php echo htmlspecialchars($channel['name']); ?></h3>
                                <div class="card-actions">
                                    <button onclick="openEditChannelModal(<?php echo $channel['id']; ?>, '<?php echo htmlspecialchars(addslashes($channel['name'])); ?>', '<?php echo htmlspecialchars(addslashes($channel['description'])); ?>')" title="Edit Channel">
                                        <ion-icon name="create-outline"></ion-icon>
                                    </button>
                                    <?php if ($channel['is_general'] == 0): ?>
                                        <a href="?delete_channel=<?php echo $channel['id']; ?>" onclick="return confirm('Are you sure you want to delete this channel?')" title="Delete Channel">
                                            <ion-icon name="trash-outline"></ion-icon>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-content">
                                <div class="card-detail">
                                    <ion-icon name="information-circle-outline"></ion-icon>
                                    <span><?php echo !empty($channel['description']) ? htmlspecialchars($channel['description']) : 'No description'; ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span>Created: <?php echo date('M j, Y', strtotime($channel['created_at'])); ?></span>
                                </div>
                                <?php if ($channel['is_general'] == 1): ?>
                                    <div class="card-detail">
                                        <ion-icon name="star-outline"></ion-icon>
                                        <span>General Channel</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <div class="card-stats">
                                    <div class="stat">
                                        <ion-icon name="chatbubble-outline"></ion-icon>
                                        <span><?php echo isset($channelMessageCounts[$channel['id']]) ? $channelMessageCounts[$channel['id']] : 0; ?> messages</span>
                                    </div>
                                </div>
                                <a href="group-chat-admin.php?channel=<?php echo $channel['id']; ?>" class="card-button">
                                    <ion-icon name="enter-outline"></ion-icon>
                                    Join Chat
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="tab-content" id="forums-content">
                <a href="forum-chat-admin.php" class="create-button">
                    <ion-icon name="add-outline"></ion-icon>
                    Create New Forum
                </a>
                
                <div class="card-grid">
                    <?php if (empty($forums)): ?>
                        <p>No forums available yet.</p>
                    <?php else: ?>
                        <?php foreach ($forums as $forum): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title"><?php echo htmlspecialchars($forum['title']); ?></h3>
                                    <div class="card-actions">
                                        <a href="?delete_forum=<?php echo $forum['id']; ?>" onclick="return confirm('Are you sure you want to delete this forum?')" title="Delete Forum">
                                            <ion-icon name="trash-outline"></ion-icon>
                                        </a>
                                    </div>
                                </div>
                                <div class="card-content">
                                    <div class="card-detail">
                                        <ion-icon name="book-outline"></ion-icon>
                                        <span><?php echo htmlspecialchars($forum['course_title']); ?></span>
                                    </div>
                                    <div class="card-detail">
                                        <ion-icon name="calendar-outline"></ion-icon>
                                        <span><?php echo date('F j, Y', strtotime($forum['session_date'])); ?></span>
                                    </div>
                                    <div class="card-detail">
                                        <ion-icon name="time-outline"></ion-icon>
                                        <span><?php echo htmlspecialchars($forum['time_slot']); ?></span>
                                    </div>
                                    <div class="card-detail">
                                        <ion-icon name="people-outline"></ion-icon>
                                        <span>Participants: <?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                                    </div>
                                    
                                    <?php if (!empty($forumParticipants[$forum['id']])): ?>
                                        <div class="participants-list">
                                            <h4>Participants:</h4>
                                            <?php foreach ($forumParticipants[$forum['id']] as $participant): ?>
                                                <div class="participant">
                                                    <span class="participant-name"><?php echo htmlspecialchars($participant['display_name']); ?></span>
                                                    <span class="participant-badge <?php echo $participant['is_admin'] ? 'admin' : ''; ?>">
                                                        <?php echo $participant['is_admin'] ? 'Admin' : 'Mentee'; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer">
                                    <div class="card-stats">
                                        <div class="stat">
                                            <ion-icon name="chatbubble-outline"></ion-icon>
                                            <span><?php echo isset($forumMessageCounts[$forum['id']]) ? $forumMessageCounts[$forum['id']] : 0; ?> messages</span>
                                        </div>
                                    </div>
                                    <a href="forum-chat-admin.php?view=forum&forum_id=<?php echo $forum['id']; ?>" class="card-button">
                                        <ion-icon name="enter-outline"></ion-icon>
                                        Join Forum
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Create Channel Modal -->
    <div class="modal-overlay" id="createChannelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Channel</h3>
                <button class="modal-close" onclick="closeCreateChannelModal()">&times;</button>
            </div>
            
            <form class="modal-form" method="POST" action="">
                <input type="hidden" name="action" value="create_channel">
                
                <div class="form-group">
                    <label for="channel_name">Channel Name</label>
                    <input type="text" id="channel_name" name="channel_name" required>
                </div>
                
                <div class="form-group">
                    <label for="channel_description">Channel Description</label>
                    <textarea id="channel_description" name="channel_description" rows="3"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="cancel-btn" onclick="closeCreateChannelModal()">Cancel</button>
                    <button type="submit" class="submit-btn">Create Channel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Channel Modal -->
    <div class="modal-overlay" id="editChannelModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Channel</h3>
                <button class="modal-close" onclick="closeEditChannelModal()">&times;</button>
            </div>
            
            <form class="modal-form" method="POST" action="">
                <input type="hidden" name="action" value="update_channel">
                <input type="hidden" id="edit_channel_id" name="channel_id">
                
                <div class="form-group">
                    <label for="edit_channel_name">Channel Name</label>
                    <input type="text" id="edit_channel_name" name="channel_name" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_channel_description">Channel Description</label>
                    <textarea id="edit_channel_description" name="channel_description" rows="3"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="cancel-btn" onclick="closeEditChannelModal()">Cancel</button>
                    <button type="submit" class="submit-btn">Update Channel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Tab switching
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.getAttribute('data-tab');
                
                // Remove active class from all tabs and contents
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                document.getElementById(`${tabId}-content`).classList.add('active');
            });
        });
        
        // Modal functions
        function openCreateChannelModal() {
            document.getElementById('createChannelModal').classList.add('active');
        }
        
        function closeCreateChannelModal() {
            document.getElementById('createChannelModal').classList.remove('active');
        }
        
        function openEditChannelModal(id, name, description) {
            document.getElementById('edit_channel_id').value = id;
            document.getElementById('edit_channel_name').value = name;
            document.getElementById('edit_channel_description').value = description;
            document.getElementById('editChannelModal').classList.add('active');
        }
        
        function closeEditChannelModal() {
            document.getElementById('editChannelModal').classList.remove('active');
        }
        
        function confirmLogout() {
            var confirmation = confirm("Are you sure you want to log out?");
            if (confirmation) {
                window.location.href = "logout.php";
            }
            return false;
        }
    </script>
</body>
</html>
