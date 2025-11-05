<?php
// Minimal font definition for Helvetica (regular)
$name = 'Helvetica';
$type = 'Type1';
$desc = array('Ascent'=>718,'Descent'=>-207,'CapHeight'=>718,'Flags'=>32,'FontBBox'=>array(-166,-225,1000,931),'ItalicAngle'=>0,'StemV'=>88,'MissingWidth'=>600);
$up = -100; // underline position
$ut = 50;   // underline thickness
// Character widths: set a reasonable default for all 256 possible single-byte chars
$cw = array();
for ($i = 0; $i < 256; $i++) {
    $cw[chr($i)] = 600; // default width
}
$enc = '';
$diff = '';
$file = '';
$originalsize = 0;
?>