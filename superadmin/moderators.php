<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Super Admin'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Super Admin') {
    // If not a Super Admin, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- DATABASE CONNECTION ---
require '../connection/db_connection.php';

// Handle Create Moderator
if (isset($_POST['create'])) {
    $username = $_POST['username'];
    $name = $_POST['name']; 
    $email = $_POST['email'];
    $password = $_POST['password'];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $user_type = 'Admin'; // Set the user type explicitly

    // Split the full name into first and last names for the 'users' table
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

    // Corrected INSERT statement for the 'users' table
    $stmt = $conn->prepare("INSERT INTO users (username, password, user_type, first_name, last_name, email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $username, $hashed_password, $user_type, $first_name, $last_name, $email);
  
    if ($stmt->execute()) {
        // Email sending logic remains the same...
        $to = $email;
        $subject = "Your COACH Admin Access Credentials";
        $message = "
        <html>
        <body>
          <p>Dear $name,</p>
          <p>You have been granted administrator access (Moderator) to the COACH system. Below are your login credentials:</p>
          <div>
            <p><strong>Username:</strong> $username</p>
            <p><strong>Password:</strong> $password</p>
          </div>
          <p>Please log in using these credentials. For security, we recommend changing your password after your first login.</p>
        </body>
        </html>
        ";
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: COACH System <noreply@yourwebsite.com>" . "\r\n";
        
        if(mail($to, $subject, $message, $headers)) {
            header("Location: moderators.php?success=create&email=sent");
        } else {
            header("Location: moderators.php?success=create&email=failed");
        }
        exit();
    } else {
        $error = "Error creating moderator: " . $conn->error;
    }
    $stmt->close();
}

// Handle Update
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $username_admin = $_POST['username'];
    $name = $_POST['name'];
    $email = $_POST['email'];

    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
  
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ? WHERE user_id = ?");
    $stmt->bind_param("ssssi", $first_name, $last_name, $username_admin, $email, $id);
  
    if ($stmt->execute()) {
        header("Location: moderators.php?success=update");
        exit();
    } else {
        $error = "Error updating moderator: " . $conn->error;
    }
    $stmt->close();
}


// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: moderators.php?success=delete");
        exit();
    } else {
        $error = "Error deleting moderator: " . $conn->error;
    }
    $stmt->close();
}

// --- FETCH SUPERADMIN DATA for navbar ---
$username = $_SESSION['username'];
$stmt = $conn->prepare("SELECT first_name, last_name, icon FROM users WHERE username = ? AND user_type = 'Super Admin'");
$stmt->bind_param("s", $username);
$stmt->execute();
$superAdminResult = $stmt->get_result();

if ($superAdminResult->num_rows === 1) {
    $row = $superAdminResult->fetch_assoc();
    $_SESSION['superadmin_name'] = $row['first_name'] . ' ' . $row['last_name'];
    $_SESSION['superadmin_icon'] = !empty($row['icon']) ? $row['icon'] : 'img/default_pfp.png';
} else {
    $_SESSION['superadmin_name'] = "Super Admin";
    $_SESSION['superadmin_icon'] = 'img/default_pfp.png';
}
$stmt->close();


// --- FETCH ALL MODERATORS (Admins) for the table ---
$adminsResult = $conn->query("SELECT user_id, username, first_name, last_name, email FROM users WHERE user_type = 'Admin'");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css" />
    <link rel="stylesheet" href="css/mentee.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Moderators</title>
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
        <li class="navList active">
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
          </a>
        </li>
      </ul>

      <ul class="bottom-link">
        <li class="logout-link">
          <a href="#" onclick="confirmLogout()">
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
      <img src="../uploads/img/logo.png" alt="Logo"> </div>
      

    <?php if (isset($_GET['success'])): ?>
    <script>
        // This script can be improved to be a styled notification instead of an alert
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const successType = urlParams.get('success');
            let message = "";

            if (successType === 'create') {
                const emailStatus = urlParams.get('email');
                if (emailStatus === 'sent') {
                    message = 'Moderator created successfully and credentials sent!';
                } else if (emailStatus === 'failed') {
                    message = 'Moderator created, but the email failed to send.';
                } else {
                    message = 'Moderator created successfully!';
                }
            } else if (successType === 'update') {
                message = "Update successful!";
            } else if (successType === 'delete') {
                message = "Delete successful!";
            }

            if (message) {
                alert(message);
            }

            // Clean URL
            if (history.replaceState) {
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                history.replaceState(null, null, cleanUrl);
            }
        });
    </script>
    <?php endif; ?>

    <h1>Manage Moderators</h1>

    <div class="top-bar">
        <button onclick="showCreateForm()" class="create-btn">+ Create</button>
        <div class="search-box">
            <input type="text" id="searchInput" onkeyup="searchAdmins()" placeholder="Search moderators...">
        </div>
    </div>

    <div class="form-container" id="createForm" style="display:none;">
        <h2>Create New Moderator</h2>
        <form method="POST">
            <input type="hidden" name="create" value="1">
            <div class="form-group"><label>Full Name</label><input type="text" name="name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-buttons">
                <button type="submit" class="create-btn">Save</button>
                <button type="button" onclick="hideCreateForm()" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>

    <div id="tableContainer">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Name</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $adminsResult->fetch_assoc()): ?>
          <tr class="data-row">
            <td><?= $row['user_id'] ?></td>
            <td class="username"><?= htmlspecialchars($row['username']) ?></td>
            <td class="name"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
            <td>
              <button class="view-btn" onclick='viewAdmin(this)' data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>View</button>
              <a href="moderators.php?delete=<?= $row['user_id'] ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this moderator?')">Delete</a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <div id="detailView" style="display:none;">
      <div id="adminDetails" class="form-container">
        <h2>View / Edit Moderator Details</h2>
        <form method="POST" id="adminForm">
          <input type="hidden" name="id" id="admin_id">
          <div class="form-group"><label>Name</label><input type="text" name="name" id="name" required readonly></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required readonly></div>
          <div class="form-group"><label>Username</label><input type="text" name="username" id="username" required readonly></div>
          
          <div class="form-buttons">
            <button type="button" id="editButton" class="create-btn">Edit</button>
            <button type="submit" name="update" id="updateButton" class="create-btn" style="display: none;">Update</button>
            <button type="button" onclick="goBack()" class="cancel-btn">Back</button>
          </div>
        </form>
      </div>
    </div>

    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <script>
    function showCreateForm() {
        document.getElementById('createForm').style.display = 'block';
        document.getElementById('tableContainer').style.display = 'none';
        document.getElementById('detailView').style.display = 'none';
    }

    function hideCreateForm() {
        document.getElementById('createForm').style.display = 'none';
        document.getElementById('tableContainer').style.display = 'block';
    }

    function searchAdmins() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr.data-row');

        rows.forEach(row => {
            const username = row.querySelector('.username').innerText.toLowerCase();
            const name = row.querySelector('.name').innerText.toLowerCase();

            if (username.includes(input) || name.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function viewAdmin(button) {
      const data = JSON.parse(button.getAttribute('data-info'));
      
      // Populate form fields using correct keys from the 'users' table
      document.getElementById('admin_id').value = data.user_id;
      document.getElementById('name').value = (data.first_name + ' ' + data.last_name).trim();
      document.getElementById('username').value = data.username;
      document.getElementById('email').value = data.email;
      
      // Reset form state to readonly
      document.querySelectorAll('#adminForm input').forEach(el => el.setAttribute('readonly', true));
      document.getElementById('editButton').style.display = 'inline-block';
      document.getElementById('updateButton').style.display = 'none';
      
      // Toggle views
      document.getElementById('tableContainer').style.display = 'none';
      document.getElementById('detailView').style.display = 'block';
    }

    function goBack() {
      document.getElementById('detailView').style.display = 'none';
      document.getElementById('tableContainer').style.display = 'block';
    }

    // Handle Edit button click
    document.getElementById('editButton').addEventListener('click', function() {
        document.querySelectorAll('#adminForm input[readonly]').forEach(el => {
            el.removeAttribute('readonly');
        });
        document.getElementById('editButton').style.display = 'none';
        document.getElementById('updateButton').style.display = 'inline-block';
    });
    
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
          window.location.href = "../logout.php";
        }
      }
    </script>
</body>
</html>