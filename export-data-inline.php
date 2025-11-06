<?php
require_once 'config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['sql']) || !isset($input['params'])) {
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

$sql = $input['sql'];
$params = $input['params'];

try {
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $invoices = [];
    $total_paid = 0;
    $suppliers = [];

    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
        $total_paid += (float)$row['valor_pagado'];
        $suppliers[$row['proveedor']] = true;
    }

    echo json_encode([
        'invoices' => $invoices,
        'total_invoices' => count($invoices),
        'total_paid' => $total_paid,
        'total_suppliers' => count($suppliers)
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>