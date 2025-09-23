<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

$feedback_data = $_SESSION['feedback_data'] ?? null;

// Redirect if no data is found from the previous page (e.g., if accessed directly)
if (!$feedback_data) {
    // Handle this case as needed, e.g., redirect to an error page or feedback.php
    header("Location: feedback.php"); // Example redirect
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get data from the form submission
    $mentor_reviews = $_POST['mentor_reviews'] ?? '';
    $mentor_star = $_POST['mentor_star'] ?? 0; // This will be the star value (1-5)

    // Basic server-side validation (though client-side is added below)
    if (empty($mentor_reviews) || $mentor_star == 0) {
        // Handle validation error if client-side is bypassed
        // You might want to show an error message to the user here
        echo "<script>alert('Please select a star rating and provide a mentor review.');</script>";
    } else {

        // Calculate the percentage
        $mentor_star_percentage = ($mentor_star / 5) * 100;

        // Retrieve data from the session (from feedback.php)
        $forum_id = $feedback_data['forum_id'] ?? null;
        $mentee_experience = $feedback_data['mentee_experience'] ?? '';
        $experience_star = $feedback_data['experience_star'] ?? 0; // This is the mentee experience star value
        $experience_star_percentage = $feedback_data['experience_star_percentage'] ?? 0;

        // Fetch data from other tables
        $session_title = null;
        $forum_course_title = null;
        $forum_session_date = null; // Variable to store session date from forum_chats
        $forum_time_slot = null; // Variable to store time slot from forum_chats
        $session_mentor = null;
        $mentee_name = null;

        // --- Generate the present date for Session_Date ---
        $present_date = date('Y-m-d'); // Using 'Y-m-d' format which is compatible with DATE type
        // --- End Generate present date ---


        // Fetch Session Title, Course Title, and Time Slot from forum_chats (using the passed forum_id)
        $stmt = $conn->prepare("SELECT title, course_title, session_date, time_slot FROM forum_chats WHERE id = ?");
        $stmt->bind_param("i", $forum_id);
        $stmt->execute();
        $stmt->bind_result($session_title, $forum_course_title, $fetched_session_date, $forum_time_slot); // Use a different variable name for fetched date
        $stmt->fetch();
        $stmt->close();

        // Check if the forum chat details were found and the title is not null
        if ($session_title === null || $session_title === '') {
            echo "Error: Could not retrieve valid session title for feedback. Forum ID: " . htmlspecialchars($forum_id);
            exit(); // Stop script execution
        }

        // Set default value for time slot if fetched data is null or empty
        $time_slot_to_insert = ($forum_time_slot === null || $forum_time_slot === '') ? '' : $forum_time_slot;


        // --- MODIFIED SECTION: Fetch Session_Mentor name ---
        // This query now joins 'pending_sessions' with the new 'users' table on 'user_id'
        // to get the mentor's name.
        $stmt = $conn->prepare("
            SELECT
                u.first_name,
                u.last_name
            FROM
                pending_sessions ps
            JOIN
                users u ON ps.user_id = u.user_id
            WHERE
                ps.Course_Title = ? AND
                ps.Session_Date = ? AND
                ps.Time_Slot = ? AND
                u.user_type = 'Mentor'
            LIMIT 1
        ");
        $stmt->bind_param("sss", $forum_course_title, $fetched_session_date, $forum_time_slot);
        $stmt->execute();
        $stmt->bind_result($mentor_first_name, $mentor_last_name);
        $stmt->fetch();
        $stmt->close();

        $session_mentor = trim($mentor_first_name . " " . $mentor_last_name);


        // --- MODIFIED SECTION: Fetch Mentee Name ---
        // This query now gets the logged-in mentee's name from the unified 'users' table.
        $loggedInUsername = $_SESSION['username'] ?? null;

         if ($loggedInUsername) {
             $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE username = ?");
             $stmt->bind_param("s", $loggedInUsername);
             $stmt->execute();
             $stmt->bind_result($mentee_first_name, $mentee_last_name);
             $stmt->fetch();
             $stmt->close();
             $mentee_name = trim($mentee_first_name . " " . $mentee_last_name);
         } else {
             // Fallback if the username is not in the session
             $mentee_name = "Unknown Mentee";
         }

$present_date = date('Y-m-d');

       // Prepare statement
$stmt = $conn->prepare("INSERT INTO sessions (
    session_title,
    forum_id,
    session_mentor,
    mentee_name,
    mentee_experience,
    experience_star_percentage,
    mentor_reviews,
    mentor_star_percentage,
    present_date,
    time_slot
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param("sisssdsssd",
    $session_title,
    $forum_id,
    $session_mentor,
    $mentee_name,
    $mentee_experience,
    $experience_star_percentage,
    $mentor_reviews,
    $mentor_star_percentage,
    $present_date,
    $time_slot_to_insert
);


        if ($stmt->execute()) {
            // Insertion successful - Use JavaScript alert and redirect
            echo '<script>';
            echo 'alert("Feedback submitted successfully!");';
            echo 'window.location.href = "forum-chat.php";'; // Redirect to forum-chat.php
            echo '</script>';
            exit(); // Stop script execution after the redirect
        } else {
            // Error in insertion
            echo "Error submitting feedback: " . $stmt->error;
        }

        $stmt->close();
        $conn->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/feedback-mentor.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.7.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.7.0/dist/ionicons/ionicons.js"></script>
  <title>Feedback Form</title>
</head>

<body>

    <section class="feedback-section">
        <div class="feedback-card">
          <h2>Rate your Mentor</h2>
          <p>
            We value your learning journey! Please take a moment to rate your mentoring experience
            and share your feedback with us—it helps us improve and support you better.
          </p>

          <form method="POST" action="" onsubmit="return validateMentorFeedbackForm()">
            <div class="stars" id="starContainer">
              <span class="star" data-value="1">&#9733;</span>
              <span class="star" data-value="2">&#9733;</span>
              <span class="star" data-value="3">&#9733;</span>
              <span class="star" data-value="4">&#9733;</span>
              <span class="star" data-value="5">&#9733;</span>
            </div>
            <input type="hidden" name="mentor_star" id="mentor_star" value="0">

            <textarea placeholder="Tell us about your experience!" name="mentor_reviews" id="mentor_reviews"></textarea>

            <button type="submit">Send</button>
          </form>
        </div>
      </section>

      <script>
        document.addEventListener('DOMContentLoaded', () => {
          const stars = document.querySelectorAll('.star');
          const mentorStarInput = document.getElementById('mentor_star');
          let currentRating = 0;

          function updateStars(rating) {
            stars.forEach(star => {
              const value = parseInt(star.getAttribute('data-value'));
              if (value <= rating) {
                star.classList.add('filled');
              } else {
                star.classList.remove('filled');
              }
            });
          }

          stars.forEach(star => {
            star.addEventListener('click', () => {
              currentRating = parseInt(star.getAttribute('data-value'));
              updateStars(currentRating);
              mentorStarInput.value = currentRating; // Set the hidden input value
            });

            star.addEventListener('mouseover', () => {
              updateStars(parseInt(star.getAttribute('data-value')));
            });

            star.addEventListener('mouseout', () => {
              updateStars(currentRating);
            });
          });
        });

        // --- Validation Function for Mentor Feedback ---
        function validateMentorFeedbackForm() {
            const mentorStar = document.getElementById('mentor_star').value;
            const mentorReviews = document.getElementById('mentor_reviews').value.trim(); // .trim() removes leading/trailing whitespace

            if (mentorStar == 0) {
                alert("Please select a star rating for the mentor.");
                return false; // Prevent form submission
            }

            if (mentorReviews === '') {
                alert("Please provide a review in the text area for the mentor.");
                return false; // Prevent form submission
            }

            return true; // Allow form submission
        }
        // --- End Validation Function ---

      </script>
    </body>
    </html>