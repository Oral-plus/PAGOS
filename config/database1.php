<?php
// config/database.php

// Configuración para SQL Server
define('DB_HOST', '192.168.2.244');
define('DB_NAME', 'RBOSKY3');
define('DB_USER', 'sa');
define('DB_PASS', 'Sky2022*!');

// Opciones adicionales para SQL Server
define('DB_PORT', 1433); // Puerto predeterminado de SQL Server
define('DB_CHARSET', 'UTF-8');

// Función para obtener la conexión a la base de datos
function getDbConnection1() {
    $connectionInfo = array(
        "Database" => DB_NAME,
        "UID" => DB_USER,
        "PWD" => DB_PASS,
        "CharacterSet" => DB_CHARSET,
        "TrustServerCertificate" => true, // Para evitar problemas de certificado en entornos de desarrollo
        "Encrypt" => false // Desactivar encriptación si causa problemas
    );
    
    // Intentar usar sqlsrv si está disponible
    if (function_exists('sqlsrv_connect')) {
        $conn = sqlsrv_connect(DB_HOST, $connectionInfo);
        
        if ($conn === false) {
            $errors = sqlsrv_errors();
            error_log("Error de conexión SQL Server: " . print_r($errors, true));
            throw new Exception("Error de conexión a la base de datos. Consulte el log para más detalles.");
        }
        
        return $conn;
    } else {
        // Si sqlsrv no está disponible, usar PDO
        try {
            $dsn = "sqlsrv:Server=" . DB_HOST . ";Database=" . DB_NAME;
            $conn = new PDO($dsn, DB_USER, DB_PASS, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
            ));
            return $conn;
        } catch (PDOException $e) {
            error_log("Error de conexión PDO: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos. Consulte el log para más detalles.");
        }
    }
}

// Función para verificar la conexión
function testDatabaseConnection1() {
    try {
        $conn = getDbConnection1();
        
        // Ejecutar una consulta simple para verificar la conexión
        if ($conn instanceof PDO) {
            $stmt = $conn->query("SELECT @@VERSION as version");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'success' => true,
                'message' => 'Conexión exitosa (PDO)',
                'version' => $result['version']
            ];
        } else {
            $stmt = sqlsrv_query($conn, "SELECT @@VERSION as version");
            if ($stmt === false) {
                throw new Exception("Error al ejecutar consulta: " . print_r(sqlsrv_errors(), true));
            }
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            return [
                'success' => true,
                'message' => 'Conexión exitosa (sqlsrv)',
                'version' => $row['version']
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
?>