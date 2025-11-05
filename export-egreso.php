<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Cargar autoload de Composer si existe
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

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

// === GENERAR EGRESO ===
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

    ob_start();
    include 'templates/egreso_template.php';
    $html = ob_get_clean();
    echo $html;
    exit();
}

// === PROVEEDORES Y ESTADOS (rápido) ===
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

// === EXPORTAR EXCEL (con validación de fechas) ===
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (empty($filter_date_from) || empty($filter_date_to)) {
        echo "<script>alert('Debes seleccionar un rango de fechas para exportar.'); window.history.back();</script>";
        exit();
    }

    $invoices = getGroupedPaidInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_status);

    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        exportToXlsx($invoices);
    } else {
        exportToExcelCSV($invoices);
    }
    exit();
}

// Cargar solo filtros
$suppliers = getAllSuppliers();
$statuses = getAllPaidStatuses();
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
    <link rel spinner.css" href="assets/css/styles.css">
    <style>
        .main-header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 15px 20px; margin-bottom: 20px; border-radius: 8px; }
        .filters-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; padding: 15px; }
        .alert-info { background: #e3f2fd; border: 1px solid #bbdefb; color: #1976d2; }
        .btn-export { min-width: 150px; }
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
                            <button type="button" class="btn btn-success me-2 btn-export" id="exportBtn" onclick="startExport()">
                                <span id="exportText"><i class="fas fa-file-excel me-1"></i> Exportar Excel</span>
                                <span id="exportLoading" class="d-none"><i class="fas fa-spinner fa-spin me-1"></i> Generando...</span>
                            </button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#egresoModal">
                                <i class="fas fa-file-invoice-dollar me-1"></i> Generar Egreso
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <form method="GET" class="filters-card" id="filterForm">
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
                            <label class="form-label">Desde *</label>
                            <input type="date" class="form-control" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Hasta *</label>
                            <input type="date" class="form-control" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filtrar
                            </button>
                        </div>
                    </div>
                </form>

                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Optimizado:</strong> Las facturas solo se cargan al exportar o generar egreso.
                    <br><small><strong>Importante:</strong> Debes seleccionar un rango de fechas para exportar.</small>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Egreso -->
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
    <script>
        function startExport() {
            const from = document.querySelector('[name="filter_date_from"]').value;
            const to = document.querySelector('[name="filter_date_to"]').value;
            if (!from || !to) {
                alert('Por favor selecciona un rango de fechas para exportar.');
                return;
            }

            const btn = document.getElementById('exportBtn');
            const text = document.getElementById('exportText');
            const loading = document.getElementById('exportLoading');

            text.classList.add('d-none');
            loading.classList.remove('d-none');
            btn.disabled = true;

            const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
            params.append('export', 'excel');
            window.location.href = '?' + params.toString();
        }
    </script>
</body>
</html>

<?php
// === FUNCIONES DE EXPORTACIÓN ===

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
            $grouped[$s] = ['invoices' => [], 'total_paid' => 0, 'count' => 0];
        }
        $grouped[$s]['invoices'][] = $r;
        $grouped[$s]['total_paid'] += floatval($r['ValorPagado']);
        $grouped[$s]['count']++;
    }
    return $grouped;
}

function exportToXlsx($grouped) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'FACTURAS PAGADAS');
    $sheet->setCellValue('A2', 'Fecha de generación: ' . date('d/m/Y H:i'));

    $headers = ['Proveedor', 'N° SAP', 'N° Factura', 'Fecha Factura', 'Fecha Pago', 'Valor Total', 'Valor Pagado', 'Estado'];
    $rowIndex = 4;
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col++ . $rowIndex, $h);
    }
    $rowIndex++;

    $filename = 'Facturas_Pagadas_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    if (ob_get_level()) ob_end_clean();
    flush();

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);

    $totalFacturas = 0;
    $totalPagado = 0;
    $counter = 0;

    foreach ($grouped as $proveedor => $data) {
        foreach ($data['invoices'] as $i) {
            $col = 'A';
            $sheet->setCellValue($col++ . $rowIndex, $proveedor);
            $sheet->setCellValue($col++ . $rowIndex, $i['docnum_interno_sap'] ?? $i['numero_factura_proveedor'] ?? $i['Factura']);
            $sheet->setCellValue($col++ . $rowIndex, $i['numero_factura_proveedor'] ?? $i['Factura']);
            $sheet->setCellValue($col++ . $rowIndex, formatDate($i['Fecha Factura']));
            $sheet->setCellValue($col++ . $rowIndex, formatDate($i['Fecha de Pago']));

            $valorTotal = floatval($i['Valor Total'] ?? 0);
            $valorPagado = floatval($i['Valor Pagado'] ?? 0);

            $sheet->setCellValue($col . $rowIndex, $valorTotal);
            $sheet->getStyle($col++ . $rowIndex)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->setCellValue($col . $rowIndex, $valorPagado);
            $sheet->getStyle($col++ . $rowIndex)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->setCellValue($col . $rowIndex, $i['Estado'] ?? '');

            $rowIndex++;
            $totalFacturas++;
            $totalPagado += $valorPagado;
            $counter++;

            if ($counter % 100 === 0) {
                $writer->save('php://output');
                flush();
                if (ob_get_level()) ob_clean();
            }
        }
    }

    $sheet->setCellValue('A' . $rowIndex, 'Total de facturas');
    $sheet->setCellValue('B' . $rowIndex, $totalFacturas);
    $rowIndex++;
    $sheet->setCellValue('A' . $rowIndex, 'Total pagado');
    $sheet->setCellValue('B' . $rowIndex, $totalPagado);
    $sheet->getStyle('B' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0');

    $writer->save('php://output');
    exit();
}

function exportToExcelCSV($grouped) {
    $filename = 'Facturas_Pagadas_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['FACTURAS PAGADAS'], ';');
    fputcsv($out, ['Fecha de generación', date('d/m/Y H:i')], ';');
    fputcsv($out, []);
    fputcsv($out, ['Proveedor', 'N° SAP', 'N° Factura', 'Fecha Factura', 'Fecha Pago', 'Valor Total', 'Valor Pagado', 'Estado'], ';');

    $totalFacturas = 0;
    $totalPagado = 0;

    foreach ($grouped as $proveedor => $data) {
        foreach ($data['invoices'] as $i) {
            $row = [
                $proveedor,
                $i['docnum_interno_sap'] ?? $i['numero_factura_proveedor'] ?? $i['Factura'],
                $i['numero_factura_proveedor'] ?? $i['Factura'],
                formatDate($i['Fecha Factura']),
                formatDate($i['Fecha de Pago']),
                number_format(floatval($i['Valor Total'] ?? 0), 0, ',', '.'),
                number_format(floatval($i['Valor Pagado'] ?? 0), 0, ',', '.'),
                $i['Estado'] ?? ''
            ];
            fputcsv($out, $row, ';');
            $totalFacturas++;
            $totalPagado += floatval($i['Valor Pagado'] ?? 0);
        }
    }

    fputcsv($out, []);
    fputcsv($out, ['Total de facturas', $totalFacturas], ';');
    fputcsv($out, ['Total pagado', number_format($totalPagado, 0, ',', '.')], ';');

    fclose($out);
    exit();
}

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
?>