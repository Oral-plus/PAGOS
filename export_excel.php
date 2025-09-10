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

function cleanForExcel($value) {
    if (is_null($value) || $value === '') return '';
    
    // Handle DateTime objects
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    
    // Handle SQL Server date objects by converting to string first
    if (is_object($value)) {
        $value = (string) $value;
    }
    
    // Convert to string and clean
    $value = trim((string) $value);
    
    // Handle date strings (YYYY-MM-DD format)
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        // Extract just the date part (first 10 characters)
        $dateOnly = substr($value, 0, 10);
        if ($dateOnly !== '0000-00-00' && $dateOnly !== '1900-01-01') {
            return $dateOnly;
        }
        return '';
    }
    
    // Handle numeric values - return as number for Excel
    if (is_numeric($value)) {
        return (float) $value;
    }
    
    // Clean text values
    $value = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '', $value);
    $value = str_replace(["\r", "\n"], ' ', $value);
    
    return $value;
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($value) {
        if (is_null($value) || $value === '' || $value === '0000-00-00 00:00:00') return '';
        
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        
        if (is_object($value)) {
            $value = (string) $value;
        }
        
        $value = trim((string) $value);
        
        // If it contains time information, return as is (cleaned)
        if (strpos($value, ':') !== false) {
            return substr($value, 0, 19); // YYYY-MM-DD HH:MM:SS
        }
        
        // If it's just a date, return date only
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        
        return $value;
    }
}

// Procesar la solicitud de exportación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_excel'])) {
    $fecha_aprobacion_inicio = $_POST['fecha_aprobacion_inicio'] ?? '';
    $fecha_aprobacion_fin = $_POST['fecha_aprobacion_fin'] ?? '';
    
    $ok_invoices = getFilteredInvoices('', '', '', '', '', true);
    
    if (!empty($fecha_aprobacion_inicio) && !empty($fecha_aprobacion_fin)) {
        $filtered_invoices = [];
        foreach ($ok_invoices as $invoice) {
            $approved_at = cleanForExcel($invoice['ok']);
            if ($approved_at >= $fecha_aprobacion_inicio && $approved_at <= $fecha_aprobacion_fin) {
                $filtered_invoices[] = $invoice;
            }
        }
        $ok_invoices = $filtered_invoices;
    }
    
    // Eliminar duplicados por DocNum
    $unique_invoices = [];
    $seen_docnums = [];
    
    foreach ($ok_invoices as $invoice) {
        $docnum = $invoice['docnum_interno_sap'];
        if (!in_array($docnum, $seen_docnums)) {
            $unique_invoices[] = $invoice;
            $seen_docnums[] = $docnum;
        }
    }
    
    // Ordenar por proveedor y días vencidos
    usort($unique_invoices, function($a, $b) {
        $supplierCompare = strcmp($a['nombre'], $b['nombre']);
        if ($supplierCompare === 0) {
            return $b['dias_de_vencido'] <=> $a['dias_de_vencido'];
        }
        return $supplierCompare;
    });
    
    $grouped_invoices = [];
    $grand_total = 0;
    
    foreach ($unique_invoices as $invoice) {
        $supplier = $invoice['nombre'];
        if (!isset($grouped_invoices[$supplier])) {
            $grouped_invoices[$supplier] = [
                'invoices' => [],
                'total' => 0
            ];
        }
        $grouped_invoices[$supplier]['invoices'][] = $invoice;
        $saldo = (float) str_replace(',', '', $invoice['saldo_pendiente'] ?? '0');
        $grouped_invoices[$supplier]['total'] += $saldo;
        $grand_total += $saldo;
    }
    
    $filename = "facturas_ok_" . date('Y-m-d');
    if (!empty($fecha_aprobacion_inicio) && !empty($fecha_aprobacion_fin)) {
        $filename .= "_desde_" . $fecha_aprobacion_inicio . "_hasta_" . $fecha_aprobacion_fin;
    }
    $filename .= "_total_" . number_format($grand_total, 2, '.', '') . ".xls";
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Start HTML output with Excel-compatible styling
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
            .header { background-color: #FF6600; color: white; font-weight: bold; text-align: center; padding: 8px; border: 1px solid #000; }
            .row-even { background-color: #F5F5DC; }
            .row-odd { background-color: #FFFFFF; }
            .subtotal { background-color: #E0E0E0; font-weight: bold; border-top: 2px solid #000; }
            .grand-total { background-color: #FFD700; font-weight: bold; font-size: 12pt; border: 2px solid #000; }
            td { padding: 6px; border: 1px solid #ccc; text-align: left; }
            .number { text-align: right; }
            .center { text-align: center; }
        </style>
    </head>
    <body>
        <table>';
    
    // Headers
    echo '<tr>
            <td class="header">Código</td>
            <td class="header">Nombre SN</td>
            <td class="header">Grupo</td>
            <td class="header">Docum</td>
            <td class="header">Docum</td>
            <td class="header">No ref</td>
            <td class="header">Condic</td>
            <td class="header">Fecha Creación</td>
            <td class="header">Fecha Aprobación</td>
            <td class="header">Fecha Ven</td>
            <td class="header">Días</td>
            <td class="header">Saldo Vencido</td>
          </tr>';
    
    $rowCount = 0;
    foreach ($grouped_invoices as $supplier => $group) {
        foreach ($group['invoices'] as $invoice) {
            $rowClass = ($rowCount % 2 == 0) ? 'row-even' : 'row-odd';
            
            echo '<tr class="' . $rowClass . '">
                    <td>' . htmlspecialchars(cleanForExcel($invoice['codigo_sn'] ?? '')) . '</td>
                    <td>' . htmlspecialchars(cleanForExcel($invoice['nombre'] ?? '')) . '</td>
                    <td>' . htmlspecialchars(cleanForExcel($invoice['rango_antiguedad'] ?? '')) . '</td>
                    <td>Factura proveedor</td>
                    <td>' . htmlspecialchars(cleanForExcel($invoice['numero_factura_proveedor'] ?? '')) . '</td>
                    <td>' . htmlspecialchars(cleanForExcel($invoice['docnum_interno_sap'] ?? '')) . '</td>
                    <td>' . htmlspecialchars(cleanForExcel($invoice['dias_de_vencido'] ?? '0')) . ' Días</td>
                    <td class="center">' . htmlspecialchars(cleanForExcel($invoice['created_at'] ?? '')) . '</td>
                    <td class="center">' . htmlspecialchars(cleanForExcel($invoice['ok'] ?? '')) . '</td>
                    <td class="center">' . htmlspecialchars(cleanForExcel($invoice['fecha_vencimiento'] ?? '')) . '</td>
                    <td class="number">' . htmlspecialchars(cleanForExcel($invoice['dias_de_vencido'] ?? '0')) . '</td>
                    <td class="number">$' . number_format((float) str_replace(',', '', $invoice['saldo_pendiente'] ?? '0'), 2) . '</td>
                  </tr>';
            $rowCount++;
        }
        
        // Subtotal row
        echo '<tr class="subtotal">
                <td colspan="11"></td>
                <td class="number">$' . number_format($group['total'], 2) . '</td>
              </tr>';
        
        // Empty row for separation
        echo '<tr><td colspan="12" style="height: 10px; border: none;"></td></tr>';
    }
    
    // Grand total row
    echo '<tr class="grand-total">
            <td colspan="10"></td>
            <td class="center">TOTAL GENERAL:</td>
            <td class="number">$' . number_format($grand_total, 2) . '</td>
          </tr>';
    
    echo '</table></body></html>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Facturas OK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .card {
            max-width: 800px;
            margin: 2rem auto;
            border-radius: 10px;
        }
        .btn-download {
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            border-radius: 10px 10px 0 0 !important;
        }
        .feature-icon {
            font-size: 1.5rem;
            margin-right: 10px;
            vertical-align: middle;
        }
        .compatibility-badge {
            font-size: 0.9rem;
            margin: 5px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="card shadow-lg">
            <div class="card-header bg-success text-white">
                <h2 class="mb-0"><i class="fas fa-file-excel me-2"></i>Exportar Facturas Marcadas como OK</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Este reporte incluirá únicamente las facturas marcadas como "OK" con formato Excel y colores.
                </div>
                
                <form method="POST" action="">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="fecha_aprobacion_inicio" class="form-label">Fecha Aprobación Inicio (Opcional)</label>
                            <input type="date" class="form-control" id="fecha_aprobacion_inicio" name="fecha_aprobacion_inicio">
                            <small class="form-text text-muted">Filtra por fecha cuando se aprobó la factura (campo OK)</small>
                        </div>
                        <div class="col-md-6">
                            <label for="fecha_aprobacion_fin" class="form-label">Fecha Aprobación Fin (Opcional)</label>
                            <input type="date" class="form-control" id="fecha_aprobacion_fin" name="fecha_aprobacion_fin">
                            <small class="form-text text-muted">Filtra por fecha cuando se aprobó la factura (campo OK)</small>
                        </div>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card border-success mb-3 h-100">
                                <div class="card-body">
                                    <h5 class="card-title text-success"><i class="fas fa-check-circle me-2"></i>Características del Reporte</h5>
                                    <ul class="list-unstyled">
                                        <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Solo facturas marcadas como OK</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Muestra fecha de aprobación</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Formato Excel con colores</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Encabezados naranjas</li>
                                        <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Filas alternadas con colores</li>
                                        <li><i class="fas fa-check-circle text-success feature-icon"></i>Subtotales y total general</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-primary h-100">
                                <div class="card-body">
                                    <h5 class="card-title text-primary"><i class="fas fa-file-excel me-2"></i>Formato Excel</h5>
                                    <div class="mb-3">
                                        <p>El archivo se descargará en formato Excel (.xls) con:</p>
                                        <ul>
                                            <li>Encabezados con fondo naranja</li>
                                            <li>Filas alternadas en colores</li>
                                            <li>Subtotales por proveedor</li>
                                            <li>Total general destacado</li>
                                            <li>Formato de moneda automático</li>
                                            <li>Columnas auto-ajustadas</li>
                                        </ul>
                                    </div>
                                    <div class="text-center">
                                        <span class="badge bg-primary compatibility-badge"><i class="fab fa-windows me-1"></i>Excel</span>
                                        <span class="badge bg-primary compatibility-badge"><i class="fab fa-linux me-1"></i>LibreOffice</span>
                                        <span class="badge bg-primary compatibility-badge"><i class="fab fa-apple me-1"></i>Numbers</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-3">
                        <button type="submit" name="export_excel" class="btn btn-success btn-download btn-lg">
                            <i class="fas fa-file-export me-2"></i> Generar Reporte Excel con Colores
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Volver al Panel
                        </a>
                    </div>
                </form>
            </div>
            <div class="card-footer text-muted text-center">
                <small>Sistema de Facturas - <?php echo date('Y'); ?></small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
