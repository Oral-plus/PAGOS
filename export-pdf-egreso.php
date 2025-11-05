<?php
set_time_limit(300);
ini_set('memory_limit', '512M');

session_start();

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'lib/fpdf.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Obtener filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

// Función para obtener TODAS las facturas pagadas (sin paginación)
function getAllPaidInvoicesForExport($supplier = '', $date_from = '', $date_to = '', $status = '') {
    $conn = getDbConnection();
    
    $sql = "SELECT 
                i.[id],
                i.[nombre] as proveedor_nombre,
                i.[docnum_interno_sap],
                i.[numero_factura_proveedor],
                i.[fecha_vencimiento],
                CAST(p.[Factura] AS VARCHAR(50)) as Factura,
                CAST(p.[Valor Total] AS FLOAT) as ValorTotal,
                CAST(p.[Valor Pagado] AS FLOAT) as ValorPagado,
                p.[Estado] as EstadoPago,
                p.[Fecha de Pago] as FechadePago,
                p.[Fecha Factura] as FechaFactura
            FROM [invoice_approval_system].[dbo].[invoices] i
            LEFT JOIN [invoice_approval_system].[dbo].[Invoice_pagas] p 
                ON CAST(i.[numero_factura_proveedor] AS VARCHAR(50)) = CAST(p.[Factura] AS VARCHAR(50))
            WHERE p.[Factura] IS NOT NULL";

    $params = array();
    
    if (!empty($supplier)) {
        $sql .= " AND i.[nombre] = ?";
        $params[] = $supplier;
    }
    
    if (!empty($date_from)) {
        $sql .= " AND CAST(p.[Fecha Factura] AS DATE) >= CAST(? AS DATE)";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql .= " AND CAST(p.[Fecha Factura] AS DATE) <= CAST(? AS DATE)";
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        $invoices = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $invoices[] = $row;
        }
        return $invoices;
    }
}

// Obtener todas las facturas
$all_invoices = getAllPaidInvoicesForExport($filter_supplier, $filter_date_from, $filter_date_to, $filter_status);

// Agrupar por proveedor
$grouped = [];
$total_general = 0;
$total_facturas = 0;

foreach ($all_invoices as $invoice) {
    $supplier_name = $invoice['proveedor_nombre'] ?? 'Sin nombre';
    if (!isset($grouped[$supplier_name])) {
        $grouped[$supplier_name] = [
            'invoices' => [],
            'total_paid' => 0
        ];
    }
    
    $grouped[$supplier_name]['invoices'][] = $invoice;
    $valor_pagado = floatval($invoice['ValorPagado'] ?? 0);
    $grouped[$supplier_name]['total_paid'] += $valor_pagado;
    $total_general += $valor_pagado;
    $total_facturas++;
}

// Calcular impuestos (19% IVA estimado sobre el total)
$subtotal_sin_iva = $total_general / 1.19;
$impuestos_estimados = $total_general - $subtotal_sin_iva;

// Crear el PDF usando FPDF
class PDF extends FPDF {
    function Header() {
        // Logo (si existe) - usar ruta absoluta para evitar problemas de include_path
        $logoPath = __DIR__ . '/assets/65x45.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 10, 6, 30);
        }
        
        // Título
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetTextColor(40, 167, 69);
        $this->Cell(0, 10, 'COMPROBANTE DE EGRESO', 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(3);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . ' - Generado el ' . date('d/m/Y H:i:s'), 0, 0, 'C');
    }
    
    function InfoBox($label, $value) {
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(50, 6, utf8_decode($label), 0, 0);
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(0, 6, utf8_decode($value), 0, 1);
    }
    
    function SectionTitle($title) {
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetFillColor(40, 167, 69);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, utf8_decode($title), 0, 1, 'C', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }
    
    function TableHeader() {
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetFillColor(248, 249, 250);
        $this->Cell(20, 6, utf8_decode('N° SAP'), 1, 0, 'C', true);
        $this->Cell(25, 6, utf8_decode('N° Factura'), 1, 0, 'C', true);
        $this->Cell(22, 6, 'Fecha Fact.', 1, 0, 'C', true);
        $this->Cell(22, 6, 'Fecha Pago', 1, 0, 'C', true);
        $this->Cell(30, 6, 'Valor Total', 1, 0, 'C', true);
        $this->Cell(30, 6, 'Valor Pagado', 1, 0, 'C', true);
        $this->Cell(20, 6, 'Estado', 1, 1, 'C', true);
    }
    
    function TableRow($data) {
        $this->SetFont('Helvetica', '', 7);
        $this->Cell(20, 5, utf8_decode($data['sap']), 1, 0, 'C');
        $this->Cell(25, 5, utf8_decode($data['factura']), 1, 0, 'C');
        $this->Cell(22, 5, $data['fecha_fact'], 1, 0, 'C');
        $this->Cell(22, 5, $data['fecha_pago'], 1, 0, 'C');
        $this->Cell(30, 5, '$' . number_format($data['valor_total'], 0, ',', '.'), 1, 0, 'R');
        $this->SetTextColor(40, 167, 69);
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell(30, 5, '$' . number_format($data['valor_pagado'], 0, ',', '.'), 1, 0, 'R');
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 6);
        $this->Cell(20, 5, utf8_decode($data['estado']), 1, 1, 'C');
    }
    
    function SubtotalRow($label, $value) {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(232, 245, 233);
        $this->Cell(119, 6, utf8_decode($label), 1, 0, 'R', true);
        $this->SetTextColor(40, 167, 69);
        $this->Cell(30, 6, '$' . number_format($value, 0, ',', '.'), 1, 0, 'R', true);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(20, 6, '', 1, 1, 'C', true);
    }
}

try {
    $pdf = new PDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 10);

    // Información del reporte
    $pdf->SetFillColor(248, 249, 250);
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Rect(10, $pdf->GetY(), 190, 20, 'FD');

// Información del reporte
$y_start = $pdf->GetY() + 3;
$pdf->SetY($y_start);
$pdf->SetX(15);
$pdf->InfoBox('Fecha de Generación:', date('d/m/Y H:i:s'));
$pdf->SetX(15);
$pdf->InfoBox('Usuario:', $_SESSION['username'] ?? 'Sistema');

$pdf->SetY($y_start);
$pdf->SetX(110);
$pdf->InfoBox('Período:', ($filter_date_from ? date('d/m/Y', strtotime($filter_date_from)) : 'Todas') . ' - ' . ($filter_date_to ? date('d/m/Y', strtotime($filter_date_to)) : 'Todas'));
$pdf->SetX(110);
$pdf->InfoBox('Proveedor:', $filter_supplier ? $filter_supplier : 'Todos');

$pdf->Ln(8);

// Resumen ejecutivo
$pdf->SectionTitle('RESUMEN EJECUTIVO');

$pdf->SetFillColor(232, 245, 233);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(95, 6, 'Total de Proveedores:', 1, 0, 'L', true);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 6, count($grouped), 1, 1, 'R', true);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(95, 6, utf8_decode('Total de Facturas Canceladas:'), 1, 0, 'L');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 6, $total_facturas, 1, 1, 'R');

$pdf->SetFillColor(232, 245, 233);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(95, 6, 'Subtotal (sin IVA estimado):', 1, 0, 'L', true);
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 6, '$' . number_format($subtotal_sin_iva, 2, ',', '.'), 1, 1, 'R', true);

$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(95, 6, 'IVA Estimado (19%):', 1, 0, 'L');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 6, '$' . number_format($impuestos_estimados, 2, ',', '.'), 1, 1, 'R');

$pdf->SetFillColor(200, 230, 201);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(95, 7, 'TOTAL PAGADO:', 1, 0, 'L', true);
$pdf->Cell(95, 7, '$' . number_format($total_general, 2, ',', '.'), 1, 1, 'R', true);

$pdf->Ln(5);

// Detalle por proveedor
$pdf->SectionTitle('DETALLE DE PAGOS POR PROVEEDOR');

foreach ($grouped as $supplier_name => $supplier_data) {
    // Verificar si necesitamos una nueva página
    if ($pdf->GetY() > 250) {
        $pdf->AddPage();
    }
    
    // Nombre del proveedor
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetFillColor(232, 245, 233);
    $pdf->Cell(0, 7, utf8_decode($supplier_name), 0, 1, 'L', true);
    $pdf->Ln(1);
    
    // Encabezado de tabla
    $pdf->TableHeader();
    
    // Filas de facturas
    foreach ($supplier_data['invoices'] as $invoice) {
        // Verificar espacio para nueva fila
        if ($pdf->GetY() > 270) {
            $pdf->AddPage();
            $pdf->TableHeader();
        }
        
        $fecha_factura = 'N/A';
        if (isset($invoice['FechaFactura'])) {
            if (is_object($invoice['FechaFactura']) && get_class($invoice['FechaFactura']) == 'DateTime') {
                $fecha_factura = $invoice['FechaFactura']->format('d/m/Y');
            } elseif (is_string($invoice['FechaFactura'])) {
                $fecha_factura = date('d/m/Y', strtotime($invoice['FechaFactura']));
            }
        }
        
        $fecha_pago = 'N/A';
        if (isset($invoice['FechadePago'])) {
            if (is_object($invoice['FechadePago']) && get_class($invoice['FechadePago']) == 'DateTime') {
                $fecha_pago = $invoice['FechadePago']->format('d/m/Y');
            } elseif (is_string($invoice['FechadePago'])) {
                $fecha_pago = date('d/m/Y', strtotime($invoice['FechadePago']));
            }
        }
        
        $pdf->TableRow([
            'sap' => $invoice['docnum_interno_sap'] ?? 'N/A',
            'factura' => $invoice['Factura'] ?? 'N/A',
            'fecha_fact' => $fecha_factura,
            'fecha_pago' => $fecha_pago,
            'valor_total' => $invoice['ValorTotal'] ?? 0,
            'valor_pagado' => $invoice['ValorPagado'] ?? 0,
            'estado' => $invoice['EstadoPago'] ?? 'N/A'
        ]);
    }
    
    // Subtotal del proveedor
    $pdf->SubtotalRow('SUBTOTAL ' . $supplier_name . ':', $supplier_data['total_paid']);
    $pdf->Ln(3);
}

// Información adicional
$pdf->Ln(3);
$pdf->SetFont('Helvetica', 'I', 7);
$pdf->SetTextColor(100, 100, 100);
$pdf->MultiCell(0, 4, utf8_decode('NOTA: Este comprobante de egreso refleja las facturas canceladas en el período seleccionado. Los impuestos mostrados son estimaciones basadas en el 19% de IVA. Para información detallada sobre retenciones y otros impuestos aplicados, consulte los documentos individuales de cada factura. El banco utilizado para cada transacción puede consultarse en el sistema de gestión bancaria.'), 0, 'J');

// Firmas
$pdf->Ln(8);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(0, 0, 0);

$y_firma = $pdf->GetY();
$pdf->SetY($y_firma + 15);
$pdf->Line(30, $pdf->GetY(), 90, $pdf->GetY());
$pdf->Line(120, $pdf->GetY(), 180, $pdf->GetY());

$pdf->SetY($y_firma + 17);
$pdf->SetX(30);
$pdf->Cell(60, 5, 'Elaborado por', 0, 0, 'C');
$pdf->SetX(120);
$pdf->Cell(60, 5, 'Aprobado por', 0, 1, 'C');

$pdf->SetX(30);
$pdf->Cell(60, 5, utf8_decode($_SESSION['username'] ?? 'Sistema'), 0, 0, 'C');
$pdf->SetX(120);
$pdf->Cell(60, 5, '_______________________', 0, 1, 'C');

$pdf->SetX(30);
$pdf->Cell(60, 5, date('d/m/Y'), 0, 0, 'C');
$pdf->SetX(120);
$pdf->Cell(60, 5, 'Fecha: ______________', 0, 1, 'C');

    // Generar nombre del archivo
    $filename = 'Comprobante_Egreso_' . date('Y-m-d_His') . '.pdf';

    // Salida del PDF
    $pdf->Output('D', $filename);
    exit();
} catch (Exception $e) {
    // Manejo amigable de errores: imprimir una página HTML explicativa
    http_response_code(500);
    echo '<h2>Error generando PDF</h2>';
    echo '<p>Ocurrió un error al generar el PDF: ' . htmlspecialchars($e->getMessage()) . '</p>';
    // Para depuración opcional, mostrar trace en pre
    if (defined('DEBUG') && DEBUG) {
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
    exit();
}
?>
