<?php
class Forms
{
    //Donor Sign Up Form
    public function donorSignup()
    {
        ?>
        <h2>Donor Registration</h2>
        <form method="POST" action="auth/donor_signup.php" id="donorSignupForm">
            <label for="fullname">Full Name</label>
            <input type="text" id="fullname" name="fullname" placeholder="Enter your full name" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Enter your email" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter a password" required>

            <label for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone" placeholder="e.g. 0712345678" required>

            <label for="blood_type">Blood Type</label>
            <select id="blood_type" name="blood_type" required>
                <option value="">-- Select Blood Type --</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
            </select>

            <label for="location">Location</label>
            <input type="text" id="location" name="location" placeholder="Enter your location" required>

            <button type="submit">Register as Donor</button>
        </form>
        <?php
    }

    
    // Hospital Sign Up Form
  
    public function hospitalSignup()
    {
        ?>
        <h2>Hospital Registration</h2>
        <form method="POST" action="hospital_signup.php" id="hospitalSignupForm">
            <label for="hospital_name">Hospital Name</label>
            <input type="text" id="hospital_name" name="hospital_name" placeholder="Enter hospital name" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Enter hospital email" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter a password" required>

            <label for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone" placeholder="e.g. 0712345678" required>

            <label for="location">Location</label>
            <input type="text" id="location" name="location" placeholder="Enter hospital location" required>

            <button type="submit">Register Hospital</button>
        </form>
        <?php
    }

   
    // Common Sign In Form
    
    public function signin()
    {
        ?>
        <h2>Login</h2>
        <form method="POST" action="process_signin.php" id="signinForm">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Enter your email" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Enter your password" required>

            <label for="role">Login As</label>
            <select id="role" name="role" required>
                <option value="">-- Select Role --</option>
                <option value="donor">Donor</option>
                <option value="hospital">Hospital</option>
            </select>

            <button type="submit">Login</button>
        </form>
        <?php
    }

    
    
}
?>
