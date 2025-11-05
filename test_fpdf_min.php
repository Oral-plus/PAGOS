<?php
while (ob_get_level()) { ob_end_clean(); }
@ini_set('zlib.output_compression', '0');
require_once 'fpdf/fpdf.php';
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 16);
$pdf->Cell(0, 20, 'PRUEBA FPDF LIMPIA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 10, 'Si ves esto, FPDF funciona bien.', 0, 1, 'C');
$pdfContent = $pdf->Output('S', 'prueba.pdf');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="prueba.pdf"');
header('Content-Length: ' . strlen($pdfContent));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');
header('Accept-Ranges: bytes');
header('Content-Encoding: identity');
header('Connection: close');
echo $pdfContent;
flush();
exit();
