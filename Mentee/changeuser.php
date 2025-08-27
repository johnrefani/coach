<?php
// Start session (if not already started)
session_start();

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root"; // Replace with your database username
$password = ""; // Replace with your database password
$dbname = "coach"; // Replace with your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get username from session
$current_username = $_SESSION['username'];

// Variable to store messages
$message = "";
$messageType = "";

// Handle form submission for username change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_username'])) {
    // Get form data
    $new_username = trim($_POST['new_username']);
    $password = $_POST['password'];
    
    // Basic validation
    if (empty($new_username)) {
        $message = "New username cannot be empty";
        $messageType = "error";
    } else {
        // Check if the new username already exists
        $check_sql = "SELECT Username FROM mentee_profiles WHERE Username = ? AND Username != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $new_username, $current_username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = "Username already exists. Please choose another one.";
            $messageType = "error";
        } else {
            // Get the hashed password from database to verify
            $get_password_sql = "SELECT password FROM mentee_profiles WHERE Username = ?";
            $get_password_stmt = $conn->prepare($get_password_sql);
            $get_password_stmt->bind_param("s", $current_username);
            $get_password_stmt->execute();
            $password_result = $get_password_stmt->get_result();
            
            if ($password_result->num_rows > 0) {
                $row = $password_result->fetch_assoc();
                $hashed_password = $row['password'];
                
                // Verify password
                if (password_verify($password, $hashed_password)) {
                    // Update username in database
                    $update_sql = "UPDATE mentee_profiles SET Username = ? WHERE Username = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ss", $new_username, $current_username);
                    
                    if ($update_stmt->execute()) {
                        // Update session with new username
                        $_SESSION['username'] = $new_username;
                        
                        $message = "Username updated successfully!";
                        $messageType = "success";
                        $current_username = $new_username; // Update for display on page
                    } else {
                        $message = "Error updating username: " . $conn->error;
                        $messageType = "error";
                    }
                    
                    $update_stmt->close();
                } else {
                    $message = "Incorrect password. Please try again.";
                    $messageType = "error";
                }
                
                $get_password_stmt->close();
            } else {
                $message = "Error retrieving account information.";
                $messageType = "error";
            }
            
            $check_stmt->close();
        }
    }
}

// Fetch current user data
$sql = "SELECT First_Name, Last_Name, Username, Mentee_Icon 
        FROM mentee_profiles 
        WHERE Username = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $current_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Fetch data
    $row = $result->fetch_assoc();
    
    // Assign values to variables
    $first_name = $row['First_Name'];
    $last_name = $row['Last_Name'];
    $name = $first_name . " " . $last_name;
    $profile_picture = $row['Mentee_Icon'];
    
} else {
    // No user found with that username
    echo "Error: User profile not found";
    exit();
}

// Fetch first name and mentee icon again
$firstName = '';
$menteeIcon = '';

$sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $current_username); // FIXED: use the correct username
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['First_Name'];
    $menteeIcon = $row['Mentee_Icon'];
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
  <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
  <link rel="stylesheet" href="css/changestyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Change Username</title>
  <style>
    .message {
      padding: 10px;
      border-radius: 5px;
      margin: 10px 0;
      text-align: center;
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
  </style>
</head>
<body>
     <!-- Navigation Section -->
     <section class="background" id="home">
        <nav class="navbar">
          <div class="logo">
            <img src="LogoCoach.png" alt="Logo">
            <span>COACH</span>
          </div>
    
          <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="CoachMenteeHome.php">Home</a></li>
          <li><a href="CoachMentee.php#courses">Courses</a></li>
          <li><a href="CoachMentee.php#resourceLibrary">Resource Library</a></li>
          <li><a href="CoachMenteeActivities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="group-chat.php">Forums</a></li>
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
      <li><a href="#settings">Settings</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
        </nav>
    </section>


    <main class="profile-container">
    <nav class="tabs">
      <button onclick="window.location.href='profile.php'">Profile</button>
      <button onclick="window.location.href='editprof.php'">Edit Profile</button>
      <button onclick="window.location.href='emailverify.php'">Email Verification</button>
      <button onclick="window.location.href='phoneverify.php'">Phone Verification</button>
      <button class="active" onclick="window.location.href='changeuser.php'">Change Username</button>
      <button onclick="window.location.href='resetpass.php'">Reset Password</button>
    </nav>

    <div class="container">
      <h2>Change Username</h2>
      
      <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>">
          <?php echo $message; ?>
        </div>
      <?php endif; ?>
      
      <form method="POST">
        <label>Current Username 
          <input type="text" value="<?php echo $current_username; ?>" disabled>
        </label>
        <label>New Username 
          <input type="text" name="new_username" placeholder="Enter new username" required>
        </label>
        <label>Confirm Password 
          <input type="password" name="password" placeholder="Enter your password" required>
        </label>
        <button type="submit" name="update_username">Update Username</button>
      </form>
    </div>
  </main>

  <script>
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
  </script>
</body>
</html>