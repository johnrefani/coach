<?php
session_start();

// *** 1. PHP/DB CONNECTION & AUTHENTICATION ***

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php'; 

// Fetch admin info for the navbar (required by the dashboard structure)
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, first_name, last_name, icon FROM users WHERE user_id = ? AND user_type = 'Admin'");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  // Set session variables for use in the HTML
  $_SESSION['username'] = $row['username'];
  $_SESSION['user_full_name'] = $row['first_name'] . ' ' . $row['last_name'];
  
  if (isset($row['icon']) && !empty($row['icon'])) {
    $_SESSION['user_icon'] = $row['icon'];
  } else {
    // Default image path relative to the admin/ directory
    $_SESSION['user_icon'] = "../uploads/img/default_pfp.png"; 
  }
} 
$stmt->close();


// --- ADMIN ACTION HANDLER: UNBAN USER & HANDLE APPEAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminAction = $_POST['admin_action'] ?? ''; // Existing variable name

    // 1. Handle Existing Unban Request
    if ($adminAction === 'unban_user' && isset($_POST['username_to_unban'])) {
        $usernameToUnban = $_POST['username_to_unban'];
        $stmt = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
        $stmt->bind_param("s", $usernameToUnban);
        $stmt->execute();
        $_SESSION['admin_success'] = "User **" . htmlspecialchars($usernameToUnban) . "** has been successfully unbanned.";
        
        header("Location: banned-users.php"); 
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

            // Update the appeal status
            $stmt_appeal = $conn->prepare("UPDATE ban_appeals SET status = ? WHERE id = ?");
            $stmt_appeal->bind_param("si", $status, $appeal_id);
            $stmt_appeal->execute();
            $stmt_appeal->close();

            // If approved, unban the user
            if ($status === 'approved') {
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

// --- DATA FETCHING ---

// Fetch all currently banned users
$banned_users = [];
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

// Fetch all pending appeals
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin: Banned Users & Appeals</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <link href='../css/admin_dashboard.css' rel='stylesheet'>
    <link href='https://unpkg.com/boxicons@2.1.1/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

    <style>
        /* *** FIX: Custom CSS for this page's content tables *** */
        .home-content {
            position: relative;
            min-height: 100vh;
            left: 250px;
            width: calc(100% - 250px);
            transition: all 0.3s ease;
            padding: 20px;
            box-sizing: border-box;
            padding-top: 80px; /* Space for the header */
        }
        .sidebar.close ~ .home-content {
            left: 78px;
            width: calc(100% - 78px);
        }
        .container {
            max-width: 100%; /* Changed from fixed 1200px to fit screen */
            margin: 0;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1, h2 { color: #6a0dad; margin-bottom: 20px; }
        .user-management-section {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        .user-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 15px; 
            font-size: 0.95em;
        }
        .user-table th, .user-table td { 
            border: 1px solid #ddd; 
            padding: 12px; 
            text-align: center; 
        }
        .user-table th { 
            background-color: #f2f2f2; 
            color: #333; 
            white-space: nowrap;
        }
        .action-btn { 
            padding: 8px 12px; 
            border: none; 
            border-radius: 4px; 
            color: white; 
            cursor: pointer; 
            font-weight: bold;
            font-size: 0.85em;
            margin: 2px;
        }
        .unban-btn {
            background-color: #007bff;
        }
        .success-message { background: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #c3e6cb; }
        .error-message { background: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<nav class="sidebar close">
    <header>
        <div class="image-text">
            <span class="image">
                <img src="../img/logo.png" alt="Logo">
            </span>
            <div class="text logo-text">
                <span class="name">CoachHub</span>
                <span class="profession">Admin Panel</span>
            </div>
        </div>
        <i class='bx bx-chevron-right toggle'></i>
    </header>

    <div class="menu-bar">
        <div class="menu">
            <ul class="menu-links">
                <li class="nav-link">
                    <a href="dashboard.php">
                        <i class='bx bx-home-alt icon'></i>
                        <span class="text nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-link">
                    <a href="user-management.php">
                        <i class='bx bx-user icon'></i>
                        <span class="text nav-text">User Management</span>
                    </a>
                </li>
                <li class="nav-link active">
                    <a href="banned-users.php">
                        <i class='bx bx-block icon'></i>
                        <span class="text nav-text">Banned Users</span>
                    </a>
                </li>
                </ul>
        </div>

        <div class="bottom-content">
            <li class="mode">
                <a href="#" id="logoutButton">
                    <i class='bx bx-log-out icon'></i>
                    <span class="text nav-text">Logout</span>
                </a>
            </li>
            <li class="mode">
                <div class="user-info">
                    <img src="<?php echo htmlspecialchars($_SESSION['user_icon'] ?? '../uploads/img/default_pfp.png'); ?>" alt="Admin Icon" class="user-icon">
                    <div class="text nav-text user-details">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                        <span class="user-role">Administrator</span>
                    </div>
                </div>
            </li>
        </div>
    </div>
</nav>

<section class="home-content">
    <div class="container">
        <h1>Admin Dashboard: User Management</h1>
        
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
                                <td style="text-align: left;"><?php echo htmlspecialchars($user['reason']); ?></td>
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
                                        <button type="submit" class="action-btn unban-btn">
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
    </div>
</section>

<div id="logoutDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3>Confirm Logout</h3>
        <p>Are you sure you want to log out?</p>
        <div class="dialog-buttons">
            <button id="cancelLogout" type="button">Cancel</button>
            <button id="confirmLogoutBtn" type="button" onclick="window.location.href = '../logout.php';">Logout</button>
        </div>
    </div>
</div>

<script src="js/navigation.js"></script> <script>
    // Toggle sidebar function
    const body = document.querySelector('body');
    const sidebar = document.querySelector('nav');
    const toggle = document.querySelector(".toggle");
    const logoutButton = document.getElementById("logoutButton");
    const logoutDialog = document.getElementById("logoutDialog");
    const cancelLogout = document.getElementById("cancelLogout");

    toggle.addEventListener('click', () => {
        sidebar.classList.toggle("close");
    });
    
    // Logout dialog functionality
    logoutButton.addEventListener('click', (e) => {
        e.preventDefault();
        logoutDialog.style.display = 'block';
    });

    cancelLogout.addEventListener('click', () => {
        logoutDialog.style.display = 'none';
    });

    // Close the dialog if the user clicks outside of it
    window.addEventListener('click', (e) => {
        if (e.target == logoutDialog) {
            logoutDialog.style.display = 'none';
        }
    });
</script>

<?php 
$conn->close();
?>
</body>
</html>