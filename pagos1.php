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
                'count' => 0
            ];
        }
        
        $grouped[$supplier_name]['invoices'][] = $invoice;
        $grouped[$supplier_name]['total_paid'] += floatval($invoice['ValorPagado'] ?? 0);
        $grouped[$supplier_name]['count']++;
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

// Preparar datos para exportación en formato JSON
$export_data = [];
foreach ($grouped_invoices as $supplier_name => $supplier_data) {
    $facturasMostradas = [];
    foreach ($supplier_data['invoices'] as $invoice) {
        $facturaId = $invoice['Factura'] ?? null;
        if (in_array($facturaId, $facturasMostradas)) {
            continue;
        }
        $facturasMostradas[] = $facturaId;
        
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

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Exportar Facturas Pagadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .export-container {
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
        }
        
        .export-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .export-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .export-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .export-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .export-body {
            padding: 40px 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            border: 2px solid #dee2e6;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .filters-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .filters-section h5 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .export-button-container {
            text-align: center;
            padding: 20px 0;
        }
        
        .btn-export-main {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            padding: 20px 60px;
            border-radius: 50px;
            box-shadow: 0 10px 30px rgba(40, 167, 69, 0.4);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-export-main:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(40, 167, 69, 0.6);
            background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
        }
        
        .btn-export-main:active {
            transform: translateY(-1px);
        }
        
        .btn-export-main i {
            margin-right: 10px;
            font-size: 1.5rem;
        }
        
        .filter-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 5px;
        }
        
        .no-filters {
            color: #6c757d;
            font-style: italic;
        }
        
        .back-link {
            display: inline-block;
            color: white;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: #f8f9fa;
            transform: translateX(-5px);
        }
        
        @media (max-width: 768px) {
            .export-header h1 {
                font-size: 1.8rem;
            }
            
            .btn-export-main {
                font-size: 1.1rem;
                padding: 15px 40px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
                <div class="export-container">
                    <a href="dashboard.php" class="back-link">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                    </a>
                    
                    <div class="export-card">
                        <div class="export-header">
                            <i class="fas fa-file-excel" style="font-size: 4rem; margin-bottom: 20px;"></i>
                            <h1>Exportar Facturas Pagadas</h1>
                            <p>Descarga un reporte completo en formato Excel</p>
                        </div>
                        
                        <div class="export-body">
                            <!-- Estadísticas -->
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $total_suppliers; ?></div>
                                    <div class="stat-label">Proveedores</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-file-invoice"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $total_invoices; ?></div>
                                    <div class="stat-label">Facturas</div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                    <div class="stat-value">$<?php echo number_format($total_paid, 0, ',', '.'); ?></div>
                                    <div class="stat-label">Total Pagado</div>
                                </div>
                            </div>
                            
                            <!-- Filtros Aplicados -->
                            <div class="filters-section">
                                <h5><i class="fas fa-filter me-2"></i>Filtros Aplicados</h5>
                                <div>
                                    <?php if (!empty($filter_supplier)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-building me-1"></i>
                                            <?php echo htmlspecialchars($filter_supplier); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_status)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?php echo htmlspecialchars($filter_status); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_from)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-calendar me-1"></i>
                                            Desde: <?php echo $filter_date_from; ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_to)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-calendar me-1"></i>
                                            Hasta: <?php echo $filter_date_to; ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($filter_supplier) && empty($filter_status) && empty($filter_date_from) && empty($filter_date_to)): ?>
                                        <span class="no-filters">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Sin filtros aplicados - Se exportarán todas las facturas
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Configurar Filtros -->
                            <div class="filters-section">
                                <h5><i class="fas fa-sliders-h me-2"></i>Configurar Filtros</h5>
                                <form method="GET" action="" class="row g-3">
                                    <div class="col-md-6">
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
                                    
                                    <div class="col-md-6">
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
                                    
                                    <div class="col-md-6">
                                        <label for="filter_date_from" class="form-label">Fecha Desde</label>
                                        <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="filter_date_to" class="form-label">Fecha Hasta</label>
                                        <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                    </div>
                                    
                                    <div class="col-12 text-center">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-sync-alt me-2"></i>Aplicar Filtros
                                        </button>
                                        <a href="?" class="btn btn-outline-secondary btn-lg ms-2">
                                            <i class="fas fa-times me-2"></i>Limpiar Filtros
                                        </a>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Botón de Exportación -->
                            <div class="export-button-container">
                                <button type="button" class="btn-export-main" id="exportBtn">
                                    <i class="fas fa-download"></i>
                                    Descargar Excel
                                </button>
                                <p class="text-muted mt-3 mb-0">
                                    <i class="fas fa-info-circle me-1"></i>
                                    El archivo incluirá <?php echo $total_invoices; ?> facturas de <?php echo $total_suppliers; ?> proveedores
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        // Datos de exportación desde PHP
        const exportData = <?php echo json_encode($export_data); ?>;
        const totalInvoices = <?php echo $total_invoices; ?>;
        const totalSuppliers = <?php echo $total_suppliers; ?>;
        const totalPaid = <?php echo $total_paid; ?>;
        
        document.getElementById('exportBtn').addEventListener('click', function() {
            if (exportData.length === 0) {
                alert('No hay datos para exportar. Por favor, ajusta los filtros.');
                return;
            }
            
            // Crear array para la hoja de Excel
            const hojaData = [];
            
            // Encabezado principal con estilo
            hojaData.push(['REPORTE DE FACTURAS PAGADAS']);
            hojaData.push([]);
            
            // Información del reporte
            hojaData.push(['Fecha de Exportación:', new Date().toLocaleString('es-ES')]);
            hojaData.push(['Total de Proveedores:', totalSuppliers]);
            hojaData.push(['Total de Facturas:', totalInvoices]);
            hojaData.push(['Monto Total Pagado:', '$' + totalPaid.toLocaleString('es-ES')]);
            hojaData.push([]);
            
            // Filtros aplicados
            <?php if (!empty($filter_supplier) || !empty($filter_status) || !empty($filter_date_from) || !empty($filter_date_to)): ?>
            hojaData.push(['FILTROS APLICADOS:']);
            <?php if (!empty($filter_supplier)): ?>
            hojaData.push(['Proveedor:', '<?php echo addslashes($filter_supplier); ?>']);
            <?php endif; ?>
            <?php if (!empty($filter_status)): ?>
            hojaData.push(['Estado:', '<?php echo addslashes($filter_status); ?>']);
            <?php endif; ?>
            <?php if (!empty($filter_date_from)): ?>
            hojaData.push(['Fecha Desde:', '<?php echo $filter_date_from; ?>']);
            <?php endif; ?>
            <?php if (!empty($filter_date_to)): ?>
            hojaData.push(['Fecha Hasta:', '<?php echo $filter_date_to; ?>']);
            <?php endif; ?>
            hojaData.push([]);
            <?php endif; ?>
            
            // Encabezados de columnas
            hojaData.push(['Proveedor', 'N° SAP', 'N° Factura', 'Fecha Vencimiento', 'Fecha de Pago', 'Valor Total', 'Valor Pagado', 'Estado']);
            
            // Agregar datos de facturas
            exportData.forEach(invoice => {
                hojaData.push([
                    invoice.proveedor,
                    invoice.n_sap,
                    invoice.n_factura,
                    invoice.fecha_vencimiento,
                    invoice.fecha_pago,
                    invoice.valor_total,
                    invoice.valor_pagado,
                    invoice.estado
                ]);
            });
            
            // Crear la hoja de cálculo
            const worksheet = XLSX.utils.aoa_to_sheet(hojaData);
            
            // Ajustar ancho de columnas
            const columnWidths = [
                { wch: 30 }, // Proveedor
                { wch: 15 }, // N° SAP
                { wch: 15 }, // N° Factura
                { wch: 18 }, // Fecha Vencimiento
                { wch: 18 }, // Fecha de Pago
                { wch: 15 }, // Valor Total
                { wch: 15 }, // Valor Pagado
                { wch: 12 }  // Estado
            ];
            worksheet['!cols'] = columnWidths;
            
            // Crear el libro y agregar la hoja
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, 'Facturas Pagadas');
            
            // Generar nombre de archivo con fecha
            const fecha = new Date().toISOString().split('T')[0];
            const nombreArchivo = `Facturas_Pagadas_${fecha}.xlsx`;
            
            // Descargar el archivo
            XLSX.writeFile(workbook, nombreArchivo);
            
            // Mostrar mensaje de éxito
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-2"></i>¡Descargado Exitosamente!';
            btn.style.background = 'linear-gradient(135deg, #20c997 0%, #28a745 100%)';
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
            }, 3000);
        });
    </script>
</body>
</html>
