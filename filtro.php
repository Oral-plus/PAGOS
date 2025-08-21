<?php
require_once 'config/database.php';

$conn = getDbConnection();

// Capturamos valores GET para filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';

// Obtener proveedores distintos para el select
$suppliers = [];
if ($conn instanceof PDO) {
    $stmt = $conn->query("SELECT DISTINCT supplier FROM [invoice_approval_system].[dbo].[invoices] ORDER BY supplier");
    $suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = sqlsrv_query($conn, "SELECT DISTINCT supplier FROM [invoice_approval_system].[dbo].[invoices] ORDER BY supplier");
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suppliers[] = $row['supplier'];
    }
}

// Construir consulta con filtros para invoice_approvals
$params = [];
$where = [];

$sql = "SELECT TOP 1000 * FROM [invoice_approval_system].[dbo].[invoice_approvals] ia";

if ($filter_supplier !== '') {
    // Hacemos join con invoices para filtrar por proveedor
    $sql = "SELECT TOP 1000 ia.* FROM [invoice_approval_system].[dbo].[invoice_approvals] ia
            INNER JOIN [invoice_approval_system].[dbo].[invoices] inv ON ia.invoice_id = inv.invoice_id";
    $where[] = "inv.supplier = ?";
    $params[] = $filter_supplier;
}

if ($filter_date_from !== '') {
    $where[] = "CAST(ia.created_at AS DATE) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where[] = "CAST(ia.created_at AS DATE) <= ?";
    $params[] = $filter_date_to;
}

if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

if ($conn instanceof PDO) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }
}
?>