<?php require_once 'config/database.php';

$conn = getDbConnection();

// Filtros
$filtroProveedor = $_GET['proveedor'] ?? '';
$filtroFecha = $_GET['fecha'] ?? '';
$filtroID = $_GET['id'] ?? '';

// Subida PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf'])) {
    $idFactura = $_POST['id'];
    $nombreArchivo = basename($_FILES['pdf']['name']);
    $ruta = 'pdfs/' . $nombreArchivo;
    
    if (move_uploaded_file($_FILES['pdf']['tmp_name'], $ruta)) {
        $sql = "UPDATE escaneados SET pdf_path = ? WHERE id = ?";
        $stmt = sqlsrv_prepare($conn, $sql, [$ruta, $idFactura]);
        if (sqlsrv_execute($stmt)) {
            $mensaje = "<div class='alert alert-success'>PDF subido correctamente.</div>";
        } else {
            $mensaje = "<div class='alert alert-danger'>Error al subir el PDF.</div>";
        }
    } else {
        $mensaje = "<div class='alert alert-danger'>Error al mover el archivo.</div>";
    }
}

// Consulta con filtros
$consulta = "SELECT * FROM escaneados WHERE 1=1";
$params = [];

if (!empty($filtroID)) {
    $consulta .= " AND id = ?";
    $params[] = $filtroID;
}

if (!empty($filtroProveedor)) {
    $consulta .= " AND proveedor LIKE ?";
    $params[] = "%" . $filtroProveedor . "%";
}

if (!empty($filtroFecha)) {
    $consulta .= " AND CAST(fecha AS DATE) = ?";
    $params[] = $filtroFecha;
}

$consulta .= " ORDER BY fecha DESC";
$stmt = sqlsrv_prepare($conn, $consulta, $params);
sqlsrv_execute($stmt);

// Contar resultados
$totalFacturas = 0;
$resultados = [];
while ($factura = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $resultados[] = $factura;
    $totalFacturas++;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Facturas</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #003b71;
            --secondary-color: #e6eef4;
            --accent-color: #f7a800;
            --text-dark: #2c3e50;
            --border-color: #d9e2ec;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: var(--text-dark);
        }
        
        .sidebar {
            background-color: white;
            border-right: 1px solid var(--border-color);
            height: 100vh;
            position: fixed;
            width: 220px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
        }
        
        .content {
            margin-left: 220px;
            padding: 20px;
        }
        
        .logo-container {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            margin-bottom: 15px;
        }
        
        .logo {
            max-width: 100px;
            height: auto;
        }
        
        .system-name {
            font-size: 14px;
            color: var(--text-dark);
            margin-top: 5px;
            font-weight: 500;
        }
        
        .user-info {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 15px;
        }
        
        .user-avatar {
            background-color: var(--secondary-color);
            color: var(--primary-color);
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }
        
        .user-name {
            display: inline-block;
            font-weight: 500;
            vertical-align: middle;
        }
        
        .user-role {
            display: block;
            font-size: 12px;
            color: #6c757d;
        }
        
        .menu-header {
            text-transform: uppercase;
            font-size: 12px;
            color: #6c757d;
            padding: 10px 15px;
            margin-bottom: 5px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            color: var(--text-dark);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .menu-item:hover {
            background-color: var(--secondary-color);
            color: var(--primary-color);
        }
        
        .menu-item.active {
            background-color: var(--secondary-color);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }
        
        .menu-icon {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .panel-title {
            font-size: 24px;
            font-weight: 500;
            margin-bottom: 20px;
            color: var(--primary-color);
        }
        
        .filter-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filter-header {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 20px;
            font-weight: 500;
        }
        
        .filter-body {
            padding: 20px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #002b54;
            border-color: #002b54;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }
        
        .results-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        
        .results-header {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 20px;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .results-count {
            background-color: white;
            color: var(--primary-color);
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .results-body {
            padding: 0;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: #f1f5f9;
            color: var(--text-dark);
            border-bottom: 2px solid var(--border-color);
            padding: 12px 15px;
            font-weight: 500;
        }
        
        .table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-processing {
            background-color: #e6f7ff;
            color: #0072b1;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .action-btn {
            padding: 4px 8px;
            border-radius: 4px;
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 5px;
        }
        
        .btn-view {
            background-color: #3498db;
        }
        
        .btn-view:hover {
            background-color: #2980b9;
        }
        
        .btn-complete {
            background-color: #2ecc71;
        }
        
        .btn-complete:hover {
            background-color: #27ae60;
        }
        
        .form-control {
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 8px 12px;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(0, 59, 113, 0.25);
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        
        .tab-list {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab-item {
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
        }
        
        .tab-item.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-count {
            background-color: #e9ecef;
            color: #495057;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 12px;
            margin-left: 5px;
        }
        
        .file-upload {
            position: relative;
            display: inline-block;
        }
        
        .file-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-upload-label {
            display: inline-block;
            padding: 8px 12px;
            background-color: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .file-upload:hover .file-upload-label {
            background-color: #e9ecef;
        }
        
        .file-name {
            margin-top: 5px;
            font-size: 12px;
            color: #6c757d;
        }

        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }

        footer {
            margin-top: 30px;
            padding: 10px 0;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 60px;
            }
            
            .content {
                margin-left: 60px;
            }
            
            .system-name, .user-role, .menu-text {
                display: none;
            }
            
            .logo-container {
                padding: 10px 5px;
            }
            
            .logo {
                max-width: 40px;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px 0;
            }
            
            .menu-icon {
                margin-right: 0;
            }
            
            .menu-header {
                text-align: center;
                padding: 10px 5px;
            }
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
    
        
        <!-- Content -->
        <div class="content">
            <div class="panel-title">Panel de Control</div>
            
            <?php if (isset($mensaje)): ?>
            <div class="message-container">
                <?php echo $mensaje; ?>
            </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="filter-card">
                <div class="filter-header">
                    Filtros
                </div>
                <div class="filter-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label for="id" class="form-label">Número de Factura</label>
                            <input type="text" class="form-control" id="id" name="id" value="<?= htmlspecialchars($filtroID) ?>" placeholder="ID exacto">
                        </div>
                        <div class="col-md-3">
                            <label for="fecha" class="form-label">Fecha</label>
                            <input type="date" class="form-control" id="fecha" name="fecha" value="<?= htmlspecialchars($filtroFecha) ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="proveedor" class="form-label">Proveedor</label>
                            <input type="text" class="form-control" id="proveedor" name="proveedor" value="<?= htmlspecialchars($filtroProveedor) ?>" placeholder="Nombre proveedor">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                            </div>
                        </div>
                    </form>
                    <div class="text-end mt-3">
                        <a href="?" class="btn btn-secondary">Limpiar</a>
                    </div>
                </div>
            </div>
            
            <!-- Tab Navigation -->
            <ul class="tab-list">
                <li class="tab-item active">Facturas Pendientes <span class="tab-count"><?= $totalFacturas ?></span></li>
            </ul>
            
            <!-- Results Table -->
            <div class="results-card">
                <div class="results-header">
                    <span>Facturas Pendientes</span>
                    <span class="results-count"><?= $totalFacturas ?> resultado(s) encontrado(s)</span>
                </div>
                <div class="results-body">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                
                                <th>ID</th>
                                <th>Proveedor</th>
                                <th>Fecha</th>
                                <th>Valor</th>
                                <th>Estado</th>
                                <th>PDF</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $factura): ?>
                            <tr>
                               
                                <td><?= $factura['id'] ?></td>
                                <td><?= htmlspecialchars($factura['proveedor']) ?></td>
                                <td><?= $factura['fecha']->format('d/m/Y') ?></td>
                                <td>$<?= number_format($factura['valor'], 0, ',', '.') ?></td>
                                <td>
                                    <span class="status-badge status-processing">En proceso</span>
                                </td>
                                <td>
                                    <?php if (!empty($factura['pdf_path'])): ?>
                                        <a href="<?= htmlspecialchars($factura['pdf_path']) ?>" target="_blank" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> Ver PDF
                                        </a>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="fas fa-times-circle"></i> No subido</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="action-btn btn-view" data-bs-toggle="modal" data-bs-target="#pdfModal<?= $factura['id'] ?>">
                                        <i class="fas fa-upload"></i>
                                    </button>
                                    <button class="action-btn btn-complete">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Modal for PDF Upload -->
                            <div class="modal fade" id="pdfModal<?= $factura['id'] ?>" tabindex="-1" aria-labelledby="pdfModalLabel<?= $factura['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="pdfModalLabel<?= $factura['id'] ?>">Subir PDF - Factura #<?= $factura['id'] ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="post" enctype="multipart/form-data" id="pdfForm<?= $factura['id'] ?>">
                                                <input type="hidden" name="id" value="<?= $factura['id'] ?>">
                                                <div class="mb-3">
                                                    <label for="pdf<?= $factura['id'] ?>" class="form-label">Seleccione el archivo PDF</label>
                                                    <div class="file-upload w-100">
                                                        <input type="file" name="pdf" id="pdf<?= $factura['id'] ?>" accept="application/pdf" required class="form-control">
                                                        <label for="pdf<?= $factura['id'] ?>" class="file-upload-label w-100">
                                                            <i class="fas fa-cloud-upload-alt me-2"></i> Seleccionar archivo
                                                        </label>
                                                    </div>
                                                    <div class="file-name" id="fileName<?= $factura['id'] ?>">No se ha seleccionado ningún archivo</div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" form="pdfForm<?= $factura['id'] ?>" class="btn btn-primary">Subir PDF</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($resultados) === 0): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">No se encontraron resultados con los filtros aplicados.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
               <a href="index.php">
  <button style="background: #4CAF50; border: none; padding: 15px 30px; border-radius: 8px; color: white; font-size: 16px; font-weight: 500; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: all 0.3s ease;" 
  onmouseover="this.style.background='#45a049'; this.style.boxShadow='0 6px 10px rgba(0,0,0,0.15)'" 
  onmouseout="this.style.background='#4CAF50'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.1)'">
    Volver
  </button>
</a>
            </div>
            
            <footer>
                <p>© 2025 Sistema de Facturas</p>
            </footer>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File upload display filename
        document.querySelectorAll('input[type="file"]').forEach(function(fileInput) {
            fileInput.addEventListener('change', function() {
                const fileId = this.id;
                const fileName = this.files[0]?.name || 'No se ha seleccionado ningún archivo';
                document.getElementById('fileName' + fileId.replace('pdf', '')).textContent = fileName;
            });
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    alert.style.transition = 'opacity 1s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 1000);
                });
            }, 5000);
        });
    </script>
</body>
</html>