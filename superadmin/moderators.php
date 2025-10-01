<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();


// Load SendGrid and environment variables
require __DIR__ . '/../vendor/autoload.php';
use SendGrid\Mail\Mail;

// Load environment variables using phpdotenv
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // Log this error if the .env file is missing/unreadable
    error_log("Dotenv failed to load in moderators.php: " . $e->getMessage());
}

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

require '../connection/db_connection.php';

// Load admin data for sidebar
$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Admin';

$success_message = '';
$error_message = '';

// Function to send welcome email (kept from original logic)
function sendWelcomeEmail($email, $username, $password) {
    $apiKey = getenv('SENDGRID_API_KEY');
    if (!$apiKey) {
        error_log("SENDGRID_API_KEY is not set.");
        return false;
    }

    $mail = new Mail();
    $mail->setFrom("your_email@example.com", "Admin Team");
    $mail->setSubject("Welcome to the Admin Panel!");
    $mail->addTo($email, "Admin User");
    
    // HTML Content for a better-looking email
    $htmlContent = "
        <p>Hello,</p>
        <p>You have been registered as an Admin/Moderator. Here are your credentials:</p>
        <p><strong>Username:</strong> {$username}</p>
        <p><strong>Temporary Password:</strong> {$password}</p>
        <p>Please log in and change your password immediately.</p>
        <p>Login Page: <a href='" . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'] . "/login.php'>Login Here</a></p>
        <br>
        <p>Thank you,</p>
        <p>The System Administrator</p>
    ";

    $mail->addContent("text/html", $htmlContent);

    $sendgrid = new \SendGrid($apiKey);
    try {
        $response = $sendgrid->send($mail);
        // Check for non-2xx status codes
        if ($response->statusCode() >= 300) {
            error_log("SendGrid failed with status: " . $response->statusCode());
            error_log("Response body: " . $response->body());
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log("Caught exception: " . $e->getMessage());
        return false;
    }
}


// Handle Create
if (isset($_POST['create'])) {
    $username_user = $_POST['username'];
    $first_name = $_POST['first_name']; 
    $last_name = $_POST['last_name']; 
    $email = $_POST['email'];
    $password = $_POST['password'];
    $user_type = 'Admin';
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Check for existing username or email
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $check_stmt->bind_param("ss", $username_user, $email);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        $error_message = "Username or Email already exists.";
    } else {
        // FIXED: Correct parameter order matching the SQL columns
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, user_type, first_name, last_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Approved')");
        $stmt->bind_param("ssssss", $username_user, $hashed_password, $email, $user_type, $first_name, $last_name);

        if ($stmt->execute()) {
            if (sendWelcomeEmail($email, $username_user, $password)) {
                 $success_message = "Admin/Moderator created successfully and welcome email sent!";
            } else {
                 $success_message = "Admin/Moderator created successfully, but failed to send welcome email.";
            }
        } else {
            $error_message = "Error creating admin/moderator: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Handle Update
if (isset($_POST['update'])) {
    $user_id = $_POST['user_id'];
    $first_name = $_POST['first_name']; 
    $last_name = $_POST['last_name']; 
    $email = $_POST['email'];
    $username_user = $_POST['username']; // Included username for possible update
    $new_password = $_POST['new_password'];

    // Check for unique username/email excluding current user
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
    $check_stmt->bind_param("ssi", $username_user, $email, $user_id);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        $error_message = "Username or Email already in use by another user.";
    } else {
        $sql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, username = ?";
        $params = "ssss";
        $values = [$first_name, $last_name, $email, $username_user];

        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password = ?";
            $params .= "s";
            $values[] = $hashed_password;
        }

        $sql .= " WHERE user_id = ?";
        $params .= "i";
        $values[] = $user_id;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($params, ...$values);

        if ($stmt->execute()) {
            $success_message = "Moderator details updated successfully!";
        } else {
            $error_message = "Error updating moderator details: " . $stmt->error;
        }
        $stmt->close();
    }
}


// Handle Delete
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $success_message = "Moderator deleted successfully!";
    } else {
        $error_message = "Error deleting moderator: " . $stmt->error;
    }
    $stmt->close();
    // Redirect to clean the URL
    header("Location: moderators.php?msg=" . urlencode($success_message ?: $error_message));
    exit();
}

// Fetch all Admin/Moderator users
$moderators = [];
$sql = "SELECT user_id, first_name, last_name, username, email, DATE(created_at) as created_at 
        FROM users 
        WHERE user_type = 'Admin' 
        ORDER BY created_at DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $moderators[] = $row;
    }
}

// Handle messages from redirection
if (isset($_GET['msg'])) {
    if (strpos($_GET['msg'], 'successfully') !== false) {
        $success_message = htmlspecialchars($_GET['msg']);
    } else {
        $error_message = htmlspecialchars($_GET['msg']);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Moderators | SuperAdmin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <style>
        /* General Setup */
        :root {
            --primary-color: #562b63;
            --secondary-color: #7a4a87;
            --background-color: #F4F7FC; /* Light Blue/Gray */
            --card-background: #FFFFFF;
            --text-color: #333333;
            --text-light: #777777;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--background-color);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling (Matching previous files) */
        .sidebar {
            width: 250px;
            background: var(--card-background);
            padding: 20px;
            box-shadow: var(--shadow);
            position: fixed;
            height: 100%;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .logo-details {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .logo-details ion-icon {
            font-size: 30px;
            color: var(--primary-color);
            margin-right: 10px;
        }

        .logo-details .logo_name {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-color);
        }

        .nav-links li {
            list-style: none;
            margin-bottom: 10px;
        }

        .nav-links li a {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            text-decoration: none;
            color: var(--text-color);
            font-weight: 600;
            border-radius: var(--border-radius);
            transition: all 0.3s ease;
        }

        .nav-links li a ion-icon {
            margin-right: 15px;
            font-size: 20px;
        }

        .nav-links li a:hover,
        .nav-links li a.active {
            background: var(--primary-color);
            color: var(--card-background);
            box-shadow: 0 4px 8px rgba(74, 144, 226, 0.3);
        }

        .admin-profile {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background: #eef5ff;
            border-radius: var(--border-radius);
        }

        .admin-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }

        .admin-profile .admin-name {
            font-size: 16px;
            font-weight: 600;
        }

        .logout-btn {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s;
        }

        .logout-btn:hover {
            color: var(--primary-color);
        }

        /* Home Section */
        .home-section {
            width: calc(100% - 250px);
            margin-left: 250px;
            padding: 30px;
            transition: all 0.3s ease;
        }

        .home-content {
            padding: 20px;
            background: var(--card-background);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        h2 {
            font-size: 24px;
            color: var(--text-color);
            margin-bottom: 20px;
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 10px;
            margin-top: 30px;
        }

        /* Header Bar (Search & Action) */
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            position: relative;
            flex-grow: 1;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s;
        }

        .search-box ion-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 20px;
        }

        .action-button {
            padding: 10px 20px;
            background: var(--primary-color);
            color: var(--card-background);
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s, transform 0.1s;
        }

        .action-button:hover {
            background: #3a81d4;
            transform: translateY(-1px);
        }

        /* Table Styling (Responsive & Card-like) */
        .table-container {
            overflow-x: auto;
            max-width: 100%;
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px; /* Space between rows */
        }

        .data-table th,
        .data-table td {
            padding: 15px;
            text-align: left;
        }

        .data-table th {
            background-color: var(--primary-color);
            color: var(--card-background);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 14px;
            border: none;
        }

        .data-table th:first-child {
            border-top-left-radius: var(--border-radius);
            border-bottom-left-radius: var(--border-radius);
        }

        .data-table th:last-child {
            border-top-right-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
        }

        .data-table tr.data-row td {
            background-color: var(--card-background);
            border: 1px solid #eee;
            border-left: none;
            border-right: none;
            font-size: 15px;
            color: var(--text-color);
            transition: box-shadow 0.3s;
        }

        .data-table tr.data-row:hover td {
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .data-table tr.data-row td:first-child {
            border-left: 1px solid #eee;
            border-top-left-radius: var(--border-radius);
            border-bottom-left-radius: var(--border-radius);
        }

        .data-table tr.data-row td:last-child {
            border-right: 1px solid #eee;
            border-top-right-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
        }

        /* Action Buttons in Table */
        .action-btns button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            margin-right: 10px;
            transition: color 0.3s;
        }

        .action-btns .view-btn { color: var(--primary-color); }
        .action-btns .view-btn:hover { color: #3a81d4; }
        .action-btns .delete-btn { color: #F44336; }
        .action-btns .delete-btn:hover { color: #d32f2f; }
        
        /* No Data Row */
        .data-table tr.no-data td {
            text-align: center;
            font-style: italic;
            color: var(--text-light);
            padding: 30px;
            border: none !important;
            background: transparent;
        }

        /* Utility Buttons */
        .util-btn {
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #3a81d4;
        }

        .btn-secondary {
            background-color: #ccc;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: #bbb;
        }

        /* Message Box */
        .message-box {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 600;
        }

        .message-success {
            background-color: #e6ffed;
            color: #1a7a3a;
            border: 1px solid #b3e3c6;
        }

        .message-error {
            background-color: #ffe6e6;
            color: #d32f2f;
            border: 1px solid #ffb3b3;
        }

        /* Modal Structure */
        .modal {
            display: none; /* Hidden by default */
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background-color: var(--card-background);
            margin: 5% auto; /* 15% from the top and centered */
            padding: 30px;
            border-radius: var(--border-radius);
            width: 90%; 
            max-width: 600px;
            box-shadow: var(--shadow);
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9);}
            to { opacity: 1; transform: scale(1);}
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 22px;
            color: var(--primary-color);
        }

        .close-btn {
            color: var(--text-light);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: var(--text-color);
        }

        /* Form Styling in Modal */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group input:focus, .form-group textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2);
            outline: none;
        }

        /* Validation Messages */
        .validation-message {
            font-size: 12px;
            margin-top: 5px;
        }

        .validation-message.error {
            color: #F44336;
        }
        
        .validation-message.success {
            color: #4CAF50;
        }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px; /* Collapsed sidebar for mobile */
                overflow: hidden;
            }

            .sidebar .logo-details .logo_name,
            .sidebar .nav-links li a span,
            .sidebar .admin-profile .admin-name {
                display: none;
            }

            .sidebar .nav-links li a {
                justify-content: center;
                padding: 10px 0;
            }

            .sidebar .admin-profile {
                justify-content: center;
                padding: 10px;
            }
            
            .sidebar .admin-profile img {
                margin-right: 0;
            }

            .home-section {
                width: calc(100% - 70px);
                margin-left: 70px;
                padding: 15px;
            }

            .header-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                max-width: 100%;
            }

            /* Responsive Table Cards */
            .data-table thead {
                display: none; /* Hide table headers */
            }

            .data-table tr.data-row {
                display: block;
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: var(--border-radius);
                box-shadow: var(--shadow);
            }

            .data-table tr.data-row td {
                display: block;
                text-align: right;
                border: none;
                border-bottom: 1px solid #eee;
                position: relative;
                padding-left: 50%;
            }

            .data-table tr.data-row td:before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                font-weight: 700;
                color: var(--text-color);
                text-transform: uppercase;
            }

            .data-table tr.data-row td:first-child,
            .data-table tr.data-row td:last-child {
                border-radius: 0;
            }

            .data-table tr.data-row td:last-child {
                border-bottom: none;
            }

            .action-btns {
                text-align: center !important;
                display: flex;
                justify-content: center;
                gap: 10px;
            }
        }
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
        <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="SuperAdmin Profile Picture" />
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
        <li class="navList active">
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
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
        <li class="navList">
            <a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon>
              <span class="links">Banned Users</span>
            </a>
        </li>
      </ul>

   <ul class="bottom-link">
  <li class="navList logout-link">
    <a href="#" onclick="confirmLogout()">
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

<!-- Home Section -->
<section class="home-section">
    <div class="home-content">
        <h2>Manage Moderators</h2>

        <!-- Message Boxes for PHP Feedback -->
        <?php if ($success_message): ?>
            <div class="message-box message-success"><?= $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="message-box message-error"><?= $error_message; ?></div>
        <?php endif; ?>

        <!-- Header and Action Bar -->
        <div class="header-bar">
            <div class="search-box">
                <ion-icon name="search-outline"></ion-icon>
                <input type="text" id="searchInput" onkeyup="searchModerators()" placeholder="Search by ID, Name or Email">
            </div>
            <button class="action-button" onclick="openModeratorModal('create')">
                <ion-icon name="add-circle-outline" style="vertical-align: middle;"></ion-icon> Add Moderator
            </button>
        </div>

        <!-- Moderators Table -->
        <div class="table-container">
            <table class="data-table" id="moderatorsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($moderators)): ?>
                        <?php foreach ($moderators as $moderator): ?>
                            <tr class="data-row" 
                                data-id="<?= htmlspecialchars($moderator['user_id']); ?>"
                                data-fname="<?= htmlspecialchars($moderator['first_name']); ?>"
                                data-lname="<?= htmlspecialchars($moderator['last_name']); ?>"
                                data-username="<?= htmlspecialchars($moderator['username']); ?>"
                                data-email="<?= htmlspecialchars($moderator['email']); ?>"
                            >
                                <td data-label="ID"><?= htmlspecialchars($moderator['user_id']); ?></td>
                                <td data-label="First Name"><?= htmlspecialchars($moderator['first_name']); ?></td>
                                <td data-label="Last Name"><?= htmlspecialchars($moderator['last_name']); ?></td>
                                <td data-label="Username"><?= htmlspecialchars($moderator['username']); ?></td>
                                <td data-label="Email"><?= htmlspecialchars($moderator['email']); ?></td>
                                <td data-label="Created On"><?= htmlspecialchars($moderator['created_at']); ?></td>
                                <td data-label="Actions" class="action-btns">
                                    <button class="view-btn" title="Edit Details" onclick="openModeratorModal('edit', this)">
                                        <ion-icon name="create-outline"></ion-icon>
                                    </button>
                                    <button class="delete-btn" title="Delete Moderator" onclick="openDeleteModal(<?= htmlspecialchars($moderator['user_id']); ?>)">
                                        <ion-icon name="trash-outline"></ion-icon>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="no-data"><td colspan="7">No moderators found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</section>

<!-- MODAL 1: Add/Edit Moderator -->
<div id="moderatorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Moderator</h3>
            <span class="close-btn" onclick="closeModeratorModal()">&times;</span>
        </div>
        <form id="moderatorForm" action="moderators.php" method="POST">
            <input type="hidden" name="user_id" id="moderatorId">
            <input type="hidden" name="action_type" id="actionType" value="create">

            <div class="form-group">
                <label for="fname">First Name</label>
                <input type="text" id="fname" name="first_name" required>
            </div>
            
            <div class="form-group">
                <label for="lname">Last Name</label>
                <input type="text" id="lname" name="last_name" required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required oninput="checkUniqueness(this.value, 'username', document.getElementById('moderatorId').value)">
                <div class="validation-message" id="username-message"></div>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required oninput="checkUniqueness(this.value, 'email', document.getElementById('moderatorId').value)">
                <div class="validation-message" id="email-message"></div>
            </div>

            <div class="form-group" id="passwordGroup">
                <label for="password">Password (8+ characters)</label>
                <input type="password" id="password" name="password" required oninput="validatePassword(this.value)">
                <input type="hidden" name="new_password" id="newPassword"> <!-- Used for update only -->
                <div class="validation-message" id="password-message"></div>
            </div>

            <div class="form-group modal-footer" style="text-align: right; margin-top: 30px;">
                <button type="button" class="util-btn btn-secondary" onclick="closeModeratorModal()">Cancel</button>
                <button type="submit" class="util-btn btn-primary" id="modalSubmitBtn">Create Moderator</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Delete Confirmation -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Confirm Deletion</h3>
            <span class="close-btn" onclick="closeDeleteModal()">&times;</span>
        </div>
        <p>Are you sure you want to permanently delete this moderator? This action cannot be undone.</p>
        <div class="form-group modal-footer" style="text-align: right; margin-top: 30px;">
            <button type="button" class="util-btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="util-btn delete-btn" style="background-color: #F44336; color: white;" onclick="confirmDelete()">Delete</button>
        </div>
    </div>
</div>

<script>
    // Global variable to store the ID for deletion
    let currentModeratorIdToDelete = null;

    /* --- General Modal Handlers --- */
    const moderatorModal = document.getElementById('moderatorModal');
    const deleteModal = document.getElementById('deleteModal');
    const form = document.getElementById('moderatorForm');
    const passwordInput = document.getElementById('password');
    const newPasswordInput = document.getElementById('newPassword');
    const passwordGroup = document.getElementById('passwordGroup');
    const modalTitle = document.getElementById('modalTitle');
    const actionTypeInput = document.getElementById('actionType');

    function closeModeratorModal() {
        moderatorModal.style.display = 'none';
        form.reset(); // Clear form fields
        // Clear all validation messages and states
        document.getElementById('username-message').textContent = '';
        document.getElementById('email-message').textContent = '';
        document.getElementById('password-message').textContent = '';
    }

    // Function to open the Add/Edit modal
    function openModeratorModal(mode, button = null) {
        modalTitle.textContent = mode === 'create' ? 'Add New Moderator' : 'Edit Moderator Details';
        document.getElementById('modalSubmitBtn').textContent = mode === 'create' ? 'Create Moderator' : 'Update Details';
        actionTypeInput.value = mode === 'create' ? 'create' : 'update';
        
        if (mode === 'create') {
            document.getElementById('moderatorId').value = '';
            passwordInput.name = 'password'; // Use 'password' for create
            passwordInput.required = true;
            newPasswordInput.value = '';
            passwordGroup.style.display = 'block';

        } else if (mode === 'edit' && button) {
            const row = button.closest('.data-row');
            const id = row.getAttribute('data-id');
            const fname = row.getAttribute('data-fname');
            const lname = row.getAttribute('data-lname');
            const username = row.getAttribute('data-username');
            const email = row.getAttribute('data-email');
            
            document.getElementById('moderatorId').value = id;
            document.getElementById('fname').value = fname;
            document.getElementById('lname').value = lname;
            document.getElementById('username').value = username;
            document.getElementById('email').value = email;
            
            // For Edit mode: Password is optional and handled by 'new_password' field
            passwordInput.name = 'temp_password_dummy'; // Change name so it's not sent
            passwordInput.value = ''; // Clear display field
            passwordInput.required = false; // Not required for update
            newPasswordInput.name = 'new_password';
            passwordGroup.style.display = 'block'; // Show for optional change
        }

        moderatorModal.style.display = 'block';
    }


    /* --- Delete Modal Handlers --- */
    function openDeleteModal(moderatorId) {
        currentModeratorIdToDelete = moderatorId;
        deleteModal.style.display = 'block';
    }

    function closeDeleteModal() {
        deleteModal.style.display = 'none';
        currentModeratorIdToDelete = null;
    }

    function confirmDelete() {
        if (currentModeratorIdToDelete) {
            window.location.href = `moderators.php?delete=${currentModeratorIdToDelete}`;
        }
    }

    /* --- Validation & Uniqueness Checks --- */

    // Reusable function for AJAX uniqueness check (Username/Email)
    function checkUniqueness(value, field, userId) {
        const messageElement = document.getElementById(`${field}-message`);
        const isCreating = document.getElementById('actionType').value === 'create';

        if (value.trim() === '') {
            messageElement.textContent = `Please enter a ${field}.`;
            messageElement.className = 'validation-message error';
            return;
        }

        // Only check uniqueness for new entries or if the value has changed significantly
        if (!isCreating) {
            // In edit mode, if the value matches the original value, treat as valid.
            // A server-side check is more reliable, but we will run the check regardless.
            // The PHP script handles the exclusion of the current user_id.
        }

        fetch('moderators.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `check_uniqueness=1&field=${field}&value=${encodeURIComponent(value)}&user_id=${userId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                messageElement.textContent = `${field.charAt(0).toUpperCase() + field.slice(1)} is already taken.`;
                messageElement.className = 'validation-message error';
            } else {
                messageElement.textContent = `${field.charAt(0).toUpperCase() + field.slice(1)} is available.`;
                messageElement.className = 'validation-message success';
            }
        })
        .catch(error => {
            console.error('Error checking uniqueness:', error);
            messageElement.textContent = 'Could not verify uniqueness.';
            messageElement.className = 'validation-message error';
        });
    }

    // Function to check password strength
    function validatePassword(password) {
        const messageElement = document.getElementById('password-message');
        const isEdit = document.getElementById('actionType').value === 'update';

        if (isEdit && password.trim() === '') {
            // Password change is optional in edit mode
            messageElement.textContent = 'Leave blank to keep existing password.';
            messageElement.className = 'validation-message';
            newPasswordInput.value = ''; // Ensure the new_password field is empty
            return true;
        }

        if (password.length < 8) {
            messageElement.textContent = 'Password must be at least 8 characters long.';
            messageElement.className = 'validation-message error';
            return false;
        }

        messageElement.textContent = 'Password is strong enough.';
        messageElement.className = 'validation-message success';
        
        // For edit mode, update the hidden field
        if (isEdit) {
            newPasswordInput.value = password;
        }
        return true;
    }

    // Form submission validation (prevent submission if errors exist)
    form.onsubmit = function(e) {
        const isCreating = actionTypeInput.value === 'create';
        const isUpdating = actionTypeInput.value === 'update';

        // 1. Check uniqueness messages for errors
        const usernameMsg = document.getElementById('username-message').className;
        const emailMsg = document.getElementById('email-message').className;
        
        if (usernameMsg.includes('error') || emailMsg.includes('error')) {
            e.preventDefault();
            alert('Please fix the validation errors for username and email before submitting.'); // Using alert as a final guard.
            return false;
        }

        // 2. Check password for Create mode
        if (isCreating) {
            if (!validatePassword(passwordInput.value)) {
                e.preventDefault();
                alert('Password is required and must be at least 8 characters long.');
                return false;
            }
            form.name = 'create'; // Ensure PHP receives the 'create' post variable
        } 
        
        // 3. Check password for Update mode (only if user entered something)
        if (isUpdating) {
            if (passwordInput.value.trim() !== '') {
                if (!validatePassword(passwordInput.value)) {
                    e.preventDefault();
                    alert('New password must be at least 8 characters long.');
                    return false;
                }
            } else {
                // If the user left it blank, ensure the hidden field is also blank (already handled in validatePassword)
                newPasswordInput.value = '';
            }
            form.name = 'update'; // Ensure PHP receives the 'update' post variable
            // Since the form has both password and new_password, we rely on the JS to set the correct one.
            // In the update case, we rename the form to submit to 'update' logic.
            form.setAttribute('name', 'update');
            form.querySelector('input[name="action_type"]').remove(); // Remove temporary action_type input
            
            // Re-add the new_password field name which was removed in openModeratorModal
            document.getElementById('newPassword').name = 'new_password';
        }
        
        // Final check before submission to use the correct POST variable name
        if (isCreating) {
            form.setAttribute('name', 'create');
            document.getElementById('modalSubmitBtn').name = 'create';
        } else if (isUpdating) {
            form.setAttribute('name', 'update');
            document.getElementById('modalSubmitBtn').name = 'update';
        }
    };


    /* --- Search Functionality --- */
    function searchModerators() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#moderatorsTable tbody tr.data-row');
        const noDataRow = document.querySelector('#moderatorsTable tbody tr.no-data');

        let found = false;
        rows.forEach(row => {
            const id = row.cells[0].innerText.toLowerCase();
            const firstName = row.cells[1].innerText.toLowerCase();
            const lastName = row.cells[2].innerText.toLowerCase();
            const email = row.cells[4].innerText.toLowerCase();

            if (id.includes(input) || firstName.includes(input) || lastName.includes(input) || email.includes(input)) {
                row.style.display = '';
                found = true;
            } else {
                row.style.display = 'none';
            }
        });

        // Toggle visibility of the "No moderators found" row
        if (noDataRow) {
            const hasDataRows = rows.length > 0;
            noDataRow.style.display = found ? 'none' : (hasDataRows ? 'none' : '');
        }
    }


    /* --- Initial Setup & Event Listeners --- */

    // Close popups when clicking outside of them
    window.onclick = function(event) {
        if (event.target === moderatorModal) {
            closeModeratorModal();
        }
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    }

    // Logout confirmation (replaces window.confirm)
    function confirmLogout() {
        // Simple client-side confirmation for this function
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }
</script>

<!-- PHP AJAX Endpoint for Uniqueness Check -->
<?php
// This block handles the AJAX request for uniqueness check
if (isset($_POST['check_uniqueness'])) {
    header('Content-Type: application/json');
    require '../connection/db_connection.php'; 

    $field = $_POST['field'];
    $value = $_POST['value'];
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $exists = false;

    // Sanitize field name to prevent SQL injection
    if ($field === 'username' || $field === 'email') {
        $sql = "SELECT COUNT(*) FROM users WHERE {$field} = ?";
        $params = "s";
        $values = [$value];

        if ($user_id > 0) {
            $sql .= " AND user_id != ?";
            $params .= "i";
            $values[] = $user_id;
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($params, ...$values);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count > 0) {
                $exists = true;
            }
        }
    }

    echo json_encode(['exists' => $exists]);
    $conn->close();
    exit();
}
?>
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>

</body>
</html>
