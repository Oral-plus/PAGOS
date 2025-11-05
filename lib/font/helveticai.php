<?php
// Minimal font definition for Helvetica Italic
$name = 'Helvetica-Oblique';
$type = 'Type1';
$desc = array('Ascent'=>718,'Descent'=>-207,'CapHeight'=>718,'Flags'=>32,'FontBBox'=>array(-166,-225,1000,931),'ItalicAngle'=>-12,'StemV'=>88,'MissingWidth'=>600);
$up = -100;
$ut = 50;
$cw = array();
for ($i = 0; $i < 256; $i++) {
    $cw[chr($i)] = 600;
}
$enc = '';
$diff = '';
$file = '';
$originalsize = 0;
?>