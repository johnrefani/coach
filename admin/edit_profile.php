<?php
session_start();
// Use your standard database connection file
require '../connection/db_connection.php';

// Check if a user is logged in, regardless of type
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php"); 
    exit();
}

$updated = false;
$error = '';
$user_data = null;
$imageUploaded = false;

// Determine the username of the profile to be edited from the URL
$username_to_edit = $_GET['username'] ?? '';

if (empty($username_to_edit)) {
    die("No username provided to edit.");
}

// Security Check: Ensure the logged-in user can only edit their own profile
// An exception could be made for a 'Super Admin' if needed
if ($username_to_edit !== $_SESSION['username'] && $_SESSION['user_type'] !== 'Super Admin') {
    die("You are not authorized to edit this profile.");
}


// Handle form submission for profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $original_username = $_POST['original_username'] ?? '';

    // --- Handle Profile Text Information Update ---
    if (isset($_POST['update_profile'])) {
        $new_username = $_POST['username'] ?? '';
        $full_name = $_POST['name'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($new_username) || empty($full_name)) {
            $error = "Username and Name fields are required.";
        } else {
            // Split the full name into first and last names
            $name_parts = explode(' ', $full_name, 2);
            $first_name = $name_parts[0];
            $last_name = $name_parts[1] ?? '';

            // Check if password was changed. An empty password field means no change.
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET username = ?, first_name = ?, last_name = ?, password = ? WHERE username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssss", $new_username, $first_name, $last_name, $hashed_password, $original_username);
            } else {
                // Password hasn't changed, so don't update it
                $sql = "UPDATE users SET username = ?, first_name = ?, last_name = ? WHERE username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $new_username, $first_name, $last_name, $original_username);
            }

            if ($stmt->execute()) {
                $updated = true;
                // Update session username if it was changed
                $_SESSION['username'] = $new_username; 
                $username_to_edit = $new_username; // Use new username for re-fetching data
            } else {
                $error = "Error updating profile: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    // --- Handle Profile Image Upload ---
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        
        // **FIX:** Create the uploads directory if it doesn't exist
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $filesize = $_FILES['profile_image']['size'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $error = "Invalid image format. Please use JPG, JPEG, PNG, or GIF.";
        } elseif ($filesize > 5000000) { // 5MB max
            $error = "File size exceeds the 5MB limit.";
        } else {
            $new_filename = $upload_dir . 'profile_' . uniqid() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $new_filename)) {
                $sql = "UPDATE users SET icon = ? WHERE username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $new_filename, $original_username);
                
                if ($stmt->execute()) {
                    $imageUploaded = true;
                    $_SESSION['user_icon'] = $new_filename; // Update session icon immediately
                    header("Location: edit_profile.php?username=" . urlencode($original_username) . "&upload_success=1");
                    exit();
                } else {
                    $error = "Error updating profile image in the database.";
                }
                $stmt->close();
            } else {
                $error = "Failed to upload the image. Check folder permissions.";
            }
        }
    }
}

// Fetch the latest user data to display
$sql = "SELECT user_id, username, first_name, last_name, password, icon, user_type FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username_to_edit);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

if (!$user_data) {
    die("User not found.");
}
$stmt->close();

// Set default icon if it's missing
$user_data = !empty($user_data['icon']) ? $user_data['icon'] : '../uploads/img/default_pfp.png';
$user_data['full_name'] = $user_data['first_name'] . ' ' . $user_data['last_name'];

// Update session variables for the nav bar
$_SESSION['user_full_name'] = $user_data['full_name'];
$_SESSION['user_icon'] = $user_data['icon'];
$_SESSION['user_type'] = $user_data['user_type'];


if(isset($_GET['upload_success'])) {
    $imageUploaded = true;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css"/>
  <link rel="stylesheet" href="../superadmin/css/profile.css" />
  <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
  <title>Edit Profile</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['user_icon']); ?>" alt="Profile Picture" />
      <div class="admin-text">
        <span class="admin-name"><?php echo htmlspecialchars($_SESSION['user_full_name']); ?></span>
        <span class="admin-role"><?php echo htmlspecialchars($_SESSION['user_type']); ?></span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link active" title="Edit Profile">
        <ion-icon name="create-outline"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
        <li class="navList">
            <a href="dashboard.php"> <ion-icon name="home-outline"></ion-icon>
                <span class="links">Home</span>
            </a>
        </li>
        <li class="navList">
            <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                <span class="links">Courses</span>
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
            <a href="manage_session.php"> <ion-icon name="calendar-outline"></ion-icon>
                <span class="links">Sessions</span>
            </a>
        </li>
        <li class="navList"> <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
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
      <li class="logout-link">
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

<div class="main-content">
    <h1 class="page-title">Profile Settings</h1>
    
    <div class="profile-container">
        <div class="profile-image-section">
            <div class="profile-img-container">
                <img src="<?= htmlspecialchars($user_data['icon']) ?>" class="profile-img" alt="Profile Picture" id="profileImage">
                <div class="edit-icon" onclick="document.getElementById('profileImageUpload').click()">
                    <ion-icon name="camera"></ion-icon>
                </div>
            </div>
            
            <form id="imageUploadForm" method="post" action="edit_profile.php?username=<?= urlencode($user_data['username']) ?>" enctype="multipart/form-data">
                <input type="file" name="profile_image" id="profileImageUpload" class="hidden-file-input" accept="image/*" onchange="submitImageForm()">
                <input type="hidden" name="original_username" value="<?= htmlspecialchars($user_data['username']) ?>">
            </form>
            
            <?php if ($imageUploaded): ?>
                <div class="message">Profile image updated successfully!</div>
            <?php endif; ?>
        </div>

        <div class="profile-info">
            <?php if ($updated): ?>
                <div class="message">Profile updated successfully!</div>
            <?php elseif ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="edit_profile.php?username=<?= urlencode($user_data['username']) ?>" id="profileForm">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" id="username" value="<?= htmlspecialchars($user_data['username']) ?>" class="disabled-input" readonly>
                </div>

                <div class="form-group">
                    <label>Name:</label>
                    <input type="text" name="name" id="name" value="<?= htmlspecialchars($user_data['full_name']) ?>" class="disabled-input" readonly>
                </div>

                <div class="form-group">
                    <label>New Password:</label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" placeholder="Leave blank to keep current password" class="disabled-input" readonly>
                        <span class="toggle-password" onclick="togglePasswordVisibility()">
                            <ion-icon name="eye-outline"></ion-icon>
                        </span>
                    </div>
                </div>

                <input type="hidden" name="original_username" value="<?= htmlspecialchars($user_data['username']) ?>">
                <input type="hidden" name="update_profile" value="1">
                <button type="button" id="editButton" class="action-btn" onclick="toggleEditMode()">Edit Profile</button>
                <button type="submit" id="updateButton" class="action-btn" style="display: none;">Update Profile</button>
            </form>
        </div>
    </div>
</div>
</section>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
// Navigation Toggle
const navBar = document.querySelector("nav");
const navToggle = document.querySelector(".navToggle");
if (navToggle) {
    navToggle.addEventListener('click', () => {
        navBar.classList.toggle('close');
    });
}

function confirmLogout() {
    if (confirm("Are you sure you want to logout?")) {
        window.location.href = "../logout.php";
    }
}

function toggleEditMode() {
    const editButton = document.getElementById('editButton');
    const updateButton = document.getElementById('updateButton');
    const inputs = document.querySelectorAll('#profileForm .disabled-input');
    
    inputs.forEach(input => {
        input.readOnly = false;
        input.classList.remove('disabled-input');
    });
    
    editButton.style.display = 'none';
    updateButton.style.display = 'inline-block';
}

function togglePasswordVisibility() {
    const passwordField = document.getElementById('password');
    const toggleIcon = document.querySelector('.toggle-password ion-icon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.setAttribute('name', 'eye-off-outline');
    } else {
        passwordField.type = 'password';
        toggleIcon.setAttribute('name', 'eye-outline');
    }
}

function submitImageForm() {
    const fileInput = document.getElementById('profileImageUpload');
    const imagePreview = document.getElementById('profileImage');
    
    if (fileInput.files && fileInput.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
            // The form submission is now triggered, which will handle the upload and redirect
            document.getElementById('imageUploadForm').submit();
        }
        
        reader.readAsDataURL(fileInput.files[0]);
    }
}

// Clean URL from success messages
document.addEventListener('DOMContentLoaded', function() {
    if (history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('upload_success')) {
            url.searchParams.delete('upload_success');
            history.replaceState(null, '', url.toString());
        }
    }
});
</script>
</body>
</html>
