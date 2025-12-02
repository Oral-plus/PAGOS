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
       /* ===========================
   VARIABLES DE COLOR - PALETA COHESIVA
   =========================== */
:root {
    --primary: #2563eb;
    --primary-dark: #1e40af;
    --primary-light: #3b82f6;
    
    --secondary: #0891b2;
    --secondary-dark: #0e7490;
    --secondary-light: #06b6d4;
    
    --success: #059669;
    --success-dark: #047857;
    --success-light: #10b981;
    
    --warning: #d97706;
    --warning-dark: #b45309;
    --warning-light: #f59e0b;
    
    --purple: #7c3aed;
    --purple-dark: #6d28d9;
    --purple-light: #8b5cf6;
    
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
    
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 20px;
    --radius-2xl: 24px;
    --radius-full: 9999px;
    
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    
    --backdrop-blur: blur(12px);
    --glass-bg: rgba(255, 255, 255, 0.85);
}

/* ===========================
   BADGES Y ETIQUETAS
   =========================== */
        .role-badge {
    font-size: 0.875rem;
    padding: 0.5rem 1rem;
    border-radius: var(--radius-full);
    font-weight: 600;
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
}

.badge {
    font-weight: 600;
    padding: 0.5rem 0.875rem;
    border-radius: var(--radius-full);
    letter-spacing: 0.025em;
    box-shadow: var(--shadow-sm);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.badge:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: var(--shadow-md);
}

.badge.bg-success {
    background: linear-gradient(135deg, var(--success), var(--success-dark)) !important;
}

.badge.bg-danger {
    background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
}

.badge.bg-warning {
    background: linear-gradient(135deg, var(--warning), var(--warning-dark)) !important;
}

.badge.bg-info {
    background: linear-gradient(135deg, var(--secondary), var(--secondary-dark)) !important;
}

        .bg-secondary {
    --bs-bg-opacity: 1;
    background: linear-gradient(135deg, var(--warning), var(--warning-dark)) !important;
}

.bg-secondary1 {
    --bs-bg-opacity: 1;
    background: linear-gradient(135deg, var(--gray-700), var(--gray-800)) !important;
}

/* ===========================
   SECCIÓN DE APROBACIÓN
   =========================== */
        .approval-section {
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin: 1.5rem 0;
    background: white;
    box-shadow: var(--shadow-md);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.approval-section::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--gray-300), transparent);
    transition: var(--transition);
        }
        
        .approval-section.can-approve {
    border-color: var(--success);
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.05), rgba(5, 150, 105, 0.02));
}

.approval-section.can-approve::before {
    background: linear-gradient(90deg, var(--success), var(--success-dark));
        }
        
        .approval-section.already-approved {
    border-color: var(--secondary);
    background: linear-gradient(135deg, rgba(8, 145, 178, 0.05), rgba(8, 145, 178, 0.02));
}

.approval-section.already-approved::before {
    background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
}

.approval-section:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
        }
        
        .approval-status {
            display: flex;
            align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

/* ===========================
   TARJETAS
   =========================== */
.card {
    border: none;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    overflow: visible;
    transition: var(--transition);
    background: white;
}

.card:hover {
    box-shadow: var(--shadow-xl);
}

.card-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    font-weight: 700;
    padding: 1rem 1.5rem;
    border-bottom: 3px solid rgba(255, 255, 255, 0.2);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.9375rem;
    position: relative;
    overflow: hidden;
}

.card-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
    pointer-events: none;
}

.card-body {
    padding: 1.5rem;
}

/* ===========================
   TABLAS
   =========================== */
.table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

.table thead th {
    background: linear-gradient(135deg, var(--gray-100), var(--gray-50));
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.8125rem;
    padding: 0.75rem 1rem;
    border-bottom: 3px solid var(--primary);
    color: var(--gray-800);
}

.table tbody tr {
    transition: var(--transition);
    border-bottom: 1px solid var(--gray-200);
}

.table tbody tr:hover {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.03), rgba(37, 99, 235, 0.01));
    box-shadow: var(--shadow-sm);
}

.table tbody td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.table-bordered th,
.table-bordered td {
    border: 1px solid var(--gray-200);
}

/* ===========================
   BOTONES
   =========================== */
.btn {
    border-radius: var(--radius-lg);
    font-weight: 600;
    padding: 0.625rem 1.25rem;
    transition: var(--transition);
    letter-spacing: 0.025em;
    border: 2px solid transparent;
    box-shadow: var(--shadow-sm);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-success {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    border-color: var(--success-dark);
}

.btn-success:hover {
    background: linear-gradient(135deg, var(--success-light), var(--success));
    box-shadow: var(--shadow-lg);
}

.btn-danger {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    border-color: #b91c1c;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    box-shadow: var(--shadow-lg);
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-color: var(--primary-dark);
}

.btn-primary:hover {
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    box-shadow: var(--shadow-lg);
}

.btn-outline-primary {
    border: 2px solid var(--primary);
    color: var(--primary);
}

.btn-outline-primary:hover {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    transform: translateY(-2px);
}

.btn-outline-secondary {
    border: 2px solid var(--gray-300);
    color: var(--gray-700);
}

.btn-outline-secondary:hover {
    background: var(--gray-200);
    border-color: var(--gray-400);
}

.btn-outline-success {
    border: 2px solid var(--success);
    color: var(--success-dark);
}

.btn-outline-success:hover {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    color: white;
}

/* ===========================
   ALERTAS
   =========================== */
.alert {
    border-radius: var(--radius-lg);
    border: 2px solid transparent;
    box-shadow: var(--shadow-md);
    padding: 1rem 1.25rem;
    font-weight: 500;
    letter-spacing: 0.025em;
    transition: var(--transition);
}

.alert:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.alert-success {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.1), rgba(5, 150, 105, 0.05));
    border-color: var(--success);
    color: var(--success-dark);
}

.alert-danger {
    background: linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(220, 38, 38, 0.05));
    border-color: #dc2626;
    color: #b91c1c;
}

.alert-warning {
    background: linear-gradient(135deg, rgba(217, 119, 6, 0.1), rgba(217, 119, 6, 0.05));
    border-color: var(--warning);
    color: var(--warning-dark);
}

.alert-info {
    background: linear-gradient(135deg, rgba(8, 145, 178, 0.1), rgba(8, 145, 178, 0.05));
    border-color: var(--secondary);
    color: var(--secondary-dark);
}

/* ===========================
   BOTÓN DE REGRESO
   =========================== */
        .back-button {
            position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
    border-radius: var(--radius-full);
            color: white;
    font-size: 1.5rem;
            cursor: pointer;
    box-shadow: var(--shadow-xl);
    transition: var(--transition);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
    border: 3px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
    position: relative;
    overflow: hidden;
}

.back-button::before {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: var(--radius-full);
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    opacity: 0;
    z-index: -1;
    transition: var(--transition);
        }

        .back-button:hover {
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    transform: translateY(-6px) scale(1.08);
    box-shadow: 0 25px 40px -8px rgba(37, 99, 235, 0.5);
}

.back-button:hover::before {
    opacity: 1;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
        opacity: 0.5;
    }
    50% {
        transform: scale(1.1);
        opacity: 0.8;
    }
        }

        .back-button:active {
    transform: translateY(-3px) scale(1.05);
        }

        .arrow {
    transition: var(--transition);
    display: inline-block;
    font-weight: 700;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .back-button:hover .arrow {
    transform: translateX(-5px) scale(1.1);
}

/* ===========================
   FORMULARIOS
   =========================== */
.form-control,
.form-select {
    border-radius: var(--radius-lg);
    border: 2px solid var(--gray-300);
    padding: 0.75rem 1rem;
    transition: var(--transition);
    font-weight: 500;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1), var(--shadow-md);
    transform: translateY(-2px);
}

.form-label {
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

/* ===========================
   MODAL
   =========================== */
.modal-content {
    border-radius: var(--radius-xl);
    border: none;
    box-shadow: var(--shadow-2xl);
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-bottom: 3px solid rgba(255, 255, 255, 0.2);
    padding: 1.5rem;
}

.modal-body {
    padding: 1.75rem;
}

.modal-footer {
    padding: 1.25rem 1.75rem;
    border-top: 2px solid var(--gray-200);
    background: var(--gray-50);
}

/* ===========================
   ANIMACIONES
   =========================== */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(15px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

.card {
    animation: fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ===========================
   RESPONSIVE
   =========================== */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .card-header {
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
    }
    
    .back-button {
        width: 56px;
        height: 56px;
        bottom: 1rem;
        right: 1rem;
        font-size: 1.25rem;
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

    <button class="back-button1" onclick="goBack()" aria-label="Regresar">
        <span class="arrow">←</span>
    </button>
    <style>
        .back-button1 {
    position: fixed;        /* Lo hace flotante */
    bottom: 20px;           /* Separación del borde inferior */
    right: 20px;            /* Separación del borde derecho */
    background-color: #1e88e5;   /* Azul moderno */
    color: white;
    border: none;
    border-radius: 50%;     /* Botón redondo */
    width: 55px;
    height: 55px;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0px 4px 10px rgba(0,0,0,0.25);
    transition: background 0.2s, transform 0.2s;
    z-index: 9999;
}

.back-button1:hover {
    background-color: #1565c0;
    transform: scale(1.1);
}

.arrow {
    display: inline-block;
    transform: translateY(-2px);
}

    </style>
<script>
function goBack() {
    window.history.back();
}
</script>

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