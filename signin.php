<?php
session_start(); 
require_once 'ClassAutoLoad.php';

$Objlayout->header($conf);
$Objlayout->nav($conf);
?>

<link rel="stylesheet" href="CSS/forms.css">

<section class="form-page">
    <div class="form-container">

        <!-- BANNER DISPLAY SECTION -->
        <?php
        if (isset($_SESSION['banner'])) {
            $type = $_SESSION['banner']['type'];
            $message = $_SESSION['banner']['message'];

            echo "<div class='alert-banner $type'>$message</div>";

            unset($_SESSION['banner']);
        }
        ?>

        <?php $Objform->signin(); ?>
    </div>
</section>

<?php
$Objlayout->footer($conf);
?>
