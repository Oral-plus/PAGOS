<?php
session_start();

require_once 'config/database.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

header('Content-Type: application/json');

try {
    $conn = getConnection();
    
    // Obtener proveedores únicos de la tabla invoices
    $query = "SELECT DISTINCT nombre 
              FROM invoices 
              WHERE nombre IS NOT NULL AND nombre != '' 
              ORDER BY nombre ASC";
    
    $stmt = sqlsrv_query($conn, $query);
    
    if ($stmt === false) {
        throw new Exception('Error al obtener proveedores: ' . print_r(sqlsrv_errors(), true));
    }
    
    $suppliers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suppliers[] = $row['nombre'];
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    echo json_encode([
        'success' => true,
        'suppliers' => $suppliers
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>
