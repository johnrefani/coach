<?php
session_start();

// Use the SendGrid SDK and Dotenv for environment variables
require __DIR__ . '/../vendor/autoload.php';

// Load environment variables using phpdotenv
try {
    // Assuming .env is one level up from the current directory (e.g., ../.env)
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // Log if .env fails to load, but proceed. Email sending will fail if the key is missing.
    error_log("Dotenv failed to load: " . $e->getMessage());
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
        // Send Email with SendGrid (ADAPTED)
        try {
            // Check for API key from environment variables
            if (!isset($_ENV['SENDGRID_API_KEY']) || empty($_ENV['SENDGRID_API_KEY'])) {
                throw new \Exception("SENDGRID_API_KEY is missing or empty. Please check your .env file.");
            }

            $sendgrid_email = new \SendGrid\Mail\Mail();
            $sendgrid_email->setFrom('coach.hub2025@gmail.com', 'COACH Admin'); // Use your verified sender email
            $sendgrid_email->setSubject('Your New Admin Account Credentials');
            $sendgrid_email->addTo($email, $first_name . ' ' . $last_name); // Recipient

            // Content
            $email_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .details { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
                    .detail-row { margin-bottom: 10px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Welcome to COACH!</h2>
                        </div>
                        <div class='details'>
                            <p>Dear " . htmlspecialchars($first_name) . ",</p>
                            <p>Your Admin account has been successfully created. You can now log in to the Admin Dashboard using the credentials below:</p>
                            
                            <div class='detail-row'><strong>Username:</strong> " . htmlspecialchars($username_user) . "</div>
                            <div class='detail-row'><strong>Temporary Password:</strong> " . htmlspecialchars($password) . "</div>
                            
                            <p>Please log in immediately and change your password for security purposes. Your first login will automatically prompt you to change it.</p>
                            <p><a href='https://coach-hub.online/admin/login.php'>Click here to log in</a></p>
                            
                            <p>If you have any questions, please reply to this email.</p>
                        </div>
                        <div class='footer'>
                            <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $sendgrid_email->addContent("text/html", $email_body);

            // Instantiate SendGrid client and send the email
            $sendgrid = new \SendGrid($_ENV['SENDGRID_API_KEY']);
            $response = $sendgrid->send($sendgrid_email);

            // Check API response status
            if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
                 error_log("SendGrid API failed with status code " . $response->statusCode() . ". Body: " . $response->body());
                 $message = "✅ Admin account created, but the welcome email failed to send (SendGrid API Error).";
            } else {
                $message = "✅ Admin account created successfully. Welcome email sent.";
            }

        } catch (\Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            $message = "✅ Admin account created, but the welcome email failed to send: " . $e->getMessage();
        }
    } else {
        $message = "❌ Error creating account: " . $stmt->error;
    }
}


// Handle Update
if (isset($_POST['update'])) {
    $user_id = $_POST['user_id'];
    $username_user = $_POST['username'];
    $first_name = $_POST['first_name']; 
    $last_name = $_POST['last_name']; 
    $email = $_POST['email'];

    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ? WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("ssssi", $username_user, $email, $first_name, $last_name, $user_id);
    
    if ($stmt->execute()) {
        $message = "✅ Admin account updated successfully.";
    } else {
        $message = "❌ Error updating account: " . $stmt->error;
    }
    $stmt->close();
}

// Handle Delete
if (isset($_GET['delete_id'])) {
    $user_id = $_GET['delete_id'];

    // 1. Delete associated chat messages (if any)
    $stmt = $conn->prepare("DELETE FROM chat_messages WHERE user_id = ? AND is_admin = 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    
    // 2. Delete the user
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $message = "🗑️ Admin account deleted successfully.";
    } else {
        $message = "❌ Error deleting account: " . $stmt->error;
    }
    $stmt->close();
}

// Handle Reset Password
if (isset($_POST['reset_password'])) {
    $user_id = $_POST['reset_user_id'];
    $new_password = bin2hex(random_bytes(6)); // Generate a new 12-character random password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password = ?, password_changed = 0 WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("si", $hashed_password, $user_id);
    
    if ($stmt->execute()) {
        // Fetch user details for email notification
        $stmt_user = $conn->prepare("SELECT email, first_name, last_name, username FROM users WHERE user_id = ?");
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        $user_data = $user_result->fetch_assoc();
        $stmt_user->close();

        if ($user_data) {
             // Send Email with SendGrid (ADAPTED for reset)
            try {
                if (!isset($_ENV['SENDGRID_API_KEY']) || empty($_ENV['SENDGRID_API_KEY'])) {
                    throw new \Exception("SENDGRID_API_KEY is missing or empty.");
                }

                $sendgrid_email = new \SendGrid\Mail\Mail();
                $sendgrid_email->setFrom('coach.hub2025@gmail.com', 'COACH Admin');
                $sendgrid_email->setSubject('Your Admin Account Password Has Been Reset');
                $sendgrid_email->addTo($user_data['email'], $user_data['first_name'] . ' ' . $user_data['last_name']);

                $reset_email_body = "
                    <html>
                    <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                        .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                        .details { background-color: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px; }
                        .detail-row { margin-bottom: 10px; }
                        .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                    </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>Password Reset Notification</h2>
                            </div>
                            <div class='details'>
                                <p>Dear " . htmlspecialchars($user_data['first_name']) . ",</p>
                                <p>The password for your Admin account has been reset by the Super Admin.</p>
                                
                                <div class='detail-row'><strong>Username:</strong> " . htmlspecialchars($user_data['username']) . "</div>
                                <div class='detail-row'><strong>New Temporary Password:</strong> " . htmlspecialchars($new_password) . "</div>
                                
                                <p>You must use this new password to log in. Upon your next login, you will be prompted to change it immediately.</p>
                                <p><a href='https://coach-hub.online/admin/login.php'>Click here to log in</a></p>
                                
                                <p>If you did not request this change, please contact your Super Admin immediately.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";

                $sendgrid_email->addContent("text/html", $reset_email_body);

                $sendgrid = new \SendGrid($_ENV['SENDGRID_API_KEY']);
                $response = $sendgrid->send($sendgrid_email);

                if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
                     error_log("SendGrid API (Reset) failed with status code " . $response->statusCode() . ". Body: " . $response->body());
                     $message = "✅ Password reset successfully, but the notification email failed to send (SendGrid API Error).";
                } else {
                    $message = "✅ Password reset successfully. Notification email sent.";
                }

            } catch (\Exception $e) {
                error_log("Password reset email failed: " . $e->getMessage());
                $message = "✅ Password reset successfully, but the notification email failed to send: " . $e->getMessage();
            }

        } else {
            $message = "✅ Password reset successfully, but user email data was not found for notification.";
        }
    } else {
        $message = "❌ Error resetting password: " . $stmt->error;
    }
}

// Fetch all Admin accounts (Moderators)
$moderators = [];
$result = $conn->query("SELECT user_id, username, first_name, last_name, email, icon, password_changed FROM users WHERE user_type = 'Admin' ORDER BY user_id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $moderators[] = $row;
    }
}

// Fetch user data for navigation/display
$currentUser = $_SESSION['username'];
$stmt = $conn->prepare("SELECT user_id, first_name, last_name, icon FROM users WHERE username = ?");
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $_SESSION['superadmin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['superadmin_icon'] = $user['icon'] ?: '../uploads/img/default_pfp.png';
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <link rel="stylesheet" href="css/dashboard.css" />
        <link rel="stylesheet" href="css/moderators.css"/>
        <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
        <title>Moderators | SuperAdmin</title>
        <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
        <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
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
                        <span class="admin-role">Super Admin</span>
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
                <img src="../uploads/img/logo.png" alt="Logo">
            </div>

            <div class="container">
                <h1 style="margin-bottom: 20px;">Moderator Management</h1>

                <?php if (!empty($message)): ?>
                    <div class="message"><?php echo $message; ?></div>
                <?php endif; ?>

                <div class="tab-controls">
                    <button class="tab-button active" onclick="showTab('create')">Create New Moderator</button>
                    <button class="tab-button" onclick="showTab('list')">Manage Moderators</button>
                </div>

                <div class="tab-content active" id="create">
                    <div class="form-container">
                        <h3>Create Moderator Account</h3>
                        <form id="create-form" method="POST">
                            <input type="hidden" name="create" value="1">
                            
                            <div class="input-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" required>
                            </div>

                            <div class="input-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" required>
                            </div>

                            <div class="input-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" required>
                                <div id="username-message"></div>
                            </div>
                            
                            <div class="input-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" required>
                                <div id="email-message"></div>
                            </div>
                            
                            <div class="input-group">
                                <label for="password">Temporary Password</label>
                                <input type="password" id="password" name="password" required>
                                <div class="password-note">Password must be at least 8 characters. User will be forced to change it on first login.</div>
                            </div>

                            <button type="submit" class="submit-btn">Create Account</button>
                        </form>
                    </div>
                </div>

                <div class="tab-content" id="list">
                    <h3>Current Moderators</h3>
                    <div class="table-container">
                        <?php if (empty($moderators)): ?>
                            <p>No moderator accounts found.</p>
                        <?php else: ?>
                            <table class="moderators-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($moderators as $mod): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mod['user_id']); ?></td>
                                            <td>
                                                <div class="name-display">
                                                    <img src="<?php echo htmlspecialchars($mod['icon'] ?: '../uploads/img/default_pfp.png'); ?>" alt="PFP" class="pfp">
                                                    <?php echo htmlspecialchars($mod['first_name'] . ' ' . $mod['last_name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($mod['username']); ?></td>
                                            <td><?php echo htmlspecialchars($mod['email']); ?></td>
                                            <td>
                                                <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($mod)); ?>)">
                                                    <ion-icon name="create-outline"></ion-icon> Edit
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to reset the password for <?php echo htmlspecialchars($mod['username']); ?>? A new temporary password will be emailed.');">
                                                    <input type="hidden" name="reset_password" value="1">
                                                    <input type="hidden" name="reset_user_id" value="<?php echo htmlspecialchars($mod['user_id']); ?>">
                                                    <button type="submit" class="action-btn reset-btn">
                                                        <ion-icon name="key-outline"></ion-icon> Reset
                                                    </button>
                                                </form>
                                                <a href="?delete_id=<?php echo htmlspecialchars($mod['user_id']); ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($mod['username']); ?>? This action is irreversible.');">
                                                    <ion-icon name="trash-outline"></ion-icon> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close-btn" onclick="closeEditModal()">&times;</span>
                <h3>Edit Moderator Details</h3>
                <form id="edit-form" method="POST">
                    <input type="hidden" name="update" value="1">
                    <input type="hidden" id="edit_user_id" name="user_id">

                    <div class="input-group">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" id="edit_first_name" name="first_name" required>
                    </div>

                    <div class="input-group">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" id="edit_last_name" name="last_name" required>
                    </div>
                    
                    <div class="input-group">
                        <label for="edit_username">Username</label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>

                    <div class="input-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>

                    <button type="submit" class="submit-btn">Save Changes</button>
                </form>
            </div>
        </div>

        <script>
            function showTab(tabId) {
                const tabs = document.querySelectorAll('.tab-content');
                const buttons = document.querySelectorAll('.tab-button');
                
                tabs.forEach(tab => tab.classList.remove('active'));
                buttons.forEach(btn => btn.classList.remove('active'));

                document.getElementById(tabId).classList.add('active');
                document.querySelector(`.tab-button[onclick*='${tabId}']`).classList.add('active');
            }

            // MODAL FUNCTIONS
            const editModal = document.getElementById('editModal');

            function openEditModal(modData) {
                document.getElementById('edit_user_id').value = modData.user_id;
                document.getElementById('edit_first_name').value = modData.first_name;
                document.getElementById('edit_last_name').value = modData.last_name;
                document.getElementById('edit_username').value = modData.username;
                document.getElementById('edit_email').value = modData.email;
                editModal.style.display = 'block';
            }

            function closeEditModal() {
                editModal.style.display = 'none';
            }

            window.onclick = function(event) {
                if (event.target == editModal) {
                    closeEditModal();
                }
            }

            function confirmLogout() {
                if (confirm("Are you sure you want to log out?")) {
                    window.location.href = "../login.php";
                }
            }

            // ASYNC VALIDATION FOR USERNAME AND EMAIL
            document.addEventListener('DOMContentLoaded', () => {
                const usernameInput = document.getElementById('username');
                const emailInput = document.getElementById('email');
                
                // Helper for validation check
                const checkExistence = (input, type, messageElementId) => {
                    const value = input.value.trim();
                    const messageElement = document.getElementById(messageElementId);
                    
                    if (value.length === 0) {
                        messageElement.innerHTML = '';
                        messageElement.style.color = 'inherit';
                        return;
                    }
                    
                    fetch(`../validation/check_exists.php?type=${type}&value=${encodeURIComponent(value)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.exists) {
                                messageElement.innerHTML = `${type} is already taken.`;
                                messageElement.style.color = 'rgb(244, 67, 54)'; // Red
                            } else {
                                messageElement.innerHTML = `${type} is available.`;
                                messageElement.style.color = 'rgb(76, 175, 80)'; // Green
                            }
                        })
                        .catch(error => {
                            console.error('Validation error:', error);
                            messageElement.innerHTML = '';
                        });
                };

                // Attach event listeners
                if (usernameInput) {
                    usernameInput.addEventListener('input', () => checkExistence(usernameInput, 'username', 'username-message'));
                }
                if (emailInput) {
                    emailInput.addEventListener('input', () => checkExistence(emailInput, 'email', 'email-message'));
                }
                
                // FINAL FORM VALIDATION BEFORE SUBMIT
                const createForm = document.getElementById('create-form');
                if (createForm) {
                    createForm.addEventListener('submit', function(e) {
                        const password = document.getElementById('password').value;
                        const email = document.getElementById('email').value;

                        // Check if a required field is empty
                        if (!this.checkValidity()) {
                            // Browser handles default message, but stop custom checks
                            return true; 
                        }
                        
                        // Check if username has validation error
                        const usernameMessage = document.getElementById('username-message');
                        if (usernameMessage && usernameMessage.style.color === 'rgb(244, 67, 54)') {
                            e.preventDefault();
                            alert('Please choose a different username');
                            return false;
                        }
                        
                        // Check if email has validation error
                        const emailMessage = document.getElementById('email-message');
                        if (emailMessage && emailMessage.style.color === 'rgb(244, 67, 54)') {
                            e.preventDefault();
                            alert('Please provide a valid email address');
                            return false;
                        }
                        
                        // Email format validation (in case API is slow/failed)
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(email)) {
                            e.preventDefault();
                            alert('Please enter a valid email address');
                            return false;
                        }
                        
                        // Password strength check
                        if (password.length < 8) {
                            e.preventDefault();
                            alert('Password must be at least 8 characters long');
                            return false;
                        }
                        
                        // All validations passed, form will submit
                        return true;
                    });
                }
            });
        </script>
    </body>
</html>