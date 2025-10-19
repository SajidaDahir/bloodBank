<?php
require_once 'ClassAutoLoad.php';

$Objlayout->header($conf);
$Objlayout->nav($conf);
?>

<link rel="stylesheet" href="CSS/forms.css">

<section class="form-page">
    <div class="form-container">
        <?php $Objform->hospitalSignup(); ?>
    </div>
</section>

<?php
$Objlayout->footer($conf);
?>
