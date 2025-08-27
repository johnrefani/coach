<?php
session_start();
$conn = new mysqli("localhost", "root", "", "coach");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login_mentee.php");
    exit();
}

$username = $_SESSION['username'];
$notifications = [];
$message = "";

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notificationId = $_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE booking_notifications SET is_read = 1 WHERE notification_id = ? AND recipient_username = ?");
    $stmt->bind_param("is", $notificationId, $username);
    $stmt->execute();
}

// Mark all notifications as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE booking_notifications SET is_read = 1 WHERE recipient_username = ? AND recipient_type = 'mentee'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $message = "All notifications marked as read.";
}

// Fetch notifications for this mentee
$stmt = $conn->prepare("
    SELECT n.*, b.* 
    FROM booking_notifications n
    LEFT JOIN session_bookings b ON n.booking_id = b.booking_id
    WHERE n.recipient_username = ? AND n.recipient_type = 'mentee'
    ORDER BY n.created_at DESC
");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

// Get mentee details
$stmt = $conn->prepare("SELECT First_Name, Last_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$menteeData = $result->fetch_assoc();
$firstName = $menteeData['First_Name'];
$menteeIcon = $menteeData['Mentee_Icon'];

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $notification) {
    if ($notification['is_read'] == 0) {
        $unreadCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications</title>
    <link rel="stylesheet" href="css/mentee_navbarstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        
        .container {
            max-width: 800px;
            margin: 80px auto 30px;
            padding: 20px;
        }
        
        h1 {
            color: #6b2a7a;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .mark-all-btn {
            background-color: #6b2a7a;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        
        .mark-all-btn:hover {
            background-color: #5a2366;
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
            border-left: 4px solid #6b2a7a;
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
            color: #6b2a7a;
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
        
        .view-btn {
            background-color: #6b2a7a;
            color: white;
        }
        
        .view-btn:hover {
            background-color: #5a2366;
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
            color: #6b2a7a;
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
            border-left: 4px solid #6b2a7a;
        }
        
        .notification-badge {
          position: absolute;
          top: -5px;
          right: -5px;
          background-color: #dc3545;
          color: white;
          border-radius: 50%;
          width: 18px;
          height: 18px;
          font-size: 12px;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        
        .notification-icon {
          position: relative;
          margin-left: 15px;
        }
        
        .nav-profile {
          display: flex;
          align-items: center;
        }
    </style>
</head>
<body>
    <section class="background" id="home">
        <nav class="navbar">
            <div class="logo">
                <img src="img/LogoCoach.png" alt="Logo">
                <span>COACH</span>
            </div>

            <div class="nav-center">
                <ul class="nav_items" id="nav_links">
                    <li><a href="CoachMenteeHome.php">Home</a></li>
                    <li><a href="CoachMenteeHome.php#courses">Courses</a></li>
                    <li><a href="CoachMenteeHome.php#resourcelibrary">Resource Library</a></li>
                    <li><a href="CoachMenteeHome.php#mentors">Activities</a></li>
                    <li><a href="forum-chat.php">Sessions</a></li>
                    <li><a href="group-chat.php">Forums</a></li>
                </ul>
            </div>

            <div class="nav-profile">
                <!-- Notification Icon -->
                <a href="mentee_notifications.php" class="notification-icon">
                    <ion-icon name="notifications-outline" style="font-size: 24px;"></ion-icon>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-badge"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a>
                
                <a href="#" id="profile-icon">
                    <?php if (!empty($menteeIcon)): ?>
                        <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
                    <?php else: ?>
                        <ion-icon name="person-circle-outline" style="font-size: 35px;"></ion-icon>
                    <?php endif; ?>
                </a>
            </div>

            <div class="sub-menu-wrap hide" id="profile-menu">
                <div class="sub-menu">
                    <div class="user-info">
                        <div class="user-icon">
                            <?php if (!empty($menteeIcon)): ?>
                                <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
                            <?php else: ?>
                                <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
                            <?php endif; ?>
                        </div>
                        <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
                    </div>
                    <ul class="sub-menu-items">
                        <li><a href="profile.php">Profile</a></li>
                        <li><a href="mentee_bookings.php">My Bookings</a></li>
                        <li><a href="#settings">Settings</a></li>
                        <li><a href="#" onclick="confirmLogout()">Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    </section>

    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?= $message ?></div>
        <?php endif; ?>
        
        <div class="notification-header">
            <h1>My Notifications</h1>
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
                <p class="empty-text">You don't have any notifications at the moment.</p>
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
                        
                        <?php if (isset($notification['course_title'])): ?>
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
                                    <ion-icon name="checkmark-circle-outline"></ion-icon>
                                    <span><?php echo ucfirst(htmlspecialchars($notification['status'])); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="notification-actions">
                            <?php if (isset($notification['booking_id']) && $notification['status'] === 'approved' && $notification['forum_id']): ?>
                                <a href="forum-chat.php?view=forum&forum_id=<?php echo $notification['forum_id']; ?>" class="action-btn view-btn">
                                    <ion-icon name="chatbubbles-outline"></ion-icon> Join Session
                                </a>
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

    <script src="mentee.js"></script>
    <script>
        function confirmLogout() {
            var confirmation = confirm("Are you sure you want to log out?");
            if (confirmation) {
                window.location.href = "logout.php";
            } else {
                return false;
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const profileIcon = document.getElementById('profile-icon');
            const profileMenu = document.getElementById('profile-menu');
            
            profileIcon.addEventListener('click', function(e) {
                e.preventDefault();
                profileMenu.classList.toggle('hide');
            });
            
            document.addEventListener('click', function(e) {
                if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target)) {
                    profileMenu.classList.add('hide');
                }
            });
        });
    </script>
</body>
</html>