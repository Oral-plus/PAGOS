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

// Verificar si se proporcionó ID de factura
if (!isset($_GET['docnum_interno_sap']) || empty($_GET['docnum_interno_sap'])) {
    header("Location: index.php");
    exit();
}

$invoice_id = $_GET['docnum_interno_sap'];
$invoice = getInvoiceById($invoice_id);

// Verificar si la factura existe
if (!$invoice) {
    header("Location: index.php");
    exit();
}

// Marcar factura como vista por el usuario actual
markInvoiceAsViewed($invoice_id, $user_id);

// Obtener historial de aprobaciones
$approvals = getInvoiceApprovals($invoice_id);

// MODIFICADO: Solo verificar aprobación de subgerente
$has_subgerente_approval = false;
$has_rejection = false;

// Verificar aprobaciones y rechazos
foreach ($approvals as $approval) {
    if ($approval['action'] == 'approve' && $approval['user_role'] == 'subgerente') {
        $has_subgerente_approval = true;
    } elseif ($approval['action'] == 'reject') {
        $has_rejection = true;
    }
}

// Si tiene aprobación de subgerente → marcar como completado
if ($has_subgerente_approval && $invoice['status'] != 'completado' && $invoice['status'] != 'rechazado' && $invoice['status'] != 'corregida') {
    try {
        $conn = getDbConnection();
        
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare("UPDATE invoices SET status = 'completado' WHERE docnum_interno_sap = ?");
            $stmt->execute([$invoice_id]);
        } else {
            $sql = "UPDATE invoices SET status = 'completado' WHERE docnum_interno_sap = ?";
            $params = array($invoice_id);
            $stmt = sqlsrv_prepare($conn, $sql, $params);
            if ($stmt) {
                sqlsrv_execute($stmt);
                sqlsrv_free_stmt($stmt);
            }
        }
        
        $invoice['status'] = 'completado';
    } catch (Exception $e) {
        error_log("Error al actualizar el estado de la factura: " . $e->getMessage());
    }
}

// MODIFICADO: Solo subgerente puede aprobar/rechazar
$can_approve = ($role === 'subgerente');

// Verificar si el usuario ya aprobó esta factura
$user_already_approved = false;
foreach ($approvals as $approval) {
    if ($approval['user_id'] == $user_id && $approval['action'] == 'approve') {
        $user_already_approved = true;
        break;
    }
}

// Procesar formulario de aprobación
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    if (!$can_approve) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>No tiene permisos para aprobar facturas. Solo el rol Subgerente puede aprobar.</div>';
    } elseif ($user_already_approved) {
        $message = '<div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>Ya ha aprobado esta factura anteriormente.</div>';
    } else {
        $comments = $_POST['comments'] ?? '';
        $result = approveInvoice($invoice_id, $user_id, $role, $comments);
        
        if ($result) {
            $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Factura aprobada correctamente</div>';
            
            // Recargar datos
            $invoice = getInvoiceById($invoice_id);
            $approvals = getInvoiceApprovals($invoice_id);
            
            // Actualizar estado si subgerente aprobó
            $has_subgerente_approval = false;
            foreach ($approvals as $approval) {
                if ($approval['action'] == 'approve' && $approval['user_role'] == 'subgerente') {
                    $has_subgerente_approval = true;
                    break;
                }
            }
            
            if ($has_subgerente_approval && $invoice['status'] != 'completado' && $invoice['status'] != 'rechazado' && $invoice['status'] != 'corregida') {
                try {
                    $conn = getDbConnection();
                    if ($conn instanceof PDO) {
                        $stmt = $conn->prepare("UPDATE invoices SET status = 'completado' WHERE docnum_interno_sap = ?");
                        $stmt->execute([$invoice_id]);
                    } else {
                        $sql = "UPDATE invoices SET status = 'completado' WHERE docnum_interno_sap = ?";
                        $params = array($invoice_id);
                        $stmt = sqlsrv_prepare($conn, $sql, $params);
                        if ($stmt) {
                            sqlsrv_execute($stmt);
                            sqlsrv_free_stmt($stmt);
                        }
                    }
                    $invoice['status'] = 'completado';
                } catch (Exception $e) {
                    error_log("Error al actualizar estado: " . $e->getMessage());
                }
            }
            
            $user_already_approved = true;
        } else {
            $message = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Error al aprobar la factura</div>';
        }
    }
}

// Procesar rechazo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    if (!$can_approve) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>No tiene permisos para rechazar facturas.</div>';
    } else {
        $reject_reason = $_POST['reject_reason'] ?? '';
        if (empty($reject_reason)) {
            $message = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Debe proporcionar una razón para el rechazo.</div>';
        } else {
            $result = rejectInvoice($invoice_id, $user_id, $role, $reject_reason);
            if ($result) {
                $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Factura rechazada correctamente</div>';
                $invoice = getInvoiceById($invoice_id);
                $approvals = getInvoiceApprovals($invoice_id);
                
                try {
                    $conn = getDbConnection();
                    if ($conn instanceof PDO) {
                        $stmt = $conn->prepare("UPDATE invoices SET status = 'rechazado' WHERE docnum_interno_sap = ?");
                        $stmt->execute([$invoice_id]);
                    } else {
                        $sql = "UPDATE invoices SET status = 'rechazado' WHERE docnum_interno_sap = ?";
                        $params = array($invoice_id);
                        $stmt = sqlsrv_prepare($conn, $sql, $params);
                        if ($stmt) {
                            sqlsrv_execute($stmt);
                            sqlsrv_free_stmt($stmt);
                        }
                    }
                    $invoice['status'] = 'rechazado';
                } catch (Exception $e) {
                    error_log("Error al rechazar: " . $e->getMessage());
                }
            } else {
                $message = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Error al rechazar la factura</div>';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Detalles de Factura - Sistema de Aprobación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.0.279/web/pdf_viewer.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .role-badge {
            font-size: 0.9em;
            padding: 8px 12px;
            border-radius: 20px;
        }
        .bg-secondary {
    --bs-bg-opacity: 1;
      background-color: orange !important;

}
.bg-secondary1 {
    --bs-bg-opacity: 1;
      background-color: gray !important;

}
        .approval-section {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .approval-section.can-approve {
            border-color: #28a745;
            background-color: #f8fff9;
        }
        
        .approval-section.already-approved {
            border-color: #17a2b8;
            background-color: #f0f9ff;
        }
        
        .approval-status {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .back-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: #2563eb;
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-button:hover {
            background: #1d4ed8;
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(37, 99, 235, 0.5);
        }

        .back-button:active {
            transform: translateY(-1px);
        }

        .arrow {
            transition: transform 0.3s ease;
        }

        .back-button:hover .arrow {
            transform: translateX(-2px);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Detalles de Factura #<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?></h1>

                    <?php
                    $rol = strtolower($_SESSION['user_role'] ?? '');
                    if ($rol === 'gerente' || $rol === 'admin'):
                    ?>
                        <a href="approved_invoices.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-1"></i> Volver
                        </a>
                    <?php endif; ?>
                </div>
                
                <?php echo $message; ?>
                
                <!-- Alertas de estado -->
                <?php if (!$has_subgerente_approval && !in_array($invoice['status'], ['completado', 'rechazado', 'corregida'])): ?>
                <div class="alert alert-warning">
                    <strong><i class="fas fa-exclamation-triangle me-2"></i>Estado:</strong> 
                    Factura pendiente de aprobación por Subgerente
                </div>
                <?php endif; ?>
                
                <?php if ($has_subgerente_approval && $invoice['status'] == 'completado'): ?>
                <div class="alert alert-success">
                    <strong><i class="fas fa-check-circle me-2"></i>Estado:</strong> 
                    Factura aprobada y completada por Subgerente
                </div>
                <?php endif; ?>
                
                <?php if ($has_rejection && in_array($invoice['status'], ['rechazado', 'corregida'])): ?>
                <div class="alert alert-danger">
                    <strong><i class="fas fa-times-circle me-2"></i>Estado:</strong> 
                    La factura ha sido <?php echo $invoice['status'] == 'corregida' ? 'rechazada y corregida' : 'rechazada'; ?>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <!-- Información de la Factura -->
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Información de la Factura</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered">
                                    <tr><th style="width: 30%">Número de Factura:</th><td><?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?></td></tr>
                                    <tr><th>Fecha:</th><td><?php echo formatDate($invoice['fecha_vencimiento']); ?></td></tr>
                                    <tr><th>Proveedor:</th><td><?php echo htmlspecialchars($invoice['nombre']); ?></td></tr>
                                    <tr><th>Nit:</th><td><?php echo htmlspecialchars($invoice['codigo_sn']); ?></td></tr>
                                    <tr><th>Número factura:</th><td><?php echo htmlspecialchars($invoice['numero_factura_proveedor']); ?></td></tr>
                                    <tr><th>Días vencidos</th><td><?php echo $invoice['dias_de_vencido']; ?></td></tr>
                                    <tr><th>Saldo Pendiente</th><td>$<?php echo number_format($invoice['saldo_pendiente'], 2, ',', '.'); ?></td></tr>
                                    <tr>
                                        <th>Estado:</th>
                                        <td ><span  class="badge <?php echo getStatusBadgeClass($invoice['status']); ?>">
                                            <?php echo getStatusLabel($invoice['status']); ?>
                                        </span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Sección de aprobación (solo subgerente) -->
                        <?php if ($invoice['status'] != 'completado' && $invoice['status'] != 'rechazado' && $invoice['status'] != 'corregida' && $role === 'subgerente'): ?>
                        <div class="approval-section <?php echo $user_already_approved ? 'already-approved' : 'can-approve'; ?>">
                            <div class="approval-status">
                                <?php if ($user_already_approved): ?>
                                    <i class="fas fa-check-circle text-info fa-2x"></i>
                                    <div>
                                        <h6 class="mb-1 text-info">Ya aprobó esta factura</h6>
                                        <small class="text-muted">Su aprobación como Subgerente ha sido registrada</small>
                                    </div>
                                <?php else: ?>
                                    <i class="fas fa-user-check text-success fa-2x"></i>
                                    <div>
                                        <h6 class="mb-1 text-success">Puede aprobar esta factura</h6>
                                        <small class="text-muted">Como Subgerente, puede aprobar o rechazar</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!$user_already_approved): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <form method="POST" class="mb-3">
                                        <div class="mb-3">
                                            <label for="comments" class="form-label">Comentarios (opcional)</label>
                                            <textarea class="form-control" id="comments" name="comments" rows="3" placeholder="Agregue comentarios..."></textarea>
                                        </div>
                                        <button type="submit" name="approve" class="btn btn-success w-100">
                                            <i class="fas fa-check me-1"></i> Aprobar
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <form method="POST">
                                        <div class="mb-3">
                                            <label for="reject_reason" class="form-label">Razón del rechazo <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="reject_reason" name="reject_reason" rows="3" placeholder="Especifique la razón..." required></textarea>
                                        </div>
                                        <button type="submit" name="reject" class="btn btn-danger w-100" onclick="return confirm('¿Rechazar esta factura?')">
                                            <i class="fas fa-times me-1"></i> Rechazar
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Historial de Aprobaciones -->
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Historial de Aprobaciones</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($approvals) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Usuario</th>
                                                    <th>Rol</th>
                                                    <th>Acción</th>
                                                    <th>Fecha</th>
                                                    <th>Comentarios</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $shown = [];
                                                foreach ($approvals as $approval):
                                                    // Clave única robusta
                                                    $key = $approval['id'] ?? ($approval['user_id'] . '-' . $approval['action'] . '-' . $approval['created_at']);
                                                    if (isset($shown[$key])) continue;
                                                    $shown[$key] = true;

                                                    $action = $approval['action'];
                                                    $badgeClass = 'bg-secondary';
                                                    $label = ucfirst($action);
                                                    $icon = '';

                                                    switch ($action) {
                                                        case 'approve':
                                                            $badgeClass = 'bg-success';
                                                            $label = 'Aprobado';
                                                            $icon = '<i class="fas fa-check"></i> ';
                                                            break;
                                                        case 'reject':
                                                            $badgeClass = 'bg-danger';
                                                            $label = 'Rechazado';
                                                            $icon = '<i class="fas fa-times"></i> ';
                                                            break;
                                                        case 'view':
                                                            $badgeClass = 'bg-info';
                                                            $label = 'Visto';
                                                            $icon = '<i class="fas fa-eye"></i> ';
                                                            break;
                                                        case 'corrected':
                                                            $badgeClass = 'bg-warning text-dark';
                                                            $label = 'Corregido';                                                            
                                                            break;
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($approval['user_name']); ?></td>
                                                    <td><?php echo ucfirst($approval['user_role']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $badgeClass; ?>">
                                                            <?php echo $icon . $label; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatDateTime($approval['created_at']); ?></td>
                                                    <td><?php echo htmlspecialchars($approval['comments'] ?? ''); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        No hay historial de aprobaciones para esta factura
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Documento y Detalles SAP -->
                    <div class="col-md-6">
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Documento de Factura</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($invoice['archivo_adjunto'])): ?>
                                    <?php
                                    $sap_path = $invoice['archivo_adjunto'];
                                    $encoded_path = urlencode($sap_path);
                                    $servilayer_url = "servilayer.php?file={$encoded_path}";
                                    $file_extension = strtolower(pathinfo($sap_path, PATHINFO_EXTENSION));
                                    ?>
                                    
                                    <?php if ($file_extension === 'pdf'): ?>
                                        <div class="pdf-controls mb-3 d-flex align-items-center gap-2">
                                            <button id="zoom-out" class="btn btn-sm btn-outline-secondary"><i class="fas fa-search-minus"></i></button>
                                            <span id="zoom-level" class="badge bg-secondary1">100%</span>
                                            <button id="zoom-in" class="btn btn-sm btn-outline-secondary"><i class="fas fa-search-plus"></i></button>
                                            <button id="fullscreen" class="btn btn-sm btn-outline-success"><i class="fas fa-expand"></i> Pantalla completa</button>
                                        </div>
                                        <div id="pdf-container" style="height: 600px; border: 1px solid #ddd; overflow: auto;">
                                            <embed id="pdf-embed" src="<?= $servilayer_url ?>#toolbar=0&navpanes=0&scrollbar=0&view=FitH" type="application/pdf" width="100%" height="100%">
                                        </div>

                                    <?php elseif (in_array($file_extension, ['png', 'jpg', 'jpeg'])): ?>
                                        <div class="pdf-controls mb-3 d-flex align-items-center gap-2">
                                            <button id="fullscreen" class="btn btn-sm btn-outline-success"><i class="fas fa-expand"></i> Pantalla completa</button>
                                        </div>
                                        <div id="pdf-container" style="text-align: center; border: 1px solid #ddd; padding: 10px;">
                                            <img id="image-viewer" src="<?= $servilayer_url ?>" alt="Factura" class="img-fluid rounded" style="max-height: 600px;">
                                        </div>

                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-file-alt"></i> Archivo no compatible con vista previa.<br>
                                            <a href="<?= $servilayer_url ?>" class="btn btn-sm btn-primary mt-2" target="_blank"><i class="fas fa-download"></i> Descargar</a>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-2">
                                        <a href="<?= $servilayer_url ?>" download class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-download"></i> Descargar
                                        </a>
                                    </div>

                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No hay documento adjunto
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Detalles SAP</h5>
                            </div>
                            <div class="card-body">
                                <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#sapDetailsModal">
                                    <i class="fas fa-list-ul me-1"></i> Ver Detalles SAP
                                </button>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <tr><th>Cuenta contable</th><td><?php echo htmlspecialchars($invoice['cuenta_contable']); ?></td></tr>
                                        <tr><th>Nombre de cuenta</th><td><?php echo htmlspecialchars($invoice['nombre_cuenta']); ?></td></tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal Detalles SAP -->
    <div class="modal fade" id="sapDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Detalles SAP #<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead><tr><th>Detalle</th><th>Cantidad</th></tr></thead>
                            <tbody>
                                <?php
                                $invoice_items = getInvoiceItems($invoice_id);
                                if ($invoice_items):
                                    foreach ($invoice_items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['detalle']); ?></td>
                                            <td><?php echo number_format($item['cantidad'], 0, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach;
                                else: ?>
                                    <tr><td colspan="2" class="text-center">No hay detalles</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <button class="back-button" onclick="window.history.back()">
        <span class="arrow">←</span>
    </button>

    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.0.279/build/pdf.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileExtension = "<?= $file_extension ?? '' ?>";
            const pdfContainer = document.getElementById('pdf-container');
            const fullscreenBtn = document.getElementById('fullscreen');

            if (fileExtension === 'pdf') {
                const pdfEmbed = document.getElementById('pdf-embed');
                const zoomLevel = document.getElementById('zoom-level');
                const zoomInBtn = document.getElementById('zoom-in');
                const zoomOutBtn = document.getElementById('zoom-out');

                let currentZoom = 100;

                function updateZoom(zoom) {
                    currentZoom = Math.max(25, Math.min(200, zoom));
                    zoomLevel.textContent = currentZoom + '%';
                    pdfEmbed.src = `<?= $servilayer_url ?>#toolbar=0&navpanes=0&scrollbar=0&zoom=${currentZoom}`;
                    zoomInBtn.disabled = currentZoom >= 200;
                    zoomOutBtn.disabled = currentZoom <= 25;
                }

                zoomInBtn.addEventListener('click', () => updateZoom(currentZoom + 10));
                zoomOutBtn.addEventListener('click', () => updateZoom(currentZoom - 10));
                updateZoom(100);
            }

            if (fullscreenBtn && pdfContainer) {
                fullscreenBtn.addEventListener('click', () => {
                    if (pdfContainer.requestFullscreen) pdfContainer.requestFullscreen();
                    else if (pdfContainer.webkitRequestFullscreen) pdfContainer.webkitRequestFullscreen();
                    else if (pdfContainer.mozRequestFullScreen) pdfContainer.mozRequestFullScreen();
                    else if (pdfContainer.msRequestFullscreen) pdfContainer.msRequestFullscreen();
                });
            }
        });
    </script>
</body>
</html>