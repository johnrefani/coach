<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Welcome to COACH</title>
  <link rel="stylesheet" href="css/welcomestyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml" />
  <script src="typing-effect.js" defer></script>
</head>
<body>
  <video autoplay muted loop id="bg-video">
    <source src="img/bgcode1.mp4" type="video/mp4" />
    Your browser does not support HTML5 video.
  </video>

  <div class="welcome-container">
    <div class="welcome-box">
      <div class="logo">
        <img src="LogoCoach.png" alt="Coach Logo" />
      </div>
      <h3 class="typing-gradient">
        <span class="typed-text">WELCOME TO COACH</span>
      </h3>
      <div class="role-buttons">
        <a href="mentee-login.html" class="role-btn">Admin</a>
        <a href="mentor-login.html" class="role-btn">SuperAdmin</a>
      </div>
    </div>
  </div>
  <script src="welcomescript.js"></script>
</body>
</html>
