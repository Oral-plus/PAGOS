<?php

session_start();

require_once 'config/database.php';
require_once 'includes/functions.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Obtener rol del usuario
$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'];

// Inicializar filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Función para obtener facturas PAGADAS con información completa
function getGroupedPaidInvoices($supplier = '', $date_from = '', $date_to = '', $status = '') {
    $conn = getDbConnection();
    
    // Consulta que une las dos tablas correctamente
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
            WHERE 1=1   ";

    $params = array();
    
    if (!empty($supplier)) {
        $sql .= " AND i.[nombre] = ?";
        $params[] = $supplier;
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
        $sql .= " AND p.[Estado] = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY i.[nombre] ASC, p.[Fecha Factura] DESC";
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
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
                'count' => 0,
                'completely_paid' => 0,
                'partially_paid' => 0
            ];
        }
        
        $grouped[$supplier_name]['invoices'][] = $invoice;
        $grouped[$supplier_name]['total_paid'] += floatval($invoice['ValorPagado'] ?? 0);
        $grouped[$supplier_name]['count']++;
        
        $valor_total = floatval($invoice['ValorTotal'] ?? 0);
        $valor_pagado = floatval($invoice['ValorPagado'] ?? 0);
        
        if ($valor_pagado >= $valor_total) {
            $grouped[$supplier_name]['completely_paid']++;
        } else {
            $grouped[$supplier_name]['partially_paid']++;
        }
    }
    
    return $grouped;
}

// Función para obtener todos los proveedores únicos
function getAllSuppliers() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT i.[nombre] 
            FROM [invoice_approval_system].[dbo].[invoices] i
            INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
                ON (
                    LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                    OR 
                    LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                )
            WHERE i.[nombre] IS NOT NULL AND i.[nombre] != '' 
            ORDER BY i.[nombre] ASC";
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } else {
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        
        $suppliers = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $suppliers[] = $row[0];
        }
        return $suppliers;
    }
}

// Función para obtener estados únicos de pagos
function getAllPaidStatuses() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT [Estado] 
            FROM [invoice_approval_system].[dbo].[Invoice_pagas] 
            WHERE [Estado] IS NOT NULL AND [Estado] != '' 
            ORDER BY [Estado] ASC";
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } else {
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        
        $statuses = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $statuses[] = $row[0];
        }
        return $statuses;
    }
}

// Función para formatear fechas


// Obtener datos
$suppliers = getAllSuppliers();
$statuses = getAllPaidStatuses();
$grouped_invoices = getGroupedPaidInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_status);

// Calcular totales generales
$total_suppliers = count($grouped_invoices);
$total_invoices = 0;
$total_paid = 0;
foreach ($grouped_invoices as $supplier_data) {
    $total_invoices += $supplier_data['count'];
    $total_paid += $supplier_data['total_paid'];
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Pagadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .main-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        .supplier-card {
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            margin-bottom: 15px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .supplier-card.hidden {
            display: none;
        }
        
        .supplier-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .supplier-header:hover {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }
        
        .supplier-header.expanded {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            border-bottom-color: #28a745;
        }
        
        .supplier-details {
            display: none;
            background: #fafafa;
        }
        
        .supplier-details.show {
            display: block;
        }
        
        .expand-icon {
            transition: transform 0.3s ease;
            color: #28a745;
        }
        
        .expand-icon.rotated {
            transform: rotate(90deg);
        }
        
        .invoice-table {
            margin: 0;
            background: white;
        }
        
        .invoice-table th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            font-size: 0.85em;
            padding: 12px 6px;
            text-align: center;
        }
        
        .invoice-table td {
            padding: 10px 6px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9em;
        }
        
        .invoice-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .status-badge {
            font-size: 0.8em;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .btn-action {
            padding: 4px 8px;
            font-size: 0.8em;
            border-radius: 4px;
        }
        
        .supplier-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .supplier-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .supplier-stats {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .stat-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .filters-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .search-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 15px;
        }
        
        .export-btn {
            background: linear-gradient(45deg, #17a2b8, #20c997);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 6px;
        }
        
        .export-btn:hover {
            background: linear-gradient(45deg, #20c997, #17a2b8);
            color: white;
        }
        
        .search-input {
            border: 2px solid #e9ecef;
            border-radius: 25px;
            padding: 10px 20px 10px 45px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        /* Responsive table */
        @media (max-width: 768px) {
            .invoice-table th,
            .invoice-table td {
                padding: 8px 4px;
                font-size: 0.8em;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Header Principal -->
                <div class="main-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1">
                                <i class="fas fa-list me-2"></i>
                                Listado de Facturas Pagadas
                            </h3>
                            <p class="mb-0 opacity-85">
                                <?php echo $total_suppliers; ?> proveedores • <?php echo $total_invoices; ?> facturas • 
                                $<?php echo number_format($total_paid, 0, ',', '.'); ?> total pagado
                            </p>
                        </div>
                        <div>
                            <button type="button" class="btn export-btn me-2" id="exportBtn">
                                <i class="fas fa-file-excel me-1"></i> Exportar Excel
                            </button>
                            <button type="button" class="btn btn-light me-2" id="expandAllBtn">
                                <i class="fas fa-expand-alt me-1"></i> Expandir Todo
                            </button>
                            <button type="button" class="btn btn-outline-light" id="collapseAllBtn">
                                <i class="fas fa-compress-alt me-1"></i> Contraer Todo
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="filters-card">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-4">
                                <label for="filter_supplier" class="form-label">Proveedor</label>
                                <select class="form-select" id="filter_supplier" name="filter_supplier">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_status" class="form-label">Estado</label>
                                <select class="form-select" id="filter_status" name="filter_status">
                                    <option value="">Todos</option>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($filter_status == $status) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_date_from" class="form-label">Desde</label>
                                <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="filter_date_to" class="form-label">Hasta</label>
                                <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-1"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Búsqueda en Tiempo Real -->
                <div class="search-container">
                    <div class="position-relative">
                        <i class="fas fa-search search-icon"></i>
                        <input 
                            type="text" 
                            class="form-control search-input" 
                            id="realTimeSearch" 
                            placeholder="Buscar proveedor, factura, número de pago en tiempo real..."
                            autocomplete="off"
                        >
                    </div>
                    <small class="text-muted mt-2 d-block">
                        <i class="fas fa-info-circle me-1"></i>
                        La búsqueda se actualiza automáticamente mientras escribes
                    </small>
                </div>
                
                <!-- Lista de Proveedores -->
                <div class="suppliers-container">
                    <?php if (count($grouped_invoices) > 0): ?>
                        <?php foreach ($grouped_invoices as $supplier_name => $supplier_data): ?>
                            <div class="supplier-card" data-supplier="<?php echo htmlspecialchars($supplier_name); ?>" data-supplier-id="<?php echo md5($supplier_name); ?>">
                                <div class="supplier-header" onclick="toggleSupplier('<?php echo md5($supplier_name); ?>')">
                                    <div class="supplier-info">
                                        <div class="supplier-name">
                                            <i class="fas fa-chevron-right expand-icon" id="icon-<?php echo md5($supplier_name); ?>"></i>
                                            <i class="fas fa-building text-primary me-2"></i>
                                            <div>
                                                <h5 class="mb-0"><?php echo htmlspecialchars($supplier_name); ?></h5>
                                                <small class="text-muted">
                                                    <i class="fas fa-file-invoice me-1"></i>
                                                    <?php echo $supplier_data['count']; ?> facturas
                                                </small>
                                            </div>
                                        </div>
                                        <div class="supplier-stats">
                                            <?php if ($supplier_data['completely_paid'] > 0): ?>
                                                <span class="stat-pill bg-success text-white">
                                                    <i class="fas fa-check me-1"></i>
                                                    <?php echo $supplier_data['completely_paid']; ?> completas
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($supplier_data['partially_paid'] > 0): ?>
                                                <span class="stat-pill bg-warning text-white">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo $supplier_data['partially_paid']; ?> parciales
                                                </span>
                                            <?php endif; ?>
                                            <div class="text-end">
                                                <div class="h5 mb-0 text-primary">
                                                    $<?php echo number_format($supplier_data['total_paid'], 0, ',', '.'); ?>
                                                </div>
                                                <small class="text-muted">Total pagado</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="supplier-details" id="details-<?php echo md5($supplier_name); ?>">
                                    <div class="table-responsive">
                                        <table class="table invoice-table">
                                            <thead>
                                                <tr>
                                                    <th>N° SAP</th>
                                                    <th>N° Factura</th>
                                                    <th>Fecha Vencimiento</th>
                                                    <th>Fecha de Pago</th>
                                                    <th>Valor Total</th>
                                                    <th>Valor Pagado</th>
                                                    <th>Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $facturasMostradas = [];
                                                foreach ($supplier_data['invoices'] as $invoice): 
                                                    $facturaId = $invoice['Factura'] ?? null;
                                                    // Si ya se mostró esta factura, la saltamos
                                                    if (in_array($facturaId, $facturasMostradas)) {
                                                        continue;
                                                    }
                                                    // Marcamos esta factura como mostrada
                                                    $facturasMostradas[] = $facturaId;
                                                ?>
                                                    <tr data-invoice-number="<?php echo htmlspecialchars($facturaId); ?>" data-payment-number="<?php echo htmlspecialchars($invoice['NumeroPago'] ?? ''); ?>">
                                                        <td class="text-center">
                                                            <strong><?php echo htmlspecialchars($invoice['docnum_interno_sap'] ?? $facturaId ?? 'N/A'); ?></strong>
                                                        </td>
                                                        <td class="text-center">
                                                            <strong><?php echo htmlspecialchars($invoice['numero_factura_proveedor'] ?? $invoice['Factura'] ?? 'N/A'); ?></strong>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="text-muted"><?php echo formatDate($invoice['fecha_vencimiento']); ?></span>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="text-info"><?php echo formatDate($invoice['FechadePago']); ?></span>
                                                        </td>
                                                        <td class="text-end">
                                                            <strong>$<?php echo number_format($invoice['ValorTotal'] ?? 0, 0, ',', '.'); ?></strong>
                                                        </td>
                                                        <td class="text-end text-success">
                                                            <strong>$<?php echo number_format($invoice['ValorPagado'] ?? 0, 0, ',', '.'); ?></strong>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php 
                                                            $valor_total = floatval($invoice['ValorTotal'] ?? 0);
                                                            $valor_pagado = floatval($invoice['ValorPagado'] ?? 0);
                                                            if ($valor_pagado >= $valor_total): ?>
                                                                <span class="status-badge bg-success text-white">
                                                                    <i class="fas fa-check me-1"></i>
                                                                    Pagada
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="status-badge bg-warning text-white">
                                                                    <i class="fas fa-clock me-1"></i>
                                                                    Parcial
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            No hay facturas pagadas disponibles con los filtros aplicados.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Mensaje cuando no hay resultados de búsqueda -->
                <div id="noResultsMessage" class="alert alert-warning" style="display: none;">
                    <i class="fas fa-search me-2"></i>
                    No se encontraron resultados para la búsqueda. Intenta con otros términos.
                </div>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
// Variables globales
let allSupplierCards = [];
let isAllExpanded = false;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    allSupplierCards = document.querySelectorAll('.supplier-card');
    initializeRealTimeSearch();
});

// Función para toggle de proveedor individual - CORREGIDA
function toggleSupplier(supplierId) {
    const details = document.getElementById('details-' + supplierId);
    const icon = document.getElementById('icon-' + supplierId);
    const header = details ? details.previousElementSibling : null;
    
    if (details && icon && header) {
        if (details.classList.contains('show')) {
            // Contraer
            details.classList.remove('show');
            icon.classList.remove('rotated');
            header.classList.remove('expanded');
        } else {
            // Expandir
            details.classList.add('show');
            icon.classList.add('rotated');
            header.classList.add('expanded');
        }
    }
}

// Expandir todos los proveedores
document.getElementById('expandAllBtn').addEventListener('click', function() {
    allSupplierCards.forEach(function(card) {
        if (!card.classList.contains('hidden')) {
            const supplierId = card.dataset.supplierId;
            const details = card.querySelector('.supplier-details');
            const icon = card.querySelector('.expand-icon');
            const header = card.querySelector('.supplier-header');
            
            if (details && icon && header) {
                details.classList.add('show');
                icon.classList.add('rotated');
                header.classList.add('expanded');
            }
        }
    });
    
    isAllExpanded = true;
});

// Contraer todos los proveedores
document.getElementById('collapseAllBtn').addEventListener('click', function() {
    allSupplierCards.forEach(function(card) {
        const details = card.querySelector('.supplier-details');
        const icon = card.querySelector('.expand-icon');
        const header = card.querySelector('.supplier-header');
        
        if (details && icon && header) {
            details.classList.remove('show');
            icon.classList.remove('rotated');
            header.classList.remove('expanded');
        }
    });
    
    isAllExpanded = false;
});

// Búsqueda en tiempo real - SIMPLIFICADA
function initializeRealTimeSearch() {
    const searchInput = document.getElementById('realTimeSearch');
    const noResultsMessage = document.getElementById('noResultsMessage');
    
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        let visibleCount = 0;
        
        allSupplierCards.forEach(function(card) {
            const supplierName = card.dataset.supplier.toLowerCase();
            let shouldShow = false;
            
            if (searchTerm === '') {
                shouldShow = true;
            } else if (supplierName.includes(searchTerm)) {
                shouldShow = true;
            } else {
                // Buscar en las facturas
                const invoiceRows = card.querySelectorAll('tbody tr');
                invoiceRows.forEach(function(row) {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(searchTerm)) {
                        shouldShow = true;
                    }
                });
            }
            
            if (shouldShow) {
                card.classList.remove('hidden');
                card.style.display = 'block';
                visibleCount++;
            } else {
                card.classList.add('hidden');
                card.style.display = 'none';
            }
        });
        
        // Mostrar/ocultar mensaje de "sin resultados"
        if (visibleCount === 0 && searchTerm !== '') {
            noResultsMessage.style.display = 'block';
        } else {
            noResultsMessage.style.display = 'none';
        }
    });
    
    // Limpiar búsqueda con Escape
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            this.value = '';
            this.dispatchEvent(new Event('input'));
            this.blur();
        }
    });
}

// Función COMPLETA para exportar a Excel con FECHA DE VENCIMIENTO, FECHA DE PAGO y NÚMERO DE FACTURA
document.getElementById('exportBtn').addEventListener('click', function () {
    const wb = XLSX.utils.book_new();
    const data = [];
    const seenInvoices = new Set();
    let totalFacturas = 0;
    let sumaValorTotal = 0;
    let sumaValorPagado = 0;
    const exportDate = new Date().toLocaleDateString('es-CO');

    <?php foreach ($grouped_invoices as $supplier_name => $supplier_data): ?>
        <?php foreach ($supplier_data['invoices'] as $invoice): ?>
            <?php
            $facturaId = $invoice['docnum_interno_sap'] ?? $invoice['Factura'] ?? 'N/A';
            $numeroFactura = $invoice['numero_factura_proveedor'] ?? $invoice['Factura'] ?? 'N/A';
            $fechaVencimiento = formatDate($invoice['fecha_vencimiento']);
            $fechaPago = formatDate($invoice['FechadePago']);
            $valorTotal = floatval($invoice['ValorTotal'] ?? 0);
            $valorPagado = floatval($invoice['ValorPagado'] ?? 0);
            ?>
            
            if (!seenInvoices.has('<?php echo $facturaId; ?>')) {
                seenInvoices.add('<?php echo $facturaId; ?>');
                totalFacturas++;
                sumaValorTotal += <?php echo $valorTotal; ?>;
                sumaValorPagado += <?php echo $valorPagado; ?>;
                
                data.push([
                    '<?php echo addslashes($supplier_name); ?>',
                    '<?php echo addslashes($facturaId); ?>',
                    '<?php echo addslashes($numeroFactura); ?>',
                    '<?php echo addslashes($fechaVencimiento); ?>',
                    '<?php echo addslashes($fechaPago); ?>',
                    <?php echo $valorTotal; ?>,
                    <?php echo $valorPagado; ?>
                ]);
            }
        <?php endforeach; ?>
    <?php endforeach; ?>

    // Formatear totales como moneda COP
    const sumaValorTotalFormateado = sumaValorTotal.toLocaleString('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0
    });

    const sumaValorPagadoFormateado = sumaValorPagado.toLocaleString('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0
    });

    // Encabezado y resumen con valores formateados
    data.unshift(
        ['FACTURAS PAGADAS - REPORTE COMPLETO CON FECHAS'],
        ['Fecha de exportación:', exportDate],
        ['Total facturas:', totalFacturas],
        ['Total valor facturas:', sumaValorTotalFormateado],
        ['Total valor pagado:', sumaValorPagadoFormateado],
        [],
        ['Proveedor', 'N° SAP', 'N° Factura', 'Fecha Vencimiento', 'Fecha de Pago', 'Valor Total', 'Valor Pagado']
    );

    const ws = XLSX.utils.aoa_to_sheet(data);

    const colWidths = [
        { wch: 35 }, // Proveedor
        { wch: 15 }, // N° SAP
        { wch: 20 }, // N° Factura
        { wch: 18 }, // Fecha Vencimiento
        { wch: 18 }, // Fecha de Pago
        { wch: 15 }, // Valor Total
        { wch: 15 }  // Valor Pagado
    ];
    ws['!cols'] = colWidths;

    XLSX.utils.book_append_sheet(wb, ws, "Facturas Pagadas Completo");

    const fileName = `facturas_pagadas_completo_${new Date().toISOString().slice(0, 10)}.xlsx`;
    XLSX.writeFile(wb, fileName);

    // Mostrar alerta de éxito
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 350px; max-width: 500px;';
    alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-2 fs-4"></i>
            <div>
                <strong>¡Exportación Exitosa!</strong><br>
                <small class="text-muted">Archivo: ${fileName}</small><br>
                <small class="text-muted">${totalFacturas} facturas exportadas</small>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);

    // Auto remover la alerta
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 6000);
});

</script>
</body>
</html>
