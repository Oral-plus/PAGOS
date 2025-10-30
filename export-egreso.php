<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'];

// Filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// === NUEVO: Generar Egreso ===
if (isset($_POST['action']) && $_POST['action'] === 'generar_egreso') {
    $proveedor = $_POST['proveedor'] ?? '';
    $fecha_inicio = $_POST['fecha_inicio'] ?? '';
    $fecha_fin = $_POST['fecha_fin'] ?? '';
    $numero_documento = $_POST['numero_documento'] ?? '';

    if (empty($proveedor) || empty($fecha_inicio) || empty($fecha_fin) || empty($numero_documento)) {
        die('Error: Todos los campos son requeridos');
    }

    $facturas = getFacturasEgreso($proveedor, $fecha_inicio, $fecha_fin);
    if (empty($facturas)) {
        die('No se encontraron facturas para el proveedor y rango de fechas seleccionados');
    }

    $total_valor = array_sum(array_column($facturas, 'ValorPagado'));
    $info_proveedor = $facturas[0];
    $fecha_documento = date('d/m/Y');
    $fecha_vencimiento = date('d/m/Y', strtotime('+30 days'));

    // Renderizar Egreso
    ob_start();
    include 'templates/egreso_template.php';
    $html = ob_get_clean();
    echo $html;
    exit();
}

// === Función optimizada para obtener solo proveedores (rápido) ===
function getAllSuppliers() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT i.[nombre] 
            FROM [invoice_approval_system].[dbo].[invoices] i
            INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
                ON LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
                OR LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
            WHERE i.[nombre] IS NOT NULL AND i.[nombre] != '' 
            ORDER BY i.[nombre]";
    
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = sqlsrv_query($conn, $sql);
        if (!$stmt) die(print_r(sqlsrv_errors(), true));
        $suppliers = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $suppliers[] = $row[0];
        }
        return $suppliers;
    }
}

function getAllPaidStatuses() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT [Estado] FROM [invoice_approval_system].[dbo].[Invoice_pagas] WHERE [Estado] IS NOT NULL AND [Estado] != '' ORDER BY [Estado]";
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = sqlsrv_query($conn, $sql);
        if (!$stmt) die(print_r(sqlsrv_errors(), true));
        $statuses = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
            $statuses[] = $row[0];
        }
        return $statuses;
    }
}

// === Solo cargar facturas al exportar o generar egreso ===
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $invoices = getGroupedPaidInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_status);
    exportToExcel($invoices);
    exit();
}

// Solo obtener proveedores para filtros (rápido)
$suppliers = getAllSuppliers();
$statuses = getAllPaidStatuses();

// NO cargar facturas en la vista principal
$grouped_invoices = [];
$total_suppliers = 0;
$total_invoices = 0;
$total_paid = 0;

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
        .main-header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 15px 20px; margin-bottom: 20px; border-radius: 8px; }
        .filters-card, .search-container { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; padding: 15px; }
        .search-input { border: 2px solid #e9ecef; border-radius: 25px; padding: 10px 20px 10px 45px; font-size: 16px; }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6c757d; }
        .supplier-card { border: 2px solid #e3f2fd; border-radius: 8px; margin-bottom: 15px; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .supplier-header { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px 20px; cursor: pointer; }
        .supplier-header:hover { background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); }
        .expand-icon { transition: transform 0.3s ease; color: #28a745; }
        .expand-icon.rotated { transform: rotate(90deg); }
        .stat-pill { padding: 4px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .invoice-table th { background: #f8f9fa; font-size: 0.85em; padding: 12px 6px; text-align: center; }
        .invoice-table td { padding: 10px 6px; font-size: 0.9em; }
        .btn-action { padding: 4px 8px; font-size: 0.8em; border-radius: 4px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="main-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-1"><i class="fas fa-list me-2"></i> Facturas Pagadas</h3>
                            <p class="mb-0 opacity-85">Filtra y exporta facturas pagadas por proveedor</p>
                        </div>
                        <div>
                            <button type="button" class="btn btn-success me-2" id="exportBtn">
                                <i class="fas fa-file-excel me-1"></i> Exportar Excel
                            </button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#egresoModal">
                                <i class="fas fa-file-invoice-dollar me-1"></i> Generar Egreso
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <form method="GET" class="filters-card">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Proveedor</label>
                            <select class="form-select" name="filter_supplier">
                                <option value="">Todos</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $filter_supplier === $s ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Estado</label>
                            <select class="form-select" name="filter_status">
                                <option value="">Todos</option>
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Desde</label>
                            <input type="date" class="form-control" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Hasta</label>
                            <input type="date" class="form-control" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filtrar
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Info: No se cargan facturas hasta exportar -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Optimizado:</strong> Las facturas solo se cargan al exportar o generar egreso.
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para Generar Egreso -->
    <div class="modal fade" id="egresoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="" id="egresoForm">
                    <input type="hidden" name="action" value="generar_egreso">
                    <div class="modal-header">
                        <h5 class="modal-title">Generar Egreso</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Proveedor</label>
                            <select class="form-select" name="proveedor" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha Inicio</label>
                                <input type="date" class="form-control" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha Fin</label>
                                <input type="date" class="form-control" name="fecha_fin" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Número Documento</label>
                            <input type="text" class="form-control" name="numero_documento" placeholder="EJ: EG-2025-001" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Generar Egreso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        document.getElementById('exportBtn').addEventListener('click', function() {
            const params = new URLSearchParams(new FormData(document.querySelector('form')));
            params.append('export', 'excel');
            window.location.href = '?' + params.toString();
        });
    </script>
</body>
</html>

<?php
// === Función para exportar Excel (solo se llama al exportar) ===
function getGroupedPaidInvoices($supplier = '', $date_from = '', $date_to = '', $status = '') {
    $conn = getDbConnection();
    $sql = "WITH Ranked AS (
        SELECT 
            i.[nombre] as proveedor_nombre,
            p.[Factura],
            p.[Fecha Factura],
            p.[Valor Total],
            p.[Valor Pagado],
            p.[Estado],
            p.[Fecha de Pago],
            i.[docnum_interno_sap],
            i.[numero_factura_proveedor],
            ROW_NUMBER() OVER (PARTITION BY p.[Factura] ORDER BY p.[Fecha de Pago] DESC) as rn
        FROM [invoice_approval_system].[dbo].[invoices] i
        INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
            ON LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
            OR LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
        WHERE 1=1
    )
    SELECT * FROM Ranked WHERE rn = 1";

    $params = [];
    if ($supplier) { $sql .= " AND proveedor_nombre = ?"; $params[] = $supplier; }
    if ($date_from) { $sql .= " AND [Fecha Factura] >= ?"; $params[] = $date_from; }
    if ($date_to) { $sql .= " AND [Fecha Factura] <= ?"; $params[] = $date_to; }
    if ($status) { $sql .= " AND Estado = ?"; $params[] = $status; }
    $sql .= " ORDER BY proveedor_nombre, [Fecha Factura] DESC";

    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) die(print_r(sqlsrv_errors(), true));
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    }

    $grouped = [];
    foreach ($rows as $r) {
        $s = $r['proveedor_nombre'];
        if (!isset($grouped[$s])) {
            $grouped[$s] = ['invoices' => [], 'total_paid' => 0, 'count' => 0, 'completely_paid' => 0, 'partially_paid' => 0];
        }
        $grouped[$s]['invoices'][] = $r;
        $grouped[$s]['total_paid'] += floatval($r['ValorPagado']);
        $grouped[$s]['count']++;
        if (floatval($r['ValorPagado']) >= floatval($r['Valor Total'])) {
            $grouped[$s]['completely_paid']++;
        } else {
            $grouped[$s]['partially_paid']++;
        }
    }
    return $grouped;
}

function exportToExcel($grouped) {
    $data = [['FACTURAS PAGADAS'], ['Fecha:', date('d/m/Y H:i')]];
    $totalFacturas = 0;
    $data[] = ['Total de facturas:', 0]; // Se actualizará
    $data[] = [];
    $data[] = ['Proveedor', 'N° SAP', 'N° Factura', 'Fecha Factura', 'Fecha Pago', 'Valor Total', 'Valor Pagado', 'Estado'];

    foreach ($grouped as $s => $d) {
        foreach ($d['invoices'] as $i) {
            $data[] = [
                $s,
                $i['docnum_interno_sap'] ?? $i['Factura'],
                $i['numero_factura_proveedor'] ?? $i['Factura'],
                formatDate($i['Fecha Factura']),
                formatDate($i['Fecha de Pago']),
                number_format($i['Valor Total'], 0, ',', '.'),
                number_format($i['Valor Pagado'], 0, ',', '.'),
                $i['Estado']
            ];
            $totalFacturas++;
        }
    }
    $data[2] = ['Total de facturas:', $totalFacturas];

    $ws = XLSX.utils.aoa_to_sheet($data);
    $wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet($wb, $ws, "Facturas");
    XLSX.writeFile($wb, "Facturas_Pagadas_" . date('Ymd_His') . ".xlsx");
}

// === Función para Egreso (usada en modal) ===
function getFacturasEgreso($proveedor, $inicio, $fin) {
    $conn = getDbConnection();
    $sql = "WITH Ranked AS (
        SELECT 
            i.[docnum_interno_sap], i.[numero_factura_proveedor], p.*,
            ROW_NUMBER() OVER (PARTITION BY p.[Factura] ORDER BY p.[Fecha de Pago] DESC) as rn
        FROM [invoice_approval_system].[dbo].[invoices] i
        INNER JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
            ON LTRIM(RTRIM(CAST(i.[docnum_interno_sap] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
            OR LTRIM(RTRIM(CAST(i.[numero_factura_proveedor] AS NVARCHAR(255)))) = LTRIM(RTRIM(CAST(p.[Factura] AS NVARCHAR(255))))
        WHERE i.[nombre] = ? AND p.[Fecha Factura] BETWEEN ? AND ?
    )
    SELECT * FROM Ranked WHERE rn = 1 ORDER BY [Fecha Factura]";
    
    $params = [$proveedor, $inicio, $fin];
    if (is_a($conn, 'PDO')) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) die(print_r(sqlsrv_errors(), true));
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
        return $rows;
    }
}

// === Formatear fecha ===
function formatDate($date) {
    if (!$date) return 'N/A';
    if (is_object($date) && $date instanceof DateTime) {
        return $date->format('d/m/Y');
    }
    return date('d/m/Y', is_numeric($date) ? $date : strtotime($date));
}
?>