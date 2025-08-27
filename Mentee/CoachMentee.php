<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// SESSION CHECK
if (!isset($_SESSION['username'])) {
  header("Location: login_mentee.php");
  exit();
}

$firstName = '';
$menteeIcon = '';
$showWelcome = false;

if (isset($_SESSION['login_success']) && $_SESSION['login_success'] === true) {
  $showWelcome = true;
  unset($_SESSION['login_success']); // prevent showing again
}

// Get username from session
$username = $_SESSION['username'];

// Fetch First_Name and Mentee_Icon from the database
$sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $firstName = $row['First_Name'];
  $menteeIcon = $row['Mentee_Icon'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
  <link rel="stylesheet" href="css/mentee_courses.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Mentee Dashboard</title>
</head>

<body>
  <section class="background" id="home">
    <nav class="navbar">
      <div class="logo">
        <img src="img/LogoCoach.png" alt="Logo">
        <span>COACH</span>
      </div>

      <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="CoachMenteeHome.php">Home</a></li>
          <li><a href="#courses">Courses</a></li>
          <li><a href="#resourcelibrary">Resource Library</a></li>
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

  <!-- Course Section -->
  <section id="courses">
    <div class="title">Choose what you want to learn!</div>
    <div class="course-grid">
      <?php
// Course section with filtering for Active status only
$sql = "SELECT * FROM courses WHERE Course_Status = 'Active' OR Course_Status IS NULL";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
  while ($course = $result->fetch_assoc()):
  ?>
    <div class="course-card">
      <?php if (!empty($course['Course_Icon'])): ?>
        <img src="uploads/<?php echo htmlspecialchars($course['Course_Icon']); ?>" alt="Course Icon">
      <?php endif; ?>
      <h2><?php echo htmlspecialchars($course['Course_Title']); ?></h2>
      <p><?php echo htmlspecialchars($course['Course_Description']); ?></p>
      <p><strong><?php echo htmlspecialchars($course['Skill_Level']); ?></strong></p>
      <form method="GET" action="mentee_sessions.php">
        <input type="hidden" name="course" value="<?= htmlspecialchars($course['Course_Title']) ?>">
        <button class="choose-btn">Choose</button>
      </form>
    </div>
  <?php endwhile; 
} else {
  echo '<div class="no-courses"><p>No active courses available at the moment.</p></div>';
}
?>
    </div>
  </section>

  <!-- Resource Library Section -->
<section id="resourcelibrary" class="resource-library" style="display: none;">
  <div class="resource-container">
    <div class="resource-left">
      <img src="img/book.png" alt="Stack of Books" class="books"/>
    </div>
    <div class="resource-right">
      <h1>Learn more by reading files shared by your mentors!</h1>
      <p>
        Access valuable PowerPoint presentations, PDF files, and Video tutorials to enhance your learning journey 🚀
      </p>
      <div class="search-container">
        <input type="text" id="search-box" placeholder="Find resources by title or keyword...">
        <button onclick="performSearch()"><ion-icon name="search-outline"></ion-icon></button>
      </div>
    </div>
  </div>

  <div class="title">Check out the resources you can learn from!</div>
   <div class="button-wrapper">
  <div id="categoryButtons" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <button class="category-btn active" data-category="all">All</button>
    <button class="category-btn" data-category="HTML">HTML</button>
    <button class="category-btn" data-category="CSS">CSS</button>
    <button class="category-btn" data-category="Java">Java</button>
    <button class="category-btn" data-category="C#">C#</button>
    <button class="category-btn" data-category="JS">JavaScript</button>
    <button class="category-btn" data-category="PHP">PHP</button>
</div>
</div>
  <div class="resource-grid" id="resource-results">
    <?php
      // Fetch resources from the database
      $sql_resources = "SELECT Resource_ID, Resource_Title, Resource_Icon, Resource_Type, Resource_File, Category FROM resources WHERE Status = 'Approved'";
      $result_resources = $conn->query($sql_resources);

      if ($result_resources && $result_resources->num_rows > 0) {
        // Output data for each resource
        while ($resource = $result_resources->fetch_assoc()) {
          echo '<div class="course-card" data-category="' . htmlspecialchars($resource['Category']) . '" data-status="Approved">';
          if (!empty($resource['Resource_Icon'])) {
            // Ensure the path is correct, assuming icons are in an 'uploads' folder
            echo '<img src="uploads/' . htmlspecialchars($resource['Resource_Icon']) . '" alt="Resource Icon">';
          }
          echo '<h2>' . htmlspecialchars($resource['Resource_Title']) . '</h2>';
          echo '<p><strong>Type: ' . htmlspecialchars($resource['Resource_Type']) . '</strong></p>';

          // --- FIXED VIEW BUTTON ---
          // Ensure Resource_File contains only the filename, not the full path yet
          $filePath = $resource['Resource_File']; // Get the filename from DB
          $fileTitle = $resource['Resource_Title'];

          // Construct the URL for view_resource.php
          // urlencode() is crucial for filenames/titles with spaces or special characters
          $viewUrl = 'view_resource.php?file=' . urlencode($filePath) . '&title=' . urlencode($fileTitle);

          // Create the link
          echo '<a href="' . htmlspecialchars($viewUrl) . '" class="view-btn" target="_blank">View</a>'; // Updated class from btn-view to view-btn
          // --- END FIXED VIEW BUTTON ---

          echo '</div>';
        }
      } else {
        echo "<p>No resources found.</p>";
      }
    ?>
  </div>
</section>


  <script src="mentee.js"></script>
  <script>
    const buttons = document.querySelectorAll('.category-btn');
    const resourceCards = document.querySelectorAll('#resource-results .course-card');

  buttons.forEach(button => {
    button.addEventListener('click', () => {
      // Remove active class from all buttons, then add to the clicked one
      buttons.forEach(btn => btn.classList.remove('active'));
      button.classList.add('active');

      const selected = button.getAttribute('data-category');

      resourceCards.forEach(card => {
        const cardCategory = card.getAttribute('data-category');
        const cardStatus = card.getAttribute('data-status');

        // Show card if:
        // - selected is "all", or
        // - it matches the category, or
        // - it matches the status
        if (
          selected === 'all' ||
          cardCategory === selected ||
          cardStatus === selected
        ) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    });
  });

    function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
      // If the user clicks "OK", redirect to logout.php
      window.location.href = "logout.php";
    } else {
      // If the user clicks "Cancel", do nothing
      return false;
    }
  }

  function performSearch() {
  const query = document.getElementById('search-box').value;

  fetch('search_resources.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: 'query=' + encodeURIComponent(query)
  })
  .then(response => response.text())
  .then(data => {
    document.getElementById('resource-results').innerHTML = data;
  })
  .catch(error => console.error('Search error:', error));
}

// 👇 Real-time search as you type
document.getElementById('search-box').addEventListener('input', function () {
  performSearch();
});
  </script>
</body>
</html>

<?php $conn->close(); ?>
