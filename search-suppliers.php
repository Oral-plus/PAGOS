<?php
header('Content-Type: application/json');

require_once 'config/database.php';

try {
    $conn = getDbConnection();
    
    $sql = "SELECT DISTINCT [nombre] 
            FROM [invoice_approval_system].[dbo].[invoices]
            WHERE [nombre] IS NOT NULL AND [nombre] != '' 
            ORDER BY [nombre] ASC";
    
    $suppliers = [];
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } else {
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            throw new Exception(print_r(sqlsrv_errors(), true));
        }
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $suppliers[] = $row[0];
        }
    }
    
    echo json_encode(['suppliers' => $suppliers]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
