<?php
// Minimal test to isolate FPDF library behavior
require_once __DIR__ . '/fpdf/fpdf.php';

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 14);
$pdf->Cell(0, 10, 'FPDF Minimal Test', 0, 1, 'C');
$pdf->Ln(8);
$pdf->SetFont('Helvetica', '', 12);
$pdf->Cell(0, 8, 'Generated at: ' . date('Y-m-d H:i:s'), 0, 1, 'C');

// Clear output buffers to avoid corruption
while (ob_get_level()) { @ob_end_clean(); }

// Capture Output() data and write to a file for inspection
ob_start();
$pdf->Output('D', 'test.pdf');
$data = ob_get_clean();

$file = __DIR__ . '/test_output.pdf';
file_put_contents($file, $data);

echo "Wrote: " . $file . " (" . filesize($file) . " bytes)\n";
