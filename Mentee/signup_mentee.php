<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signup Page</title>
    <link rel="stylesheet" href="css/signupstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
</head>
<body>
    <video autoplay muted loop id="bg-video">
        <source src="img/bgcode.mp4" type="video/mp4">
        Your browser does not support HTML5 video.
    </video>

    <div class="page-content">
<section class="welcomeb">
    <div class="welcome-container">
        <div class="welcome-box">
            <h3 class="typing-gradient">
                <span class="typed-text">Welcome, Mentee! Start your path.</span>
              </h3>
            </div>
    </div>
</section>

<div class="container">
    <h1>SIGN UP</h1>
<p>Register now and unlock new opportunities.</p>
    <form action="signup.php" method="POST">
        <div class="top-section">
            <!-- Left Side: Username and Password -->
            <div class="form-box">
                <h2>Personal Information</h2>

                <label for="fname">First Name</label>
                <input type="text" id="fname" name="fname" placeholder="First Name" required>

                <label for="lname">Last Name</label>
                <input type="text" id="lname" name="lname" placeholder="Last Name" required>

                <label for="dob">Date of Birth</label>
                <input type="date" id="dob" name="dob" required>
                <span id="dob-error" class="error-message"></span>

                <label for="gender">Gender</label>
                <select id="gender" name="gender" required>
                    <option value="" disabled selected>Select Gender</option>
                    <option>Male</option>
                    <option>Female</option>
                    <option>Other</option>
                </select>
            </div>

            <!-- Right Side: Personal Information -->
            <div class="form-box">
                <h2>Username and Password</h2>

                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Username" required>

                <label for="password">Password</label>
                <div class="password-field-container">
                    <input type="password" id="password" name="password" placeholder="Password" required>
                    <div id="password-popup" class="password-popup">
                        <p>Password must:</p>
                        <ul>
                            <li id="length-check">Be at least 8 characters long</li>
                            <li id="uppercase-check">Include at least one uppercase letter</li>
                            <li id="lowercase-check">Include at least one lowercase letter</li>
                            <li id="number-check">Include at least one number</li>
                            <li id="special-check">Include at least one special character (!@#$%^&*)</li>
                        </ul>
                    </div>
                </div>
                <span id="password-error" class="error-message"></span>

                <label for="confirm-password">Confirm Password</label>
                <input type="password" id="confirm-password" name="confirm-password" placeholder="Confirm Password" required>
                <span id="confirm-password-error" class="error-message"></span>
            </div>
        </div>

        <!-- Bottom: Contact Information -->
        <div class="form-box full-width">
            <h2>Contact Information</h2>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="youremail@gmail.com" required>

            <label for="contact">Contact Number</label>
            <div class="phone-input-container">
                <input type="tel" id="contact" name="contact" placeholder="9XXXXXXXXX" required pattern="9[0-9]{9}" title="Please enter a valid Philippine mobile number starting with 9 followed by 9 digits">
                <input type="hidden" id="full-contact" name="full-contact">
            </div>

            <label for="address">Full Address</label>
            <input type="text" id="address" name="address" placeholder="Street, City, Province, ZIP" required>
    
            <label>Are you still a student?</label>
            <div class="student-options">
            <input type="radio" id="student-yes" name="student" value="yes" required>
            <label for="student-yes">Yes</label>

            <input type="radio" id="student-no" name="student" value="no">
            <label for="student-no">No</label>
            </div>

            <label for="grade">If yes, what grade are you?</label>
            <input type="text" id="grade" name="grade" placeholder="e.g. Grade 12">

            <label for="occupation">If not, what is your occupation?</label>
            <input type="text" id="occupation"  name="occupation" placeholder="e.g. IT Specialist">

            <label for="learning">What do you want to learn with Coach?</label>
            <textarea id="learning" name="learning" rows="3" placeholder="Type your answer here..." required></textarea>
        </div>

        <div class="terms-container">
            <label>
                <input type="checkbox" id="terms" required>
                <span>
                    I agree to the <a href="#">Terms & Conditions</a> and <a href="#">Data Privacy Policy</a>.
                </span>
            </label>
        
            <label>                                         
                <input type="checkbox" id="consent" required>
                <span>
                    I consent to receive updates and communications from COACH, trusting that all shared information will be used responsibly to support my growth and development. I understand that COACH values my privacy and that I can opt out of communications at any time.
                </span>
            </label>
        </div>
        
        <!-- Buttons -->
        <div class="form-buttons">
            <button type="button" class="cancel-btn"><a href="login_mentee.php" style="color: #290c26;">Cancel</a></button>
            <button type="submit" class="submit-btn">Register</button>
        </div>
        
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM elements for DOB validation
        const dobInput = document.getElementById('dob');
        const dobError = document.getElementById('dob-error');
        
        // DOM elements for password validation
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm-password');
        const passwordError = document.getElementById('password-error');
        const confirmPasswordError = document.getElementById('confirm-password-error');
        const passwordPopup = document.getElementById('password-popup');
        
        // DOM elements for username validation
        const usernameInput = document.getElementById('username');
        const usernameError = document.createElement('span');
        usernameError.id = 'username-error';
        usernameError.className = 'error-message';
        usernameInput.parentNode.insertBefore(usernameError, usernameInput.nextSibling);
        
        // Password requirement checkers
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const lowercaseCheck = document.getElementById('lowercase-check');
        const numberCheck = document.getElementById('number-check');
        const specialCheck = document.getElementById('special-check');
        
        const form = document.querySelector('form');
        
        // Username validation
        let usernameTimer;
        let isUsernameValid = true;
        
        function checkUsername() {
            const username = usernameInput.value.trim();
            
            if (username.length < 3) {
                usernameError.textContent = "Username must be at least 3 characters.";
                usernameError.style.color = "#f44336";
                usernameInput.classList.add('invalid-input');
                usernameInput.classList.remove('valid-input');
                isUsernameValid = false;
                return;
            }
            
            // Show loading indicator
            usernameError.textContent = "Checking username...";
            usernameError.style.color = "#2196F3";
            
            // Create form data
            const formData = new FormData();
            formData.append('check_username', username);
            
            // Send AJAX request to the same page
            fetch('signup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.exists) {
                    usernameError.textContent = "This username is already taken.";
                    usernameError.style.color = "#f44336";
                    usernameInput.classList.add('invalid-input');
                    usernameInput.classList.remove('valid-input');
                    isUsernameValid = false;
                } else {
                    usernameError.textContent = "Username is available!";
                    usernameError.style.color = "#4CAF50";
                    usernameInput.classList.add('valid-input');
                    usernameInput.classList.remove('invalid-input');
                    isUsernameValid = true;
                }
            })
            .catch(error => {
                usernameError.textContent = "Error checking username. Please try again.";
                usernameError.style.color = "#f44336";
                console.error('Error:', error);
                isUsernameValid = false;
            });
        }
        
        usernameInput.addEventListener('input', function() {
            // Clear any existing error message while typing
            if (this.value.trim().length === 0) {
                usernameError.textContent = "";
                usernameInput.classList.remove('valid-input', 'invalid-input');
            }
            
            // Clear the previous timer
            clearTimeout(usernameTimer);
            
            // Set a new timer to check username after typing stops for 500ms
            usernameTimer = setTimeout(checkUsername, 500);
        });
        
        // Calculate dates for DOB validation
        const today = new Date();
        const minAllowedYear = today.getFullYear() - 100; // Minimum year (100 years ago)
        const maxAllowedYear = today.getFullYear() - 10;  // Maximum year (10 years ago)
        
        // Set the last day of the latest year they can select
        const maxDate = new Date(maxAllowedYear, 11, 31).toISOString().split('T')[0]; // December 31 of max year
        const minDate = new Date(minAllowedYear, 0, 1).toISOString().split('T')[0];   // January 1 of min year
        
        // Set attributes for the date input
        dobInput.setAttribute('max', maxDate);
        dobInput.setAttribute('min', minDate);
        
        // Date of birth validation function
        function validateDOB() {
            const selectedDate = new Date(dobInput.value);
            const today = new Date();
            
            const selectedYear = selectedDate.getFullYear();
            const currentYear = today.getFullYear();
            
            // Future date check
            if (selectedDate > today) {
                dobError.textContent = "Date of birth cannot be in the future.";
                return false;
            }
            // Calculate the minimum required birth year
            else if (selectedYear > currentYear - 10) {
                dobError.textContent = "You must be at least 10 years old to register.";
                return false;
            } else {
                dobError.textContent = "";
                return true;
            }
        }
        
        // Password validation function
        function validatePassword() {
            const password = passwordInput.value;
            let isValid = true;
            
            // Check password length (min 8 characters)
            const hasValidLength = password.length >= 8;
            lengthCheck.className = hasValidLength ? 'valid' : 'invalid';
            isValid = isValid && hasValidLength;
            
            // Check for uppercase letter
            const hasUppercase = /[A-Z]/.test(password);
            uppercaseCheck.className = hasUppercase ? 'valid' : 'invalid';
            isValid = isValid && hasUppercase;
            
            // Check for lowercase letter
            const hasLowercase = /[a-z]/.test(password);
            lowercaseCheck.className = hasLowercase ? 'valid' : 'invalid';
            isValid = isValid && hasLowercase;
            
            // Check for number
            const hasNumber = /[0-9]/.test(password);
            numberCheck.className = hasNumber ? 'valid' : 'invalid';
            isValid = isValid && hasNumber;
            
            // Check for special character
            const hasSpecial = /[!@#$%^&*]/.test(password);
            specialCheck.className = hasSpecial ? 'valid' : 'invalid';
            isValid = isValid && hasSpecial;
            
            if (!isValid && password.length > 0) {
                passwordError.textContent = "Please meet all password requirements.";
            } else {
                passwordError.textContent = "";
            }
            
            return isValid;
        }
        
        // Confirm password validation function
        function validateConfirmPassword() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (confirmPassword.length > 0 && password !== confirmPassword) {
                confirmPasswordError.textContent = "Passwords do not match.";
                return false;
            } else {
                confirmPasswordError.textContent = "";
                return true;
            }
        }
        
        // Show password popup
        function showPasswordPopup() {
            passwordPopup.style.display = 'block';
        }
        
        // Hide password popup
        function hidePasswordPopup() {
            // Only hide if all requirements are met or the field is empty
            if (validatePassword() || passwordInput.value.length === 0) {
                passwordPopup.style.display = 'none';
            }
        }
        
        // Add event listeners for DOB
        dobInput.addEventListener('change', validateDOB);
        dobInput.addEventListener('blur', validateDOB);
        
        // Add event listeners for password
        passwordInput.addEventListener('focus', showPasswordPopup);
        passwordInput.addEventListener('keyup', validatePassword);
        passwordInput.addEventListener('blur', hidePasswordPopup);
        
        // Add event listeners for confirm password
        confirmPasswordInput.addEventListener('keyup', validateConfirmPassword);
        confirmPasswordInput.addEventListener('blur', validateConfirmPassword);
        
        // Form submission validation
        form.addEventListener('submit', function(event) {
            const isDOBValid = validateDOB();
            const isPasswordValid = validatePassword();
            const isConfirmPasswordValid = validateConfirmPassword();
            
            // Check username validity along with other validations
            if (!isDOBValid || !isPasswordValid || !isConfirmPasswordValid || !isUsernameValid) {
                event.preventDefault();
                
                // Focus on the username field if it's invalid
                if (!isUsernameValid) {
                    usernameInput.focus();
                    
                    if (usernameError.textContent === "") {
                        usernameError.textContent = "Please enter a valid username.";
                    }
                }
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        // Phone number handling
        const contactInput = document.getElementById('contact');
        const fullContactInput = document.getElementById('full-contact');
        const form = document.querySelector('form');
        
        // Update the hidden field before form submission
        form.addEventListener('submit', function(event) {
            // Format the phone number with the +63 prefix
            const phoneNumber = contactInput.value.trim();
            if(phoneNumber) {
                fullContactInput.value = '+63' + phoneNumber;
            }
        });
        
        // Add validation for Philippine mobile number format
        contactInput.addEventListener('input', function() {
            // Remove any non-digit characters
            this.value = this.value.replace(/\D/g, '');
            
            // Ensure it doesn't start with 0
            if (this.value.startsWith('0')) {
                this.value = this.value.substring(1);
            }
            
            // Limit to 10 digits (9 + 9 more digits)
            if (this.value.length > 12) {
                this.value = this.value.slice(0, 12);
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
    // Define the sequence of required fields
    const fieldSequence = [
        'fname',        // First Name
        'lname',        // Last Name
        'dob',          // Date of Birth
        'gender',       // Gender
        'username',     // Username
        'password',     // Password
        'confirm-password', // Confirm Password
        'email',        // Email
        'contact',      // Contact Number
        'address',      // Full Address
        // Student/occupation fields are conditionally required
        'learning'      // What do you want to learn
    ];

    // Get all form fields
    const formFields = {};
    fieldSequence.forEach(id => {
        formFields[id] = document.getElementById(id);
    });

    // Special handling for radio buttons
    const studentYes = document.getElementById('student-yes');
    const studentNo = document.getElementById('student-no');
    const gradeField = document.getElementById('grade');
    const occupationField = document.getElementById('occupation');

    // Function to check if a field has valid input
    function isFieldValid(field) {
        if (!field) return false;
        
        // Special handling for different field types
        if (field.tagName === 'SELECT') {
            return field.value !== '' && !field.disabled;
        } else if (field.type === 'radio') {
            // For radio buttons, check if any in the group is selected
            const name = field.name;
            const radioGroup = document.querySelectorAll(`input[name="${name}"]`);
            return Array.from(radioGroup).some(radio => radio.checked);
        } else {
            return field.value.trim() !== '' && !field.disabled;
        }
    }

    // Function to enable/disable fields based on the sequence
    function updateFieldAvailability() {
        let allPreviousValid = true;
        
        for (let i = 0; i < fieldSequence.length; i++) {
            const fieldId = fieldSequence[i];
            const field = formFields[fieldId];
            
            if (!field) continue;
            
            // If all previous fields are valid, enable this field
            if (allPreviousValid) {
                field.disabled = false;
            } else {
                field.disabled = true;
            }
            
            // Check if current field is valid for the next iteration
            if (i < fieldSequence.length - 1) {
                allPreviousValid = allPreviousValid && isFieldValid(field);
            }
        }
        
        // Special handling for conditional fields (student/occupation)
        if (studentYes && studentNo && gradeField && occupationField) {
            // Enable the radio buttons based on the address field
            const addressField = formFields['address'];
            const canSelectStudentStatus = addressField && isFieldValid(addressField);
            
            studentYes.disabled = !canSelectStudentStatus;
            studentNo.disabled = !canSelectStudentStatus;
            
            // Enable grade field only if "Yes" is selected for student
            gradeField.disabled = !(canSelectStudentStatus && studentYes.checked);
            
            // Enable occupation field only if "No" is selected for student
            occupationField.disabled = !(canSelectStudentStatus && studentNo.checked);
        }
    }

    // Add event listeners to all fields
    fieldSequence.forEach(id => {
        const field = formFields[id];
        if (field) {
            field.addEventListener('input', updateFieldAvailability);
            field.addEventListener('change', updateFieldAvailability);
        }
    });

    // Special handling for radio buttons
    if (studentYes && studentNo) {
        studentYes.addEventListener('change', function() {
            if (this.checked) {
                updateFieldAvailability();
            }
        });
        
        studentNo.addEventListener('change', function() {
            if (this.checked) {
                updateFieldAvailability();
            }
        });
    }

    // Set initial state of fields
    updateFieldAvailability();

    // Visual indication for disabled fields
    const allInputs = document.querySelectorAll('input, select, textarea');
    allInputs.forEach(input => {
        // Add styles for disabled state
        const style = document.createElement('style');
        style.textContent = `
            input:disabled, select:disabled, textarea:disabled {
                background-color: #f8f8f8;
                cursor: not-allowed;
                opacity: 0.7;
            }
            input:disabled::placeholder, select:disabled::placeholder, textarea:disabled::placeholder {
                color: #ccc;
            }
        `;
        document.head.appendChild(style);
    });
});
</script>
</body>
</html>