<?php
require_once __DIR__ . '/../fpdf/fpdf.php';

function build_pdf_content(array $cerradas, array $egreso) {
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'FACTURAS CERRADAS (STATUS = C)', 0, 1, 'C');
    $pdf->Ln(5);

    // Encabezado tabla cerradas
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(40, 167, 69);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(55, 8, 'Proveedor', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'N SAP', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'NIT', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'F. Venc.', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Valor', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Prioridad', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Estado', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Dias Final', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Final', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Tipo Doc', 1, 1, 'C', true);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 8);

    $safeDateFormat = function($date, $format='d/m/Y') {
        if (empty($date)) return '';
        $t = strtotime($date);
        return $t ? date($format, $t) : '';
    };

    foreach ($cerradas as $f) {
        $priority = strtolower($f['priority'] ?? '');
        $fill = $priority === 'alta' ? [248, 215, 218] : ($priority === 'media' ? [255, 243, 205] : ($priority === 'baja' ? [212, 237, 218] : [255, 255, 255]));
        $pdf->SetFillColor(...$fill);
        $final = ($f['final'] ?? '') === 'si' ? 'FINAL' : 'MARCADA';

        $pdf->Cell(55, 6, substr($f['nombre'] ?? '', 0, 28), 1, 0, 'L', true);
        $pdf->Cell(30, 6, $f['docnum_interno_sap'] ?? '', 1, 0, 'C', true);
        $pdf->Cell(25, 6, $f['codigo_sn'] ?? '', 1, 0, 'C', true);
        $pdf->Cell(25, 6, $safeDateFormat($f['fecha_vencimiento']), 1, 0, 'C', true);
        $pdf->Cell(25, 6, '$' . number_format(abs((float)($f['saldo_pendiente'] ?? 0)), 2), 1, 0, 'R', true);
        $pdf->Cell(25, 6, $f['priority'] ?? '', 1, 0, 'C', true);
        $pdf->Cell(20, 6, $f['status'] ?? '', 1, 0, 'C', true);
        $pdf->Cell(20, 6, $f['dias_finalizada'] ?? 0, 1, 0, 'C', true);
        $pdf->Cell(20, 6, $final, 1, 0, 'C', true);
        $pdf->Cell(25, 6, $f['TipoDocumento'] ?? '', 1, 1, 'C', true);
    }

    // PÁGINA EGRESO
    if (!empty($egreso['facturas'])) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'EGRESO DE PAGO - ' . ($egreso['numero_documento'] ?? ''), 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFillColor(0, 123, 255);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(15, 8, 'No.', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Tipo', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Doc.No.', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Fecha', 1, 0, 'C', true);
        $pdf->Cell(80, 8, 'Referencia', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Valor ($)', 1, 1, 'C', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8);
        $n = 1; $seen = [];
        foreach ($egreso['facturas'] as $f) {
            if (in_array($f['Factura'] ?? null, $seen)) continue;
            $seen[] = $f['Factura'] ?? null;
            $pdf->Cell(15, 6, $n++, 1, 0, 'C');
            $pdf->Cell(30, 6, 'Factura', 1, 0, 'L');
            $pdf->Cell(50, 6, $f['docnum_interno_sap'] ?? $f['Factura'] ?? 'N/A', 1, 0, 'C');
            $pdf->Cell(30, 6, $safeDateFormat($f['FechaFactura'] ?? ''), 1, 0, 'C');
            $pdf->Cell(80, 6, substr($f['numero_factura_proveedor'] ?? $f['Factura'] ?? 'N/A', 0, 40), 1, 0, 'L');
            $pdf->Cell(40, 6, number_format($f['ValorPagado'] ?? 0, 3, '.', ','), 1, 1, 'R');
        }
        $pdf->SetFillColor(232, 232, 232);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(205, 8, 'TOTAL:', 1, 0, 'R', true);
        $pdf->Cell(40, 8, number_format($egreso['total_valor'] ?? 0, 3, '.', ','), 1, 1, 'R', true);
    }

    // Return PDF as string
    return $pdf->Output('S', 'facturas.pdf');
}
