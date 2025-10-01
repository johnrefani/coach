<?php
session_start();

// Load SendGrid and environment variables
require __DIR__ . '/../vendor/autoload.php';
use SendGrid\Mail\Mail;

// Load environment variables using phpdotenv
try {
    // Correctly creates an immutable Dotenv instance from the parent directory
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // Log this error if the .env file is missing/unreadable
    error_log("Dotenv failed to load in moderators.php: " . $e->getMessage());
}

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: login.php");
    exit();
}

require '../connection/db_connection.php';

// Handle Create
if (isset($_POST['create'])) {
    $username_user = $_POST['username'];
    $first_name = $_POST['first_name']; 
    $last_name = $_POST['last_name']; 
    $email = $_POST['email'];
    $password = $_POST['password'];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $user_type = 'Admin';

    // FIXED: Correct parameter order matching the SQL columns
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, user_type, first_name, last_name, password_changed) VALUES (?, ?, ?, ?, ?, ?, 0)");
    $stmt->bind_param("ssssss", $username_user, $hashed_password, $email, $user_type, $first_name, $last_name);
    
    if ($stmt->execute()) {
        // Send Email with SendGrid (Original logic preserved)
        try {
            if (!isset($_ENV['SENDGRID_API_KEY']) || empty($_ENV['SENDGRID_API_KEY'])) {
                 error_log("SendGrid API key is missing. Email not sent to " . $email);
                 throw new Exception("SendGrid API key not set in .env file.");
            }
            $sender_email = $_ENV['FROM_EMAIL'] ?? 'noreply@coach-hub.online'; 
            if (empty($sender_email) || $sender_email == 'noreply@coach-hub.online') {
                 error_log("FROM_EMAIL is missing in .env file or fallback is used. Email not sent to " . $email);
                 throw new Exception("FROM_EMAIL not set in .env file or is invalid.");
            }


            $email_content = new Mail();
            $email_content->setFrom($sender_email, 'COACH System');
            $email_content->setSubject("Your COACH Admin Access Credentials");
            $email_content->addTo($email, $username_user);

            // Content
            $html_body = "
            <html>
            <head>
              <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .credentials { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                .warning { background-color: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 15px 0; border-radius: 5px; color: #856404; }
              </style>
            </head>
            <body>
              <div class='container'>
                <div class='header'>
                  <h2>Welcome to COACH Admin Panel</h2>
                </div>
                <div class='content'>
                  <p>Dear Mr./Ms. <b>$first_name $last_name</b>,</p>
                  <p>You have been granted administrator access to the COACH system. Below are your login credentials:</p>
                  
                  <div class='credentials'>
                    <p><strong>Username:</strong> $username_user</p>
                    <p><strong>Temporary Password:</strong> $password</p>
                  </div>
                  
                  <div class='warning'>
                    <p><strong>⚠️ IMPORTANT:</strong> For security reasons, you will be required to change your password upon your first login. You cannot access the system until you create a new password.</p>
                  </div>
                  
                  <p>Please log in at <a href='https://coach-hub.online/login.php'>COACH Login</a> using these credentials.</p>
                  <p>If you have any questions or need assistance, please contact the system administrator.</p>
                </div>
                <div class='footer'>
                  <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                </div>
              </div>
            </body>
            </html>
            ";
            
            $email_content->addContent("text/html", $html_body);

            $sendgrid = new \SendGrid($_ENV['SENDGRID_API_KEY']);
            $response = $sendgrid->send($email_content);

            if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
                 $error_message = "SendGrid API failed with status code " . $response->statusCode() . ". Body: " . $response->body() . ". Headers: " . print_r($response->headers(), true);
                 error_log($error_message); 
                 throw new Exception("Email failed to send. Status: " . $response->statusCode() . ". Check logs for details.");
            }
            
            header("Location: moderators.php?status=created&email=sent");
            exit();

        } catch (\Exception $e) {
            error_log("SendGrid Error: " . $e->getMessage());
            header("Location: moderators.php?status=created&email=failed&error=" . urlencode("SendGrid failed. See logs. Details: " . $e->getMessage()));
            exit();
        }

    } else {
        $error = "Error creating user: " . $conn->error;
    }
    $stmt->close();
}

// --- Update ---
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $username_user = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ? WHERE user_id = ?");
    $stmt->bind_param("ssssi", $first_name, $last_name, $username_user, $email, $id);
    
    if ($stmt->execute()) {
        header("Location: moderators.php?status=updated");
        exit();
    } else {
        $error = "Error updating user: " . $conn->error;
    }
    $stmt->close();
}

// --- Delete ---
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header("Location: moderators.php?status=deleted");
        exit();
    } else {
        $error = "Error deleting user: " . $conn->error;
    }
    $stmt->close();
}

// --- Fetch Admins ---
$result = $conn->query("SELECT * FROM users WHERE user_type = 'Admin'");

// --- Fetch SuperAdmin Data ---
// This block populates the session variables needed for the new sidebar design
if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
} elseif (isset($_SESSION['superadmin'])) {
    $username = $_SESSION['superadmin'];
} elseif (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT username, first_name, last_name, icon FROM users WHERE user_id = ? AND user_type = 'Super Admin'");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $admin_result = $stmt->get_result();
    
    if ($admin_result->num_rows === 1) {
        $row = $admin_result->fetch_assoc();
        $username = $row['username'];
        $_SESSION['superadmin_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $_SESSION['superadmin_icon'] = !empty($row['icon']) ? $row['icon'] : "../uploads/img/default_pfp.png";
        $_SESSION['first_name'] = $row['first_name']; // Added for consistency with mentees.php structure
        $_SESSION['username'] = $username; // Ensure username is set
    } else {
        $_SESSION['superadmin_name'] = "SuperAdmin";
        $_SESSION['superadmin_icon'] = "../uploads/img/default_pfp.png";
    }
    $stmt->close();
    goto skip_username_query;
} else {
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT first_name, last_name, icon FROM users WHERE username = ? AND user_type = 'Super Admin'");
$stmt->bind_param("s", $username);
$stmt->execute();
$admin_result = $stmt->get_result();

skip_username_query:
if (isset($admin_result) && $admin_result->num_rows === 1) {
    $row = $admin_result->fetch_assoc();
    $_SESSION['superadmin_name'] = $row['first_name'] . ' ' . $row['last_name'];
    $_SESSION['superadmin_icon'] = !empty($row['icon']) ? $row['icon'] : "../uploads/img/default_pfp.png";
    $_SESSION['first_name'] = $row['first_name']; // Added for consistency with mentees.php structure
} else {
    // Keep defaults if not found
    $_SESSION['superadmin_name'] = $_SESSION['superadmin_name'] ?? "SuperAdmin";
    $_SESSION['superadmin_icon'] = $_SESSION['superadmin_icon'] ?? "../uploads/img/default_pfp.png";
}
if (isset($stmt)) {
    $stmt->close();
}

$admin_icon = $_SESSION['superadmin_icon'];
$admin_name = $_SESSION['first_name'];

// Check for status messages from redirect and format for new design
$message = '';
$error = $error ?? '';

if (isset($_GET['status'])) {
    if ($_GET['status'] == 'created') {
        $message = "Moderator created successfully! " . (isset($_GET['email']) && $_GET['email'] == 'sent' ? 'Login credentials sent via email.' : 'Failed to send email.');
    } elseif ($_GET['status'] == 'updated') {
        $message = "Moderator details updated successfully!";
    } elseif ($_GET['status'] == 'deleted') {
        $message = "Moderator deleted successfully!";
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
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Moderators | SuperAdmin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex; /* Use flexbox for main layout */
            min-height: 100vh;
        }

        /* Sidebar/Navbar Styles (Restored to Original Dark Design) */
        .sidebar {
            width: 250px;
           background-color: #562b63; /* Deep Purple */
            color: #e0e0e0;
            padding: 20px 0; /* Adjusted padding for internal links */
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        .sidebar-header {
            text-align: center;
            padding: 0 20px;
            margin-bottom: 30px;
        }
        .sidebar-header img {
            width: 70px; /* Slightly smaller */
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
           border: 3px solid #7a4a87;
            margin-bottom: 8px;
        }
        .sidebar-header h4 {
            margin: 0;
            font-weight: 500;
            color: #fff;
        }
        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1; /* Allow navigation list to grow */
        }
        .sidebar nav ul li a {
            display: block;
            color: #e0e0e0;
            text-decoration: none;
            padding: 12px 20px; /* Uniform padding */
            margin: 5px 0;
            border-radius: 0; /* No rounded corners on links */
            transition: background-color 0.2s, border-left-color 0.2s;
            display: flex;
            align-items: center;
            border-left: 5px solid transparent; /* Prepare for active indicator */
        }
        .sidebar nav ul li a i {
            margin-right: 12px;
            font-size: 18px;
        }
        .sidebar nav ul li a:hover {
            background-color: #37474f; /* Slightly lighter dark color on hover */
            color: #fff;
        }
        .sidebar nav ul li a.active {
             background-color: #7a4a87; /* Active background */
            border-left: 5px solid #00bcd4; /* Vibrant blue/cyan left border */
            color: #00bcd4; /* Active text color */
        }
        .logout-container {
            margin-top: auto;
            padding: 20px;
            border-top: 1px solid #37474f;
        }
        .logout-btn {
            background-color: #e53935; /* Red logout button */
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: bold;
        }
        .logout-btn:hover {
            background-color: #c62828;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 20px 30px;
        }
        

        header {
            padding: 10px 0;
            border-bottom: 2px solid #562b63;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 {
             color: #562b63;
            margin: 0;
            font-size: 28px;
            margin-top: 30px;
        }
        
        /* Action Buttons (New Mentee) */
        .new-moderator-btn { /* Renamed for clarity, using the style of .new-mentee-btn */
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
            margin-top: 30px;
        }
        .new-moderator-btn:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }
        
        /* Table Styles (Matching Mentors page) */
        .table-container {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            background-color: #fff;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: none;
            padding: 15px;
            text-align: left;
        }
        th {
            background-color: #562b63;
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 14px;
        }
        tr:nth-child(even) {
            background-color: #f8f8f8;
        }
        tr:hover:not(.no-data) {
            background-color: #f1f1f1;
        }
        
        .action-button {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .action-button:hover {
            background-color: #5a6268;
        }

        /* Search Bar & Controls */
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .search-box input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            width: 300px;
            font-size: 16px;
        }

        /* Details View & Form Styles (Matching Mentors page) */
        .details-view, .form-container {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }
        .details-view h3, .form-container h3 {
            color: #562b63;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 30px;
            margin-bottom: 20px;
        }
        .details-view p {
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .details-view strong {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 0.95em;
        }
        .details-view input[type="text"], .details-view input[type="email"], .details-view input[type="date"], .details-view textarea, .details-view select {
            flex-grow: 1;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-sizing: border-box;
            background-color: #f8f9fa;
            cursor: default;
        }
        .details-view textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        /* Buttons in Detail/Form View */
        .action-buttons {
            margin-top: 20px;
            text-align: right;
            border-top: 1px solid #eee;
            padding-top: 15px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        .action-buttons.between {
            justify-content: space-between;
        }
        .action-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            margin-left: 0; /* Adjusted for better gap control via flexbox */
        }
        .back-btn { 
            background-color: #6c757d;
            color: white;
        }
        .back-btn:hover {
            background-color: #5a6268;
        }
        .edit-btn, .update-btn {
            background-color: #00bcd4;
            color: white;
        }
        .edit-btn:hover, .update-btn:hover {
            background-color: #0097a7;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }
        .create-btn {
            background-color: #28a745;
            color: white;
        }
        .create-btn:hover {
            background-color: #218838;
        }

        .hidden {
            display: none;
        }
        
        /* Message/Error display */
        .message-box {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Specific form group override for password toggle in create form */
        .password-input-container {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .password-input-container input {
            flex-grow: 1;
            margin-right: -1px; /* Overlap with button border */
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        .password-toggle {
            background-color: #6c757d;
            color: white;
            border: 1px solid #6c757d;
            padding: 10px 12px;
            border-radius: 0 6px 6px 0;
            cursor: pointer;
            transition: background-color 0.3s;
            height: 42px; /* Match input height */
            box-sizing: border-box;
            line-height: 1; /* Center the icon */
        }
        .password-toggle:hover {
            background-color: #5a6268;
        }
        .password-toggle i {
            margin: 0;
        }

    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="SuperAdmin Profile Picture" />
        <h4><?php echo htmlspecialchars($_SESSION['superadmin_name']); ?></h4>
        <p style="font-size: 0.8em; color: #aaa; margin-top: 0;">SuperAdmin</p>
    </div>
    
    <nav>
        <ul>
            <li><a href="dashboard.php"><i class="fas fa-home"></i> Home</a></li>
            <li><a href="moderators.php" class="active"><i class="fas fa-user-shield"></i> Moderators</a></li> 
            <li><a href="manage_mentees.php"><i class="fas fa-user-graduate"></i> Mentees</a></li>
            <li><a href="manage_mentors.php"><i class="fas fa-chalkboard-teacher"></i> Mentors</a></li>
            <li><a href="courses.php"><i class="fas fa-book"></i> Courses</a></li>
            <li><a href="manage_session.php"><i class="fas fa-calendar-alt"></i> Sessions</a></li>
            <li><a href="feedbacks.php"><i class="fas fa-star"></i> Feedback</a></li>
            <li><a href="channels.php"><i class="fas fa-comments"></i> Channels</a></li>
            <li><a href="activities.php"><i class="fas fa-clipboard-list"></i> Activities</a></li>
            <li><a href="resource.php"><i class="fas fa-atlas"></i> Resource Library</a></li>
            <li><a href="reports.php"><i class="fas fa-folder-open"></i> Reported Posts</a></li>
            <li><a href="banned-users.php"><i class="fas fa-user-slash"></i> Banned Users</a></li>
        </ul>
    </nav>
    
    <div class="logout-container">
        <button onclick="confirmLogout()" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
    </div>
</div>

<div class="main-content">
    
    <header>
        <h1>Manage Moderators</h1>
        </header>

    <?php if ($message): ?>
        <div class="message-box success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if (isset($error) && $error): ?>
        <div class="message-box error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="controls">
        <button onclick="showCreateForm()" class="new-moderator-btn"><i class="fas fa-plus-circle"></i> Create New Moderator</button>

        <div class="search-box">
            <input type="text" id="searchInput" onkeyup="searchModerators()" placeholder="Search moderators by ID, Username, or Name...">
        </div>
    </div>
    
    <div class="form-container hidden" id="createForm">
        <h3>Create New Moderator</h3>
        <form method="POST" id="createModeratorForm">
            <input type="hidden" name="create" value="1">
            <div class="details-grid">
                
                <p><strong>First Name</strong>
                    <input type="text" name="first_name" required>
                </p>
                
                <p><strong>Last Name</strong>
                    <input type="text" name="last_name" required>
                </p>
                
                <p><strong>Email</strong>
                    <input type="email" name="email" id="create_email" required>
                </p>
                
                <p><strong>Username</strong>
                    <input type="text" name="username" id="create_username" required>
                </p>

                <p><strong>Temporary Password</strong>
                    <div class="password-input-container">
                        <input type="password" name="password" id="create_password" required>
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('create_password')">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                </p>
            </div>
            
            <div class="action-buttons">
                <button type="button" onclick="hideCreateForm()" class="back-btn"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="create-btn"><i class="fas fa-save"></i> Save Moderator</button>
            </div>
        </form>
    </div>

    <div id="tableContainer" class="table-container">
        <table id="moderatorsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr class="data-row">
                            <td><?= $row['user_id'] ?></td>
                            <td class="username"><?= htmlspecialchars($row['username']) ?></td>
                            <td class="name"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td class="email"><?= htmlspecialchars($row['email']) ?></td>
                            <td>
                                <button class="action-button" onclick='viewModerator(this)' 
                                    data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="no-data"><td colspan="5" style="text-align:center;">No Moderators found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="detailView" class="details-view hidden">
        <h3>Moderator Details</h3>
        <form method="POST" id="moderatorForm">
            
            <div class="action-buttons between">
                <div>
                    <button type="button" id="deleteButton" class="delete-btn" onclick="confirmDelete()"><i class="fas fa-trash-alt"></i> Delete</button>
                </div>
                <div>
                    <button type="button" onclick="goBack()" class="back-btn"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" id="editButton" class="edit-btn"><i class="fas fa-edit"></i> Edit</button>
                    <button type="submit" name="update" value="1" id="updateButton" class="update-btn hidden"><i class="fas fa-sync-alt"></i> Update</button>
                </div>
            </div>
            
            <input type="hidden" name="id" id="user_id">
            
            <div class="details-grid">
                
                <p><strong>User ID</strong>
                    <input type="text" id="display_user_id" readonly>
                </p>
                <p><strong>Username</strong>
                    <input type="text" name="username" id="username" required readonly>
                </p>
                <p><strong>First Name</strong>
                    <input type="text" name="first_name" id="first_name" required readonly>
                </p>
                <p><strong>Last Name</strong>
                    <input type="text" name="last_name" id="last_name" required readonly>
                </p>
                <p><strong>Email</strong>
                    <input type="email" name="email" id="email" required readonly>
                </p>
                <p><strong>Password (Leave Blank to Keep Current)</strong>
                    <input type="password" name="password" id="password_update" readonly>
                </p>
            </div>
            
        </form>
    </div>
</div>

<script>
let currentModeratorId = null;

// --- Utility Functions ---

function togglePasswordVisibility(fieldId) {
    const passwordField = document.getElementById(fieldId);
    const toggleButton = passwordField.nextElementSibling;
    const icon = toggleButton.querySelector('i');
    
    if (passwordField.type === "password") {
        passwordField.type = "text";
        icon.classList.remove('far', 'fa-eye');
        icon.classList.add('far', 'fa-eye-slash');
    } else {
        passwordField.type = "password";
        icon.classList.remove('far', 'fa-eye-slash');
        icon.classList.add('far', 'fa-eye');
    }
}

function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
        window.location.href = 'logout.php';
    }
}

function goBack() {
    document.getElementById('detailView').classList.add('hidden');
    document.getElementById('tableContainer').classList.remove('hidden');
    document.getElementById('createForm').classList.add('hidden');
}

// --- CRUD UI Functions ---

function showCreateForm() {
    document.getElementById('tableContainer').classList.add('hidden');
    document.getElementById('detailView').classList.add('hidden');
    document.getElementById('createForm').classList.remove('hidden');
    // Clear any previous input
    document.getElementById('createModeratorForm').reset();
}

function hideCreateForm() {
    document.getElementById('createForm').classList.add('hidden');
    document.getElementById('tableContainer').classList.remove('hidden');
}

function viewModerator(button) {
    const moderatorData = JSON.parse(button.getAttribute('data-info'));
    currentModeratorId = moderatorData.user_id;

    // Populate detail view fields
    document.getElementById('user_id').value = moderatorData.user_id;
    document.getElementById('display_user_id').value = moderatorData.user_id;
    document.getElementById('first_name').value = moderatorData.first_name;
    document.getElementById('last_name').value = moderatorData.last_name;
    document.getElementById('email').value = moderatorData.email;
    document.getElementById('username').value = moderatorData.username;
    
    // Reset password field and readonly status
    const passwordField = document.getElementById('password_update');
    passwordField.value = '';
    passwordField.readOnly = true;

    // Set all fields to readonly initially
    const formFields = document.querySelectorAll('#moderatorForm input:not([type="hidden"])');
    formFields.forEach(field => field.readOnly = true);
    
    // Hide update/show edit
    document.getElementById('updateButton').classList.add('hidden');
    document.getElementById('editButton').classList.remove('hidden');

    // Show the detail view
    document.getElementById('tableContainer').classList.add('hidden');
    document.getElementById('createForm').classList.add('hidden');
    document.getElementById('detailView').classList.remove('hidden');
}

// Enable editing fields
document.getElementById('editButton').addEventListener('click', function() {
    const formFields = document.querySelectorAll('#moderatorForm input:not([type="hidden"])');
    formFields.forEach(field => {
        // Allow editing for all fields except ID
        if (field.id !== 'display_user_id') {
            field.readOnly = false;
            // The password field should be editable when the user hits 'Edit'
            if (field.id === 'password_update') {
                field.placeholder = 'Enter new password...';
            }
        }
    });

    document.getElementById('editButton').classList.add('hidden');
    document.getElementById('updateButton').classList.remove('hidden');
});


function confirmDelete() {
    if (currentModeratorId && confirm(`Are you sure you want to permanently delete the Moderator with ID ${currentModeratorId}? This action cannot be undone.`)) {
        window.location.href = `moderators.php?delete=${currentModeratorId}`;
    }
}

// --- Search Functionality ---
function searchModerators() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#moderatorsTable tbody tr.data-row');
    const noDataRow = document.querySelector('#moderatorsTable tbody tr.no-data');

    let found = false;
    rows.forEach(row => {
        const id = row.cells[0].innerText.toLowerCase();
        const username = row.cells[1].innerText.toLowerCase();
        const name = row.cells[2].innerText.toLowerCase();

        if (id.includes(input) || username.includes(input) || name.includes(input)) {
            row.style.display = '';
            found = true;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Handle no data row visibility
    if (noDataRow) {
        noDataRow.style.display = found ? 'none' : (rows.length === 0 ? '' : 'none');
    }
}

</script>
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script> 
</body>
</html>