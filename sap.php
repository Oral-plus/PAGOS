<?php
// CONFIGURACIÓN
$config = [
    'base_url' => 'https://192.168.2.242:50000/b1s/v1',
    'username' => 'MANAGER',
    'password' => 'SKY0303',
    'companyDB' => 'RBOSKY3'
];

// LOGIN A SAP
$login = [
    'UserName' => $config['username'],
    'Password' => $config['password'],
    'CompanyDB' => $config['companyDB']
];

$ch = curl_init($config['base_url'] . '/Login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($login));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);

// MANEJO COOKIES
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
preg_match_all('/Set-Cookie:\s*([^;]*)/mi', $header, $matches);
$cookies = implode('; ', $matches[1]);

$sessionInfo = json_decode($body, true);
if (!isset($sessionInfo['SessionId'])) {
    die("No se pudo iniciar sesión en SAP.");
}

// CONSULTAR FACTURA
$docEntry = 37177; // <-- Cambia aquí por el DocEntry real
$url = $config['base_url'] . "/PurchaseInvoices($docEntry)?\$select=DocEntry,DocNum,AttachmentEntry";
$ch2 = curl_init($url);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Cookie: ' . $cookies,
    'Content-Type: application/json'
]);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
$response2 = curl_exec($ch2);
$factura = json_decode($response2, true);

$attachmentEntry = $factura['AttachmentEntry'] ?? null;
if (!$attachmentEntry || $attachmentEntry == 0) {
    die("No hay anexo en esta factura.");
}

// CONSULTAR ANEXOS
$urlAttach = $config['base_url'] . "/Attachments2($attachmentEntry)";
$ch3 = curl_init($urlAttach);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'Cookie: ' . $cookies,
    'Content-Type: application/json'
]);
curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
$response3 = curl_exec($ch3);
$anexos = json_decode($response3, true);

// Función para detectar si es imagen
function esImagen($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $extValidas = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    return in_array($ext, $extValidas);
}

// Mostrar archivos
echo "<h2>Archivos adjuntos a la factura</h2>";
echo "<style>
    .preview-container {
        border: 2px solid #ccc;
        padding: 10px;
        margin-bottom: 20px;
        width: fit-content;
        max-width: 600px;
        font-family: Arial, sans-serif;
    }
    .preview-container img {
        max-width: 100%;
        height: auto;
        display: block;
        margin-bottom: 10px;
        border-radius: 4px;
    }
    .preview-container a.download-link {
        display: inline-block;
        margin-top: 5px;
        color: #0066cc;
        text-decoration: none;
    }
    .preview-container a.download-link:hover {
        text-decoration: underline;
    }
    .file-icon {
        font-size: 50px;
        margin-bottom: 10px;
        color: #999;
    }
</style>";

if (!empty($anexos['Attachments2_Lines'])) {
    foreach ($anexos['Attachments2_Lines'] as $linea) {
        $archivo = $linea['FileName'];
        // URL pública de acceso (ajusta según tu servidor)
        $urlHTTP = "http://192.168.2.5/hefesto/Attachment/" . urlencode($archivo);

        echo "<div class='preview-container'>";
        echo "<p><strong>Archivo:</strong> $archivo</p>";

        if (esImagen($archivo)) {
            // Mostrar vista previa imagen
            echo "<img src='$urlHTTP' alt='$archivo' loading='lazy' />";
        } else {
            // Mostrar ícono o mensaje para archivo no imagen
            echo "<div class='file-icon'>&#128196;</div>"; // ícono genérico de archivo 📄
            echo "<p>Vista previa no disponible para este tipo de archivo.</p>";
        }

        echo "<a class='download-link' href='$urlHTTP' target='_blank' download>Descargar archivo</a>";
        echo "</div>";
    }
} else {
    echo "No se encontraron archivos adjuntos.";
}

// LOGOUT
curl_setopt($ch, CURLOPT_URL, $config['base_url'] . '/Logout');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Cookie: ' . $cookies,
    'Content-Type: application/json'
]);
curl_exec($ch);
?>
