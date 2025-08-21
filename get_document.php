<?php
// get_document.php
header('Access-Control-Allow-Origin: *');

if (!isset($_GET['docnum']) || empty($_GET['docnum'])) {
    header("HTTP/1.1 400 Bad Request");
    die('Número de documento requerido');
}

$docnum = $_GET['docnum'];
$remote_path = "\\\\192.168.2.5\\hefesto\\Attachment\\{$docnum}.pdf";

// Configurar headers para PDF
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . 
       "; filename=\"documento_{$docnum}.pdf\"");

// Usar smbclient para obtener el archivo
$command = "smbclient '\\\\192.168.2.5\\hefesto' -N -c 'get Attachment\\{$docnum}.pdf -'";
passthru($command);
?>