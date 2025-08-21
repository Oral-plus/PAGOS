<?php
session_start();

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Conexión directa a SQL Server
$serverName = "HERCULES";
$connectionOptions = array(
    "Database" => "RBOSKY3",
    "Uid" => "sa",
    "PWD" => "Sky2022*!",
    "CharacterSet" => "UTF-8"
);

// Crear la conexión
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Verificar la conexión
if ($conn === false) {
    error_log("Error de conexión SQL Server: " . print_r(sqlsrv_errors(), true));
    http_response_code(500);
    exit('Database connection error');
}

// Función para obtener conexión (compatibilidad)
function getDbConnection() {
    global $conn;
    return $conn;
}

// Función para buscar factura por archivo
function findInvoiceByFile($filename) {
    global $conn;
    
    $sql = "SELECT archivo_adjunto FROM invoices WHERE archivo_adjunto LIKE ?";
    $params = array('%' . $filename . '%');
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error en consulta SQL: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result;
}

// Función para buscar en tabla SAP (si usas la estructura del segundo archivo)
function findSapDocument($docNum) {
    global $conn;
    
    $sql = "SELECT T1.DocNum, T0.AbsEntry, T0.trgtPath 
            FROM OINV T1 
            LEFT JOIN ATC1 T0 ON T0.AbsEntry = T1.DocEntry 
            WHERE T1.DocNum = ?";
    $params = array($docNum);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("Error en consulta SAP: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    return $result;
}

// Verificar si se proporcionó el parámetro file
if (!isset($_GET['file']) || empty($_GET['file'])) {
    http_response_code(400);
    exit('File parameter required');
}

$requested_file = urldecode($_GET['file']);

// Función para limpiar y validar la ruta del archivo
function sanitizeFilePath($path) {
    // Remover caracteres peligrosos
    $path = str_replace(['../', '..\\', '<', '>', '|', '*', '?'], '', $path);
    
    // Normalizar separadores de directorio
    $path = str_replace('\\', '/', $path);
    
    return $path;
}

// Función para determinar el tipo MIME
function getMimeType($file_path) {
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    $mime_types = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed'
    ];
    
    return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
}

// Limpiar la ruta del archivo
$clean_file_path = sanitizeFilePath($requested_file);

// Lista de posibles rutas base donde pueden estar los archivos
$possible_base_paths = [
    '', // Ruta actual
    'uploads/',
    'documents/',
    'attachments/',
    'files/',
    'IMG/', // Basado en tu segundo archivo
    'facturas/',
    'documentos/',
    '../uploads/',
    '../documents/',
    '../attachments/',
    '../files/',
    '../IMG/',
    // Agregar más rutas según tu estructura de directorios
];

$file_found = false;
$full_file_path = '';

// Buscar el archivo en las posibles ubicaciones
foreach ($possible_base_paths as $base_path) {
    $test_path = $base_path . $clean_file_path;
    
    if (file_exists($test_path) && is_file($test_path)) {
        $full_file_path = $test_path;
        $file_found = true;
        break;
    }
}

// Si no se encuentra el archivo, intentar con la ruta original
if (!$file_found && file_exists($clean_file_path) && is_file($clean_file_path)) {
    $full_file_path = $clean_file_path;
    $file_found = true;
}

// Si aún no se encuentra, verificar en la base de datos
if (!$file_found) {
    try {
        // Buscar el archivo en la base de datos de facturas
        $result = findInvoiceByFile(basename($clean_file_path));
        
        if ($result && !empty($result['archivo_adjunto'])) {
            $db_file_path = $result['archivo_adjunto'];
            if (file_exists($db_file_path) && is_file($db_file_path)) {
                $full_file_path = $db_file_path;
                $file_found = true;
            }
        }
        
        // Si no se encuentra, intentar buscar en la estructura SAP
        if (!$file_found) {
            // Extraer número de documento si está en el nombre del archivo
            if (preg_match('/(\d+)/', basename($clean_file_path), $matches)) {
                $docNum = $matches[1];
                $sap_result = findSapDocument($docNum);
                
                if ($sap_result && !empty($sap_result['trgtPath'])) {
                    $sap_file_path = $sap_result['trgtPath'];
                    if (file_exists($sap_file_path) && is_file($sap_file_path)) {
                        $full_file_path = $sap_file_path;
                        $file_found = true;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error al buscar archivo en base de datos: " . $e->getMessage());
    }
}

// Si no se encuentra el archivo, devolver error 404
if (!$file_found) {
    // Log del error para debugging
    error_log("Archivo no encontrado: " . $clean_file_path . " | Rutas buscadas: " . implode(', ', $possible_base_paths));
    
    http_response_code(404);
    exit('File not found: ' . htmlspecialchars($clean_file_path));
}

// Verificar permisos de lectura
if (!is_readable($full_file_path)) {
    error_log("Archivo no legible: " . $full_file_path);
    http_response_code(403);
    exit('File not readable');
}

// Obtener información del archivo
$file_size = filesize($full_file_path);
$mime_type = getMimeType($full_file_path);
$file_name = basename($full_file_path);

// Log de acceso exitoso
error_log("Archivo servido exitosamente: " . $full_file_path . " | Usuario: " . $_SESSION['user_id']);

// Configurar headers para la descarga/visualización
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . $file_size);
header('Content-Disposition: inline; filename="' . $file_name . '"');

// Headers adicionales para PDFs
if ($mime_type === 'application/pdf') {
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
}

// Headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// Limpiar cualquier salida previa
if (ob_get_level()) {
    ob_end_clean();
}

// Servir el archivo
if ($file_size > 8192) {
    // Para archivos grandes, usar readfile con buffer
    $handle = fopen($full_file_path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }
} else {
    // Para archivos pequeños, usar readfile directamente
    readfile($full_file_path);
}

// Cerrar conexión
if ($conn) {
    sqlsrv_close($conn);
}

exit();
?>
