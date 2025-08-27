<?php
session_start();
$conn = new mysqli("localhost", "root", "", "coach");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if admin is logged in
if (!isset($_SESSION['admin_username'])) {
    header("Location: login_admin.php");
    exit();
}

$adminUsername = $_SESSION['admin_username'];
$notifications = [];
$message = "";

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notificationId = $_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE booking_notifications SET is_read = 1 WHERE notification_id = ? AND recipient_username = ?");
    $stmt->bind_param("is", $notificationId, $adminUsername);
    $stmt->execute();
}

// Mark all notifications as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE booking_notifications SET is_read = 1 WHERE recipient_username = ? AND recipient_type = 'admin'");
    $stmt->bind_param("s", $adminUsername);
    $stmt->execute();
    $message = "All notifications marked as read.";
}

// Fetch notifications for this admin
$stmt = $conn->prepare("
    SELECT n.*, b.* 
    FROM booking_notifications n
    JOIN session_bookings b ON n.booking_id = b.booking_id
    WHERE n.recipient_username = ? AND n.recipient_type = 'admin'
    ORDER BY n.created_at DESC
");
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

// Get admin's name for display
$stmt = $conn->prepare("SELECT Admin_Name, Admin_Icon FROM admins WHERE Admin_Username = ?");
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $adminName = $row['Admin_Name'];
    $adminIcon = $row['Admin_Icon'];
} else {
    $adminName = $adminUsername;
    $adminIcon = "";
}

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $notification) {
    if ($notification['is_read'] == 0) {
        $unreadCount++;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Admin Notifications</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        .container {
            padding: 20px;
            margin-top: 60px;
        }
        
        h2 {
            color: #6a2c70;
            margin-bottom: 20px;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .mark-all-btn {
            background-color: #6a2c70;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        
        .mark-all-btn:hover {
            background-color: #5a2460;
        }
        
        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .notification-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 15px;
            transition: transform 0.2s;
            border-left: 4px solid #6a2c70;
        }
        
        .notification-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .notification-card.unread {
            background-color: #f9f3fc;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .notification-time {
            color: #777;
            font-size: 12px;
        }
        
        .notification-message {
            margin-bottom: 15px;
        }
        
        .notification-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .booking-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .booking-detail {
            background-color: #f0f0f0;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .booking-detail ion-icon {
            color: #6a2c70;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .approve-btn {
            background-color: #28a745;
            color: white;
        }
        
        .approve-btn:hover {
            background-color: #218838;
        }
        
        .reject-btn {
            background-color: #dc3545;
            color: white;
        }
        
        .reject-btn:hover {
            background-color: #c82333;
        }
        
        .mark-read-btn {
            background-color: #6c757d;
            color: white;
        }
        
        .mark-read-btn:hover {
            background-color: #5a6268;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .empty-icon {
            font-size: 60px;
            color: #6a2c70;
            margin-bottom: 20px;
        }
        
        .empty-text {
            color: #555;
            margin-bottom: 30px;
        }
        
        .message {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 15px 0;
            border-left: 4px solid #6a2c70;
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
      <img src="<?php echo !empty($adminIcon) ? htmlspecialchars($adminIcon) : 'img/default-admin.png'; ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($adminName); ?>
        </span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="CoachAdminPFP.php?username=<?= urlencode($adminUsername) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdmin.php'">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminCourses.php'">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Courses</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminMentees.php'">
          <ion-icon name="person-outline"></ion-icon>
          <span class="links">Mentees</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminMentors.php'">
          <ion-icon name="people-outline"></ion-icon>
          <span class="links">Mentors</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminAdmins.php'">
          <ion-icon name="lock-closed-outline"></ion-icon>
          <span class="links">Admins</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminSession.php'">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='admin-sessions.php'">
          <ion-icon name="chatbubbles-outline"></ion-icon>
          <span class="links">Channels</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminResource.php'">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
      <li class="navList active">
        <a href="#" onclick="window.location='admin_notifications.php'">
          <ion-icon name="notifications-outline"></ion-icon>
          <span class="links">Notifications</span>
          <?php if ($unreadCount > 0): ?>
            <span class="notification-badge"><?php echo $unreadCount; ?></span>
          <?php endif; ?>
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
        <?php if ($message): ?>
            <div class="message"><?= $message ?></div>
        <?php endif; ?>
        
        <div class="notification-header">
            <h2>Booking Notifications</h2>
            <?php if (!empty($notifications)): ?>
                <a href="?mark_all_read=1" class="mark-all-btn">Mark All as Read</a>
            <?php endif; ?>
        </div>
        
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <ion-icon name="notifications-outline"></ion-icon>
                </div>
                <h3>No Notifications</h3>
                <p class="empty-text">You don't have any booking notifications at the moment.</p>
            </div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-card <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                        <div class="notification-header">
                            <div class="notification-time">
                                <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?>
                            </div>
                            <?php if (!$notification['is_read']): ?>
                                <span class="unread-indicator">New</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notification-message">
                            <?php echo htmlspecialchars($notification['message']); ?>
                        </div>
                        
                        <div class="booking-details">
                            <div class="booking-detail">
                                <ion-icon name="book-outline"></ion-icon>
                                <span><?php echo htmlspecialchars($notification['course_title']); ?></span>
                            </div>
                            <div class="booking-detail">
                                <ion-icon name="calendar-outline"></ion-icon>
                                <span><?php echo htmlspecialchars($notification['session_date']); ?></span>
                            </div>
                            <div class="booking-detail">
                                <ion-icon name="time-outline"></ion-icon>
                                <span><?php echo htmlspecialchars($notification['time_slot']); ?></span>
                            </div>
                            <div class="booking-detail">
                                <ion-icon name="person-outline"></ion-icon>
                                <span><?php echo htmlspecialchars($notification['mentee_username']); ?></span>
                            </div>
                        </div>
                        
                        <div class="notification-actions">
                            <?php if ($notification['status'] === 'pending'): ?>
                                <div>
                                    <a href="booking_action.php?action=approve&id=<?php echo $notification['booking_id']; ?>" class="action-btn approve-btn">
                                        <ion-icon name="checkmark-outline"></ion-icon> Approve
                                    </a>
                                    <a href="booking_action.php?action=reject&id=<?php echo $notification['booking_id']; ?>" class="action-btn reject-btn">
                                        <ion-icon name="close-outline"></ion-icon> Reject
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="booking-status">
                                    Status: <strong><?php echo ucfirst(htmlspecialchars($notification['status'])); ?></strong>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$notification['is_read']): ?>
                                <a href="?mark_read=<?php echo $notification['notification_id']; ?>" class="action-btn mark-read-btn">
                                    <ion-icon name="checkmark-done-outline"></ion-icon> Mark as Read
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
        window.location.href = "logout.php";
    } else {
        return false;
    }
}
</script>
</body>
</html>