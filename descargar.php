<?php
// Ruta donde están los archivos en el servidor
// Ajusta esta ruta a la ubicación real (ruta UNC para red compartida, o ruta local)
$basePath = '\\\\192.168.2.5\\hefesto\\Attachment\\'; 

if (!isset($_GET['file'])) {
    die('Archivo no especificado.');
}

$fileName = basename($_GET['file']); // Evitar traversal path
$filePath = $basePath . $fileName;

if (!file_exists($filePath)) {
    die('Archivo no encontrado.');
}

// Obtener extensión para tipo MIME
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    // Agrega más tipos si es necesario
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Decidir si se abre inline (en navegador) o se descarga (attachment)
// Para PDF, mejor mostrar inline para abrir directamente con Adobe o visor PDF
$disposition = ($ext === 'pdf') ? 'inline' : 'attachment';

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
?>
