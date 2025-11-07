<?php
class layout
{
    
    public function header($conf)
    {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo $conf['site_name']; ?></title>
            <link rel="stylesheet" href="CSS/style.css">
            <script src="JS/script.js" defer></script>
        </head>
        <body>
        <?php
    }

    
    public function nav($conf)
    {
        ?>
        <nav class="navbar">
            <div class="logo">
                <span class="heart">❤️</span><?php echo $conf['site_name']; ?>
            </div>
            <ul class="nav-links">
                <li><a href="./">Home</a></li>
                <li><a href="#about">About</a></li>
                <li><a href="#how">How It Works</a></li>
                <li><a href="#contact">Contact</a></li>
            </ul>
            <div class="nav-actions">
                <button id="loginBtn" class="btn-outline">Login</button>
                <button id="registerBtn" class="btn-primary">Register</button>
            </div>
        </nav>

        <!--  Universal Choice Modal -->
        <div id="choiceModal" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2 id="modalTitle">Choose Option</h2>
                <div class="choice-buttons">
                    <button id="donorChoice" class="btn-primary">Donor</button>
                    <button id="hospitalChoice" class="btn-outline">Hospital</button>
                </div>
            </div>
        </div>
        <?php
    }

    
    public function banner($conf)
    {
        ?>
        <section class="hero">
            <div class="hero-overlay"></div>
            <div class="hero-text">
                <h1>Welcome to <?php echo $conf['site_name']; ?></h1>
                <p>Connecting blood donors and hospitals to save lives.</p>
             
            </div>
        </section>
        <?php
    }

    
    public function how_it_works()
    {
        ?>
        <section class="how-it-works" id="how">
            <h2>How BloodBank Works</h2>
            <p class="subtitle">Simple steps to save lives</p>
            <div class="steps">
                <div class="step">
                    <div class="circle">1</div>
                    <h3>Register</h3>
                    <p>Sign up as a donor or hospital.</p>
                </div>
                <div class="step">
                    <div class="circle">2</div>
                    <h3>Connect</h3>
                    <p>Find matches based on blood type and location.</p>
                </div>
                <div class="step">
                    <div class="circle">3</div>
                    <h3>Coordinate</h3>
                    <p>Schedule donations or requests with ease.</p>
                </div>
                <div class="step">
                    <div class="circle">4</div>
                    <h3>Save Lives</h3>
                    <p>Your contribution makes a difference.</p>
                </div>
            </div>
        </section>
        <?php
    }

   
    public function why_donate_section()
    {
        ?>
        <section class="why-donate">
            <h2>Why Donate Blood?</h2>
            <div class="donate-cards">
                <div class="donate-card">
                    <h3>Save Lives</h3>
                    <p>Every donation can save up to three lives. Your blood helps patients in emergencies, surgeries, and chronic illnesses.</p>
                </div>

                <div class="donate-card">
                    <h3>Support Your Community</h3>
                    <p>Donating blood ensures hospitals have enough supply for local patients in need.</p>
                </div>

                <div class="donate-card">
                    <h3>Health Benefits</h3>
                    <p>Regular donors enjoy health checks and may reduce risks of certain diseases through frequent donation.</p>
                </div>
            </div>
        </section>
        <?php
    }

   
    public function footer($conf)
    {
        ?>
        <footer id="contact">
            <div class="contact-section">
                <h3>Contact Us</h3>
                <p>Have questions or want to get involved? Reach out to us anytime!</p>
                <ul>
                    <li><strong>Email:</strong> <a href="mailto:info@bloodbank.org">info@bloodbank.org</a></li>
                    <li><strong>Phone:</strong> <a href="tel:+254712345678">+254 712 345 678</a></li>
                    <li><strong>Address:</strong> Nairobi, Kenya</li>
                </ul>
            </div>

            <hr class="footer-line">

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> <?php echo $conf['site_name']; ?>. All rights reserved.</p>
            </div>
        </footer>
        </body>
        </html>
        <?php
    }
}
?>
