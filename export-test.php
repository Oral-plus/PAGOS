<?php
// Página sencilla para probar las exportaciones (Excel/CSV y PDF)
// Coloca esta página en el root del proyecto y accede desde el navegador.

$base = dirname(__FILE__);
$actionExcel = 'export-egreso.php';
$actionPdf = 'export-pdf-egreso.php';

// Valores por defecto de prueba
$sample_supplier = '';
$sample_date_from = '';
$sample_date_to = '';
$sample_status = '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pruebas de Export</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1 class="mb-3">Pruebas de Export (Excel / PDF)</h1>
    <p>Usa el formulario para probar la exportación con filtros. Si la librería PhpSpreadsheet está instalada, la exportación Excel devolverá .xlsx; en caso contrario, CSV.</p>

    <form id="exportForm" class="row g-3 mb-3" method="GET" target="_blank">
        <div class="col-md-3">
            <label class="form-label">Proveedor</label>
            <input class="form-control" name="filter_supplier" value="<?= htmlentities($sample_supplier) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Desde</label>
            <input type="date" class="form-control" name="filter_date_from" value="<?= htmlentities($sample_date_from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Hasta</label>
            <input type="date" class="form-control" name="filter_date_to" value="<?= htmlentities($sample_date_to) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Estado</label>
            <input class="form-control" name="filter_status" value="<?= htmlentities($sample_status) ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end gap-2">
            <button type="submit" formaction="<?= $actionExcel ?>?export=excel" class="btn btn-success">Exportar Excel / CSV</button>
            <button type="submit" formaction="<?= $actionPdf ?>" class="btn btn-danger">Exportar PDF</button>
            <a class="btn btn-secondary" href="pagos.php">Ir a Pagos</a>
        </div>
    </form>

    <h5>Opciones rápidas</h5>
    <div class="mb-3">
        <a class="btn btn-outline-success me-2" href="<?= $actionExcel ?>?export=excel" target="_blank">Exportar sin filtros (Excel/CSV)</a>
        <a class="btn btn-outline-primary" href="<?= $actionPdf ?>" target="_blank">Exportar sin filtros (PDF)</a>
    </div>

    <hr>
    <p>Si alguna exportación falla, copia el mensaje de error completo y pégalo aquí para que lo repare.</p>
</div>
</body>
</html>
