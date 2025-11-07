<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'] ?? 'user'; // ← Arreglado: evitar undefined

// Inicializar filtros con valores por defecto
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Exportar Facturas Pagadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* === FONDO OSCURO FIJO === */
        .modal-backdrop-custom {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
            backdrop-filter: blur(5px);
        }

        /* === TARJETA FIJA CENTRADA === */
        .export-modal-card {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1000;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.4s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translate(-50%, -60%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }

        /* === SCROLLBAR PERSONALIZADA === */
        .export-modal-card::-webkit-scrollbar { width: 8px; }
        .export-modal-card::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .export-modal-card::-webkit-scrollbar-thumb { background: #1e40af; border-radius: 10px; }

        /* === ESTILOS ORIGINALES (sin cambios) === */
        .export-header {
            background: linear-gradient(135deg,#1e40af,#2563eb);
            color:#fff;
            padding:25px 20px;
            text-align:center;
        }
        .export-header h1 { font-size:1.8rem; font-weight:700; margin-bottom:5px; }
        .export-header p { font-size:.95rem; opacity:.9; margin:0; }
        .export-body { padding:25px 20px; }

        .filters-section {
            background:#f3f6fa;
            border-left:4px solid #1e40af;
            border-radius:10px;
            padding:15px;
            margin-bottom:20px;
        }
        .filters-section h5 { color:#1e40af; margin-bottom:10px; font-weight:600; }

        .search-container { position:relative; }
        .search-input {
            width:100%;
            padding:10px 35px 10px 12px;
            border:1.5px solid #ddd;
            border-radius:8px;
            font-size:.95rem;
            transition:.3s;
        }
        .search-input:focus {
            outline:none;
            border-color:#1e40af;
            box-shadow:0 0 0 2px rgba(30,64,175,.1);
        }
        .search-icon {
            position:absolute;
            right:10px;
            top:50%;
            transform:translateY(-50%);
            color:#6c757d;
        }
        .search-results {
            position:absolute;
            top:100%; left:0; right:0;
            background:#fff;
            border:1.5px solid #1e40af;
            border-top:none;
            border-radius:0 0 8px 8px;
            max-height:250px;
            overflow-y:auto;
            display:none;
            z-index:1000;
        }
        .search-results.show { display:block; }
        .search-result-item {
            padding:10px 12px;
            cursor:pointer;
            border-bottom:1px solid #f0f0f0;
            transition:.2s;
        }
        .search-result-item:hover {
            background:#eff6ff;
            padding-left:18px;
            color:#1e40af;
            font-weight:500;
        }

        .selected-supplier-badge {
            display:inline-flex;
            align-items:center;
            background:linear-gradient(135deg,#1e40af,#2563eb);
            color:#fff;
            padding:6px 12px;
            border-radius:15px;
            margin-top:8px;
            font-size:.85rem;
            gap:6px;
        }
        .selected-supplier-badge i.fa-times { cursor:pointer; }

        .export-button-container {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            justify-content:center;
            padding:15px 0;
        }
        .btn-export-main {
            background:linear-gradient(135deg,#1e40af,#2563eb);
            border:none;
            color:#fff;
            font-size:1rem;
            font-weight:600;
            padding:12px 35px;
            border-radius:40px;
            box-shadow:0 5px 20px rgba(30,64,175,.4);
            transition:.3s;
            text-transform:uppercase;
        }
        .btn-export-pdf { background:linear-gradient(135deg,#dc3545,#c82333); box-shadow:0 5px 20px rgba(220,53,69,.4); }
        .btn-export-main:hover { transform:translateY(-2px); box-shadow:0 10px 30px rgba(30,64,175,.5); }

        .info-box {
            background:linear-gradient(135deg,#e0e7ff,#dbeafe);
            border-left:4px solid #1e40af;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        }
        .info-box h4 { color:#1e40af; margin-bottom:5px; font-weight:600; }
        .info-box p { color:#374151; margin:0; line-height:1.5; }

        @media(max-width:768px){
            .export-modal-card { width: 95%; }
            .export-header h1 { font-size:1.4rem; }
            .btn-export-main { font-size:.9rem; padding:10px 25px; }
            .export-button-container { flex-direction:column; align-items:center; }
        }
    </style>
</head>
<body>

    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

                <!-- FONDO OSCURO -->
                <div class="modal-backdrop-custom"></div>

                <!-- TARJETA FIJA CENTRADA -->
                <div class="export-modal-card">
                    <div class="export-header">
                        <i class="fas fa-file-export" style="font-size: 4rem; margin-bottom: 20px;"></i>
                        <h1>Exportar Facturas Pagadas</h1>
                        <p>Descarga un reporte completo en Excel o PDF</p>
                    </div>
                    
                    <div class="export-body">
                        <div class="info-box">
                            <h4><i class="fas fa-info-circle me-2"></i>¿Cómo funciona?</h4>
                            <p>
                                Configura los filtros que desees aplicar y presiona el botón de exportación. 
                                El sistema generará automáticamente un archivo Excel o PDF con todas las facturas pagadas 
                                que coincidan con tus criterios de búsqueda.
                            </p>
                        </div>
                        
                        <?php if (!empty($filter_supplier) || !empty($filter_status) || !empty($filter_date_from) || !empty($filter_date_to)): ?>
                        <div class="filters-section">
                            <h5><i class="fas fa-filter me-2"></i>Filtros Aplicados</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (!empty($filter_supplier)): ?>
                                    <span class="badge bg-primary"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($filter_supplier); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_status)): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($filter_status); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_date_from)): ?>
                                    <span class="badge bg-info"><i class="fas fa-calendar me-1"></i>Desde: <?php echo $filter_date_from; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_date_to)): ?>
                                    <span class="badge bg-info"><i class="fas fa-calendar me-1"></i>Hasta: <?php echo $filter_date_to; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="filters-section">
                            <h5><i class="fas fa-sliders-h me-2"></i>Configurar Filtros</h5>
                            <form method="GET" action="" class="row g-3" id="filterForm">
                                <div class="col-md-6">
                                    <label for="supplier_search" class="form-label">Buscar Proveedor</label>
                                    <div class="search-container">
                                        <input type="text" class="form-control search-input" id="supplier_search" placeholder="Escribe para buscar proveedores..." autocomplete="off">
                                        <i class="fas fa-search search-icon"></i>
                                        <div class="search-results" id="searchResults"></div>
                                    </div>
                                    <input type="hidden" name="filter_supplier" id="filter_supplier" value="<?php echo htmlspecialchars($filter_supplier); ?>">
                                    <?php if (!empty($filter_supplier)): ?>
                                    <div id="selectedSupplierBadge" class="selected-supplier-badge">
                                        <i class="fas fa-building"></i>
                                        <span id="selectedSupplierName"><?php echo htmlspecialchars($filter_supplier); ?></span>
                                        <i class="fas fa-times" onclick="clearSupplier()"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="filter_date_from" class="form-label">Fecha Desde</label>
                                    <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="filter_date_to" class="form-label">Fecha Hasta</label>
                                    <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                </div>
                                
                                <div class="col-12 text-center">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sync-alt me-2"></i>Aplicar Filtros
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="export-button-container">
                            <button type="button" class="btn-export-main" id="exportExcelBtn">
                                <i class="fas fa-file-excel"></i> Descargar Excel
                            </button>
                            <button type="button" class="btn-export-main btn-export-pdf" id="exportPdfBtn">
                                <i class="fas fa-file-pdf"></i> Descargar PDF
                            </button>
                        </div>
                        <p class="text-muted text-center mt-3 mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Los archivos se generarán con los filtros aplicados
                        </p>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        // === TU JAVASCRIPT ORIGINAL (100% funcional) ===
        function formatCurrencyCOP(value) {
            const num = parseFloat(value);
            if (isNaN(num)) return '$ 0,00';
            return new Intl.NumberFormat('es-CO', {
                style: 'currency',
                currency: 'COP',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(num);
        }

        const searchInput = document.getElementById('supplier_search');
        const searchResults = document.getElementById('searchResults');
        const filterSupplierInput = document.getElementById('filter_supplier');
        let allSuppliers = [];
        let searchTimeout;
        
        fetch('search-suppliers.php')
            .then(response => response.json())
            .then(data => {
                allSuppliers = data.suppliers || [];
            })
            .catch(error => console.error('Error cargando proveedores:', error));
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim().toLowerCase();
            if (query.length === 0) {
                searchResults.classList.remove('show');
                return;
            }
            searchTimeout = setTimeout(() => {
                const filtered = allSuppliers.filter(supplier => 
                    supplier.toLowerCase().includes(query)
                );
                displaySearchResults(filtered);
            }, 300);
        });
        
        function displaySearchResults(suppliers) {
            if (suppliers.length === 0) {
                searchResults.innerHTML = '<div class="no-results p-3 text-muted">No se encontraron proveedores</div>';
                searchResults.classList.add('show');
                return;
            }
            const html = suppliers.map(supplier => 
                `<div class="search-result-item" onclick="selectSupplier('${supplier.replace(/'/g, "\\'")}')">${supplier}</div>`
            ).join('');
            searchResults.innerHTML = html;
            searchResults.classList.add('show');
        }
        
        function selectSupplier(supplier) {
            filterSupplierInput.value = supplier;
            searchInput.value = '';
            searchResults.classList.remove('show');
            const badgeHtml = `
                <div id="selectedSupplierBadge" class="selected-supplier-badge">
                    <i class="fas fa-building"></i>
                    <span id="selectedSupplierName">${supplier}</span>
                    <i class="fas fa-times" onclick="clearSupplier()"></i>
                </div>
            `;
            const existing = document.getElementById('selectedSupplierBadge');
            if (existing) existing.remove();
            searchInput.parentElement.insertAdjacentHTML('afterend', badgeHtml);
        }
        
        function clearSupplier() {
            filterSupplierInput.value = '';
            const badge = document.getElementById('selectedSupplierBadge');
            if (badge) badge.remove();
        }
        
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('show');
            }
        });
        
        document.getElementById('exportExcelBtn').addEventListener('click', () => exportData('excel', this));
        document.getElementById('exportPdfBtn').addEventListener('click', () => exportData('pdf', this));
        
        function exportData(format, btn) {
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>Generando ${format.toUpperCase()}...`;
            
            const urlParams = new URLSearchParams(window.location.search);
            const filters = {
                filter_supplier: urlParams.get('filter_supplier') || '',
                filter_status: urlParams.get('filter_status') || '',
                filter_date_from: urlParams.get('filter_date_from') || '',
                filter_date_to: urlParams.get('filter_date_to') || ''
            };
            
            fetch('export-data.php?' + new URLSearchParams(filters))
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        return;
                    }
                    if (data.invoices.length === 0) {
                        alert('No hay datos para exportar con los filtros aplicados.');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        return;
                    }
                    if (format === 'excel') generateExcel(data, filters);
                    else generatePDF(data, filters);
                    
                    btn.innerHTML = `<i class="fas fa-check me-2"></i>¡Descargado!`;
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }, 3000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al generar el archivo.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        // === GENERAR EXCEL ===
        function generateExcel(data, filters) {
            const hojaData = [];
            hojaData.push(["REPORTE DE FACTURAS PAGADAS-EGRESOS"]);
            hojaData.push([]);
            hojaData.push(["Fecha de Exportación:", new Date().toLocaleString("es-CO")]);
            hojaData.push(["Total de Proveedores:", data.total_suppliers]);
            hojaData.push(["Total de Facturas:", data.total_invoices]);
            hojaData.push(["Monto Total Pagado:", formatCurrencyCOP(data.total_paid)]);
            hojaData.push([]);

            if (filters.filter_supplier || filters.filter_status || filters.filter_date_from || filters.filter_date_to) {
                hojaData.push(["FILTROS APLICADOS:"]);
                if (filters.filter_supplier) hojaData.push(["Proveedor:", filters.filter_supplier]);
                if (filters.filter_status) hojaData.push(["Estado:", filters.filter_status]);
                if (filters.filter_date_from) hojaData.push(["Fecha Desde:", filters.filter_date_from]);
                if (filters.filter_date_to) hojaData.push(["Fecha Hasta:", filters.filter_date_to]);
                hojaData.push([]);
            }

            hojaData.push([
                "Proveedor", "N° SAP", "N° Factura", "Fecha Vencimiento",
                "Fecha de Pago", "Valor Total", "Valor Pagado", "Estado"
            ]);

            data.invoices.forEach(invoice => {
                hojaData.push([
                    invoice.proveedor,
                    invoice.n_sap,
                    invoice.n_factura,
                    invoice.fecha_vencimiento,
                    invoice.fecha_pago,
                    formatCurrencyCOP(parseFloat(invoice.valor_total)),
                    formatCurrencyCOP(parseFloat(invoice.valor_pagado)),
                    invoice.estado
                ]);
            });

            const worksheet = XLSX.utils.aoa_to_sheet(hojaData);
            const headerStyle = { fill: { fgColor: { rgb: "FF4472C4" } }, font: { bold: true, color: { rgb: "FFFFFFFF" }, size: 12 }, alignment: { horizontal: "center" } };
            const headerRow = hojaData.length - data.invoices.length - 1;
            for (let col = 0; col < 8; col++) {
                const cell = XLSX.utils.encode_cell({ r: headerRow, c: col });
                if (worksheet[cell]) worksheet[cell].s = headerStyle;
            }

            for (const key in worksheet) {
                if (key[0] === "!") continue;
                if (!worksheet[key].s) worksheet[key].s = {};
                worksheet[key].s.alignment = { horizontal: "center", vertical: "center", wrapText: true };
                worksheet[key].s.font = { size: 10, color: { rgb: "FF1e40af" } };
                worksheet[key].s.border = { top: { style: "thin" }, bottom: { style: "thin" }, left: { style: "thin" }, right: { style: "thin" } };
            }

            worksheet["!cols"] = [{ wch: 30 }, { wch: 15 }, { wch: 15 }, { wch: 18 }, { wch: 18 }, { wch: 18 }, { wch: 18 }, { wch: 12 }];
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, "Facturas Pagadas");
            const fecha = new Date().toISOString().split("T")[0];
            XLSX.writeFile(workbook, `Facturas_Pagadas_${fecha}.xlsx`);
        }

        // === GENERAR PDF ===
        function generatePDF(data, filters) {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('l', 'mm', 'a4');
            doc.addImage('assets/65x45.png', 'PNG', 10, 8, 25, 20);
            doc.setFontSize(18); doc.setTextColor(30, 64, 175); doc.setFont(undefined, 'bold');
            doc.text('REPORTE DE FACTURAS PAGADAS-EGRESOS', 148, 20, { align: 'center' });
            
            doc.setFontSize(10); doc.setTextColor(30, 64, 175); doc.setFont(undefined, 'normal');
            let yPos = 35;
            doc.text(`Fecha de Exportación: ${new Date().toLocaleString('es-CO')}`, 14, yPos); yPos += 6;
            doc.text(`Total de Proveedores: ${data.total_suppliers}`, 14, yPos); yPos += 6;
            doc.text(`Total de Facturas: ${data.total_invoices}`, 14, yPos); yPos += 6;
            doc.text(`Monto Total Pagado: ${formatCurrencyCOP(data.total_paid)}`, 14, yPos); yPos += 10;
            
            if (filters.filter_supplier || filters.filter_status || filters.filter_date_from || filters.filter_date_to) {
                doc.setFontSize(11); doc.setFont(undefined, 'bold'); doc.text('FILTROS APLICADOS:', 14, yPos); yPos += 6;
                doc.setFontSize(9); doc.setFont(undefined, 'normal');
                if (filters.filter_supplier) { doc.text(`Proveedor: ${filters.filter_supplier}`, 14, yPos); yPos += 5; }
                if (filters.filter_status) { doc.text(`Estado: ${filters.filter_status}`, 14, yPos); yPos += 5; }
                if (filters.filter_date_from) { doc.text(`Fecha Desde: ${filters.filter_date_from}`, 14, yPos); yPos += 5; }
                if (filters.filter_date_to) { doc.text(`Fecha Hasta: ${filters.filter_date_to}`, 14, yPos); yPos += 5; }
                yPos += 5;
            }

            const tableData = data.invoices.map(invoice => [
                invoice.proveedor, invoice.n_sap, invoice.n_factura,
                invoice.fecha_vencimiento, invoice.fecha_pago,
                formatCurrencyCOP(parseFloat(invoice.valor_total)),
                formatCurrencyCOP(parseFloat(invoice.valor_pagado)),
                invoice.estado
            ]);

            doc.autoTable({
                startY: yPos,
                head: [['Proveedor', 'N° SAP', 'N° Factura', 'F. Vencimiento', 'F. Pago', 'Valor Total', 'Valor Pagado', 'Estado']],
                body: tableData,
                theme: 'grid',
                headStyles: { fillColor: [68, 114, 196], textColor: 255, fontStyle: 'bold', fontSize: 8, halign: 'center' },
                bodyStyles: { fontSize: 7, halign: 'center', textColor: [30, 64, 175] },
                columnStyles: { 0: { cellWidth: 45 }, 1: { cellWidth: 20 }, 2: { cellWidth: 25 }, 3: { cellWidth: 25 }, 4: { cellWidth: 25 }, 5: { cellWidth: 25 }, 6: { cellWidth: 25 }, 7: { cellWidth: 20 } },
                margin: { left: 14, right: 14 }
            });

            const fecha = new Date().toISOString().split('T')[0];
            doc.save(`Facturas_Pagadas_${fecha}.pdf`);
        }
    </script>
</body>
</html>