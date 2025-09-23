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

// Get username from session
$username = $_SESSION['username'];

// Variable to store messages
$message = "";
$messageType = "";

// Handle form submission for profile updates
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    // Get form data
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $contact_number = $_POST['contact_number'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    
    // Prepare SQL statement to update user data
    $update_sql = "UPDATE users SET 
                  first_name = ?, 
                  last_name = ?, 
                  email = ?, 
                  contact_number = ?, 
                  dob = ?, 
                  gender = ? 
                  WHERE username = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sssssss", $first_name, $last_name, $email, $contact_number, $dob, $gender, $username);
    
    if ($update_stmt->execute()) {
        $message = "Profile updated successfully!";
        $messageType = "success";
    } else {
        $message = "Error updating profile: " . $conn->error;
        $messageType = "error";
    }
    
    $update_stmt->close();
}

// Handle profile picture upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_profile_pic'])) {
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_pic']['name'];
        $filetype = $_FILES['profile_pic']['type'];
        $filesize = $_FILES['profile_pic']['size'];
        
        // Verify file extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), $allowed)) {
            $message = "Error: Please upload an image file (jpg, jpeg, png, gif)";
            $messageType = "error";
        } else {
            // Check filesize (limit to 5MB)
            if ($filesize > 5 * 1024 * 1024) {
                $message = "Error: File size exceeds the limit (5MB)";
                $messageType = "error";
            } else {
                // Create a unique filename
                $new_filename = "../uploads/profile_" . $username . "_" . time() . "." . $ext;
                
                // Make sure the uploads directory exists
                if (!file_exists('uploads')) {
                    mkdir('uploads', 0777, true);
                }
                
                // Move the uploaded file
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $new_filename)) {
                    // Update the database with the new profile picture path
                    $update_pic_sql = "UPDATE users SET icon = ? WHERE username = ?";
                    $update_pic_stmt = $conn->prepare($update_pic_sql);
                    $update_pic_stmt->bind_param("ss", $new_filename, $username);
                    
                    if ($update_pic_stmt->execute()) {
                        $message = "Profile picture updated successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error updating profile picture in database: " . $conn->error;
                        $messageType = "error";
                    }
                    
                    $update_pic_stmt->close();
                } else {
                    $message = "Error uploading file";
                    $messageType = "error";
                }
            }
        }
    } else {
        $message = "Error: No file uploaded or an error occurred";
        $messageType = "error";
    }
}

// Fetch current user data
$sql = "SELECT first_name, last_name, username, dob, gender, email, contact_number, icon 
        FROM users 
        WHERE username = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Fetch data
    $row = $result->fetch_assoc();
    
    // Assign values to variables
    $first_name = $row['first_name'];
    $last_name = $row['last_name'];
    $name = $first_name . " " . $last_name;
    $username = $row['username'];
    $dob = $row['dob'];
    $gender = $row['gender'];
    $email = $row['email'];
    $contact = $row['contact_number'];
    $profile_picture = $row['icon'];
    
} else {
    // No user found with that username
    echo "Error: User profile not found";
    exit();
}

// Fetch first name and mentee icon again
$firstName = '';
$menteeIcon = '';

$sql = "SELECT first_name, icon FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['first_name'];
    $menteeIcon = $row['icon'];
}


$stmt->close();
$conn->close();

// Function to get profile picture path
function getProfilePicture($profile_picture) {
    if ($profile_picture && !empty($profile_picture)) {
        return $profile_picture; // Return the path stored in the database
    } else {
        return "img/default_pfp.png"; // Return the default image path
    }
}

// Get the correct profile picture path
$profile_picture_path = getProfilePicture($profile_picture);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/edit-profile.css" />
  <link rel="stylesheet" href="css/message.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Edit Profile</title>
  
  </style>
</head>
<body>
     <!-- Navigation Section -->
     <section class="background" id="home">
        <nav class="navbar">
          <div class="logo">
            <img src="../uploads/img/LogoCoach.png" alt="Logo">
            <span>COACH</span>
          </div>
    
          <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="home.php">Home</a></li>
          <li><a href="course.php">Courses</a></li>
          <li><a href="resource_library.php">Resource Library</a></li>
          <li><a href="activities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="forums.php">Forums</a></li>
        </ul>
      </div>
    
          <div class="nav-profile">
  <a href="#" id="profile-icon">
    <?php if (!empty($menteeIcon)): ?>
      <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
    <?php else: ?>
      <ion-icon name="person-circle-outline" style="font-size: 35px;"></ion-icon>
    <?php endif; ?>
  </a>
</div>

<div class="sub-menu-wrap hide" id="profile-menu">
  <div class="sub-menu">
    <div class="user-info">
      <div class="user-icon">
        <?php if (!empty($menteeIcon)): ?>
          <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
        <?php else: ?>
          <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
        <?php endif; ?>
      </div>
      <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
    </div>
    <ul class="sub-menu-items">
      <li><a href="profile.php">Profile</a></li>
      <li><a href="taskprogress.php">Progress</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
        </nav>
    </section>

    <main class="profile-container">
    <nav class="tabs">
      <button onclick="window.location.href='profile.php'">Profile</button>
      <button class="active" onclick="window.location.href='edit-profile.php'">Edit Profile</button>
      <button onclick="window.location.href='verify-email.php'">Email Verification</button>
      <button onclick="window.location.href='verify-phone.php'">Phone Verification</button>
      <button onclick="window.location.href='edit-username.php'">Edit Username</button>
      <button onclick="window.location.href='reset-password.php'">Reset Password</button>
    </nav>

    <div class="container">
      <h2>Edit Profile</h2>

      <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>">
          <?php echo $message; ?>
        </div>
      <?php endif; ?>

      <!-- Profile Image Upload -->
      <div class="profile-image-section">
        <img src="<?php echo $profile_picture_path; ?>" alt="Profile Picture" id="profilePreview">
        
        <form method="POST" enctype="multipart/form-data" class="upload-form">
          <label for="profile_pic" class="upload-btn">Choose Profile Picture</label>
          <input type="file" id="profile_pic" name="profile_pic" accept="image/*" hidden onchange="previewImageAndSubmit(this)">
          <input type="submit" name="upload_profile_pic" id="submit-pic" hidden>
        </form>
      </div>

      <form method="POST">
        <label>First Name <input type="text" name="first_name" value="<?php echo $first_name; ?>" required></label>
        <label>Last Name <input type="text" name="last_name" value="<?php echo $last_name; ?>" required></label>
        <label>Email <input type="email" name="email" value="<?php echo $email; ?>" required></label>
        <label>Contact Number <input type="text" name="contact_number" value="<?php echo $contact; ?>" required></label>
        <label>Date of Birth <input type="date" name="dob" value="<?php echo $dob; ?>" required></label>
        <label>Gender
          <select name="gender" required>
            <option value="Male" <?php if ($gender == 'Male') echo 'selected'; ?>>Male</option>
            <option value="Female" <?php if ($gender == 'Female') echo 'selected'; ?>>Female</option>
            <option value="Other" <?php if ($gender == 'Other') echo 'selected'; ?>>Other</option>
          </select>
        </label>
        <button type="submit" name="update_profile">Save Changes</button>
      </form>
    </div>
  </main>

  <script>
    // Toggle profile menu
    document.getElementById('profile-icon').addEventListener('click', function(e) {
      e.preventDefault();
      const profileMenu = document.getElementById('profile-menu');
      profileMenu.classList.toggle('show');
      profileMenu.classList.remove('hide');
    });

    // Close menu when clicking elsewhere
    window.addEventListener('click', function(e) {
      const profileIcon = document.getElementById('profile-icon');
      const profileMenu = document.getElementById('profile-menu');
      if (!profileMenu.contains(e.target) && !profileIcon.contains(e.target)) {
        profileMenu.classList.remove('show');
        profileMenu.classList.add('hide');
      }
    });

    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../logout.php";
        }
    }
    // Preview image and auto-submit when file selected
    function previewImageAndSubmit(input) {
      if (input.files && input.files[0]) {
        var reader = new FileReader();
        
        reader.onload = function(e) {
          document.getElementById('profilePreview').src = e.target.result;
          // Auto-submit the form when a file is selected
          document.getElementById('submit-pic').click();
        }
        
        reader.readAsDataURL(input.files[0]);
      }
    }
  </script>
</body>
</html>