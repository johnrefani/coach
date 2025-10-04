<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// SESSION CHECK: Verify if the user is logged in and is an 'Admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
  header("Location: ../login.php");
  exit();
}

// CONNECT TO DATABASE
require '../connection/db_connection.php';

// ====================
// CHART DATA FETCHING ENDPOINT (Users Growth)
// ====================
if (isset($_GET['start']) && isset($_GET['end'])) {
    header('Content-Type: application/json');
    
    $start = $_GET['start'];
    $end   = $_GET['end'];
    
    $sql = "
        SELECT LOWER(user_type) as user_type, DATE(created_at) as date, COUNT(*) as total
        FROM users
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY LOWER(user_type), DATE(created_at)
        ORDER BY date ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();
    echo json_encode($data);
    exit;
}

$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, first_name, last_name, icon FROM users WHERE user_id = ? AND user_type = 'Admin'");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['admin_username'] = $row['username'];
    $_SESSION['admin_name'] = $row['first_name'] . ' ' . $row['last_name'];
  
    if (isset($row['icon']) && !empty($row['icon'])) {
        $_SESSION['admin_icon'] = $row['icon'];
    } else {
        $_SESSION['admin_icon'] = "../uploads/img/default_pfp.png";
    }
} else {
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$stmt->close();

// ====================
// PERFORMANCE TRACKER: MENTEES PER COURSE
// ====================
$sql = "SELECT course_title, COUNT(*) as total_mentees 
        FROM session_bookings 
        GROUP BY course_title 
        ORDER BY total_mentees DESC";

$result = $conn->query($sql);

$courses = [];
$mentees_count = [];

if ($result->num_rows > 0) {
  while($row = $result->fetch_assoc()) {
    $courses[] = $row['course_title'];
    $mentees_count[] = $row['total_mentees'];
  }
}

// ========================
// Fetch from chat_messages
// ========================
$sql_forum = "SELECT COUNT(*) AS total_forum FROM chat_messages WHERE chat_type = 'forum'";
$result_forum = $conn->query($sql_forum);
$row_forum = $result_forum->fetch_assoc();
$forum_count = $row_forum['total_forum'];

$sql_comment = "SELECT COUNT(*) AS total_comment FROM chat_messages WHERE chat_type = 'comment'";
$result_comment = $conn->query($sql_comment);
$row_comment = $result_comment->fetch_assoc();
$comment_count = $row_comment['total_comment'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/adminhomestyle.css" />
  <link rel="stylesheet" href="css/reportstyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css"/>
  <title>Report Analysis</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>
    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
        <li><a href="dashboard.php"><ion-icon name="home-outline"></ion-icon><span class="links">Home</span></a></li>
        <li><a href="manage_mentees.php"><ion-icon name="person-outline"></ion-icon><span class="links">Mentees</span></a></li>
        <li><a href="manage_mentors.php"><ion-icon name="people-outline"></ion-icon><span class="links">Mentors</span></a></li>
        <li><a href="courses.php"><ion-icon name="book-outline"></ion-icon><span class="links">Courses</span></a></li>
        <li><a href="manage_session.php"><ion-icon name="calendar-outline"></ion-icon><span class="links">Sessions</span></a></li>
        <li><a href="feedbacks.php"><ion-icon name="star-outline"></ion-icon><span class="links">Feedback</span></a></li>
        <li><a href="channels.php"><ion-icon name="chatbubbles-outline"></ion-icon><span class="links">Channels</span></a></li>
        <li class="navList"><a href="activities.php"><ion-icon name="clipboard-outline"></ion-icon><span class="links">Activities</span></a></li>
        <li><a href="resource.php"><ion-icon name="library-outline"></ion-icon><span class="links">Resource Library</span></a></li>
        <li class="navList"><a href="reports.php"><ion-icon name="folder-outline"></ion-icon><span class="links">Reported Posts</span></a></li>
        <li class="navList"><a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon><span class="links">Banned Users</span></a></li>
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
    
  <div class="container" id="report-content">
    <div class="header">
      <div class="logo">
        <span>📈</span> COACH Report Analysis
      </div>

      <form method="POST" style="display:inline;">
        <input type="text" name="daterange" class="date-range" />
      </form>
    </div>

    <div style="margin: 20px 0; text-align: right;">
      <button id="save-pdf" class="btn">Save Report as PDF</button>
    </div>

    <div class="top-cards">
      <div class="card1 performance-card">
        <h3>Performance Tracker (Top 5 Courses)</h3>
        <?php 
          $data = [];
          foreach ($courses as $i => $course) {
            $data[] = [
              'course' => $course,
              'count'  => (int)$mentees_count[$i]
            ];
          }

          usort($data, function($a, $b) {
            return $b['count'] <=> $a['count'];
          });

          $top5 = array_slice($data, 0, 5);
          $maxValue = !empty($top5) ? max(array_column($top5, 'count')) : 0;

          foreach ($top5 as $item): 
            $course  = htmlspecialchars($item['course']);
            $count   = $item['count'];
            $percent = ($maxValue > 0) ? ($count / $maxValue) * 100 : 0;
        ?>
          <div class="progress">
            <label><?php echo $course; ?> &nbsp; <?php echo $count; ?> mentees</label>
            <div class="progress-bar">
              <div class="bar purple" style="width: <?php echo $percent; ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="view-all-container">
          <button id="showAllBtn" class="btn btn-view-all">View All Courses</button>
        </div>
      </div>

      <div class="card">
        <h3>New Users</h3>
        <canvas id="userChart"></canvas>
      </div>
    </div>

    <div id="allCoursesModal" class="modal" style="display:none;">
      <div class="modal-content">
        <span id="closeModal" class="close-btn">&times;</span>
        <h3>All Booked Sessions</h3>

        <?php 
          $maxValueAll = !empty($data) ? max(array_column($data, 'count')) : 0;
          foreach ($data as $item): 
            $course  = htmlspecialchars($item['course']);
            $count   = $item['count'];
            $percent = ($maxValueAll > 0) ? ($count / $maxValueAll) * 100 : 0;
        ?>
          <div class="progress">
            <label><?php echo $course; ?> &nbsp; <?php echo $count; ?> mentees</label>
            <div class="progress-bar">
              <div class="bar green" style="width: <?php echo $percent; ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="grid">
      <div class="card" style="text-align:center;">
        <h3>Forum Posts</h3>
        <div class="big-number"><?php echo number_format($forum_count); ?></div>
      </div>

      <div class="card" style="text-align:center;">
        <h3>Forum Comments</h3>
        <div class="big-number"><?php echo number_format($comment_count); ?></div>
      </div>
    </div>

    <div class="card table-card">
      <table>
        <thead>
          <tr>
            <th>Campaign Name</th>
            <th>Total spent</th>
            <th>Reach</th>
            <th>Link clicks</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Brand Awareness - June</td>
            <td>$4 340.00</td>
            <td>990 357</td>
            <td>180 657</td>
          </tr>
          <tr>
            <td>Post of the week #12</td>
            <td>$1 250.00</td>
            <td>7 8490</td>
            <td>15 849</td>
          </tr>
          <tr>
            <td>Facebook contest</td>
            <td>$252.00</td>
            <td>19 756</td>
            <td>1 456</td>
          </tr>
          <tr>
            <td>Holiday ad campaign Fb & Ig</td>
            <td>$20.00</td>
            <td>39 321</td>
            <td>9 322</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script src="admin.js"></script>

<script>
// Save PDF functionality
document.getElementById("save-pdf").addEventListener("click", () => {
  const report = document.getElementById("report-content");
  html2canvas(report, { scale: 2 }).then(canvas => {
    const imgData = canvas.toDataURL("image/png");
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF("p", "pt", "a4");
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const imgWidth = pageWidth - 40;
    const imgHeight = (canvas.height * imgWidth) / canvas.width;

    let heightLeft = imgHeight;
    let position = 20;

    pdf.addImage(imgData, "PNG", 20, position, imgWidth, imgHeight);
    heightLeft -= pageHeight;

    while (heightLeft > 0) {
      position = heightLeft - imgHeight + 20;
      pdf.addPage();
      pdf.addImage(imgData, "PNG", 20, position, imgWidth, imgHeight);
      heightLeft -= pageHeight;
    }

    pdf.save("report-analysis.pdf");
  });
});

// Chart and Date Range Picker
$(function() {
  const ctxUsers = document.getElementById('userChart').getContext('2d');
  let userChart = new Chart(ctxUsers, {
    type: 'bar',
    data: {
      labels: [],
      datasets: [
        { label: 'Mentees', data: [], backgroundColor: '#6a0dad' },
        { label: 'Mentors', data: [], backgroundColor: '#0d6efd' },
        { label: 'Admins',  data: [], backgroundColor: '#28a745' }
      ]
    },
    options: { 
      responsive: true, 
      maintainAspectRatio: true,
      scales: { 
        y: { beginAtZero: true, ticks: { stepSize: 1 } }
      } 
    }
  });

  // Function to load chart data
  function loadChartData(start, end) {
    console.log('Loading data from:', start.format('YYYY-MM-DD'), 'to:', end.format('YYYY-MM-DD'));
    
    $.ajax({
      url: window.location.pathname,
      method: 'GET',
      data: {
        start: start.format('YYYY-MM-DD'),
        end: end.format('YYYY-MM-DD')
      },
      dataType: 'json',
      success: function(response) {
        console.log('Response received:', response);
        
        // Generate all date labels in range
        let labels = [];
        let current = start.clone();
        while (current <= end) {
          labels.push(current.format('DD MMM'));
          current.add(1, 'days');
        }
        
        // Initialize data arrays with zeros
        let menteeData = Array(labels.length).fill(0);
        let mentorData = Array(labels.length).fill(0);
        let adminData  = Array(labels.length).fill(0);

        // Fill in the data from response
        response.forEach(row => {
          let dateLabel = moment(row.date).format('DD MMM');
          let idx = labels.indexOf(dateLabel);
          if (idx !== -1) {
            let userType = row.user_type.toLowerCase();
            if (userType === 'mentee') menteeData[idx] += parseInt(row.total);
            if (userType === 'mentor') mentorData[idx] += parseInt(row.total);
            if (userType === 'admin')  adminData[idx]  += parseInt(row.total);
          }
        });

        console.log('Chart data:', { labels, menteeData, mentorData, adminData });

        // Update chart
        userChart.data.labels = labels;
        userChart.data.datasets[0].data = menteeData;
        userChart.data.datasets[1].data = mentorData;
        userChart.data.datasets[2].data = adminData;
        userChart.update();
      },
      error: function(xhr, status, error) {
        console.error('Error loading chart data:', error);
        console.error('Response:', xhr.responseText);
      }
    });
  }

  // Initialize daterangepicker
  $('input[name="daterange"]').daterangepicker({
    opens: 'left',
    startDate: moment().subtract(6, 'days'),
    endDate: moment(),
    locale: { format: 'DD MMM YYYY' },
    ranges: {
      'Yesterday':   [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
      'Last 7 days': [moment().subtract(6, 'days'), moment()],
      'Last 14 days':[moment().subtract(13, 'days'), moment()],
      'Last 28 days':[moment().subtract(27, 'days'), moment()],
      'Last 30 days':[moment().subtract(29, 'days'), moment()],
    }
  }, function(start, end, label) {
    console.log('Date range changed:', start.format('YYYY-MM-DD'), end.format('YYYY-MM-DD'));
    loadChartData(start, end);
  });

  // Load initial data with last 7 days
  loadChartData(moment().subtract(6, 'days'), moment());
});

// Modal functionality
document.addEventListener("DOMContentLoaded", function () {
  const showAllBtn = document.getElementById("showAllBtn");
  const modal = document.getElementById("allCoursesModal");
  const closeModal = document.getElementById("closeModal");

  if (!showAllBtn || !modal || !closeModal) return;

  showAllBtn.addEventListener("click", function () {
    modal.style.display = "block";
  });

  closeModal.addEventListener("click", function () {
    modal.style.display = "none";
  });

  window.addEventListener("click", function (event) {
    if (event.target === modal) modal.style.display = "none";
  });
});
</script>

</body>
</html>