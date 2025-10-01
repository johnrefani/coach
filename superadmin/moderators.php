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
    error_log("Dotenv failed to load in moderators.php: " . $e->getMessage());
}

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

require '../connection/db_connection.php';

$message = '';
$message_type = '';

// Load admin data for sidebar
$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Super Admin';

// Function to send activation email
function sendActivationEmail($recipient_email, $username, $password, $first_name) {
    $sendgrid_api_key = $_ENV['SENDGRID_API_KEY'] ?? null;
    $from_email = $_ENV['SENDGRID_FROM_EMAIL'] ?? 'noreply@yourdomain.com';
    
    if (!$sendgrid_api_key) {
        error_log("SENDGRID_API_KEY is not set.");
        return false;
    }

    $email = new Mail();
    $email->setFrom($from_email, "Platform Admin");
    $email->setSubject("Your Moderator Account Has Been Created");
    $email->addTo($recipient_email, $first_name);

    $email_body = "Hello {$first_name},\n\n";
    $email_body .= "Your Moderator account has been successfully created.\n\n";
    $email_body .= "Login Details:\n";
    $email_body .= "Username: {$username}\n";
    $email_body .= "Temporary Password: {$password}\n\n";
    $email_body .= "Please log in and change your password immediately.\n\n";
    $email_body .= "Thank you,\nThe Admin Team";

    $email->addContent("text/plain", $email_body);

    $sendgrid = new \SendGrid($sendgrid_api_key);
    try {
        $response = $sendgrid->send($email);
        // Check for success (status code 200 or 202)
        return $response->statusCode() == 200 || $response->statusCode() == 202;
    } catch (\Exception $e) {
        error_log("SendGrid Error: " . $e->getMessage());
        return false;
    }
}


// --- CRUD Operations ---

// Handle Create
if (isset($_POST['create'])) {
    $username_user = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $password = $_POST['password']; // Raw password for email
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $user_type = 'Admin'; // Admin is the type for moderators

    // Check for duplicate username or email first
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $check_stmt->bind_param("ss", $username_user, $email);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        $message = "Error: A user with this username or email already exists.";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, user_type, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $username_user, $hashed_password, $email, $user_type, $first_name, $last_name);

        if ($stmt->execute()) {
            // Send activation email
            if (sendActivationEmail($email, $username_user, $password, $first_name)) {
                $message = "Moderator created successfully and activation email sent.";
                $message_type = "success";
            } else {
                $message = "Moderator created, but failed to send activation email.";
                $message_type = "warning";
            }
        } else {
            $message = "Error creating moderator: " . $stmt->error;
            $message_type = "error";
        }
        $stmt->close();
    }
    // Redirect to prevent form resubmission
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header("Location: moderators.php");
    exit();
}

// Handle Update
if (isset($_POST['update'])) {
    $user_id = $_POST['user_id'];
    $username = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $new_password = $_POST['password']; // New password or empty string

    // Check for duplicate username/email, excluding the current user
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
    $check_stmt->bind_param("ssi", $username, $email, $user_id);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        $message = "Error: A user with this username or email already exists.";
        $message_type = "error";
    } else {
        $sql = "UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ? ";
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password = ? ";
        }
        $sql .= "WHERE user_id = ? AND user_type = 'Admin'";

        $stmt = $conn->prepare($sql);

        if (!empty($new_password)) {
            $stmt->bind_param("sssssi", $username, $first_name, $last_name, $email, $hashed_password, $user_id);
        } else {
            $stmt->bind_param("ssssi", $username, $first_name, $last_name, $email, $user_id);
        }

        if ($stmt->execute()) {
            $message = "Moderator details updated successfully.";
            $message_type = "success";
        } else {
            $message = "Error updating moderator: " . $stmt->error;
            $message_type = "error";
        }
        $stmt->close();
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header("Location: moderators.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $message = "Moderator deleted successfully.";
        $message_type = "success";
    } else {
        $message = "Error deleting moderator: " . $stmt->error;
        $message_type = "error";
    }
    $stmt->close();
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header("Location: moderators.php");
    exit();
}

// Check for and display session messages after redirect
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- Fetch all Moderators (Admins) ---
$moderators = [];
$result = $conn->query("SELECT user_id, first_name, last_name, username, email FROM users WHERE user_type = 'Admin' ORDER BY user_id DESC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $moderators[] = $row;
    }
}

// Convert PHP array to JSON for JavaScript
$moderators_json = json_encode($moderators);
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
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>

        :root {
            --primary-color: #4CAF50; /* Green (Success/Primary Action) */
            --primary-dark: #388E3C;
            --secondary-color: #FFC107; /* Amber/Yellow (Edit/Warning) */
            --background-color: #f4f7f6; /* Light gray background */
            --card-background: #ffffff;
            --text-color: #333;
            --light-text: #777;
            --danger-color: #F44336; /* Red (Delete/Error) */
            --sidebar-bg: #2c3e50; /* Dark Blue/Gray */
            --sidebar-hover: #34495e;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --sidebar-width: 250px;
            --header-height: 60px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        /* --- Layout & Sidebar --- */
        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            padding: 20px 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
            transition: width 0.3s ease;
            position: fixed;
            height: 100%;
            overflow-y: auto;
            z-index: 50;
        }

        .sidebar.collapsed {
            width: 80px;
        }

        .main-content {
            flex-grow: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            padding: 20px;
        }

        .main-content.collapsed {
            margin-left: 80px;
        }

        .profile-section {
            display: flex;
            align-items: center;
            padding: 10px 20px 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .profile-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }

        .profile-info {
            margin-left: 15px;
            line-height: 1.2;
        }

        .profile-info h4 {
            font-size: 1rem;
            font-weight: 600;
        }

        .profile-info p {
            font-size: 0.8rem;
            color: #bdc3c7;
        }
        
        /* --- Header/Navbar --- */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--header-height);
            background-color: var(--card-background);
            box-shadow: 0 2px 8px var(--shadow-color);
            padding: 0 20px;
            margin-bottom: 20px;
            border-radius: 12px;
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .toggle-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: var(--text-color);
            cursor: pointer;
            outline: none;
            padding: 5px;
        }

        /* --- Main Content Cards --- */
        .card {
            background-color: var(--card-background);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px var(--shadow-color);
            margin-bottom: 20px;
        }
        
        /* --- Controls (Search and Buttons) --- */
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-container {
            position: relative;
            width: 100%;
            max-width: 350px;
        }

        .search-container ion-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--light-text);
        }

        .search-input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
            outline: none;
        }

        .add-button {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.1s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .add-button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* --- Table Styling --- */
        .table-responsive {
            overflow-x: auto;
            max-width: 100%;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .data-table thead {
            background-color: #eef1f4; /* Light header background */
        }

        .data-table th {
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: #555;
            border-bottom: 2px solid #ddd;
            text-transform: uppercase;
            font-size: 0.9rem;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }

        .data-table tbody tr:hover {
            background-color: #f7f7f7;
        }

        .data-table td {
            padding: 15px;
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .data-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .no-data {
            text-align: center;
            padding: 20px !important;
            color: var(--light-text);
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: opacity 0.2s, transform 0.1s, box-shadow 0.2s;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .view-btn {
            background-color: #2196F3; /* Blue */
            color: white;
        }

        .edit-btn {
            background-color: var(--secondary-color);
            color: var(--text-color);
        }

        .delete-btn {
            background-color: var(--danger-color);
            color: white;
        }

        /* --- Modal Styling --- */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            padding-top: 50px;
        }

        .modal-content {
            background-color: var(--card-background);
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.4);
            width: 90%;
            max-width: 550px;
            animation: animatetop 0.4s;
            position: relative;
        }
        
        @keyframes animatetop {
            from {top: -300px; opacity: 0}
            to {top: 0; opacity: 1}
        }

        .close-btn {
            color: var(--light-text);
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
            right: 25px;
            cursor: pointer;
        }

        .close-btn:hover,
        .close-btn:focus {
            color: var(--text-color);
        }

        .modal-content h2 {
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            font-size: 1.75rem;
            color: var(--text-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input[readonly],
        .form-group select[readonly] {
            background-color: #f0f0f0;
            cursor: not-allowed;
            color: #555;
        }
        
        .form-group input:not([readonly]):focus,
        .form-group select:not([readonly]):focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
            outline: none;
        }


        .form-actions {
            margin-top: 25px;
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.1s;
        }

        .save-btn {
            background-color: var(--primary-color);
            color: white;
        }

        .save-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .cancel-btn {
            background-color: #ccc;
            color: var(--text-color);
        }
        
        .cancel-btn:hover {
            background-color: #bbb;
            transform: translateY(-1px);
        }

        /* Message Box Styling */
        .message-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .message-box.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .message-box.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        /* Delete and Logout Confirmation Modals */
        #deleteConfirmationModal .modal-content,
        #logoutConfirmationModal .modal-content {
            max-width: 400px;
            text-align: center;
        }
        
        #deleteConfirmationModal h3,
        #logoutConfirmationModal h3 {
            margin-top: 0;
            color: var(--danger-color);
            font-size: 1.5rem;
        }
        
        #deleteConfirmationModal p,
        #logoutConfirmationModal p {
            margin-bottom: 25px;
            color: var(--light-text);
        }
        
        #deleteConfirmationModal .form-actions,
        #logoutConfirmationModal .form-actions {
            justify-content: center;
        }
        
        .logout-btn {
            background-color: #999;
            color: white;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .sidebar:not(.collapsed) {
                width: 100%;
                height: auto;
                position: relative;
                max-height: 100vh; /* Prevents overflow when expanded */
            }
            
            .sidebar.collapsed {
                width: 80px;
                position: fixed;
            }

            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            
            .main-content:not(.collapsed) {
                margin-left: 0;
            }

            .main-content.collapsed {
                margin-left: 80px;
            }

            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-container {
                max-width: 100%;
                order: 1;
            }
            
            .add-button {
                order: 2;
                width: 100%;
                justify-content: center;
            }
            
            /* Hide logo text on mobile to save space */
            .logo h2 span {
                display: none;
            }
            
            .logo h2 {
                justify-content: flex-start;
            }
            
            .sidebar:not(.collapsed) .logo h2 {
                justify-content: center;
            }
            
            .data-table {
                min-width: 600px;
            }
            
            .modal-content {
                margin: 20px auto;
            }
        }
        
        @media (min-width: 769px) {
            .sidebar.collapsed .profile-section {
                padding: 15px 0;
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
        <li class="navList">
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
          </a>
        </li>
        <li class="navList active">
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

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="header">
            <button class="toggle-btn" onclick="toggleSidebar()"><ion-icon name="menu-outline"></ion-icon></button>
            <h1>Manage Moderators</h1>
        </div>

        <?php if ($message): ?>
        <div class="message-box <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="controls">
                <div class="search-container">
                    <ion-icon name="search-outline"></ion-icon>
                    <input type="text" id="searchInput" class="search-input" onkeyup="searchModerators()" placeholder="Search by ID, Name, or Email...">
                </div>
                <button class="add-button" onclick="openCreateModal()">
                    <ion-icon name="person-add-outline"></ion-icon>
                    Add New Moderator
                </button>
            </div>

            <div class="table-responsive">
                <table id="moderatorsTable" class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($moderators)): ?>
                            <tr class="no-data"><td colspan="6">No moderators found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($moderators as $moderator): ?>
                            <tr class="data-row" data-id="<?php echo $moderator['user_id']; ?>">
                                <td><?php echo htmlspecialchars($moderator['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($moderator['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($moderator['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($moderator['username']); ?></td>
                                <td><?php echo htmlspecialchars($moderator['email']); ?></td>
                                <td class="action-buttons">
                                    <button class="action-btn view-btn" onclick="viewModerator(<?php echo htmlspecialchars(json_encode($moderator)); ?>, false)">View</button>
                                    <button class="action-btn edit-btn" onclick="viewModerator(<?php echo htmlspecialchars(json_encode($moderator)); ?>, true)">Edit</button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal(<?php echo $moderator['user_id']; ?>)">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Moderator Details Modal (Create/View/Edit) -->
<div id="moderatorDetailsModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('moderatorDetailsModal')">&times;</span>
        <h2 id="modalTitle"></h2>
        <form id="moderatorForm" method="POST" action="moderators.php">
            <input type="hidden" name="user_id" id="user_id">
            <input type="hidden" name="action_type" id="action_type">

            <div class="form-group">
                <label for="first_name">First Name*</label>
                <input type="text" id="first_name" name="first_name" required>
            </div>

            <div class="form-group">
                <label for="last_name">Last Name*</label>
                <input type="text" id="last_name" name="last_name" required>
            </div>
            
            <div class="form-group">
                <label for="username">Username*</label>
                <input type="text" id="username" name="username" required>
                <small class="text-danger" id="username-message" style="color: var(--danger-color); font-size: 0.8rem; display:none;"></small>
            </div>

            <div class="form-group">
                <label for="email">Email*</label>
                <input type="email" id="email" name="email" required>
                <small class="text-danger" id="email-message" style="color: var(--danger-color); font-size: 0.8rem; display:none;"></small>
            </div>
            
            <div class="form-group" id="password_group">
                <label for="password"><span id="password_label">Password*</span></label>
                <input type="password" id="password" name="password">
                <small class="password-note" id="password_note" style="color: var(--light-text); font-size: 0.8rem; display: block;"></small>
                <small class="text-danger" id="password-message" style="color: var(--danger-color); font-size: 0.8rem; display:none;"></small>
            </div>

            <div class="form-actions">
                <button type="button" class="cancel-btn" onclick="closeModal('moderatorDetailsModal')">Cancel</button>
                <button type="submit" class="save-btn" id="submitButton"></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmationModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('deleteConfirmationModal')">&times;</span>
        <h3>Confirm Deletion</h3>
        <p>Are you sure you want to permanently delete this moderator? This action cannot be undone.</p>
        <div class="form-actions">
            <button type="button" class="cancel-btn" onclick="closeModal('deleteConfirmationModal')">Cancel</button>
            <button type="button" class="delete-btn" id="confirmDeleteButton" onclick="finalDelete()">Delete</button>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div id="logoutConfirmationModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('logoutConfirmationModal')">&times;</span>
        <h3>Confirm Logout</h3>
        <p>Are you sure you want to log out of the Super Admin Panel?</p>
        <div class="form-actions">
            <button type="button" class="cancel-btn" onclick="closeModal('logoutConfirmationModal')">Cancel</button>
            <button type="button" class="action-btn logout-btn" onclick="finalLogout()">Logout</button>
        </div>
    </div>
</div>

<script>
    const allModerators = <?php echo $moderators_json; ?>;
    let currentModeratorId = null;

    // --- Layout and Utility Functions ---

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('collapsed');
        document.getElementById('mainContent').classList.toggle('collapsed');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        // Clear field validation messages when closing the main modal
        if (modalId === 'moderatorDetailsModal') {
            document.getElementById('moderatorForm').reset();
            hideValidationMessages();
            // Ensure password group is visible (needed for create/edit)
            document.getElementById('password_group').style.display = 'block';
        }
    }

    function hideValidationMessages() {
        document.getElementById('username-message').style.display = 'none';
        document.getElementById('email-message').style.display = 'none';
        document.getElementById('password-message').style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            // Close the specific modal that was clicked
            event.target.style.display = 'none';
            if (event.target.id === 'moderatorDetailsModal') {
                hideValidationMessages();
                document.getElementById('moderatorForm').reset();
            }
        }
    }
    
    // --- Logout Functions (No confirm() or alert()) ---

    function openLogoutModal() {
        document.getElementById('logoutConfirmationModal').style.display = 'block';
    }
    
    function finalLogout() {
        window.location.href = "../login.php";
    }

    // --- Search Functionality ---

    function searchModerators() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#moderatorsTable tbody tr.data-row');
        let found = 0;

        rows.forEach(row => {
            const id = row.cells[0].innerText.toLowerCase();
            const firstName = row.cells[1].innerText.toLowerCase();
            const lastName = row.cells[2].innerText.toLowerCase();
            const email = row.cells[4].innerText.toLowerCase();

            if (id.includes(input) || firstName.includes(input) || lastName.includes(input) || email.includes(input)) {
                row.style.display = '';
                found++;
            } else {
                row.style.display = 'none';
            }
        });

        // Hide/Show 'No Data' row if it exists
        const noDataRow = document.querySelector('#moderatorsTable tbody tr.no-data');
        if (noDataRow) {
            noDataRow.style.display = found === 0 && rows.length > 0 ? '' : 'none';
        }
    }

    // --- CRUD Modal Functions ---

    function openCreateModal() {
        const modal = document.getElementById('moderatorDetailsModal');
        const form = document.getElementById('moderatorForm');
        
        // Reset and clear messages
        form.reset();
        hideValidationMessages();
        document.getElementById('user_id').value = '';
        
        // Set up for Create
        document.getElementById('modalTitle').textContent = 'Create New Moderator';
        document.getElementById('action_type').name = 'create';
        document.getElementById('submitButton').textContent = 'Create Moderator';

        // Password field is required for creation
        document.getElementById('password').setAttribute('required', 'required');
        document.getElementById('password_label').textContent = 'Password*';
        document.getElementById('password_note').textContent = 'Password must be at least 8 characters long.';
        document.getElementById('password_group').style.display = 'block';
        
        // Enable all fields
        document.querySelectorAll('#moderatorForm input').forEach(el => el.removeAttribute('readonly'));
        document.getElementById('submitButton').style.display = 'inline-block';
        
        modal.style.display = 'block';
    }

    function viewModerator(data, isEditMode) {
        const modal = document.getElementById('moderatorDetailsModal');
        
        // Populate fields
        document.getElementById('user_id').value = data.user_id;
        document.getElementById('first_name').value = data.first_name;
        document.getElementById('last_name').value = data.last_name;
        document.getElementById('username').value = data.username;
        document.getElementById('email').value = data.email;
        document.getElementById('password').value = ''; // Never populate password

        // Clear validation messages
        hideValidationMessages();

        if (isEditMode) {
            // Setup for Edit
            document.getElementById('modalTitle').textContent = `Edit Moderator: ${data.first_name} ${data.last_name}`;
            document.getElementById('action_type').name = 'update';
            document.getElementById('submitButton').textContent = 'Update Details';

            // Password is optional for update
            document.getElementById('password').removeAttribute('required');
            document.getElementById('password_label').textContent = 'New Password';
            document.getElementById('password_note').textContent = 'Leave blank to keep current password. Must be 8+ chars if changed.';

            document.querySelectorAll('#moderatorForm input').forEach(el => el.removeAttribute('readonly'));
            document.getElementById('submitButton').style.display = 'inline-block';
            document.getElementById('password_group').style.display = 'block';


        } else {
            // Setup for View (Read-only)
            document.getElementById('modalTitle').textContent = `Moderator Details: ${data.first_name} ${data.last_name}`;
            document.getElementById('action_type').name = 'view'; // Prevent submission
            document.getElementById('submitButton').style.display = 'none';

            // Make all fields read-only
            document.querySelectorAll('#moderatorForm input').forEach(el => el.setAttribute('readonly', 'readonly'));
            
            // Hide password fields for view mode
            document.getElementById('password_group').style.display = 'none';
        }

        modal.style.display = 'block';
    }
    
    // --- Delete Functions (No confirm() or alert()) ---
    
    function openDeleteModal(id) {
        currentModeratorId = id;
        document.getElementById('deleteConfirmationModal').style.display = 'block';
    }
    
    function finalDelete() {
        if (currentModeratorId) {
            // Perform the deletion by navigating to the delete URL
            window.location.href = `moderators.php?delete=${currentModeratorId}`;
        }
        closeModal('deleteConfirmationModal');
    }


    // --- Form Submission and Validation (No alert()) ---

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('moderatorForm');
        
        form.addEventListener('submit', function(e) {
            const actionType = document.getElementById('action_type').name;
            const passwordField = document.getElementById('password');
            const password = passwordField.value;
            const emailField = document.getElementById('email');
            const email = emailField.value;

            hideValidationMessages();
            let isValid = true;

            // 1. Email format validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                document.getElementById('email-message').textContent = 'Please enter a valid email address.';
                document.getElementById('email-message').style.display = 'block';
                isValid = false;
            }

            // 2. Password strength check (only if creating or changing password)
            if (actionType === 'create' || (actionType === 'update' && password.length > 0)) {
                if (password.length < 8) {
                    e.preventDefault();
                    document.getElementById('password-message').textContent = 'Password must be at least 8 characters long.';
                    document.getElementById('password-message').style.display = 'block';
                    isValid = false;
                }
            }
            
            // Prevent submission if not valid
            if (!isValid) {
                e.preventDefault();
                // Optionally scroll to the first invalid field
                const firstInvalid = form.querySelector('.text-danger[style*="block"]').closest('.form-group').querySelector('input');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }

            // If client-side validation passes, the form is allowed to submit to PHP for server-side checks (like uniqueness).
        });
        
        // Initial state check for sidebar on larger screens
        if (window.innerWidth <= 768) {
            toggleSidebar(); // Collapse by default on mobile
        }
    });

</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
</body>
</html>
