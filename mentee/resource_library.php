<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    // If not a Mentee, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- DATABASE CONNECTION & FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

$firstName = '';
$menteeIcon = '';

// Get username from session
$username = $_SESSION['username'];

// Fetch First_Name and Mentee_Icon from the database
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/courses.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Resource Library</title>
</head>

<body>

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
      <li><a href="#settings">Settings</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
    </nav>
  </section>

  <section>
    <div class="resource-container">
      <div class="resource-left">
        <img src="../uploads/img/book.png" alt="Stack of Books" class="books"/>
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
        // This query correctly fetches all approved resources uploaded by mentors
        $sql_resources = "SELECT Resource_Title, Resource_Icon, Resource_Type, Resource_File, Category FROM resources WHERE Status = 'Approved'";
        $result_resources = $conn->query($sql_resources);

        if ($result_resources && $result_resources->num_rows > 0) {
          while ($resource = $result_resources->fetch_assoc()) {
            echo '<div class="course-card" data-category="' . htmlspecialchars($resource['Category']) . '">';
            if (!empty($resource['Resource_Icon'])) {
              // The path goes UP to project, then DOWN to uploads
              echo '<img src="../uploads/' . htmlspecialchars($resource['Resource_Icon']) . '" alt="Resource Icon">';
            }
            echo '<h2>' . htmlspecialchars($resource['Resource_Title']) . '</h2>';
            echo '<p><strong>Type: ' . htmlspecialchars($resource['Resource_Type']) . '</strong></p>';
            
            // --- UPDATED VIEW BUTTON LINK ---
            $filePath = $resource['Resource_File'];
            $fileTitle = $resource['Resource_Title'];

            // This link now points to the view_resource.php inside the /mentor/ folder
            $viewUrl = '../mentor/view_resource_mentee.php?file=' . urlencode($filePath) . '&title=' . urlencode($fileTitle);

            echo '<a href="' . htmlspecialchars($viewUrl) . '" class="view-btn" target="_blank">View</a>';
            // --- END UPDATE ---

            echo '</div>';
          }
        } else {
          echo "<p>No resources found.</p>";
        }
      ?>
    </div>
      </section>

  <script src="js/mentee.js"></script>
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
          
          if (selected === 'all' || cardCategory === selected) {
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
        window.location.href = "../logout.php";
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

    // Real-time search as you type
    document.getElementById('search-box').addEventListener('input', function () {
      performSearch();
    });
  </script>
</body>
</html>

<?php $conn->close(); ?>
