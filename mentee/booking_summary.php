<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    // If not a Mentee, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

$message = "";
$bookingComplete = false;
$username = $_SESSION['username'];

// Get mentee details
$stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE Username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$menteeData = $result->fetch_assoc();
$menteeName = $menteeData['first_name'] . ' ' . $menteeData['last_name'];

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['course_title'], $_GET['selected_date'], $_GET['time_slot'])) {
    $courseTitle = $_GET['course_title'];
    $sessionDate = $_GET['selected_date'];
    $timeSlot = $_GET['time_slot'];
    $notes = $_GET['notes'] ?? null;
    
    // Check if this mentee already has a booking for this session
    $stmt = $conn->prepare("SELECT * FROM session_bookings WHERE mentee_username = ? AND course_title = ? AND session_date = ? AND time_slot = ?");
    $stmt->bind_param("ssss", $username, $courseTitle, $sessionDate, $timeSlot);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $message = "You already have a booking for this session.";
    } else {
        // Insert the booking with automatic approval
        $stmt = $conn->prepare("INSERT INTO session_bookings (mentee_username, course_title, session_date, time_slot, status, notes) VALUES (?, ?, ?, ?, 'approved', ?)");
        $stmt->bind_param("sssss", $username, $courseTitle, $sessionDate, $timeSlot, $notes);
        
        if ($stmt->execute()) {
            $bookingId = $conn->insert_id;
            $bookingComplete = true;
            $message = "Your booking has been confirmed successfully!";
            
            // Create notification for all admins
            $notificationMsg = "$menteeName has booked a $courseTitle session on $sessionDate at $timeSlot";
            
            // Get all admin usernames
            $adminResult = $conn->query("SELECT Admin_Username FROM admins");
            while ($admin = $adminResult->fetch_assoc()) {
                $adminUsername = $admin['Admin_Username'];
                $stmt = $conn->prepare("INSERT INTO booking_notifications (booking_id, recipient_type, recipient_username, message) VALUES (?, 'admin', ?, ?)");
                $stmt->bind_param("iss", $bookingId, $adminUsername, $notificationMsg);
                $stmt->execute();
            }
            
            // Check if a forum already exists for this session
            $forumCheck = $conn->prepare("SELECT id FROM forum_chats WHERE course_title = ? AND session_date = ? AND time_slot = ?");
            $forumCheck->bind_param("sss", $courseTitle, $sessionDate, $timeSlot);
            $forumCheck->execute();
            $forumResult = $forumCheck->get_result();
            
            if ($forumResult->num_rows === 0) {
                // Create a forum for this session
                $forumTitle = "$courseTitle Session";
                $createForum = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot) VALUES (?, ?, ?, ?)");
                $createForum->bind_param("ssss", $forumTitle, $courseTitle, $sessionDate, $timeSlot);
                $createForum->execute();
                $forumId = $conn->insert_id;
                
                // Add the mentee to the forum participants
                $addParticipant = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
                $addParticipant->bind_param("is", $forumId, $username);
                $addParticipant->execute();
                
                // Add to session_participants with active status
                $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'active')");
                $insertStatus->bind_param("is", $forumId, $username);
                $insertStatus->execute();
                
                // Update the booking with the forum ID
                $updateBooking = $conn->prepare("UPDATE session_bookings SET forum_id = ? WHERE booking_id = ?");
                $updateBooking->bind_param("ii", $forumId, $bookingId);
                $updateBooking->execute();
            } else {
                // Forum already exists, add the mentee to it
                $forumId = $forumResult->fetch_assoc()['id'];
                
                // Check if mentee is already in the forum
                $checkParticipant = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
                $checkParticipant->bind_param("is", $forumId, $username);
                $checkParticipant->execute();
                $participantResult = $checkParticipant->get_result();
                
                if ($participantResult->num_rows === 0) {
                    // Add the mentee to the forum participants
                    $addParticipant = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
                    $addParticipant->bind_param("is", $forumId, $username);
                    $addParticipant->execute();
                    
                    // Add to session_participants with active status
                    $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'active')");
                    $insertStatus->bind_param("is", $forumId, $username);
                    $insertStatus->execute();
                }
                
                // Update the booking with the forum ID
                $updateBooking = $conn->prepare("UPDATE session_bookings SET forum_id = ? WHERE booking_id = ?");
                $updateBooking->bind_param("ii", $forumId, $bookingId);
                $updateBooking->execute();
            }
        } else {
            $message = "Error: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Summary</title>
    <link rel="stylesheet" href="mentee_sessions.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <style>
        .booking-summary {
            max-width: 600px;
            margin: 50px auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .booking-status {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            background-color: #f9f3fc;
        }
        
        .booking-details {
            margin: 25px 0;
            text-align: left;
            padding: 20px;
            background: #f9f3fc;
            border-radius: 8px;
        }
        
        .booking-details p {
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
        }
        
        .booking-details p span:first-child {
            font-weight: bold;
            color: #6b2a7a;
        }
        
        .buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .primary-btn {
            background-color: #6b2a7a;
            color: white;
        }
        
        .secondary-btn {
            background-color: #e0e0e0;
            color: #333;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .success-icon {
            font-size: 60px;
            color: #6b2a7a;
            margin-bottom: 20px;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <div class="booking-summary">
        <?php if ($bookingComplete): ?>
            <div class="success-icon pulse">✓</div>
            <h2>Booking Confirmed!</h2>
            <div class="booking-status">
                <p><?php echo $message; ?></p>
            </div>
            
            <div class="booking-details">
                <h3>Booking Details</h3>
                <p><span>Course:</span> <span><?php echo htmlspecialchars($_GET['course_title']); ?></span></p>
                <p><span>Date:</span> <span><?php echo htmlspecialchars($_GET['selected_date']); ?></span></p>
                <p><span>Time:</span> <span><?php echo htmlspecialchars($_GET['time_slot']); ?></span></p>
                <p><span>Status:</span> <span>Confirmed</span></p>
                
                <?php if (!empty($_GET['notes'])): ?>
                    <p><span>Notes:</span> <span><?php echo htmlspecialchars($_GET['notes']); ?></span></p>
                <?php endif; ?>
            </div>
            
            <p>You can now access this session from your bookings page.</p>
        <?php else: ?>
            <h2>Booking Error</h2>
            <div class="booking-status">
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>
        
        <div class="buttons">
            <a href="CoachMenteeHome.php" class="btn secondary-btn">Back to Home</a>
            <a href="mentee_bookings.php" class="btn primary-btn">View My Bookings</a>
        </div>
    </div>
</body>
</html>
