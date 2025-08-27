<?php
session_start();
if (!isset($_SESSION['superadmin'])) {
    header("Location: loginsuperadmin.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "coach");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$updated = false;
$error = '';
$superadmin = null;
$imageUploaded = false;

// Determine username
$username = $_GET['username'] ?? ($_POST['original_username'] ?? '');

if (empty($username)) {
    die("No username provided.");
}

// If form was submitted, process update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = $_POST['username'] ?? '';
    $admin_name = $_POST['admin_name'] ?? '';
    $password = $_POST['password'] ?? '';
    $original_username = $_POST['original_username'] ?? '';

    // Check if profile form was submitted
    if (isset($_POST['update_profile'])) {
        if (empty($new_username) || empty($admin_name) || empty($password)) {
            $error = "All fields are required.";
        } else {
            // Check if the password has been changed by comparing with the stored hash
            $check_sql = "SELECT SAdmin_Password FROM SuperAdmin WHERE SAdmin_Username = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $original_username);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $current_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            // If password has changed, hash the new password
            if (!password_verify($password, $current_data['SAdmin_Password'])) {
                // Password has been changed, hash it
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $sql = "UPDATE SuperAdmin SET SAdmin_Username = ?, SAdmin_Name = ?, SAdmin_Password = ? WHERE SAdmin_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $new_username, $admin_name, $hashed_password, $original_username);
            } else {
                // Password hasn't changed, use original values
                $sql = "UPDATE SuperAdmin SET SAdmin_Username = ?, SAdmin_Name = ? WHERE SAdmin_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $new_username, $admin_name, $original_username);
            }

            if ($stmt->execute()) {
                $updated = true;
                $username = $new_username; // for re-fetching updated data
                $_SESSION['superadmin'] = $new_username; // Update session
            } else {
                $error = "Error updating profile.";
            }

            $stmt->close();
        }
    }
    
    // Check if image was uploaded
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $filetype = $_FILES['profile_image']['type'];
        $filesize = $_FILES['profile_image']['size'];
        
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($ext), $allowed)) {
            $error = "Error: Please select a valid image format (JPG, JPEG, PNG, GIF).";
        } else if ($filesize > 5000000) { // 5MB max
            $error = "Error: File size must be less than 5MB.";
        } else {
            // Create a unique filename to prevent overwriting
            $new_filename = uniqid('superadmin_') . '.' . $ext;
            $destination = 'uploads/' . $new_filename;
            
            // Make sure the uploads directory exists
            if (!file_exists('uploads')) {
                mkdir('uploads', 0777, true);
            }
            
            // Try to move the uploaded file
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $sql = "UPDATE SuperAdmin SET SAdmin_Icon = ? WHERE SAdmin_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $destination, $username);
                
                if ($stmt->execute()) {
                    $imageUploaded = true;
                } else {
                    $error = "Error updating profile image in database.";
                }
                
                $stmt->close();
            } else {
                $error = "Error uploading image. Please try again.";
            }
        }
    }
}

// Fetch latest superadmin data
$sql = "SELECT SAdmin_ID, SAdmin_Username, SAdmin_Name, SAdmin_Password, SAdmin_Icon FROM SuperAdmin WHERE SAdmin_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$superadmin = $result->fetch_assoc();

if (!$superadmin) {
    die("SuperAdmin not found.");
}

// Set default icon if missing
$superadmin['SAdmin_Icon'] = !empty($superadmin['SAdmin_Icon']) ? $superadmin['SAdmin_Icon'] : 'img/default_pfp.png';

// Display password as masked for security
$masked_password = "••••••••";

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/superadmin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/pfp.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>SuperAdmin Profile</title>
</head>
<body>
<nav>
<div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($superadmin['SAdmin_Icon']); ?>" alt="SuperAdmin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($superadmin['SAdmin_Name']); ?>
        </span>
        <span class="admin-role">SuperAdmin</span>
      </div>
      <a href="CoachSuperAdminPFP.php?username=<?= urlencode($_SESSION['superadmin']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>
  
  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="CoachSuperAdmin.php">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminAdmins.php'">
          <ion-icon name="lock-closed-outline"></ion-icon>
          <span class="links">Moderators</span>
        </a>
      </li>
    </ul>

    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px;">
          <ion-icon name="log-out-outline"></ion-icon>
          Logout
        </a>
      </li>
    </ul>
  </div>
</nav>

<section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="img/logo.png" alt="Logo">
    </div>

<div class="main-content">
    <h1 class="page-title">SuperAdmin Profile Settings</h1>

<div class="profile-container">
    <div class="profile-image-section">
        <div class="profile-img-container">
            <img src="<?= htmlspecialchars($superadmin['SAdmin_Icon']) ?>" class="profile-img" alt="Profile Picture" id="profileImage">
            <div class="edit-icon" onclick="document.getElementById('profileImageUpload').click()">
                <ion-icon name="camera"></ion-icon>
            </div>
        </div>
        
        <form id="imageUploadForm" method="post" action="CoachSuperAdminPFP.php" enctype="multipart/form-data">
            <input type="file" name="profile_image" id="profileImageUpload" class="hidden-file-input" accept="image/*" onchange="submitImageForm()">
            <input type="hidden" name="original_username" value="<?= htmlspecialchars($superadmin['SAdmin_Username']) ?>">
        </form>
        
        <?php if ($imageUploaded): ?>
            <div class="image-upload-message">Profile image updated successfully!</div>
        <?php endif; ?>
    </div>

    <div class="profile-info">
        <?php if ($updated): ?>
            <div class="message">Profile updated successfully!</div>
        <?php elseif ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="CoachSuperAdminPFP.php" id="profileForm">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($superadmin['SAdmin_Username']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Name:</label>
                <input type="text" name="admin_name" id="admin_name" value="<?= htmlspecialchars($superadmin['SAdmin_Name']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Password:</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" value="<?= $masked_password ?>" class="disabled-input" readonly>
                    <span class="toggle-password" onclick="togglePasswordVisibility()">
                        <ion-icon name="eye-outline"></ion-icon>
                    </span>
                </div>
            </div>

            <input type="hidden" name="original_username" value="<?= htmlspecialchars($superadmin['SAdmin_Username']) ?>">
            <input type="hidden" name="update_profile" value="1">
            <button type="button" id="editButton" class="action-btn" onclick="toggleEditMode()">Edit Profile</button>
            <button type="submit" id="updateButton" class="action-btn" style="display: none;">Update Profile</button>
        </form>
    </div>
</div>

</div>

<script>
function confirmLogout() {
    if (confirm("Are you sure you want to logout?")) {
        window.location.href = "logout.php";
    }
}

function toggleEditMode() {
    const editButton = document.getElementById('editButton');
    const updateButton = document.getElementById('updateButton');
    const username = document.getElementById('username');
    const admin_name = document.getElementById('admin_name');
    const password = document.getElementById('password');
    
    // Toggle between edit and update modes
    if (editButton.textContent === 'Edit Profile') {
        // Enable editing
        username.readOnly = false;
        admin_name.readOnly = false;
        password.readOnly = false;
        
        username.classList.remove('disabled-input');
        admin_name.classList.remove('disabled-input');
        password.classList.remove('disabled-input');
        
        // Clear the password field for security
        password.value = '';
        
        editButton.style.display = 'none';
        updateButton.style.display = 'inline-block';
    }
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
    // Show a preview of the selected image before submitting
    const fileInput = document.getElementById('profileImageUpload');
    const imagePreview = document.getElementById('profileImage');
    
    if (fileInput.files && fileInput.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
            
            // Submit the form after a short delay to show the preview
            setTimeout(function() {
                document.getElementById('imageUploadForm').submit();
            }, 500);
        }
        
        reader.readAsDataURL(fileInput.files[0]);
    }
}
</script>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>