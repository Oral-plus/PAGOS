<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Verificar si es una petición AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die('Acceso no permitido');
}

// Obtener parámetros de la petición
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$supplier = isset($_GET['supplier']) ? $_GET['supplier'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Obtener facturas paginadas
$invoices = getGroupedPaidInvoices($supplier, $date_from, $date_to, $status, $page);

// Preparar respuesta
$response = [
    'success' => true,
    'data' => $invoices,
    'hasMore' => !empty($invoices),
    'page' => $page
];

// Enviar respuesta JSON
header('Content-Type: application/json');
echo json_encode($response);
?>