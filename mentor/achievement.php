<?php
session_start();

require '../connection/db_connection.php';

// Define requirements for the tiers (using placeholder values since they aren't defined in the original snippet)
$certified_req_sessions = 5; 
$distinguished_req_feedback = 10;
$elite_req_resources = 3;

// SESSION CHECK - UPDATED for the new 'users' table structure
// We now check for a general 'username' and ensure the 'user_type' is 'Mentor'.
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'Mentor') {
  // Redirect to a general login page if the user is not a logged-in mentor.
  header("Location: ../login.php"); // CHNAGE: Redirect to your main login page
  exit();
}

// FETCH Mentor_Name AND icon BASED ON username from the 'users' table
$username = $_SESSION['username']; // CHANGE: Using the new 'username' session variable
// CHANGE: The SQL query now targets the 'users' table instead of 'applications'.
// Column names are updated from First_Name, Last_Name, Mentor_Icon to first_name, last_name, icon.
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS Mentor_Name, icon FROM users WHERE username = ? AND user_type = 'Mentor'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username); // CHANGE: Binding the new $username variable
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];

  // Check if icon exists and is not empty
  // CHANGE: Updated column name from 'Mentor_Icon' to 'icon'
  if (isset($row['icon']) && !empty($row['icon'])) {
    $_SESSION['mentor_icon'] = $row['icon'];
  } else {
    // Provide a default icon if none is set
    $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
  }
} else {
  // Handle case where mentor is not found, though session check should prevent this
  $_SESSION['mentor_name'] = "Unknown Mentor";
  $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
}

// NO CHANGE NEEDED BELOW THIS POINT FOR DATA FETCHING
// The following queries correctly use the mentor's full name, which we retrieved above.

// FETCH Course_Title AND Skill_Level BASED ON Mentor_Name
$mentorName = $_SESSION['mentor_name'];
$courseSql = "SELECT Course_Title, Skill_Level FROM courses WHERE Assigned_Mentor = ?";
$courseStmt = $conn->prepare($courseSql);
$courseStmt->bind_param("s", $mentorName);
$courseStmt->execute();
$courseResult = $courseStmt->get_result();

if ($courseResult->num_rows === 1) {
    $courseRow = $courseResult->fetch_assoc();
    $_SESSION['course_title'] = $courseRow['Course_Title'];
    $_SESSION['skill_level'] = $courseRow['Skill_Level'];
} else {
    $_SESSION['course_title'] = "No Assigned Course";
    $_SESSION['skill_level'] = "-";
}

$courseStmt->close();

// Query to count approved resources uploaded by the mentor
$resourceSql = "SELECT COUNT(*) AS approved_count FROM resources WHERE UploadedBy = ? AND Status = 'Approved'";
$resourceStmt = $conn->prepare($resourceSql);
$resourceStmt->bind_param("s", $mentorName);
$resourceStmt->execute();
$resourceResult = $resourceStmt->get_result();

if ($resourceResult->num_rows === 1) {
    $resourceRow = $resourceResult->fetch_assoc();
    $approvedResourcesCount = $resourceRow['approved_count'];
} else {
    $approvedResourcesCount = 0;
}

$resourceStmt->close();

// Query to count approved resources uploaded by the mentor
$resourceSql = "SELECT COUNT(*) AS approved_count FROM resources WHERE UploadedBy = ? AND Status = 'Approved'";
$resourceStmt = $conn->prepare($resourceSql);
$resourceStmt->bind_param("s", $mentorName);
$resourceStmt->execute();
$resourceResult = $resourceStmt->get_result();

if ($resourceResult->num_rows === 1) {
    $resourceRow = $resourceResult->fetch_assoc();
    $approvedResourcesCount = $resourceRow['approved_count'];
} else {
    $approvedResourcesCount = 0;
}

$resourceStmt->close();


// FETCH Number of Sessions BASED ON Course_Title
$courseTitle = $_SESSION['course_title'];
$sessionCount = 0;

if ($courseTitle !== "No Assigned Course") {
    $sessionSql = "SELECT COUNT(*) AS session_count FROM sessions WHERE Course_Title = ?";
    $sessionStmt = $conn->prepare($sessionSql);
    $sessionStmt->bind_param("s", $courseTitle);
    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();

    if ($sessionResult->num_rows === 1) {
        $sessionRow = $sessionResult->fetch_assoc();
        $sessionCount = $sessionRow['session_count'];
    }

    $sessionStmt->close();
  }
// Determine Unlock Statuses
$certified_unlocked = $sessionCount >= $certified_req_sessions;
$distinguished_unlocked = $certified_unlocked && ($feedbackCount >= $distinguished_req_feedback);
$elite_unlocked = $distinguished_unlocked && ($approvedResourcesCount >= $elite_req_resources);


$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/achievement.css" />
  <link rel="stylesheet" href="css/navigation.css"/>
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Achievement | Mentor</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['mentor_icon']); ?>" alt="Mentor Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['mentor_name']); ?>
        </span>
        <span class="admin-role">Mentor</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
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
        <a href="courses.php">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList">
        <a href="sessions.php">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="feedbacks.php">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="activities.php">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList">
        <a href="resource.php">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
      <li class="navList active">
        <a href="achievement.php">
          <ion-icon name="trophy-outline"></ion-icon>
          <span class="links">Achievement</span>
        </a>
      </li>
    </ul>
    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout(event)">
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

    <div id="homeContent" style="padding: 20px;"></div>

<section class="achievement-container">
    <h1 class="page-title">My Achievements</h1>
    <p class="page-description">
        Celebrate your milestones! Below are the tiers for achieving certificates and recognition here on COACH. Click on any tier to see the full description and requirements.
    </p>

    <div class="achievement-tiers">

        <div class="tier-card tier-certified">
            <span class="tier-icon">🥉</span>
            <h2 class="tier-title">Certified Mentor</h2>
            <p class="tier-description">
                The foundational level recognizing mentors who have successfully completed the core training modules and mentored their first batch of users. This tier establishes you as a recognized and capable mentor in the COACH community.
            </p>
            <button class="tier-button certified-button">View Details</button>
        </div>

        <div class="tier-card tier-distinguished">
            <span class="tier-icon">🥈</span>
            <h2 class="tier-title">Distinguished Mentor</h2>
            <p class="tier-description">
                Awarded to mentors who have demonstrated consistent excellence, successfully guided multiple mentees, and received high feedback scores. You are a proven asset to our community.
            </p>
            <button class="tier-button distinguished-button">View Details</button>
        </div>

        <div class="tier-card tier-elite">
            <span class="tier-icon">👑</span>
            <h2 class="tier-title">Elite Mentor</h2>
            <p class="tier-description">
                The highest tier reserved for top-performing mentors who have contributed significantly to the platform, perhaps by creating resources or training new mentors. This status grants special recognition and privileges.
            </p>
            <button class="tier-button elite-button">View Details</button>
        </div>

    </div>
</section>

<div id="modal-certified" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('certified')">&times;</span>
        <h2>Certified Mentor Progress</h2>
        <p>Complete the following requirements to achieve the **Certified Mentor** status and unlock your certificate.</p>
        
        <ul class="progress-list">
            <li class="certified-req-1">
                <span class="progress-item-text">Complete Core Mentor Training Modules</span>
                                <span class="progress-status status-complete" data-current="1" data-required="1">
                    <ion-icon name="checkmark-circle"></ion-icon> Complete
                </span>
            </li>
            <li class="certified-req-2">
                <span class="progress-item-text">Successfully conduct at least **<?php echo $certified_req_sessions; ?>** mentorship sessions.</span>
                                <span class="progress-status" data-current="<?php echo $sessionCount; ?>" data-required="<?php echo $certified_req_sessions; ?>">
                    <?php echo $sessionCount; ?>/<?php echo $certified_req_sessions; ?>
                </span>
            </li>
        </ul>
        
        <button id="certified-download-btn" class="certificate-button" disabled>
            Download Certified Mentor Certificate
        </button>
    </div>
</div>

<div id="modal-distinguished" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('distinguished')">&times;</span>
        <h2>Distinguished Mentor Progress</h2>
        <p>Complete the following requirements to achieve the **Distinguished Mentor** status and unlock your certificate.</p>
        
        <ul class="progress-list">
            <li class="distinguished-req-1">
                <span class="progress-item-text">Achieve Certified Mentor Status</span>
                                <span class="progress-status" data-current="<?php echo $certified_unlocked ? 1 : 0; ?>" data-required="1">
                     <?php echo $certified_unlocked ? 'Unlocked' : 'Pending'; ?>
                </span>
            </li>
            <li class="distinguished-req-2">
                <span class="progress-item-text">Receive at least **<?php echo $distinguished_req_feedback; ?>** positive mentee feedback reports.</span>
                                <span class="progress-status" data-current="<?php echo $feedbackCount; ?>" data-required="<?php echo $distinguished_req_feedback; ?>">
                    <?php echo $feedbackCount; ?>/<?php echo $distinguished_req_feedback; ?>
                </span>
            </li>
        </ul>
        
        <button id="distinguished-download-btn" class="certificate-button" disabled>
            Download Distinguished Mentor Certificate
        </button>
    </div>
</div>

<div id="modal-elite" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('elite')">&times;</span>
        <h2>Elite Mentor Progress</h2>
        <p>Complete the following requirements to achieve the **Elite Mentor** status and unlock your certificate.</p>
        
        <ul class="progress-list">
            <li class="elite-req-1">
                <span class="progress-item-text">Achieve Distinguished Mentor Status</span>
                                <span class="progress-status" data-current="<?php echo $distinguished_unlocked ? 1 : 0; ?>" data-required="1">
                    <?php echo $distinguished_unlocked ? 'Unlocked' : 'Pending'; ?>
                </span>
            </li>
            <li class="elite-req-2">
                <span class="progress-item-text">Upload and have **<?php echo $elite_req_resources; ?>** resources approved in the Resource Library.</span>
                                <span class="progress-status" data-current="<?php echo $approvedResourcesCount; ?>" data-required="<?php echo $elite_req_resources; ?>">
                    <?php echo $approvedResourcesCount; ?>/<?php echo $elite_req_resources; ?>
                </span>
            </li>
        </ul>
        
        <button id="elite-download-btn" class="certificate-button" disabled>
            Download Elite Mentor Certificate
        </button>
    </div>
</div>

<script>
    // PHP variables passed to JavaScript
    const certifiedUnlocked = <?php echo json_encode($certified_unlocked); ?>;
    const distinguishedUnlocked = <?php echo json_encode($distinguished_unlocked); ?>;
    // FIX: Corrected variable name from $mentor_name to $_SESSION['mentor_name']
    const mentorName = <?php echo json_encode($_SESSION['mentor_name']); ?>; 
    
    /**
     * Toggles the visibility of a specific modal.
     * @param {string} tier - The tier name ('certified', 'distinguished', 'elite').
     */
    function openModal(tier) {
        document.getElementById(`modal-${tier}`).classList.add('active');
        // Hide scrollbar on body when modal is open
        document.body.style.overflow = 'hidden';
        checkProgress(tier); // Check and update progress when modal opens
    }

    function closeModal(tier) {
        document.getElementById(`modal-${tier}`).classList.remove('active');
        document.body.style.overflow = ''; // Restore body scroll
    }

    // Close modal when clicking outside the content
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                const tier = overlay.id.replace('modal-', '');
                closeModal(tier);
            }
        });
    });

    /**
     * Checks progress requirements and updates the UI (status color, checkmark icon).
     * Also enables the download button if all criteria are met.
     * @param {string} tier - The tier name.
     */
    function checkProgress(tier) {
        let allCriteriaMet = true;
        
        // Loop through all progress status elements in the current modal
        document.querySelectorAll(`#modal-${tier} .progress-status`).forEach(statusEl => {
            const current = parseInt(statusEl.getAttribute('data-current'));
            const required = parseInt(statusEl.getAttribute('data-required'));
            
            // Check if status is explicitly set to 'Complete' or calculated as complete
            const isComplete = (statusEl.textContent.trim() === 'Complete') || 
                               (statusEl.textContent.trim() === 'Unlocked') || 
                               (current >= required);

            if (isComplete) {
                statusEl.classList.remove('status-incomplete');
                statusEl.classList.add('status-complete');
                // Update icon to checkmark if it's not a simple 'Unlocked' label
                if (statusEl.textContent.trim().includes('Complete') || statusEl.textContent.trim().includes('Unlocked')) {
                    statusEl.innerHTML = `<ion-icon name="checkmark-circle"></ion-icon> ${statusEl.textContent.trim()}`;
                } else {
                     statusEl.innerHTML = `<ion-icon name="checkmark-circle"></ion-icon> ${current}/${required}`;
                }
            } else {
                allCriteriaMet = false;
                statusEl.classList.remove('status-complete');
                statusEl.classList.add('status-incomplete');
                // Update icon to close/error icon
                if (statusEl.textContent.trim().includes('Pending')) {
                    statusEl.innerHTML = `<ion-icon name="close-circle"></ion-icon> Pending`;
                } else {
                    statusEl.innerHTML = `<ion-icon name="close-circle"></ion-icon> ${current}/${required}`;
                }
            }
        });
        
        // Handle the download button state
        const downloadBtn = document.getElementById(`${tier}-download-btn`);
        if (allCriteriaMet) {
            downloadBtn.disabled = false;
            downloadBtn.classList.add('unlocked');
            downloadBtn.onclick = () => downloadCertificate(tier);
        } else {
            downloadBtn.disabled = true;
            downloadBtn.classList.remove('unlocked');
            downloadBtn.onclick = null; // Remove click handler when disabled
        }
    }

    /**
     * Mocks the certificate download action. 
     */
    function downloadCertificate(tier) {
        // Simple mock behavior: in a real application, this would trigger 
        // a server-side PDF/image generation script.
        const tierTitle = tier.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
        
        // IMPORTANT: Do NOT use alert() in production. Using it here for a quick mock.
        // Replace with a custom modal confirmation in a real app.
        const message = `Congratulations ${mentorName}!\n\nSimulating download for: ${tierTitle} Mentor Certificate.\n\n(In a real app, a PDF/image file would be generated by the server here.)`;
        
        // Since custom modals are required instead of alert(), I will log to console instead
        console.log("--- Certificate Download Initiated ---");
        console.log(message);
        console.log("--------------------------------------");
        
        // Since I cannot use alert() or create a new complex modal, I'll update the button text temporarily to confirm the action
        const downloadBtn = document.getElementById(`${tier}-download-btn`);
        const originalText = downloadBtn.textContent;
        downloadBtn.textContent = "Download Started!";
        setTimeout(() => {
             downloadBtn.textContent = originalText;
        }, 2000);
    }

    // **********************************************
    // FIX START: ADD EVENT LISTENERS FOR VIEW DETAILS BUTTONS
    // **********************************************
    document.addEventListener('DOMContentLoaded', () => {
        // Certified Mentor button
        const certifiedBtn = document.querySelector('.certified-button');
        if (certifiedBtn) {
            certifiedBtn.addEventListener('click', () => {
                openModal('certified');
            });
        }

        // Distinguished Mentor button
        const distinguishedBtn = document.querySelector('.distinguished-button');
        if (distinguishedBtn) {
            distinguishedBtn.addEventListener('click', () => {
                openModal('distinguished');
            });
        }

        // Elite Mentor button
        const eliteBtn = document.querySelector('.elite-button');
        if (eliteBtn) {
            eliteBtn.addEventListener('click', () => {
                openModal('elite');
            });
        }
    });
    // **********************************************
    // FIX END
    // **********************************************

    // Initialize progress checks on page load
    window.onload = function() {
        checkProgress('certified');
        checkProgress('distinguished');
        checkProgress('elite');
    };

    // Include the logout logic from your original file
    function confirmLogout(event) {
        event.preventDefault();
        document.getElementById('logoutDialog').style.display = 'flex';
    }

    document.getElementById('cancelLogout').onclick = function() {
        document.getElementById('logoutDialog').style.display = 'none';
    };

    document.getElementById('confirmLogoutBtn').onclick = function() {
        // In a real application, replace this with actual logout logic (e.g., redirect to 'logout.php')
        window.location.href = "../logout.php"; 
    };
</script>
  
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
  </script>
  <div id="logoutDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3>Confirm Logout</h3>
        <p>Are you sure you want to log out?</p>
        <div class="dialog-buttons">
            <button id="cancelLogout" type="button">Cancel</button>
            <button id="confirmLogoutBtn" type="button">Logout</button>
        </div>
    </div>
</div>
</body>
</html>