<?php

session_start();

require_once 'config/database.php';
require_once 'includes/functions.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

// Inicializar filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Función para obtener facturas PAGADAS con información completa
function getGroupedPaidInvoices($supplier = '', $date_from = '', $date_to = '', $status = '') {
    $conn = getDbConnection();
    
    $sql = "SELECT 
                i.[id],
                i.[codigo_sn],
                i.[nombre] as proveedor_nombre,
                i.[fecha_contable],
                i.[fecha_vencimiento],
                i.[dias_de_vencido],
                i.[saldo_pendiente],
                i.[numero_factura_proveedor],
                i.[docnum_interno_sap],
                i.[archivo_adjunto],
                i.[status] as invoice_status,
                i.[priority],
                i.[created_at],
                p.[DocStatus],
                p.[Factura],
                p.[Codigo Proveedor] as CodigoProveedor,
                p.[Fecha Factura] as FechaFactura,
                p.[Valor Total] as ValorTotal,
                p.[Valor Pagado] as ValorPagado,
                p.[Estado] as EstadoPago,
                p.[Fecha de Pago] as FechadePago
                
            FROM [invoice_approval_system].[dbo].[invoices] i
            INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
                ON (
                    LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                    OR 
                    LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                )
            WHERE 1=1";

    $params = array();
    
    if (!empty($supplier)) {
        $sql .= " AND i.[nombre] LIKE ?";
        $params[] = '%' . $supplier . '%';
    }
    
    if (!empty($date_from)) {
        $sql .= " AND p.[Fecha Factura] >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql .= " AND p.[Fecha Factura] <= ?";
        $params[] = $date_to;
    }
    
    if (!empty($status)) {
        $sql .= " AND p.[Estado] LIKE ?";
        $params[] = '%' . $status . '%';
    }
    
    $sql .= " ORDER BY i.[nombre] ASC, p.[Fecha Factura] DESC";
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return ['error' => 'Error en la consulta: ' . print_r(sqlsrv_errors(), true)];
        }
        
        $invoices = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $invoices[] = $row;
        }
    }
    
    // Agrupar por proveedor
    $grouped = [];
    foreach ($invoices as $invoice) {
        $supplier_name = $invoice['proveedor_nombre'] ?? 'Sin nombre';
        if (!isset($grouped[$supplier_name])) {
            $grouped[$supplier_name] = [
                'invoices' => [],
                'total_paid' => 0,
                'count' => 0
            ];
        }
        
        $grouped[$supplier_name]['invoices'][] = $invoice;
        $grouped[$supplier_name]['total_paid'] += floatval($invoice['ValorPagado'] ?? 0);
        $grouped[$supplier_name]['count']++;
    }
    
    return $grouped;
}

// Obtener datos
$grouped_invoices = getGroupedPaidInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_status);

// Verificar si hay error
if (isset($grouped_invoices['error'])) {
    header('Content-Type: application/json');
    echo json_encode($grouped_invoices);
    exit();
}

// Preparar datos para exportación
$export_data = [];
$facturasProcesadas = []; // Para evitar duplicados globalmente

foreach ($grouped_invoices as $supplier_name => $supplier_data) {
    foreach ($supplier_data['invoices'] as $invoice) {
        $facturaId = $invoice['Factura'] ?? null;
        
        // Evitar duplicados basándose en el ID de factura
        $facturaKey = $facturaId . '_' . ($invoice['docnum_interno_sap'] ?? '');
        if (in_array($facturaKey, $facturasProcesadas)) {
            continue;
        }
        $facturasProcesadas[] = $facturaKey;
        
        $export_data[] = [
            'proveedor' => $supplier_name,
            'n_sap' => $invoice['docnum_interno_sap'] ?? $facturaId ?? 'N/A',
            'n_factura' => $invoice['numero_factura_proveedor'] ?? $invoice['Factura'] ?? 'N/A',
            'fecha_vencimiento' => formatDate($invoice['fecha_vencimiento']),
            'fecha_pago' => formatDate($invoice['FechadePago']),
            'valor_total' => floatval($invoice['ValorTotal'] ?? 0),
            'valor_pagado' => floatval($invoice['ValorPagado'] ?? 0),
            'estado' => (floatval($invoice['ValorPagado'] ?? 0) >= floatval($invoice['ValorTotal'] ?? 0)) ? 'Pagada' : 'Parcial'
        ];
    }
}

// Calcular totales basándose SOLO en los datos filtrados y exportados (sin duplicados)
$total_suppliers = 0;
$total_invoices = count($export_data);
$total_paid = 0;
$proveedoresUnicos = [];

foreach ($export_data as $invoice) {
    // Contar proveedores únicos
    if (!in_array($invoice['proveedor'], $proveedoresUnicos)) {
        $proveedoresUnicos[] = $invoice['proveedor'];
        $total_suppliers++;
    }
    
    // Sumar solo el valor pagado de las facturas filtradas
    $total_paid += floatval($invoice['valor_pagado'] ?? 0);
}

// Devolver datos en formato JSON
header('Content-Type: application/json');
echo json_encode([
    'invoices' => $export_data,
    'total_suppliers' => $total_suppliers,
    'total_invoices' => $total_invoices,
    'total_paid' => $total_paid
]);
