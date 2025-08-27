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
$message = "";

if (isset($_GET['action'], $_GET['id'])) {
    $action = $_GET['action'];
    $bookingId = $_GET['id'];
    
    // Get booking details
    $stmt = $conn->prepare("SELECT * FROM session_bookings WHERE booking_id = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $booking = $result->fetch_assoc();
        $menteeUsername = $booking['mentee_username'];
        $courseTitle = $booking['course_title'];
        $sessionDate = $booking['session_date'];
        $timeSlot = $booking['time_slot'];
        
        // Get mentee name
        $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM mentee_profiles WHERE Username = ?");
        $stmt->bind_param("s", $menteeUsername);
        $stmt->execute();
        $menteeResult = $stmt->get_result();
        $menteeData = $menteeResult->fetch_assoc();
        $menteeName = $menteeData['First_Name'] . ' ' . $menteeData['Last_Name'];
        
        // Get admin name
        $stmt = $conn->prepare("SELECT Admin_Name FROM admins WHERE Admin_Username = ?");
        $stmt->bind_param("s", $adminUsername);
        $stmt->execute();
        $adminResult = $stmt->get_result();
        $adminData = $adminResult->fetch_assoc();
        $adminName = $adminData['Admin_Name'];
        
        if ($action === 'approve') {
            // Check if there's a forum for this session
            $stmt = $conn->prepare("SELECT id FROM forum_chats WHERE course_title = ? AND session_date = ? AND time_slot = ?");
            $stmt->bind_param("sss", $courseTitle, $sessionDate, $timeSlot);
            $stmt->execute();
            $forumResult = $stmt->get_result();
            
            if ($forumResult->num_rows > 0) {
                $forumData = $forumResult->fetch_assoc();
                $forumId = $forumData['id'];
                
                // Update booking status and link to forum
                $stmt = $conn->prepare("UPDATE session_bookings SET status = 'approved', forum_id = ? WHERE booking_id = ?");
                $stmt->bind_param("ii", $forumId, $bookingId);
                
                if ($stmt->execute()) {
                    // Add mentee to forum participants
                    $stmt = $conn->prepare("INSERT IGNORE INTO forum_participants (forum_id, username) VALUES (?, ?)");
                    $stmt->bind_param("is", $forumId, $menteeUsername);
                    $stmt->execute();
                    
                    // Create notification for mentee
                    $notificationMsg = "Your booking for $courseTitle on $sessionDate at $timeSlot has been approved by $adminName.";
                    $stmt = $conn->prepare("INSERT INTO booking_notifications (booking_id, recipient_type, recipient_username, message) VALUES (?, 'mentee', ?, ?)");
                    $stmt->bind_param("iss", $bookingId, $menteeUsername, $notificationMsg);
                    $stmt->execute();
                    
                    $message = "Booking approved successfully. The mentee has been added to the session forum.";
                } else {
                    $message = "Error approving booking: " . $stmt->error;
                }
            } else {
                // Create a new forum for this session
                $forumTitle = "$courseTitle Session";
                $stmt = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $forumTitle, $courseTitle, $sessionDate, $timeSlot);
                
                if ($stmt->execute()) {
                    $forumId = $conn->insert_id;
                    
                    // Update booking status and link to forum
                    $stmt = $conn->prepare("UPDATE session_bookings SET status = 'approved', forum_id = ? WHERE booking_id = ?");
                    $stmt->bind_param("ii", $forumId, $bookingId);
                    
                    if ($stmt->execute()) {
                        // Add mentee to forum participants
                        $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
                        $stmt->bind_param("is", $forumId, $menteeUsername);
                        $stmt->execute();
                        
                        // Create notification for mentee
                        $notificationMsg = "Your booking for $courseTitle on $sessionDate at $timeSlot has been approved by $adminName.";
                        $stmt = $conn->prepare("INSERT INTO booking_notifications (booking_id, recipient_type, recipient_username, message) VALUES (?, 'mentee', ?, ?)");
                        $stmt->bind_param("iss", $bookingId, $menteeUsername, $notificationMsg);
                        $stmt->execute();
                        
                        $message = "Booking approved successfully. A new forum has been created for this session.";
                    } else {
                        $message = "Error updating booking: " . $stmt->error;
                    }
                } else {
                    $message = "Error creating forum: " . $stmt->error;
                }
            }
        } elseif ($action === 'reject') {
            // Update booking status
            $stmt = $conn->prepare("UPDATE session_bookings SET status = 'rejected' WHERE booking_id = ?");
            $stmt->bind_param("i", $bookingId);
            
            if ($stmt->execute()) {
                // Create notification for mentee
                $notificationMsg = "Your booking for $courseTitle on $sessionDate at $timeSlot has been rejected by $adminName.";
                $stmt = $conn->prepare("INSERT INTO booking_notifications (booking_id, recipient_type, recipient_username, message) VALUES (?, 'mentee', ?, ?)");
                $stmt->bind_param("iss", $bookingId, $menteeUsername, $notificationMsg);
                $stmt->execute();
                
                $message = "Booking rejected successfully.";
            } else {
                $message = "Error rejecting booking: " . $stmt->error;
            }
        } else {
            $message = "Invalid action.";
        }
    } else {
        $message = "Booking not found.";
    }
} else {
    $message = "Missing parameters.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Booking Action</title>
    <style>
        .action-container {
            max-width: 500px;
            margin: 100px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        h2 {
            color: #6a2c70;
            margin-bottom: 20px;
        }
        
        .message {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            background-color: #f9f3fc;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6a2c70;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background-color: #5a2366;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="action-container">
        <h2>Booking Action</h2>
        <div class="message">
            <p><?php echo $message; ?></p>
        </div>
        <a href="admin_notifications.php" class="btn">Back to Notifications</a>
    </div>
</body>
</html>