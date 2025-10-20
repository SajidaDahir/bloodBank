<?php
require_once 'ClassAutoLoad.php';
session_start();

// Render header + navbar
$Objlayout->header($conf);
$Objlayout->nav($conf);
?>

<!-- Link the form CSS -->
<link rel="stylesheet" href="CSS/forms.css">

<section class="form-page">
    <div class="form-container">

        <?php
        // 🔹 Display banner messages (success, warning, or error)
        if (isset($_SESSION['banner'])) {
            $b = $_SESSION['banner'];
            echo "<div class='banner {$b['type']}'>{$b['message']}</div>";
            unset($_SESSION['banner']); // clear message after displaying
        }

        // 🔹 Display the hospital signup form from Forms class
        $Objform->hospitalSignup();
        ?>

    </div>
</section>

<?php
// Render footer
$Objlayout->footer($conf);
?>
