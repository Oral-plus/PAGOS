<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/pdf_helpers.php';
require_once 'includes/pdf_generator.php';

// Simple auth check
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit();
}

// Read params (same as pagos.php form)
$supplier = $_GET['supplier'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$today_only = $_GET['today_only'] ?? '';

$prov = trim($_GET['egreso_proveedor'] ?? '');
$ini = $_GET['fecha_inicio'] ?? '';
$fin = $_GET['fecha_fin'] ?? '';
$numero_documento = $_GET['numero_documento'] ?? 'EGRESO-' . date('Ymd');

$c = new CerradasInvoiceManager();
$e = new EgresoPagoManager();
$cerradas = $c->getCerradasInvoices($supplier, $date_from, $date_to, $today_only);

$egreso = [
    'facturas' => [],
    'numero_documento' => $numero_documento,
    'total_valor' => 0
];
if ($prov && $ini && $fin) {
    $egreso['facturas'] = $e->getFacturasEgreso($prov, $ini, $fin);
    foreach ($egreso['facturas'] as $f) {
        $egreso['total_valor'] += (float)($f['ValorPagado'] ?? 0);
    }
}

// Build PDF content
$pdfContent = build_pdf_content($cerradas, $egreso);
if ($pdfContent === null) {
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Failed to generate PDF';
    exit();
}

$filename = 'facturas_cerradas_' . date('Y-m-d') . '.pdf';
// Save a copy on the server for auditing / later download
$saveDir = __DIR__ . '/cache/generated_pdfs';
if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0755, true);
}
$safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $numero_documento);
$saveName = $safeBase . '_' . date('Ymd_His') . '.pdf';
$savePath = $saveDir . DIRECTORY_SEPARATOR . $saveName;
$saved = @file_put_contents($savePath, $pdfContent);
if ($saved === false) {
    error_log("[download_pdf] Failed to save PDF to {$savePath}");
} else {
    // provide saved filename in a header (basename only) so the UI can show it if needed
    header('X-Saved-PDF: ' . basename($savePath));
}

// Clear buffers and send headers to force download
while (ob_get_level()) { @ob_end_clean(); }
@ini_set('zlib.output_compression', '0');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');
header('Accept-Ranges: bytes');
header('Content-Length: ' . strlen($pdfContent));
header('Content-Encoding: identity');

echo $pdfContent;
flush();
exit();

