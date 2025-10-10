<?php
session_start();

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
// NOTE: Ensure this file correctly sets up the $conn variable.
require '../connection/db_connection.php'; 

// --- ADMIN ACTION HANDLER: UNBAN USER & HANDLE APPEAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminAction = $_POST['admin_action'] ?? ''; // This is your existing variable name

    // 1. Handle Existing Unban Request
    if ($adminAction === 'unban_user' && isset($_POST['username_to_unban'])) {
        $usernameToUnban = $_POST['username_to_unban'];
        $stmt = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
        $stmt->bind_param("s", $usernameToUnban);
        $stmt->execute();
        $_SESSION['admin_success'] = "User **" . htmlspecialchars($usernameToUnban) . "** has been successfully unbanned.";
        
        header("Location: banned-users.php"); // Refresh the page to see the change
        exit();
    }
    
    // 2. Handle Appeal Actions (Approve/Reject) (NEW LOGIC)
    elseif ($adminAction === 'handle_appeal' && isset($_POST['appeal_id'], $_POST['status'])) {
        $appeal_id = intval($_POST['appeal_id']);
        $status = $_POST['status']; // 'approved' or 'rejected'

        // Get the username associated with the appeal
        $stmt = $conn->prepare("SELECT username FROM ban_appeals WHERE id = ?");
        $stmt->bind_param("i", $appeal_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $appeal_user = $result->fetch_assoc();
        $stmt->close();

        if ($appeal_user) {
            $username_to_unban = $appeal_user['username'];

            // Update the appeal status (NEW: status field in ban_appeals)
            $stmt_appeal = $conn->prepare("UPDATE ban_appeals SET status = ? WHERE id = ?");
            $stmt_appeal->bind_param("si", $status, $appeal_id);
            $stmt_appeal->execute();
            $stmt_appeal->close();

            // If approved, unban the user
            if ($status === 'approved') {
                // Delete the ban entry from the 'banned_users' table
                $stmt_unban = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
                $stmt_unban->bind_param("s", $username_to_unban);
                $stmt_unban->execute();
                $stmt_unban->close();
                $_SESSION['admin_success'] = "Appeal for user **$username_to_unban** approved and user **unbanned**.";
            } else {
                $_SESSION['admin_success'] = "Appeal for user **$username_to_unban** rejected.";
            }
        } else {
            $_SESSION['admin_error'] = "Appeal not found.";
        }

        header("Location: banned-users.php");
        exit();
    }
}

// --- DATA FETCHING: GET ALL BANNED USERS ---
$banned_users = [];
// Assuming your banned_users table has columns like: username, reason, ban_until
$bannedQuery = "SELECT username, reason, ban_until FROM banned_users";
$stmt = $conn->prepare($bannedQuery);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $banned_users[] = $row;
    }
}
$stmt->close();

// --- DATA FETCHING: GET PENDING APPEALS (NEW LOGIC) ---
$appeals = [];
$stmt = $conn->prepare("SELECT id, username, reason, appeal_date FROM ban_appeals WHERE status = 'pending' ORDER BY appeal_date DESC");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $appeals[] = $row;
    }
}
$stmt->close();

// Include your header file here
// include 'header.php'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banned Users | SuperAdmin</title>
     <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="css/banned-users.css"/>
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/navigation.css"/>
    <style>
        /* Minimal CSS for readability, adjust as needed */
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .user-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .user-table th, .user-table td { border: 1px solid #ddd; padding: 12px; text-align: center; }
        .user-table th { background-color: #f2f2f2; color: #333; }
        .action-btn { padding: 8px 12px; border: none; border-radius: 4px; color: white; cursor: pointer; font-weight: bold; }
        .success-message { background: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #c3e6cb; }
        .error-message { background: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #f5c6cb; }
        .unban-btn { padding: 8px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<nav>
    <div class="nav-top">
      <div class="logo">
        <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
        <div class="logo-name">COACH</div>
      </div>

      <div class="admin-profile">
        <img src="<?php echo htmlspecialchars($_SESSION['superadmin_icon']); ?>" alt="SuperAdmin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($_SESSION['superadmin_name']); ?></span>
          <span class="admin-role">SuperAdmin</span>
        </div>
        <a href="profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
          <ion-icon name="create-outline" class="verified-icon"></ion-icon>
        </a>
      </div>
    </div>

    <div class="menu-items">
      <ul class="navLinks">
        <li class="navList">
          <a href="dashboard.php">
            <ion-icon name="home-outline"></ion-icon>
            <span class="links">Home</span>
          </a>
        </li>
        <li class="navList">
            <a href="manage_mentees.php"> <ion-icon name="person-outline"></ion-icon>
              <span class="links">Mentees</span>
            </a>
        </li>
        <li class="navList">
            <a href="manage_mentors.php"> <ion-icon name="people-outline"></ion-icon>
              <span class="links">Mentors</span>
            </a>
        </li>
        <li class="navList">
            <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                <span class="links">Courses</span>
            </a>
        </li>
        <li class="navList">
            <a href="manage_session.php"> <ion-icon name="calendar-outline"></ion-icon>
              <span class="links">Sessions</span>
            </a>
        </li>
        <li class="navList"> 
            <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
              <span class="links">Feedback</span>
            </a>
        </li>
        <li class="navList">
            <a href="channels.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
              <span class="links">Channels</span>
            </a>
        </li>
        <li class="navList">
           <a href="activities.php"> <ion-icon name="clipboard"></ion-icon>
              <span class="links">Activities</span>
            </a>
        </li>
        <li class="navList">
            <a href="resource.php"> <ion-icon name="library-outline"></ion-icon>
              <span class="links">Resource Library</span>
            </a>
        </li>
        <li class="navList">
            <a href="reports.php"><ion-icon name="folder-outline"></ion-icon>
              <span class="links">Reported Posts</span>
            </a>
        </li>
        <li class="navList active">
            <a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon>
              <span class="links">Banned Users</span>
            </a>
        </li>
      </ul>

  <ul class="bottom-link">
  <li class="navList logout-link">
    <a href="#" onclick="confirmLogout(event)">
      <ion-icon name="log-out-outline"></ion-icon>
      <span class="links">Logout</span>
    </a>
  </li>
</ul>
    </div>
  </nav>

        <section class="dashboard">
        <div class="top">
        <ion-icon class="navToggle" name="menu-outline"></ion-icon>
        <img src="../uploads/img/logo.png" alt="Logo"> </div>

<div class="container">
    <h1 style="color: #6a0dad;">Admin Dashboard: User Management</h1>
    
    <?php 
    // Display Success/Error Messages
    if (isset($_SESSION['admin_success'])) {
        echo '<div class="success-message">' . $_SESSION['admin_success'] . '</div>';
        unset($_SESSION['admin_success']);
    }
    if (isset($_SESSION['admin_error'])) {
        echo '<div class="error-message">' . $_SESSION['admin_error'] . '</div>';
        unset($_SESSION['admin_error']);
    }
    ?>
    
    <h2 style="color: #6a0dad;">Currently Banned Users (<?php echo count($banned_users); ?>)</h2>

    <div class="user-management-section">
        <?php if (empty($banned_users)): ?>
            <p style="text-align: center; padding: 20px; color: #555;">No users are currently banned.</p>
        <?php else: ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Reason</th>
                        <th>Ban Until</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banned_users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['reason']); ?></td>
                            <td>
                                <?php 
                                    if ($user['ban_until']) {
                                        echo date("M d, Y, g:i a", strtotime($user['ban_until']));
                                    } else {
                                        echo "Permanent";
                                    }
                                ?>
                            </td>
                            <td>
                                <form action="banned-users.php" method="POST" onsubmit="return confirm('Are you sure you want to unban this user?');">
                                    <input type="hidden" name="username_to_unban" value="<?php echo htmlspecialchars($user['username']); ?>">
                                    <input type="hidden" name="admin_action" value="unban_user">
                                    <button type="submit" class="unban-btn">
                                        <i class="fa fa-unlock"></i> Unban
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    
    <hr style="margin: 40px 0;">

    <h2 style="color: #6a0dad;">Pending Ban Appeals (<?php echo count($appeals); ?>)</h2>

    <div class="user-management-section">
        <?php if (empty($appeals)): ?>
            <p style="text-align: center; padding: 20px; color: #555;">No pending ban appeals at this time. 🎉</p>
        <?php else: ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Reason</th>
                        <th>Appeal Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appeals as $appeal): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($appeal['id']); ?></td>
                            <td><?php echo htmlspecialchars($appeal['username']); ?></td>
                            <td style="max-width: 400px; text-align: left; font-size: 0.9em;">
                                <?php echo nl2br(htmlspecialchars($appeal['reason'])); ?>
                            </td>
                            <td><?php echo date("Y-m-d H:i", strtotime($appeal['appeal_date'])); ?></td>
                            <td>
                                <form action="banned-users.php" method="POST" style="display: inline-block;">
                                    <input type="hidden" name="admin_action" value="handle_appeal">
                                    <input type="hidden" name="appeal_id" value="<?php echo $appeal['id']; ?>">
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="action-btn" style="background-color: #28a745;" 
                                        onclick="return confirm('Are you sure you want to APPROVE this appeal and UNBAN the user?');">
                                        ✅ Approve
                                    </button>
                                </form>
                                <form action="banned-users.php" method="POST" style="display: inline-block; margin-left: 5px;">
                                    <input type="hidden" name="admin_action" value="handle_appeal">
                                    <input type="hidden" name="appeal_id" value="<?php echo $appeal['id']; ?>">
                                    <input type="hidden" name="status" value="rejected">
                                    <button type="submit" class="action-btn" style="background-color: #dc3545;"
                                        onclick="return confirm('Are you sure you want to REJECT this appeal? This will not unban the user.');">
                                        ❌ Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <script src="js/navigation.js"></script>
    <script>
    // Any necessary custom script
    </script>
    </div>

<?php 
// include 'footer.php'; // Include the footer HTML (if you use one)
// Close the database connection
$conn->close();
?>
</body>
</html>