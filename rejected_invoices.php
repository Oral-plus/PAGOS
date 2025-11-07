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
$message = '';

// Marcar como completada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $invoice_id = $_POST['invoice_id'];
    try {
        $conn = getDbConnection();
        if ($conn instanceof PDO) {
            $conn->beginTransaction();
            $stmt = $conn->prepare("DELETE FROM invoice_approvals WHERE invoice_id = ? AND action = 'reject'");
            $stmt->execute([$invoice_id]);
            $stmt = $conn->prepare("UPDATE invoices SET status = 'completada' WHERE docnum_interno_sap = ?");
            $stmt->execute([$invoice_id]);
            $conn->commit();
        } else {
            sqlsrv_begin_transaction($conn);
            $sql = "DELETE FROM invoice_approvals WHERE invoice_id = ? AND action = 'reject'";
            $stmt = sqlsrv_query($conn, $sql, array($invoice_id));
            if ($stmt) sqlsrv_free_stmt($stmt);
            $sql = "UPDATE invoices SET status = 'completada' WHERE docnum_interno_sap = ?";
            $stmt = sqlsrv_query($conn, $sql, array($invoice_id));
            if ($stmt) sqlsrv_free_stmt($stmt);
            sqlsrv_commit($conn);
        }
        $message = '<div class="alert alert-success alert-sm mb-3"><i class="fas fa-check-circle"></i> Factura #'.$invoice_id.' completada</div>';
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        $message = '<div class="alert alert-danger alert-sm mb-3"><i class="fas fa-exclamation-triangle"></i> Error: ' . $e->getMessage() . '</div>';
    }
}

// Corregir factura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['correct_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $correction_comments = $_POST['correction_comments'] ?? '';
    
    if (empty(trim($correction_comments))) {
        $message = '<div class="alert alert-danger alert-sm mb-3"><i class="fas fa-exclamation-triangle"></i> Ingrese comentarios de corrección</div>';
    } else {
        try {
            $conn = getDbConnection();
            if ($conn instanceof PDO) {
                $conn->beginTransaction();
                $stmt = $conn->prepare("UPDATE invoices SET status = 'corregida' WHERE docnum_interno_sap = ?");
                $stmt->execute([$invoice_id]);
                $current_time = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments, created_at) VALUES (?, ?, ?, 'corregida', ?, ?)");
                $stmt->execute([$invoice_id, $user_id, $role, $correction_comments, $current_time]);
                $conn->commit();
            } else {
                sqlsrv_begin_transaction($conn);
                $sql = "UPDATE invoices SET status = 'corregida' WHERE docnum_interno_sap = ?";
                $stmt = sqlsrv_query($conn, $sql, array($invoice_id));
                if ($stmt) sqlsrv_free_stmt($stmt);
                $current_time = date('Y-m-d H:i:s');
                $sql = "INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments, created_at) VALUES (?, ?, ?, 'corregida', ?, ?)";
                $stmt = sqlsrv_query($conn, $sql, array($invoice_id, $user_id, $role, $correction_comments, $current_time));
                if ($stmt) sqlsrv_free_stmt($stmt);
                sqlsrv_commit($conn);
            }
            $message = '<div class="alert alert-success alert-sm mb-3"><i class="fas fa-edit"></i> Factura #'.$invoice_id.' corregida</div>';
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            $message = '<div class="alert alert-danger alert-sm mb-3"><i class="fas fa-exclamation-triangle"></i> Error: ' . $e->getMessage() . '</div>';
        }
    }
}

$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_rejected_by = $_GET['filter_rejected_by'] ?? '';

function getAllSuppliers() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT nombre FROM invoices WHERE status = 'rechazado' ORDER BY nombre ASC";
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = sqlsrv_query($conn, $sql);
            if ($stmt === false) return array();
            $results = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = $row['nombre'];
            }
            sqlsrv_free_stmt($stmt);
            return $results;
        }
    } catch (Exception $e) {
        return array();
    }
}

function getAllRejectors() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT u.id, u.name FROM invoice_approvals a JOIN users u ON a.user_id = u.id WHERE a.action = 'reject' ORDER BY u.name ASC";
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } else {
            $stmt = sqlsrv_query($conn, $sql);
            if ($stmt === false) return array();
            $results = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            return $results;
        }
    } catch (Exception $e) {
        return array();
    }
}

function getRejectedInvoices($supplier = '', $date_from = '', $date_to = '', $rejected_by = '') {
    $conn = getDbConnection();
    try {
        $sql = "SELECT DISTINCT i.* FROM invoices i";
        if (!empty($rejected_by)) {
            $sql .= " JOIN invoice_approvals a ON i.docnum_interno_sap = a.invoice_id JOIN users u ON a.user_id = u.id";
        }
        $sql .= " WHERE i.status = 'rechazado'";
        $params = array();
        
        if (!empty($rejected_by)) {
            $sql .= " AND a.action = 'reject' AND a.user_id = ?";
            $params[] = $rejected_by;
        }
        if (!empty($supplier)) {
            $sql .= " AND i.nombre = ?";
            $params[] = $supplier;
        }
        if (!empty($date_from)) {
            $sql .= " AND i.fecha_vencimiento >= ?";
            $params[] = $date_from;
        }
        if (!empty($date_to)) {
            $sql .= " AND i.fecha_vencimiento <= ?";
            $params[] = $date_to;
        }
        $sql .= " ORDER BY i.fecha_vencimiento DESC";
        
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } else {
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) return array();
            $invoices = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            return $invoices;
        }
    } catch (Exception $e) {
        return array();
    }
}

$suppliers = getAllSuppliers();
$rejectors = getAllRejectors();
$rejected_invoices = getRejectedInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_rejected_by);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Rechazadas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        :root {
            --primary: #0d6efd;
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #198754;
            --secondary: #6c757d;
            --light: #f8f9fa;
            --border: #dee2e6;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-size: 13px;
            line-height: 1.4;
            background-color: #f5f6f7;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        /* Added keyframe animations for smooth entrance effects */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.8;
            }
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .container-fluid {
            padding: 0;
        }
        
        main {
            padding: 12px 16px;
            animation: slideInLeft 0.5s ease-out;
        }
        
        .alert-sm {
            padding: 8px 12px;
            font-size: 12px;
            margin-bottom: 12px;
            animation: slideDown 0.4s ease-out;
        }
        
        .card {
            border: 1px solid var(--border);
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 16px;
            animation: slideDown 0.5s ease-out;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* Enhanced card hover effect */
        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        
        .card-header {
            padding: 10px 16px;
            background-color: #fff;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 13px;
            animation: slideDown 0.5s ease-out;
        }
        
        .card-body {
            padding: 12px 16px;
            animation: fadeIn 0.6s ease-out;
        }
        
        .card-header.bg-primary {
            background: linear-gradient(135deg, var(--primary) 0%, #0b5ed7 100%);
            color: white;
        }
        
        .form-label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 4px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .form-control, .form-select {
            font-size: 12px;
            padding: 6px 8px;
            height: auto;
            border-radius: 4px;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        /* Added focus states with smooth transitions */
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
            transform: translateY(-1px);
        }
        
        .btn {
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        /* Button ripple effect on hover */
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #0b5ed7 0%, #0a58ca 100%);
            box-shadow: 0 4px 8px rgba(13, 110, 253, 0.3);
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background-color: var(--warning);
            border-color: var(--warning);
            color: #000;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #d39e00 100%);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
            transform: translateY(-2px);
        }
        
        .btn-info {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
            color: #000;
        }
        
        .btn-info:hover {
            background: linear-gradient(135deg, #31d5f8 0%, #0dcaf0 100%);
            box-shadow: 0 4px 8px rgba(13, 202, 240, 0.3);
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            border-color: var(--secondary);
            color: var(--secondary);
            transition: all 0.3s ease;
        }
        
        .btn-outline-secondary:hover {
            background-color: var(--secondary);
            box-shadow: 0 2px 6px rgba(108, 117, 125, 0.2);
        }
        
        /* Enhanced table styles with animations */
        .table {
            font-size: 12px;
            margin-bottom: 0;
            animation: fadeIn 0.6s ease-out;
        }
        
        .table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid var(--border);
        }
        
        .table thead th {
            padding: 8px 12px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            color: #333;
            border: none;
            animation: slideDown 0.5s ease-out;
        }
        
        .table tbody td {
            padding: 8px 12px;
            border-color: var(--border);
            vertical-align: middle;
            transition: all 0.3s ease;
        }
        
        /* Added row hover animation */
        .table tbody tr {
            animation: slideInLeft 0.4s ease-out forwards;
        }
        
        .table tbody tr:nth-child(1) { animation-delay: 0s; }
        .table tbody tr:nth-child(2) { animation-delay: 0.05s; }
        .table tbody tr:nth-child(3) { animation-delay: 0.1s; }
        .table tbody tr:nth-child(4) { animation-delay: 0.15s; }
        .table tbody tr:nth-child(5) { animation-delay: 0.2s; }
        .table tbody tr:nth-child(n+6) { animation-delay: 0.25s; }
        
        .table tbody tr:hover {
            background: linear-gradient(90deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.08) 100%);
            box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.1);
            transform: scale(1.01);
        }
        
        .table-responsive {
            border-radius: 0;
            animation: scaleIn 0.5s ease-out;
        }
        
        .badge {
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 600;
            border-radius: 3px;
            animation: scaleIn 0.4s ease-out;
            transition: all 0.3s ease;
        }
        
        .badge:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        
        .badge.bg-danger {
            background-color: var(--danger) !important;
        }
        
        .badge.bg-warning {
            background-color: var(--warning) !important;
            color: #000;
        }
        
        .badge.bg-success {
            background-color: var(--success) !important;
        }
        
        .badge.bg-secondary {
            background-color: var(--secondary) !important;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 8px;
            align-items: flex-end;
            animation: slideDown 0.5s ease-out;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Staggered animation for filter groups */
        .filter-group:nth-child(1) { animation-delay: 0s; }
        .filter-group:nth-child(2) { animation-delay: 0.1s; }
        .filter-group:nth-child(3) { animation-delay: 0.2s; }
        .filter-group:nth-child(4) { animation-delay: 0.3s; }
        .filter-group:nth-child(5) { animation-delay: 0.4s; }
        
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Enhanced action button animations */
        .action-buttons .btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .action-buttons .btn:hover {
            animation: pulse 0.6s ease-in-out;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--warning) 0%, #e0a800 100%);
            color: #000;
            border-bottom: 1px solid var(--border);
            padding: 10px 16px;
            animation: slideDown 0.4s ease-out;
        }
        
        .modal-body {
            padding: 16px;
            font-size: 12px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .modal-footer {
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            animation: slideDown 0.4s ease-out reverse;
        }
        
        .text-muted {
            color: var(--secondary) !important;
            font-size: 11px;
        }
        
        .row {
            margin: 0;
        }
        
        .g-3 {
            gap: 8px !important;
        }
        
        .col-md-3, .col-md-2, .col-md-6 {
            padding: 0;
        }
        
        /* Modal animation */
        .modal.fade .modal-dialog {
            animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        /* Loading animation for export button */
        #exportBtn:active {
            animation: pulse 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            main {
                padding: 8px 12px;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 11px;
            }
            
            .btn {
                font-size: 11px;
                padding: 5px 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10">
                <?php echo $message; ?>
                
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-filter me-2"></i>Filtros de Búsqueda
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="filter-form">
                            <div class="filter-group">
                                <label for="filter_supplier" class="form-label">Proveedor</label>
                                <select class="form-select" id="filter_supplier" name="filter_supplier">
                                    <option value="">Todos</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="filter_rejected_by" class="form-label">Rechazada por</label>
                                <select class="form-select" id="filter_rejected_by" name="filter_rejected_by">
                                    <option value="">Todos</option>
                                    <?php foreach ($rejectors as $rejector): ?>
                                        <option value="<?php echo $rejector['id']; ?>" <?php echo ($filter_rejected_by == $rejector['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($rejector['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="filter_date_from" class="form-label">Desde</label>
                                <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo $filter_date_from; ?>">
                            </div>
                            <div class="filter-group">
                                <label for="filter_date_to" class="form-label">Hasta</label>
                                <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo $filter_date_to; ?>">
                            </div>
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i>Filtrar
                                </button>
                                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo me-1"></i>Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-primary" style="color: white; display: flex; justify-content: space-between; align-items: center;">
                        <div><i class="fas fa-list me-2"></i>Facturas Rechazadas</div>
                        <button class="btn btn-sm btn-light" id="exportBtn">
                            <i class="fas fa-download me-1"></i>Exportar
                        </button>
                    </div>
                    <div class="card-body">
                        <?php 
                        $unique_invoices = [];
                        $filtered_invoices = [];
                        foreach ($rejected_invoices as $invoice) {
                            if (!in_array($invoice['docnum_interno_sap'], $unique_invoices)) {
                                $unique_invoices[] = $invoice['docnum_interno_sap'];
                                $filtered_invoices[] = $invoice;
                            }
                        }
                        ?>
                        
                        <?php if (count($filtered_invoices) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="invoicesTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Valor</th>
                                            <th>Rechazada por</th>
                                            <th>Motivo</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $conn = getDbConnection();
                                        foreach ($filtered_invoices as $invoice): 
                                            if ($invoice['ESTADOSAP'] !== 'O') continue;
                                            
                                            try {
                                                if ($conn instanceof PDO) {
                                                    $stmt = $conn->prepare("SELECT a.*, u.name as user_name FROM invoice_approvals a JOIN users u ON a.user_id = u.id WHERE a.invoice_id = ? AND a.action = 'reject' ORDER BY a.created_at DESC LIMIT 1");
                                                    $stmt->execute([$invoice['docnum_interno_sap']]);
                                                    $rejection = $stmt->fetch();
                                                } else {
                                                    $sql = "SELECT TOP 1 a.*, u.name as user_name FROM invoice_approvals a JOIN users u ON a.user_id = u.id WHERE a.invoice_id = ? AND a.action = 'reject' ORDER BY a.created_at DESC";
                                                    $stmt = sqlsrv_query($conn, $sql, array($invoice['docnum_interno_sap']));
                                                    $rejection = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                                                    sqlsrv_free_stmt($stmt);
                                                }
                                            } catch (Exception $e) {
                                                $rejection = null;
                                            }
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?></strong></td>
                                                <td><?php echo formatDate($invoice['fecha_vencimiento']); ?></td>
                                                <td><?php echo htmlspecialchars(substr($invoice['nombre'], 0, 20)); ?></td>
                                                <td><strong>$<?php echo number_format($invoice['saldo_pendiente'], 0, ',', '.'); ?></strong></td>
                                                <td><?php echo $rejection ? htmlspecialchars($rejection['user_name']) : '-'; ?></td>
                                                <td>
                                                    <a href="#" class="btn btn-sm btn-link view-reason-btn" 
                                                       data-reason="<?php echo htmlspecialchars($rejection && !empty($rejection['comments']) ? $rejection['comments'] : 'Sin motivo registrado'); ?>"
                                                       data-invoice="<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?>">
                                                        <small><?php echo $rejection && !empty($rejection['comments']) ? htmlspecialchars(substr($rejection['comments'], 0, 30)) . '...' : '-'; ?></small>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="view_invoice.php?docnum_interno_sap=<?php echo $invoice['docnum_interno_sap']; ?>" class="btn btn-sm btn-info">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if (in_array($role, ['admin', 'gerente', 'contador', 'Preparador'])): ?>
                                                            <button type="button" class="btn btn-sm btn-warning correct-invoice-btn" data-bs-toggle="modal" data-bs-target="#correctInvoiceModal" data-invoice-id="<?php echo $invoice['docnum_interno_sap']; ?>" data-invoice-supplier="<?php echo htmlspecialchars($invoice['nombre']); ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div style="font-size: 11px; color: var(--secondary); margin-top: 8px;">
                                <i class="fas fa-info-circle"></i> <?php echo count($filtered_invoices); ?> facturas encontradas
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" style="font-size: 12px;">
                                <i class="fas fa-info-circle"></i> No hay facturas rechazadas
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal Corregir -->
    <div class="modal fade" id="correctInvoiceModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="fas fa-edit me-2"></i>Corregir Factura</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <strong>ID:</strong> <span id="correctInvoiceIdText"></span>
                        </div>
                        <div class="mb-3">
                            <label for="correction_comments" class="form-label">Comentarios</label>
                            <textarea class="form-control" id="correction_comments" name="correction_comments" rows="3" required placeholder="Describa las correcciones..."></textarea>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="confirmCorrection" required>
                            <label class="form-check-label" for="confirmCorrection" style="font-size: 11px;">
                                Confirmo que he realizado las correcciones
                            </label>
                        </div>
                        <input type="hidden" name="invoice_id" id="correctInvoiceIdInput" value="">
                        <input type="hidden" name="correct_invoice" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-sm btn-warning">Corregir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Added new modal to view complete rejection reason -->
    <div class="modal fade" id="viewReasonModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-info-circle me-2"></i>Motivo del Rechazo</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <strong>Factura:</strong> <span id="reasonInvoiceId"></span>
                    </div>
                    <div class="alert alert-warning" role="alert">
                        <p id="reasonText" style="margin: 0; font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-break: break-word;"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const correctInvoiceBtns = document.querySelectorAll('.correct-invoice-btn');
        correctInvoiceBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const invoiceId = this.getAttribute('data-invoice-id');
                document.getElementById('correctInvoiceIdInput').value = invoiceId;
                document.getElementById('correctInvoiceIdText').textContent = invoiceId;
                document.getElementById('correction_comments').value = '';
                document.getElementById('confirmCorrection').checked = false;
            });
        });

        const viewReasonBtns = document.querySelectorAll('.view-reason-btn');
        viewReasonBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const reason = this.getAttribute('data-reason');
                const invoiceId = this.getAttribute('data-invoice');
                
                document.getElementById('reasonInvoiceId').textContent = invoiceId;
                document.getElementById('reasonText').textContent = reason;
                
                const modal = new bootstrap.Modal(document.getElementById('viewReasonModal'));
                modal.show();
            });
        });
        
        document.getElementById('exportBtn').addEventListener('click', function() {
            const table = document.getElementById('invoicesTable');
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, "Facturas");
            const now = new Date();
            const fileName = `facturas_rechazadas_${now.getFullYear()}${(now.getMonth()+1).toString().padStart(2, '0')}${now.getDate().toString().padStart(2, '0')}.xlsx`;
            XLSX.writeFile(wb, fileName);
        });
    });
    </script>
</body>
</html>
