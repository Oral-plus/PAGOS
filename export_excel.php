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

// Función para limpiar datos para CSV
function cleanForCSV($value) {
    if (is_null($value)) return '';
    // Escapar comillas dobles
    $value = str_replace('"', '""', $value);
    // Eliminar saltos de línea
    $value = str_replace(["\r", "\n"], ' ', $value);
    return $value;
}

// Procesar la solicitud de exportación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_excel'])) {
    // Obtener todas las facturas (pendientes y OK)
    $pending_invoices = getFilteredInvoices('', '', '', '', '', false);
    $ok_invoices = getFilteredInvoices('', '', '', '', '', true);
    $all_invoices = array_merge($pending_invoices, $ok_invoices);
    
    // Eliminar duplicados
    $unique_invoices = [];
    $seen_docnums = [];
    
    foreach ($all_invoices as $invoice) {
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
    
    // Configurar headers para descarga
    $filename = "reporte_facturas_" . date('Y-m-d') . ".csv";
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // BOM para UTF-8 (ayuda con la codificación en Excel)
    echo "\xEF\xBB\xBF";
    
    // Abrir buffer de salida
    $output = fopen('php://output', 'w');
    
    // Encabezados (con número de factura del proveedor)
    $headers = [
        "ID Factura",
        "Número Factura Proveedor",  // Columna añadida
        "Código Proveedor", 
        "Proveedor",
        "Fecha Vencimiento",
        "Días Vencidos",
        "Valor",
        "Estado"
    ];
    fputcsv($output, $headers, ';');
    
    // Datos (con número de factura del proveedor)
    foreach ($unique_invoices as $invoice) {
        $estado = ($invoice['ok'] === 'ok') ? 'Marcada OK' : 'Pendiente';
        
        $row = [
            cleanForCSV($invoice['docnum_interno_sap']),
            cleanForCSV($invoice['numero_factura_proveedor'] ?? ''),  // Campo modificado
            cleanForCSV($invoice['codigo_sn']),
            cleanForCSV($invoice['nombre']),
            cleanForCSV(formatDate($invoice['fecha_vencimiento'])),
            cleanForCSV($invoice['dias_de_vencido']),
            cleanForCSV("$" . number_format($invoice['saldo_pendiente'], 2, '.', ',')),
            cleanForCSV($estado)
        ];
        
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar a Excel</title>
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
            <div class="card-header bg-primary text-white">
                <h2 class="mb-0"><i class="fas fa-file-excel me-2"></i>Exportar Reporte de Facturas</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Este reporte incluirá todas las facturas (pendientes y aprobadas) con el número de factura del proveedor, organizadas por proveedor y días vencidos.
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-success mb-3 h-100">
                            <div class="card-body">
                                <h5 class="card-title text-success"><i class="fas fa-check-circle me-2"></i>Características del Reporte</h5>
                                <ul class="list-unstyled">
                                    <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Incluye número de factura del proveedor</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Datos organizados en columnas</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Formato compatible con Excel</li>
                                    <li class="mb-2"><i class="fas fa-check-circle text-success feature-icon"></i>Incluye todas las facturas</li>
                                    <li><i class="fas fa-check-circle text-success feature-icon"></i>Ordenado por proveedor</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-primary h-100">
                            <div class="card-body">
                                <h5 class="card-title text-primary"><i class="fas fa-file-csv me-2"></i>Instrucciones</h5>
                                <div class="mb-3">
                                    <p>Al abrir el archivo en Excel:</p>
                                    <ol>
                                        <li>Seleccione "Datos" → "Desde texto/CSV"</li>
                                        <li>Elija el archivo descargado</li>
                                        <li>Seleccione "Delimitado"</li>
                                        <li>Marque "Punto y coma"</li>
                                        <li>Verifique que la codificación sea "65001: Unicode (UTF-8)"</li>
                                        <li>Haga clic en "Cargar"</li>
                                    </ol>
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
                
                <form method="POST" action="">
                    <div class="d-grid gap-3">
                        <button type="submit" name="export_excel" class="btn btn-success btn-download btn-lg">
                            <i class="fas fa-file-export me-2"></i> Generar Reporte
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