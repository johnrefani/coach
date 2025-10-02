<?php
session_start();
require '../connection/db_connection.php';

// Load PHPMailer
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if a user is logged in and is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] !== 'Admin' && $_SESSION['user_type'] !== 'Super Admin')) {
    header("Location: ../login.php"); // Redirect to a generic login page
    exit();
}

// Function to send resource notification emails
function sendResourceNotificationEmail($uploaderEmail, $uploaderName, $resourceTitle, $status, $rejectionReason = '') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'coach.hub2025@gmail.com';
        $mail->Password   = 'ehke bope zjkj pwds';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('coach.hub2025@gmail.com', 'COACH Team');
        $mail->addAddress($uploaderEmail, $uploaderName);

        // Content
        $mail->isHTML(true);
        
        if ($status === 'Approved') {
            $mail->Subject = "Resource Approved - " . $resourceTitle;
            $mail->Body = "
            <html>
            <head>
              <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: rgb(241, 223, 252); }
                .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .resource-details { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
              </style>
            </head>
            <body>
              <div class='container'>
                <div class='header'>
                  <h2>Resource Approved</h2>
                </div>
                <div class='content'>
                  <p>Dear <b>" . htmlspecialchars($uploaderName) . "</b>,</p>
                  <p>Congratulations! Your resource has been <b>approved</b> and is now available in the COACH Resource Library. ðŸŽ‰</p>
                  
                  <div class='resource-details'>
                    <h3>Resource Details:</h3>
                    <p><strong>Title:</strong> " . htmlspecialchars($resourceTitle) . "</p>
                  </div>

                  <p>Your contribution will help other learners in their educational journey. Thank you for sharing your knowledge with the COACH community!</p>
                  <p>You can view your approved resource by logging in to your account at <a href='https://coach-hub.online/login.php'>COACH</a>.</p>
                </div>
                <div class='footer'>
                  <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                </div>
              </div>
            </body>
            </html>
            ";
        } else { // rejected
            $mail->Subject = "Resource Update - " . $resourceTitle;
            $mail->Body = "
            <html>
            <head>
              <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: rgb(241, 223, 252); }
                .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .resource-details { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .notes-box { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 5px; }
                .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
              </style>
            </head>
            <body>
              <div class='container'>
                <div class='header'>
                  <h2>Resource Update</h2>
                </div>
                <div class='content'>
                  <p>Dear <b>" . htmlspecialchars($uploaderName) . "</b>,</p>
                  <p>Thank you for your contribution to the COACH Resource Library. Unfortunately, your resource could not be approved at this time.</p>
                  
                  <div class='resource-details'>
                    <h3>Resource Details:</h3>
                    <p><strong>Title:</strong> " . htmlspecialchars($resourceTitle) . "</p>
                  </div>";
                  
            if (!empty($rejectionReason)) {
                $mail->Body .= "
                  <div class='notes-box'>
                    <h4>Feedback:</h4>
                    <p>" . nl2br(htmlspecialchars($rejectionReason)) . "</p>
                  </div>";
            }

            $mail->Body .= "
                  <p>You are welcome to revise your resource based on the feedback and resubmit it. Please log in to your account at <a href='https://coach-hub.online/login.php'>COACH</a> to upload an updated version.</p>
                  <p>We appreciate your effort to contribute to our learning community.</p>
                </div>
                <div class='footer'>
                  <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                </div>
              </div>
            </body>
            </html>
            ";
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle resource status updates with email notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['resource_id'])) {
    $resourceId = $_POST['resource_id'];
    $action = $_POST['action'];
    $rejectionReason = isset($_POST['rejection_reason']) ? $_POST['rejection_reason'] : '';
    
    // Get resource and uploader details
    $stmt = $conn->prepare("SELECT r.Resource_Title, u.email, CONCAT(u.first_name, ' ', u.last_name) as uploader_name 
                           FROM resources r
                           JOIN users u ON r.user_id = u.user_id
                           WHERE r.Resource_ID = ?");
    $stmt->bind_param("i", $resourceId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $resourceData = $result->fetch_assoc();
        $resourceTitle = $resourceData['Resource_Title'];
        $uploaderEmail = $resourceData['email'];
        $uploaderName = $resourceData['uploader_name'];
        
        // Update resource status
        if ($action === 'Rejected' && !empty($rejectionReason)) {
            $updateStmt = $conn->prepare("UPDATE resources SET Status = ?, Reason = ? WHERE Resource_ID = ?");
            $updateStmt->bind_param("ssi", $action, $rejectionReason, $resourceId);
        } else {
            $updateStmt = $conn->prepare("UPDATE resources SET Status = ? WHERE Resource_ID = ?");
            $updateStmt->bind_param("si", $action, $resourceId);
        }
        
        if ($updateStmt->execute()) {
            // Send email notification
            $emailSent = sendResourceNotificationEmail($uploaderEmail, $uploaderName, $resourceTitle, $action, $rejectionReason);
            
            if ($emailSent) {
                $message = $action === 'Approved' ? "Resource approved and email notification sent!" : "Resource rejected and email notification sent!";
            } else {
                $message = $action === 'Approved' ? "Resource approved but email notification failed." : "Resource rejected but email notification failed.";
            }
            
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($message));
            exit();
        }
        $updateStmt->close();
    }
    $stmt->close();
}

// Display message if redirected with one
$message = isset($_GET['message']) ? $_GET['message'] : '';

// Fetch admin details for the navbar
$admin_id = $_SESSION['user_id'];
$admin_details_query = $conn->prepare("SELECT first_name, last_name, icon FROM users WHERE user_id = ?");
$admin_details_query->bind_param("i", $admin_id);
$admin_details_query->execute();
$admin_result = $admin_details_query->get_result();
$admin_info = $admin_result->fetch_assoc();

$admin_name = htmlspecialchars($admin_info['first_name'] . ' ' . $admin_info['last_name']);
$admin_icon = !empty($admin_info['icon']) ? htmlspecialchars($admin_info['icon']) : '../uploads/img/default_profile.png';
$admin_username = htmlspecialchars($_SESSION['username']);

// Set display role based on user type
$displayRole = $_SESSION['user_type'] === 'Super Admin' ? 'Super Admin' : 'Moderator';

// FETCH RESOURCES with uploader's name from the new users table
$resources = [];
$sql = "SELECT r.*, u.first_name, u.last_name 
        FROM resources r
        LEFT JOIN users u ON r.user_id = u.user_id 
        ORDER BY r.Resource_ID DESC";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
  while ($row = $res->fetch_assoc()) {
    $resources[] = $row;
  }
}

// Count resources by status
$approvedCount = 0;
$pendingCount = 0;
$rejectedCount = 0;

foreach ($resources as $resource) {
    if ($resource['Status'] == 'Approved') {
        $approvedCount++;
    } elseif ($resource['Status'] == 'Under Review') {
        $pendingCount++;
    } elseif ($resource['Status'] == 'Rejected') {
        $rejectedCount++;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/resources.css" />
  <link rel="icon" href="uploads/img/coachicon.svg" type="image/svg+xml">
  <title><?php echo $displayRole; ?> Dashboard - Resources</title>
  <style>
    .message {
      background-color: #d4edda;
      color: #155724;
      padding: 10px;
      margin: 20px 0;
      border: 1px solid #c3e6cb;
      border-radius: 5px;
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
      <img src="<?php echo $admin_icon; ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name"><?php echo $admin_name; ?></span>
        <span class="admin-role"><?php echo $displayRole; ?></span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($admin_username) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="#" onclick="window.location='dashboard.php'">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
<li class="navList"><a href="moderators.php"><ion-icon name="lock-closed-outline"></ion-icon><span class="links">Moderators</span></a></li>
            <li class="navList">
                <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>      
      <li class="navList">
        <a href="#" onclick="window.location='courses.php'">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Courses</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='manage_mentees.php'">
          <ion-icon name="person-outline"></ion-icon>
          <span class="links">Mentees</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='manage_mentors.php'">
          <ion-icon name="people-outline"></ion-icon>
          <span class="links">Mentors</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='manage_session.php'">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='channels.php'">
          <ion-icon name="chatbubbles-outline"></ion-icon>
          <span class="links">Channels</span>
        </a>
      </li>
      <li class="navList"> <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
      <li class="navList">
        <a href="#" onclick="window.location='activities.php'">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList active">
        <a href="#" onclick="window.location='resource.php'">
          <ion-icon name="library-outline"></ion-icon>
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
      <img src="../uploads/img/logo.png" alt="Logo">
    </div>

    <div id="resourceLibraryContent" style="padding: 20px;">
    <h1 class="section-title" id="resourceTitle">Manage Resource Library</h1>
    
    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="dashboard">
      <div class="top-bar">
        <button class="filter-btn active" data-status="Approved">
          <ion-icon name="checkmark-circle-outline"></ion-icon>
          <span>Resources</span> <span id="approvedCount"><?php echo $approvedCount; ?></span>
        </button>
        <button class="filter-btn" data-status="Under Review">
          <ion-icon name="time-outline"></ion-icon>
          <span>Pending Resources</span> <span id="pendingresourceCount"><?php echo $pendingCount; ?></span>
        </button>
        <button class="filter-btn" data-status="Rejected">
          <ion-icon name="close-circle-outline"></ion-icon>
          <span>Rejected Resources</span> <span id="rejectedCount"><?php echo $rejectedCount; ?></span>
        </button>
      </div>

      <div class="category-bar">
        <button class="category-btn active" data-category="all">All</button>
        <button class="category-btn" data-category="IT">Information Technology</button>
        <button class="category-btn" data-category="CS">Computer Science</button>
        <button class="category-btn" data-category="DS">Data Science</button>
        <button class="category-btn" data-category="GD">Game Development</button>
        <button class="category-btn" data-category="DAT">Digital Animation</button>
      </div>

      <div id="resourceContainer">
        <?php foreach ($resources as $resource): ?>
        <div class="resource-card"
             data-status="<?php echo htmlspecialchars($resource['Status']); ?>"
             data-category="<?php echo htmlspecialchars($resource['Category']); ?>">

          <?php if (!empty($resource['Resource_Icon']) && file_exists("../uploads/" . $resource['Resource_Icon'])): ?>
            <img src="../uploads/<?php echo htmlspecialchars($resource['Resource_Icon']); ?>" alt="Resource Icon" />
          <?php else: ?>
            <div class="no-image">No Image</div>
          <?php endif; ?>

          <h2><?php echo htmlspecialchars($resource['Resource_Title']); ?></h2>
          <p><strong>Type:</strong> <?php echo htmlspecialchars($resource['Resource_Type']); ?></p>
          <p><strong>Uploaded By:</strong> <?php echo htmlspecialchars($resource['first_name'] . ' ' . $resource['last_name']); ?></p>

          <?php if (!empty($resource['Resource_File']) && file_exists("../uploads/" . $resource['Resource_File'])): ?>
            <p><strong>File:</strong> 
              <a href="view_resource.php?file=<?php echo urlencode($resource['Resource_File']); ?>&title=<?php echo urlencode($resource['Resource_Title']); ?>" 
                 target="_blank" class="view-button">View</a>
            </p>
          <?php else: ?>
            <p><strong>File:</strong> No file uploaded or file not found</p>
          <?php endif; ?>

          <?php if ($resource['Status'] === 'Approved'): ?>
            <p class="approval-status"><strong>Status:</strong> Approved</p>
          <?php endif; ?>
          
          <?php if ($resource['Status'] === 'Rejected' && !empty($resource['Reason'])): ?>
            <p class="rejection-reason"><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($resource['Reason']); ?></p>
          <?php endif; ?>

          <?php if ($resource['Status'] === 'Under Review'): ?>
          <div class="action-buttons">
            <form method="post">
              <input type="hidden" name="resource_id" value="<?php echo $resource['Resource_ID']; ?>">
              <button type="submit" style="font-size: 14px; margin-bottom: 20px; font-weight: bold;" class="approve-btn purple-btn" name="action" value="Approved">Approve</button>
              <button type="button" style="font-size: 14px; margin-bottom: 20px; font-weight: bold;" class="reject-btn purple-btn" name="action" value="Rejected">Reject</button>
            </form>
          </div>
          <?php endif; ?>

        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Element Selection ---
        const navBar = document.querySelector("nav");
        const navToggle = document.querySelector(".navToggle");
        const body = document.body;

        const approvedCountEl = document.getElementById('approvedCount');
        const pendingresourceCountEl = document.getElementById('pendingresourceCount');
        const rejectedCountEl = document.getElementById("rejectedCount");

        const categoryButtons = document.querySelectorAll('.category-btn');
        const filterButtons = document.querySelectorAll('.filter-btn');
        const cards = document.querySelectorAll('.resource-card');

        // --- Navigation Toggle ---
        if (navToggle && navBar) {
            navToggle.addEventListener('click', () => {
                navBar.classList.toggle('close');
            });
        }

        // --- Dark Mode (from original JS, kept for functionality) ---
        const darkToggle = document.querySelector(".darkToggle");
        if (darkToggle) {
            darkToggle.addEventListener('click', () => {
                body.classList.toggle('dark');
                localStorage.setItem('darkMode', body.classList.contains('dark') ? 'enabled' : '');
            });

            if (localStorage.getItem('darkMode') === 'enabled') {
                body.classList.add('dark');
            }
        }

        // Create modal elements for rejection reason
        const modalContainer = document.createElement('div');
        modalContainer.className = 'rejection-modal-container';
        modalContainer.style.cssText = `
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        `;
        
        const modalContent = document.createElement('div');
        modalContent.className = 'rejection-modal-content';
        modalContent.style.cssText = `
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            max-width: 500px;
            width: 90%;
        `;
        
        modalContent.innerHTML = `
            <h3>Reason for Rejection</h3>
            <textarea id="rejectionReason" placeholder="Please provide a reason for rejection..." style="width: 100%; height: 100px; margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 3px;"></textarea>
            <div class="modal-buttons" style="text-align: right; margin-top: 15px;">
                <button id="cancelReject" style="margin-right: 10px; padding: 8px 16px; border: 1px solid #ddd; background: white; border-radius: 3px; cursor: pointer;">Cancel</button>
                <button id="confirmReject" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Confirm</button>
            </div>
        `;
        
        modalContainer.appendChild(modalContent);
        document.body.appendChild(modalContainer);

        let activeStatus = "Approved";
        let activeCategory = "all";
        let currentResourceId = null;
        let currentForm = null;

        function updateResourceVisibility() {
            cards.forEach(card => {
                const cardCategory = card.getAttribute('data-category');
                const cardStatus = card.getAttribute('data-status');

                const statusMatch = cardStatus === activeStatus;
                const categoryMatch = activeCategory === 'all' || cardCategory === activeCategory;

                card.style.display = (statusMatch && categoryMatch) ? 'flex' : 'none';
            });
            updateCountBadges();
        }

        categoryButtons.forEach(button => {
            button.addEventListener('click', () => {
                activeCategory = button.getAttribute('data-category');
                categoryButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                updateResourceVisibility();
            });
        });

        filterButtons.forEach(button => {
            button.addEventListener('click', () => {
                activeStatus = button.getAttribute('data-status');
                filterButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                const allCategoryBtn = document.querySelector('.category-btn[data-category="all"]');
                if(allCategoryBtn){
                    activeCategory = "all";
                    categoryButtons.forEach(btn => btn.classList.remove('active'));
                    allCategoryBtn.classList.add('active');
                }
                updateResourceVisibility();
            });
        });

        function updateCountBadges() {
            if (approvedCountEl) approvedCountEl.textContent = document.querySelectorAll('.resource-card[data-status="Approved"]').length;
            if (pendingresourceCountEl) pendingresourceCountEl.textContent = document.querySelectorAll('.resource-card[data-status="Under Review"]').length;
            if (rejectedCountEl) rejectedCountEl.textContent = document.querySelectorAll('.resource-card[data-status="Rejected"]').length;
        }
        
        // --- Rejection Modal Logic ---
        document.querySelectorAll('.reject-btn').forEach(button => {
            button.addEventListener('click', function() {
                currentForm = this.closest('form');
                currentResourceId = currentForm.querySelector('input[name="resource_id"]').value;
                modalContainer.style.display = 'flex';
            });
        });

        document.getElementById('cancelReject').addEventListener('click', () => {
            modalContainer.style.display = 'none';
            document.getElementById('rejectionReason').value = '';
        });

        document.getElementById('confirmReject').addEventListener('click', () => {
            const reason = document.getElementById('rejectionReason').value.trim();
            if (reason === '') {
                alert('Please provide a reason for rejection.');
                return;
            }

            // Create hidden inputs in the form and submit it
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'rejection_reason';
            reasonInput.value = reason;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'Rejected';

            currentForm.appendChild(reasonInput);
            currentForm.appendChild(actionInput);
            currentForm.submit();

            modalContainer.style.display = 'none';
            document.getElementById('rejectionReason').value = '';
        });

        modalContainer.addEventListener('click', (e) => {
            if (e.target === modalContainer) {
                modalContainer.style.display = 'none';
                document.getElementById('rejectionReason').value = '';
            }
        });
        
        // Initial setup
        updateResourceVisibility();
    });

    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }
  </script>

</body>
</html>