<?php
session_start(); // Start the session
// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/mentors.css">
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentors</title>
</head>
<body>

<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Icon">
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['user_full_name']); ?>
        </span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
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
             <li class="navList active">
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
      <img src="../uploads/img/logo.png" alt="Logo"> </div>

      <h1>Manage Mentors</h1>
    <div class="dashboard">
        <div class="top-bar">
            <button id="btnMentors">
                <ion-icon name="people-outline"></ion-icon>
                <span>Coach Mentors</span> <span id="approvedCount">0</span>
            </button>
            <button id="btnApplicants">
                <ion-icon name="person-add-outline"></ion-icon>
                <span>Applicants</span> <span id="applicantCount">0</span>
            </button>
            <button id="btnRejected">
                <ion-icon name="close-circle-outline"></ion-icon>
                <span>Rejected</span> <span id="rejectedCount">0</span>
            </button>
        </div>

        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Search by ID or Name...">
            <button onclick="searchMentors()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
        </div>

        <div id="tableContainer"></div>
        <div id="detailView" class="hidden"></div>
    </div>
</section>

<script>
    // --- Data fetched from PHP and inlined JS logic ---

    // Fetch mentor data from the new 'users' table
    const mentorData = <?php
        // The SQL query is updated to select users with the 'Mentor' type
        $sql = "SELECT * FROM users WHERE user_type = 'Mentor'";
        $result = $conn->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode($data);
        $conn->close();
    ?>;

    // --- Start of inlined and updated admin_mentors.js ---
    
    // Element selections
    const btnMentors = document.getElementById('btnMentors');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnRejected = document.getElementById("btnRejected");
    const tableContainer = document.getElementById('tableContainer');
    const detailView = document.getElementById('detailView');
    const approvedCount = document.getElementById('approvedCount');
    const applicantCount = document.getElementById('applicantCount');
    const rejectedCount = document.getElementById("rejectedCount");
    const searchInput = document.getElementById('searchInput');

    // Filter data based on status (using new snake_case 'status' column)
    let approved = mentorData.filter(m => m.status === "Approved");
    let applicants = mentorData.filter(m => m.status === "Under Review");
    let rejected = mentorData.filter(m => m.status === "Rejected");

    // Update counts
    approvedCount.textContent = approved.length;
    applicantCount.textContent = applicants.length;
    rejectedCount.textContent = rejected.length;

    let currentTable = null;

    // Function to search mentors by ID or name
    function searchMentors() {
        const input = searchInput.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr.data-row');

        rows.forEach(row => {
            const id = row.querySelector('td:first-child').innerText.toLowerCase();
            const firstName = row.querySelector('.first-name')?.innerText.toLowerCase() || '';
            const lastName = row.querySelector('.last-name')?.innerText.toLowerCase() || '';

            if (id.includes(input) || firstName.includes(input) || lastName.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    searchInput.addEventListener('input', searchMentors);

    // Function to display the table of mentors/applicants
    function showTable(data, isApplicant = false) {
        currentData = data;
        let html = `<table><thead>
            <tr><th>ID</th><th>First Name</th><th>Last Name</th><th>Action</th></tr>
        </thead><tbody>`;

        data.forEach(row => {
            // Updated to use new column names: user_id, first_name, last_name
            html += `<tr class="data-row">
                <td>${row.user_id}</td>
                <td class="first-name">${row.first_name}</td>
                <td class="last-name">${row.last_name}</td>
                <td><button onclick="viewDetails(${row.user_id}, ${isApplicant})">View</button></td>
            </tr>`;
        });

        html += '</tbody></table>';
        tableContainer.innerHTML = html;
        tableContainer.classList.remove('hidden');
        detailView.classList.add('hidden');
    }

    // Function to display detailed view of a single user
    function viewDetails(id, isApplicant) {
        // Updated to find user by 'user_id'
        const row = mentorData.find(m => m.user_id == id);
        if (!row) return;

        // Updated to use 'resume' and 'certificates' columns
        let resumeLink = row.resume ? `<a href="view_application.php?file=${encodeURIComponent(row.resume)}&type=resume" target="_blank">View Resume</a>` : "N/A";
        let certLink = row.certificates ? `<a href="view_application.php?file=${encodeURIComponent(row.certificates)}&type=certificate" target="_blank">View Certificate</a>` : "N/A";

        // HTML structure updated with all snake_case column names
        let html = `<div class="details">
            <button onclick="backToTable()">Back</button>
            <h3>${row.first_name} ${row.last_name}</h3>
            <p><strong>First Name:</strong> <input type="text" readonly value="${row.first_name || ''}"></p>
            <p><strong>Last Name:</strong> <input type="text" readonly value="${row.last_name || ''}"></p>
            <p><strong>DOB:</strong> <input type="text" readonly value="${row.dob || ''}"></p>
            <p><strong>Gender:</strong> <input type="text" readonly value="${row.gender || ''}"></p>
            <p><strong>Email:</strong> <input type="text" readonly value="${row.email || ''}"></p>
            <p><strong>Contact:</strong> <input type="text" readonly value="${row.contact_number || ''}"></p>
            <p><strong>Username:</strong> <input type="text" readonly value="${row.username || ''}"></p>
            <p><strong>Mentored Before:</strong> <input type="text" readonly value="${row.mentored_before || ''}"></p>
            <p><strong>Experience:</strong> <input type="text" readonly value="${row.mentoring_experience || ''}"></p>
            <p><strong>Expertise:</strong> <input type="text" readonly value="${row.area_of_expertise || ''}"></p>
            <p><strong>Resume:</strong> ${resumeLink}</p>
            <p><strong>Certificates:</strong> ${certLink}</p>
            <p><strong>Status:</strong> <input type="text" readonly value="${row.status || ''}"></p>
            <p><strong>Reason for Rejection:</strong> <input type="text" readonly value="${row.reason || ''}"></p>`;

        if (isApplicant) {
            // Pass the user_id to the action functions
            html += `<div class="action-buttons">
                <button onclick="updateStatus(${id}, 'Approved')">Approve</button>
                <button onclick="showRejectionDialog(${id})">Reject</button>
            </div>`;
        }

        html += '</div>';
        detailView.innerHTML = html;
        detailView.classList.remove('hidden');
        tableContainer.classList.add('hidden');
    }
    
    // Go back to the table view
    function backToTable() {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');
    }

    // Show the rejection reason dialog
    function showRejectionDialog(id) {
        // Find user by 'user_id' and use snake_case columns
        const row = mentorData.find(m => m.user_id == id);
        const prefix = row.gender && row.gender.toLowerCase() === 'female' ? 'Ms.' : 'Mr.';
        
        const dialog = document.createElement('div');
        dialog.className = 'rejection-dialog';
        dialog.innerHTML = `
            <div class="rejection-content">
            <h3>Rejection Reason</h3>
            <p>Please provide a reason for rejecting ${prefix} ${row.first_name} ${row.last_name}'s application:</p>
            <textarea id="rejectionReason" placeholder="Enter rejection reason here..."></textarea>
            <div class="dialog-buttons">
                <button id="cancelReject">Cancel</button>
                <button id="confirmReject">Confirm Rejection</button>
            </div>
            </div>
        `;
        document.body.appendChild(dialog);
        
        document.getElementById('cancelReject').addEventListener('click', () => {
            document.body.removeChild(dialog);
        });
        
        document.getElementById('confirmReject').addEventListener('click', () => {
            const reason = document.getElementById('rejectionReason').value.trim();
            if (reason === '') {
                alert('Please provide a rejection reason.');
                return;
            }
            updateStatusWithReason(id, 'Rejected', reason);
            document.body.removeChild(dialog);
        });
    }

    // Update status without a reason (for approvals)
    function updateStatus(id, newStatus) {
        fetch('update_mentor_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&status=${newStatus}`
        })
        .then(response => response.text())
        .then(msg => {
            alert(msg);
            location.reload();
        }).catch(error => console.error('Error:', error));
    }

    // Update status with a rejection reason
    function updateStatusWithReason(id, newStatus, reason) {
        fetch('update_mentor_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&status=${newStatus}&reason=${encodeURIComponent(reason)}`
        })
        .then(response => response.text())
        .then(msg => {
            alert(msg);
            location.reload();
        }).catch(error => console.error('Error:', error));
    }

    // Button click handlers
    btnMentors.onclick = () => {
        showTable(approved, false);
    };

    btnApplicants.onclick = () => {
        showTable(applicants, true);
    };

    btnRejected.onclick = () => {
        showTable(rejected, false);
    };
    
    // Initial view: show applicants by default if there are any, otherwise show mentors
    document.addEventListener('DOMContentLoaded', () => {
        if (applicants.length > 0) {
            showTable(applicants, true);
        } else {
            showTable(approved, false);
        }
    });
    // --- End of inlined admin_mentors.js ---

    // Logout confirmation
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../logout.php";
        }
    }
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>