<?php
// Include Class Autoload
require_once 'ClassAutoLoad.php';

// Build the Homepage
$Objlayout->header($conf);
$Objlayout->nav($conf);
$Objlayout->banner($conf);
$Objlayout->how_it_works(); 
$Objlayout->why_donate_section();
$Objlayout->footer($conf);
?>
