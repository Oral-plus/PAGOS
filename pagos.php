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
$role = $user['role'];

// Inicializar filtros
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
        .export-container {
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
        }
        
        .export-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .export-header {
            /* Cambiar gradiente de verde a azul profesional */
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .export-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            /* Agregar sombra de texto para mayor definición */
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .export-header p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin: 0;
        }
        
        .export-body {
            padding: 40px 30px;
        }
        
        .filters-section {
            /* Cambiar fondo a tono azul claro para coherencia */
            background: #f0f4f8;
            border-left: 4px solid #1e40af;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .filters-section h5 {
            /* Cambiar color del texto a azul */
            color: #1e40af;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .search-container {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            /* Cambiar color de foco a azul */
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            /* Cambiar borde a azul */
            border: 2px solid #1e40af;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .search-results.show {
            display: block;
        }
        
        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f0f0f0;
            /* Cambiar color del texto a azul para coherencia */
            color: #1f2937;
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }
        
        .search-result-item:hover {
            /* Cambiar fondo hover a azul claro */
            background: #eff6ff;
            padding-left: 20px;
            color: #1e40af;
            font-weight: 500;
        }
        
        .search-result-item.selected {
            /* Cambiar fondo seleccionado a azul */
            background: #dbeafe;
            color: #1e40af;
            font-weight: 600;
        }
        
        .no-results {
            padding: 20px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }
        
        .selected-supplier-badge {
            /* Cambiar badge a azul profesional */
            display: inline-block;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            margin-top: 10px;
            font-size: 0.9rem;
            box-shadow: 0 4px 6px rgba(30, 64, 175, 0.2);
        }
        
        .selected-supplier-badge i {
            margin-left: 8px;
            cursor: pointer;
        }
        
        .export-button-container {
            text-align: center;
            padding: 20px 0;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        /* Botones de exportación con estilo azul profesional */
        .btn-export-main {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            border: none;
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            padding: 18px 50px;
            border-radius: 50px;
            box-shadow: 0 10px 30px rgba(30, 64, 175, 0.4);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
        }
        
        .btn-export-pdf {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            box-shadow: 0 10px 30px rgba(220, 53, 69, 0.4);
        }
        
        .btn-export-pdf:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(220, 53, 69, 0.6);
            background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
        }
        
        .btn-export-main:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(30, 64, 175, 0.6);
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
        }
        
        .btn-export-main:active {
            transform: translateY(-1px);
        }
        
        .btn-export-main:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-export-main i {
            margin-right: 10px;
            font-size: 1.3rem;
        }
        
        .filter-badge {
            /* Cambiar badges de filtros a azul */
            display: inline-block;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 5px;
            box-shadow: 0 2px 4px rgba(30, 64, 175, 0.2);
        }
        
        .back-link {
            display: inline-block;
            color: #1e40af;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .back-link:hover {
            color: #2563eb;
            transform: translateX(-5px);
        }
        
        .loading-spinner {
            display: none;
            margin-left: 10px;
        }
        
        .info-box {
            /* Cambiar info box a tonos azul profesionales */
            background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
            border-left: 5px solid #1e40af;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.1);
        }
        
        .info-box h4 {
            color: #1e40af;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .info-box p {
            color: #374151;
            margin: 0;
            line-height: 1.6;
        }
        
        @media (max-width: 768px) {
            .export-header h1 {
                font-size: 1.8rem;
            }
            
            .btn-export-main {
                font-size: 1rem;
                padding: 15px 35px;
            }
            
            .export-button-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="export-container">
                    <a href="dashboard.php" class="back-link">
                        <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                    </a>
                    
                    <div class="export-card">
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
                                <div>
                                    <?php if (!empty($filter_supplier)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-building me-1"></i>
                                            <?php echo htmlspecialchars($filter_supplier); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_status)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?php echo htmlspecialchars($filter_status); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_from)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-calendar me-1"></i>
                                            Desde: <?php echo $filter_date_from; ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_to)): ?>
                                        <span class="filter-badge">
                                            <i class="fas fa-calendar me-1"></i>
                                            Hasta: <?php echo $filter_date_to; ?>
                                        </span>
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
                                            <input 
                                                type="text" 
                                                class="form-control search-input" 
                                                id="supplier_search" 
                                                placeholder="Escribe para buscar proveedores..."
                                                autocomplete="off"
                                            >
                                            <i class="fas fa-search search-icon"></i>
                                            <div class="search-results" id="searchResults"></div>
                                        </div>
                                        <input type="hidden" name="filter_supplier" id="filter_supplier" value="<?php echo htmlspecialchars($filter_supplier); ?>">
                                        <?php if (!empty($filter_supplier)): ?>
                                        <div id="selectedSupplierBadge" class="selected-supplier-badge">
                                            <i class="fas fa-building me-1"></i>
                                            <span id="selectedSupplierName"><?php echo htmlspecialchars($filter_supplier); ?></span>
                                            <i class="fas fa-times" onclick="clearSupplier()"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="filter_date_from" class="form-label">Fecha Desde</label>
                                        <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" 
                                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="filter_date_to" class="form-label">Fecha Hasta</label>
                                        <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" 
                                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
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
                                    <i class="fas fa-file-excel"></i>
                                    Descargar Excel
                                </button>
                                <button type="button" class="btn-export-main btn-export-pdf" id="exportPdfBtn">
                                    <i class="fas fa-file-pdf"></i>
                                    Descargar PDF
                                </button>
                            </div>
                            <p class="text-muted text-center mt-3 mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                Los archivos se generarán con los filtros aplicados
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    
    <script>
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
                searchResults.innerHTML = '<div class="no-results"><i class="fas fa-search me-2"></i>No se encontraron proveedores</div>';
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
                    <i class="fas fa-building me-1"></i>
                    <span id="selectedSupplierName">${supplier}</span>
                    <i class="fas fa-times" onclick="clearSupplier()"></i>
                </div>
            `;
            
            const existingBadge = document.getElementById('selectedSupplierBadge');
            if (existingBadge) {
                existingBadge.remove();
            }
            
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
        
        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            exportData('excel', this);
        });
        
        document.getElementById('exportPdfBtn').addEventListener('click', function() {
            exportData('pdf', this);
        });
        
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
                    
                    if (format === 'excel') {
                        generateExcel(data, filters);
                    } else {
                        generatePDF(data, filters);
                    }
                    
                    btn.innerHTML = `<i class="fas fa-check me-2"></i>¡Descargado Exitosamente!`;
                    
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }, 3000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al generar el archivo. Por favor, intenta nuevamente.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }
        
        /* Tabla en azul centrada en Excel con mayor definición */
        function generateExcel(data, filters) {
            const hojaData = [];

            hojaData.push(["REPORTE DE FACTURAS PAGADAS-EGRESOS"]);
            hojaData.push([]);

            hojaData.push(["Fecha de Exportación:", new Date().toLocaleString("es-ES")]);
            hojaData.push(["Total de Proveedores:", data.total_suppliers]);
            hojaData.push(["Total de Facturas:", data.total_invoices]);
            hojaData.push(["Monto Total Pagado:", "$" + data.total_paid.toLocaleString("es-ES")]);
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
                "Proveedor",
                "N° SAP",
                "N° Factura",
                "Fecha Vencimiento",
                "Fecha de Pago",
                "Valor Total",
                "Valor Pagado",
                "Estado",
            ]);

            data.invoices.forEach((invoice) => {
                hojaData.push([
                    invoice.proveedor,
                    invoice.n_sap,
                    invoice.n_factura,
                    invoice.fecha_vencimiento,
                    invoice.fecha_pago,
                    invoice.valor_total,
                    invoice.valor_pagado,
                    invoice.estado,
                ]);
            });

            const worksheet = XLSX.utils.aoa_to_sheet(hojaData);

            const headerTableStyle = {
                fill: { fgColor: { rgb: "FF4472C4" } },
                font: { bold: true, color: { rgb: "FFFFFFFF" }, size: 12 },
                alignment: { horizontal: "center", vertical: "center", wrapText: true },
            };

            const headerRowIndex = hojaData.length - data.invoices.length - 1;
            for (let col = 0; col < 8; col++) {
                const cellRef = XLSX.utils.encode_cell({ r: headerRowIndex, c: col });
                worksheet[cellRef].s = headerTableStyle;
            }

            /* Aplicar estilos de centrado y definición a todas las celdas */
            for (const key in worksheet) {
                if (key[0] === "!") continue;
                if (!worksheet[key].s) worksheet[key].s = {};
                worksheet[key].s.alignment = { horizontal: "center", vertical: "center", wrapText: true };
                worksheet[key].s.font = { size: 10, color: { rgb: "FF1e40af" } };
                worksheet[key].s.border = {
                    top: { style: "thin", color: { rgb: "FFD3D3D3" } },
                    bottom: { style: "thin", color: { rgb: "FFD3D3D3" } },
                    left: { style: "thin", color: { rgb: "FFD3D3D3" } },
                    right: { style: "thin", color: { rgb: "FFD3D3D3" } }
                };
            }

            const columnWidths = [
                { wch: 30 },
                { wch: 15 },
                { wch: 15 },
                { wch: 18 },
                { wch: 18 },
                { wch: 15 },
                { wch: 15 },
                { wch: 12 },
            ];
            worksheet["!cols"] = columnWidths;
            worksheet["!margins"] = { left: 0.5, right: 0.5, top: 0.5, bottom: 0.5 };

            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, "Facturas Pagadas");

            const fecha = new Date().toISOString().split("T")[0];
            const nombreArchivo = `Facturas_Pagadas_${fecha}.xlsx`;

            XLSX.writeFile(workbook, nombreArchivo);
        }
        
        /* PDF con tabla azul centrada, logo y mayor calidad profesional */
        function generatePDF(data, filters) {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('l', 'mm', 'a4');
            
            // Agregar logo en esquina superior izquierda
            const logoUrl = 'assets/65x45.png';
            const logoWidth = 25;
            const logoHeight = 20;
            doc.addImage(logoUrl, 'PNG', 10, 8, logoWidth, logoHeight);
            
            doc.setFontSize(18);
            doc.setTextColor(30, 64, 175);
            doc.setFont(undefined, 'bold');
            doc.text('REPORTE DE FACTURAS PAGADAS-EGRESOS', 148, 20, { align: 'center' });
            
            doc.setFontSize(10);
            doc.setTextColor(30, 64, 175);
            doc.setFont(undefined, 'normal');
            let yPos = 35;
            doc.text(`Fecha de Exportación: ${new Date().toLocaleString('es-ES')}`, 14, yPos);
            yPos += 6;
            doc.text(`Total de Proveedores: ${data.total_suppliers}`, 14, yPos);
            yPos += 6;
            doc.text(`Total de Facturas: ${data.total_invoices}`, 14, yPos);
            yPos += 6;
            doc.text(`Monto Total Pagado: $${data.total_paid.toLocaleString('es-ES')}`, 14, yPos);
            yPos += 10;
            
            if (filters.filter_supplier || filters.filter_status || filters.filter_date_from || filters.filter_date_to) {
                doc.setFontSize(11);
                doc.setTextColor(30, 64, 175);
                doc.setFont(undefined, 'bold');
                doc.text('FILTROS APLICADOS:', 14, yPos);
                doc.setFontSize(9);
                doc.setTextColor(30, 64, 175);
                doc.setFont(undefined, 'normal');
                yPos += 6;
                if (filters.filter_supplier) {
                    doc.text(`Proveedor: ${filters.filter_supplier}`, 14, yPos);
                    yPos += 5;
                }
                if (filters.filter_status) {
                    doc.text(`Estado: ${filters.filter_status}`, 14, yPos);
                    yPos += 5;
                }
                if (filters.filter_date_from) {
                    doc.text(`Fecha Desde: ${filters.filter_date_from}`, 14, yPos);
                    yPos += 5;
                }
                if (filters.filter_date_to) {
                    doc.text(`Fecha Hasta: ${filters.filter_date_to}`, 14, yPos);
                    yPos += 5;
                }
                yPos += 5;
            }
            
            const tableData = data.invoices.map(invoice => [
                invoice.proveedor,
                invoice.n_sap,
                invoice.n_factura,
                invoice.fecha_vencimiento,
                invoice.fecha_pago,
                invoice.valor_total,
                invoice.valor_pagado,
                invoice.estado
            ]);
            
            doc.autoTable({
                startY: yPos,
                head: [['Proveedor', 'N° SAP', 'N° Factura', 'F. Vencimiento', 'F. Pago', 'Valor Total', 'Valor Pagado', 'Estado']],
                body: tableData,
                theme: 'grid',
                headStyles: {
                    fillColor: [68, 114, 196],
                    textColor: 255,
                    fontStyle: 'bold',
                    fontSize: 8,
                    halign: 'center',
                    valign: 'middle'
                },
                bodyStyles: {
                    fontSize: 7,
                    halign: 'center',
                    valign: 'middle',
                    textColor: [30, 64, 175]
                },
                columnStyles: {
                    0: { cellWidth: 45 },
                    1: { cellWidth: 20 },
                    2: { cellWidth: 25 },
                    3: { cellWidth: 25 },
                    4: { cellWidth: 25 },
                    5: { cellWidth: 25 },
                    6: { cellWidth: 25 },
                    7: { cellWidth: 20 }
                },
                margin: { left: 14, right: 14 },
                didDrawPage: function(data) {
                    // Encabezado y pie de página
                    const pageSize = doc.internal.pageSize;
                    const pageHeight = pageSize.getHeight();
                }
            });
            
            const fecha = new Date().toISOString().split('T')[0];
            const nombreArchivo = `Facturas_Pagadas_${fecha}.pdf`;
            
            doc.save(nombreArchivo);
        }
    </script> 
</body>
</html>
