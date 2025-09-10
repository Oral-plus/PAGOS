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

// Procesar cambio de estado a completada
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $invoice_id = $_POST['invoice_id'];
    
    try {
        $conn = getDbConnection();
        
        // Iniciar transacción
        if ($conn instanceof PDO) {
            $conn->beginTransaction();
        } else {
            sqlsrv_begin_transaction($conn);
        }
        
        // Ejecutar la actualización según el tipo de conexión
        if ($conn instanceof PDO) {
            // 1. Primero eliminar los registros de rechazo de la tabla invoice_approvals
            $stmt = $conn->prepare("DELETE FROM invoice_approvals WHERE invoice_id = ? AND action = 'reject'");
            $stmt->execute([$invoice_id]);
            
            // 2. Actualizar el estado de la factura a completada (en femenino)
            $stmt = $conn->prepare("UPDATE invoices SET status = 'completada' WHERE docnum_interno_sap = ?");
            $stmt->execute([$invoice_id]);
            
            // Verificar si se actualizó correctamente
            $stmt = $conn->prepare("SELECT status FROM invoices WHERE docnum_interno_sap = ?");
            $stmt->execute([$invoice_id]);
            $updated_status = $stmt->fetchColumn();
            
            if ($updated_status !== 'completada') {
                throw new Exception("No se pudo actualizar el estado de la factura. Estado actual: " . $updated_status);
            }
        } else {
            // Usando sqlsrv
            // 1. Primero eliminar los registros de rechazo
            $sql = "DELETE FROM invoice_approvals WHERE invoice_id = ? AND action = 'reject'";
            $params = array($invoice_id);
            $stmt = sqlsrv_prepare($conn, $sql, $params);
            if ($stmt) {
                sqlsrv_execute($stmt);
                sqlsrv_free_stmt($stmt);
            } else {
                error_log("Error al preparar la consulta de eliminación: " . print_r(sqlsrv_errors(), true));
                throw new Exception("Error al eliminar registros de rechazo");
            }
            
            // 2. Actualizar el estado de la factura a completada (en femenino)
            $sql = "UPDATE invoices SET status = 'completada' WHERE docnum_interno_sap = ?";
            $params = array($invoice_id);
            $stmt = sqlsrv_prepare($conn, $sql, $params);
            if ($stmt) {
                sqlsrv_execute($stmt);
                sqlsrv_free_stmt($stmt);
                
                // Verificar si se actualizó correctamente
                $sql = "SELECT status FROM invoices WHERE docnum_interno_sap = ?";
                $params = array($invoice_id);
                $stmt = sqlsrv_query($conn, $sql, $params);
                if ($stmt) {
                    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    if ($row && $row['status'] !== 'completada') {
                        throw new Exception("No se pudo actualizar el estado de la factura. Estado actual: " . $row['status']);
                    }
                    sqlsrv_free_stmt($stmt);
                }
            } else {
                error_log("Error al preparar la consulta de actualización: " . print_r(sqlsrv_errors(), true));
                throw new Exception("Error al actualizar el estado de la factura");
            }
        }
        
        // Confirmar transacción
        if ($conn instanceof PDO) {
            $conn->commit();
        } else {
            sqlsrv_commit($conn);
        }
        
        $message = '<div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>La factura #'.$invoice_id.' ha sido marcada como completada y se han eliminado los registros de rechazo.
                    </div>';
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        if ($conn instanceof PDO) {
            $conn->rollback();
        } else {
            sqlsrv_rollback($conn);
        }
        
        error_log("Error al completar factura: " . $e->getMessage());
        $message = '<div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Error al completar la factura: ' . $e->getMessage() . '
                    </div>';
    }
}

// CORREGIDO: Procesar corrección de factura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['correct_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $correction_comments = $_POST['correction_comments'] ?? '';
    
    // Validar que se haya proporcionado un comentario de corrección
    if (empty(trim($correction_comments))) {
        $message = '<div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Debe proporcionar comentarios de corrección.
                    </div>';
    } else {
        try {
            $conn = getDbConnection();
            
            // Iniciar transacción
            if ($conn instanceof PDO) {
                $conn->beginTransaction();
            } else {
                sqlsrv_begin_transaction($conn);
            }
            
            // 1. Actualizar el estado de la factura a 'corregida'
            if ($conn instanceof PDO) {
                $stmt = $conn->prepare("UPDATE invoices SET status = 'corregida' WHERE docnum_interno_sap = ?");
                $result = $stmt->execute([$invoice_id]);
            } else {
                $sql = "UPDATE invoices SET status = 'corregida' WHERE docnum_interno_sap = ?";
                $params = array($invoice_id);
                $stmt = sqlsrv_query($conn, $sql, $params);
                $result = ($stmt !== false);
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            
            if (!$result) {
                throw new Exception("Error al actualizar el estado de la factura");
            }
            
            // 2. Registrar la corrección en la tabla de aprobaciones con action = 'corregida'
            $current_time = date('Y-m-d H:i:s');
            if ($conn instanceof PDO) {
                $stmt = $conn->prepare("INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments, created_at) VALUES (?, ?, ?, 'corregida', ?, ?)");
                $result = $stmt->execute([$invoice_id, $user_id, $role, $correction_comments, $current_time]);
            } else {
                $sql = "INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments, created_at) VALUES (?, ?, ?, 'corregida', ?, ?)";
                $params = array($invoice_id, $user_id, $role, $correction_comments, $current_time);
                $stmt = sqlsrv_query($conn, $sql, $params);
                $result = ($stmt !== false);
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            
            if (!$result) {
                throw new Exception("Error al registrar la corrección");
            }
            
            // 3. Registrar en el log de correcciones (crear tabla si no existe)
            if ($conn instanceof PDO) {
                // Crear tabla si no existe (PDO)
                $conn->exec("CREATE TABLE IF NOT EXISTS correction_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    invoice_id VARCHAR(50) NOT NULL,
                    user_id INT NOT NULL,
                    correction_comments TEXT NOT NULL,
                    correction_time DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                
                $stmt = $conn->prepare("INSERT INTO correction_logs (invoice_id, user_id, correction_comments, correction_time) VALUES (?, ?, ?, ?)");
                $result = $stmt->execute([$invoice_id, $user_id, $correction_comments, $current_time]);
            } else {
                // Crear tabla si no existe (SQL Server)
                $create_table_sql = "
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='correction_logs' AND xtype='U')
                CREATE TABLE correction_logs (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    invoice_id VARCHAR(50) NOT NULL,
                    user_id INT NOT NULL,
                    correction_comments TEXT NOT NULL,
                    correction_time DATETIME NOT NULL,
                    created_at DATETIME DEFAULT GETDATE()
                )";
                sqlsrv_query($conn, $create_table_sql);
                
                $sql = "INSERT INTO correction_logs (invoice_id, user_id, correction_comments, correction_time) VALUES (?, ?, ?, ?)";
                $params = array($invoice_id, $user_id, $correction_comments, $current_time);
                $stmt = sqlsrv_query($conn, $sql, $params);
                $result = ($stmt !== false);
                if ($stmt) sqlsrv_free_stmt($stmt);
            }
            
            // Confirmar transacción
            if ($conn instanceof PDO) {
                $conn->commit();
            } else {
                sqlsrv_commit($conn);
            }
            
            $message = '<div class="alert alert-success">
                            <i class="fas fa-edit me-2"></i>La factura #'.$invoice_id.' ha sido marcada como corregida exitosamente.
                        </div>';
            
        } catch (Exception $e) {
            // Revertir transacción en caso de error
            if ($conn instanceof PDO) {
                $conn->rollback();
            } else {
                sqlsrv_rollback($conn);
            }
            
            error_log("Error al corregir factura: " . $e->getMessage());
            $message = '<div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>Error al corregir la factura: ' . $e->getMessage() . '
                        </div>';
        }
    }
}

// Inicializar filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_rejected_by = $_GET['filter_rejected_by'] ?? '';

// Obtener lista de proveedores para el filtro
$suppliers = getAllSuppliers();

// Obtener lista de usuarios que han rechazado facturas
$rejectors = getAllRejectors();

// Obtener facturas rechazadas con filtros
$rejected_invoices = getRejectedInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_rejected_by);

// Función para obtener todos los proveedores únicos
function getAllSuppliers() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT nombre FROM invoices WHERE status = 'rechazado' ORDER BY nombre ASC";
    
    try {
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Usar funciones nativas de sqlsrv
            $stmt = sqlsrv_query($conn, $sql);
            if ($stmt === false) {
                error_log("Error en getAllSuppliers: " . print_r(sqlsrv_errors(), true));
                return array();
            }
            
            $results = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = $row['nombre'];
            }
            sqlsrv_free_stmt($stmt);
            return $results;
        }
    } catch (Exception $e) {
        error_log("Error en getAllSuppliers: " . $e->getMessage());
        return array();
    }
}

// Función para obtener todos los usuarios que han rechazado facturas
function getAllRejectors() {
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT u.id, u.name 
            FROM invoice_approvals a 
            JOIN users u ON a.user_id = u.id 
            WHERE a.action = 'reject' 
            ORDER BY u.name ASC";
    
    try {
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } else {
            // Usar funciones nativas de sqlsrv
            $stmt = sqlsrv_query($conn, $sql);
            if ($stmt === false) {
                error_log("Error en getAllRejectors: " . print_r(sqlsrv_errors(), true));
                return array();
            }
            
            $results = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $results[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            return $results;
        }
    } catch (Exception $e) {
        error_log("Error en getAllRejectors: " . $e->getMessage());
        return array();
    }
}

// CORREGIDO: Función para obtener facturas rechazadas (solo status = 'rechazado')
function getRejectedInvoices($supplier = '', $date_from = '', $date_to = '', $rejected_by = '') {
    $conn = getDbConnection();
    
    try {
        // Base SQL query - IMPORTANTE: Solo mostrar facturas con status = 'rechazado'
        $sql = "SELECT DISTINCT i.* FROM invoices i";
        
        // Si hay filtro por quien rechazó, necesitamos un JOIN
        if (!empty($rejected_by)) {
            $sql .= " JOIN invoice_approvals a ON i.docnum_interno_sap = a.invoice_id 
                      JOIN users u ON a.user_id = u.id";
        }
        
        // CRÍTICO: Solo mostrar facturas rechazadas, no las corregidas
        $sql .= " WHERE i.status = 'rechazado'";
        
        $params = array();
        
        // Si hay filtro por quien rechazó
        if (!empty($rejected_by)) {
            $sql .= " AND a.action = 'reject' AND a.user_id = ?";
            $params[] = $rejected_by;
        }
        
        // Añadir filtro de proveedor si está presente
        if (!empty($supplier)) {
            $sql .= " AND i.nombre = ?";
            $params[] = $supplier;
        }
        
        // Añadir filtro de fecha desde si está presente
        if (!empty($date_from)) {
            $sql .= " AND i.fecha_vencimiento >= ?";
            $params[] = $date_from;
        }
        
        // Añadir filtro de fecha hasta si está presente
        if (!empty($date_to)) {
            $sql .= " AND i.fecha_vencimiento <= ?";
            $params[] = $date_to;
        }
        
        // Ordenar por fecha descendente
        $sql .= " ORDER BY i.fecha_vencimiento DESC";
        
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } else {
            // Usar funciones nativas de sqlsrv
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                error_log("Error en getRejectedInvoices: " . print_r(sqlsrv_errors(), true));
                return array();
            }
            
            $invoices = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            return $invoices;
        }
    } catch (Exception $e) {
        error_log("Error en getRejectedInvoices: " . $e->getMessage());
        return array();
    }
}


?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Rechazadas - Sistema de Aprobación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Añadir Bootstrap Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
    <!-- Añadir Tippy.js para tooltips mejorados -->
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css" />
    <style>
        /* Estilos adicionales para hacer los botones más visibles */
        .btn-completar {
            font-weight: bold;
            padding: 8px 15px;
            margin-left: 5px;
        }
        
        .btn-corregir {
            font-weight: bold;
            padding: 8px 15px;
            margin-left: 5px;
            background-color: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }
        
        .btn-corregir:hover {
            background-color: #ffca2c;
            border-color: #ffc720;
            color: #212529;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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
                    <h1 class="h2">Facturas Rechazadas</h1>
                </div>
                
                <!-- Mensaje de resultado -->
                <?php echo $message; ?>
                
                <!-- Filtros -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Filtros de Búsqueda</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-3">
                                <label for="filter_supplier" class="form-label">Proveedor</label>
                                <select class="form-select" id="filter_supplier" name="filter_supplier">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="filter_rejected_by" class="form-label">Rechazada por</label>
                                <select class="form-select" id="filter_rejected_by" name="filter_rejected_by">
                                    <option value="">Todos los usuarios</option>
                                    <?php foreach ($rejectors as $rejector): ?>
                                        <option value="<?php echo $rejector['id']; ?>" <?php echo ($filter_rejected_by == $rejector['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($rejector['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="filter_date_from" class="form-label">Fecha desde</label>
                                <input type="date" class="form-control datepicker" id="filter_date_from" name="filter_date_from" value="<?php echo $filter_date_from; ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="filter_date_to" class="form-label">Fecha hasta</label>
                                <input type="date" class="form-control datepicker" id="filter_date_to" name="filter_date_to" value="<?php echo $filter_date_to; ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i> Filtrar
                                    </button>
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo me-1"></i> Limpiar
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Listado de Facturas Rechazadas</h5>
        <button class="btn btn-sm btn-light" id="exportBtn" title="Exportar a Excel">
            <i class="fas fa-file-excel me-1"></i> Exportar
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
                <table class="table table-striped table-hover" id="invoicesTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Valor</th>
                            <th>Rechazada por</th>
                            <th>Fecha Rechazo</th>
                            <th>Motivo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $conn = getDbConnection();
                        foreach ($filtered_invoices as $invoice): 
                            if ($invoice['ESTADOSAP'] !== 'O') {
                                continue;
                            }
                            
                            try {
                                if ($conn instanceof PDO) {
                                    $stmt = $conn->prepare("
                                        SELECT a.*, a.created_at as rejection_date, u.name as user_name, u.role as user_role, a.comments as rejection_reason
                                        FROM invoice_approvals a
                                        JOIN users u ON a.user_id = u.id
                                        WHERE a.invoice_id = ? AND a.action = 'reject'
                                        ORDER BY a.created_at DESC LIMIT 1
                                    ");
                                    $stmt->execute([$invoice['docnum_interno_sap']]);
                                    $rejection = $stmt->fetch();
                                } else {
                                    $sql = "
                                        SELECT TOP 1 a.*, a.created_at as rejection_date, u.name as user_name, u.role as user_role, a.comments as rejection_reason
                                        FROM invoice_approvals a
                                        JOIN users u ON a.user_id = u.id
                                        WHERE a.invoice_id = ? AND a.action = 'reject'
                                        ORDER BY a.created_at DESC
                                    ";
                                    $params = array($invoice['docnum_interno_sap']);
                                    $stmt = sqlsrv_query($conn, $sql, $params);
                                    if ($stmt === false) {
                                        throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
                                    }
                                    $rejection = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                                    sqlsrv_free_stmt($stmt);
                                }
                            } catch (Exception $e) {
                                error_log("Error al obtener detalles del rechazo: " . $e->getMessage());
                                $rejection = null;
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?></td>
                                <td><?php echo formatDate($invoice['fecha_vencimiento']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['nombre']); ?></td>
                                <td>$<?php echo number_format($invoice['saldo_pendiente'], 2, ',', '.'); ?></td>
                                <td>
                                    <?php if ($rejection): ?>
                                        <?php echo htmlspecialchars($rejection['user_name']); ?> (<?php echo ucfirst($rejection['user_role']); ?>)
                                    <?php else: ?>
                                        <span class="text-muted">No disponible</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rejection): ?>
                                        <?php echo formatDateTime($rejection['rejection_date']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">No disponible</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rejection && !empty($rejection['rejection_reason'])): ?>
                                        <span class="d-inline-block text-truncate" style="max-width: 150px;" data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($rejection['rejection_reason']); ?>">
                                            <?php echo htmlspecialchars($rejection['rejection_reason']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">No disponible</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons d-flex gap-2">
                                        <a href="view_invoice.php?docnum_interno_sap=<?php echo $invoice['docnum_interno_sap']; ?>" class="btn btn-info btn-sm" title="Ver detalles">
                                            <i class="fas fa-eye"></i> Ver
                                        </a>
                                        <?php if (in_array($role, ['admin', 'gerente', 'contador', 'Preparador'])): ?>
                                            <button type="button" class="btn btn-warning btn-sm correct-invoice-btn" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#correctInvoiceModal" 
                                                    data-invoice-id="<?php echo $invoice['docnum_interno_sap']; ?>"
                                                    data-invoice-supplier="<?php echo htmlspecialchars($invoice['nombre']); ?>"
                                                    title="Corregir factura">
                                                <i class="fas fa-edit me-1"></i> Corregir
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <p class="text-muted">
                    <i class="fas fa-list me-1"></i> Mostrando <?php echo count($filtered_invoices); ?> facturas rechazadas
                    <?php if (!empty($filter_supplier) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_rejected_by)): ?>
                        con los filtros aplicados
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                No hay facturas rechazadas
                <?php if (!empty($filter_supplier) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_rejected_by)): ?>
                    con los filtros aplicados.
                <?php else: ?>
                    en el sistema.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
                
                <!-- Instrucciones para cambiar estado -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Instrucciones para gestionar facturas rechazadas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-edit text-warning me-2"></i>Para corregir una factura:</h6>
                                <ol>
                                    <li>Haga clic en el botón <span class="badge bg-warning text-dark">Corregir</span>.</li>
                                    <li>Ingrese los comentarios de corrección detallados.</li>
                                    <li>Confirme la acción para cambiar el estado a "Corregida".</li>
                                    <li>La factura aparecerá en el historial como "Corregida".</li>
                                </ol>
                            </div>
                       
                        </div>
                        <div class="mt-3">

                            <p class="text-muted"><strong>Importante:</strong> Las facturas corregidas estarán disponibles para nueva aprobación en el sistema.</p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal para corregir factura -->
    <div class="modal fade" id="correctInvoiceModal" tabindex="-1" aria-labelledby="correctInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="correctInvoiceModalLabel">
                            <i class="fas fa-edit me-2"></i>Corregir Factura
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Información:</strong> Al corregir esta factura, su estado cambiará a "Corregida" y aparecerá en el historial como "Corregida".
                        </div>
                        
                        <div class="mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Factura ID:</strong><br>
                                    <span id="correctInvoiceIdText" class="text-primary"></span>
                                </div>
                                <div class="col-md-6">
                                    <strong>Proveedor:</strong><br>
                                    <span id="correctInvoiceSupplierText" class="text-primary"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="correction_comments" class="form-label">
                                <i class="fas fa-comment-alt me-1"></i>
                                Comentarios de corrección <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="correction_comments" name="correction_comments" 
                                      rows="4" required 
                                      placeholder="Describa detalladamente las correcciones realizadas en esta factura..."></textarea>
                            <div class="form-text">
                                Estos comentarios serán visibles en el historial de aprobaciones como "Corregida".
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="confirmCorrection" required>
                            <label class="form-check-label" for="confirmCorrection">
                                <strong>Confirmo que he realizado las correcciones necesarias en esta factura</strong>
                            </label>
                        </div>
                        
                        <input type="hidden" name="invoice_id" id="correctInvoiceIdInput" value="">
                        <input type="hidden" name="correct_invoice" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-edit me-1"></i> Marcar como Corregida
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal para marcar como completada -->
    <div class="modal fade" id="markCompletedModal" tabindex="-1" aria-labelledby="markCompletedModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="markCompletedModalLabel">Marcar factura como completada</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>¿Está seguro que desea marcar la factura <strong id="invoiceIdText"></strong> del proveedor <strong id="invoiceSupplierText"></strong> como completada?</p>
                        <p>Esta acción cambiará el estado de la factura de "Rechazada" a "Completada" y eliminará los registros de rechazo asociados.</p>
                        
                        <input type="hidden" name="invoice_id" id="invoiceIdInput" value="">
                        <input type="hidden" name="mark_completed" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-check me-1"></i> Marcar como completada
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/locales/bootstrap-datepicker.es.min.js"></script>
    <!-- Añadir SheetJS para exportación a Excel -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar datepicker para los campos de fecha
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            language: 'es',
            autoclose: true,
            todayHighlight: true
        });
        
        // Inicializar tooltips de Bootstrap
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Configurar el modal para marcar como completada
        const markCompletedBtns = document.querySelectorAll('.mark-completed-btn');
        markCompletedBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const invoiceId = this.getAttribute('data-invoice-id');
                const invoiceSupplier = this.getAttribute('data-invoice-supplier');
                
                document.getElementById('invoiceIdInput').value = invoiceId;
                document.getElementById('invoiceIdText').textContent = invoiceId;
                document.getElementById('invoiceSupplierText').textContent = invoiceSupplier;
            });
        });
        
        // Configurar el modal para corregir factura
        const correctInvoiceBtns = document.querySelectorAll('.correct-invoice-btn');
        correctInvoiceBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const invoiceId = this.getAttribute('data-invoice-id');
                const invoiceSupplier = this.getAttribute('data-invoice-supplier');
                
                document.getElementById('correctInvoiceIdInput').value = invoiceId;
                document.getElementById('correctInvoiceIdText').textContent = invoiceId;
                document.getElementById('correctInvoiceSupplierText').textContent = invoiceSupplier;
                
                // Limpiar el textarea y checkbox
                document.getElementById('correction_comments').value = '';
                document.getElementById('confirmCorrection').checked = false;
            });
        });
        
        // Validación del formulario de corrección
        document.querySelector('#correctInvoiceModal form').addEventListener('submit', function(e) {
            const comments = document.getElementById('correction_comments').value.trim();
            const confirm = document.getElementById('confirmCorrection').checked;
            
            if (!comments || comments.length < 10) {
                e.preventDefault();
                alert('Debe proporcionar comentarios detallados de corrección (mínimo 10 caracteres).');
                return false;
            }
            
            if (!confirm) {
                e.preventDefault();
                alert('Debe confirmar que ha realizado las correcciones necesarias.');
                return false;
            }
        });
        
        // Función para exportar a Excel
        document.getElementById('exportBtn').addEventListener('click', function() {
            // Crear una copia de la tabla sin la columna de acciones
            const table = document.getElementById('invoicesTable');
            const tableClone = table.cloneNode(true);
            
            // Eliminar la última columna (Acciones) de cada fila
            const rows = tableClone.querySelectorAll('tr');
            rows.forEach(row => {
                const cells = row.querySelectorAll('th, td');
                if (cells.length > 0) {
                    row.removeChild(cells[cells.length - 1]);
                }
            });
            
            // Convertir la tabla a una hoja de cálculo
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(tableClone);
            
            // Añadir la hoja al libro
            XLSX.utils.book_append_sheet(wb, ws, "Facturas Rechazadas");
            
            // Generar el nombre del archivo con la fecha actual
            const now = new Date();
            const fileName = `facturas_rechazadas_${now.getFullYear()}${(now.getMonth()+1).toString().padStart(2, '0')}${now.getDate().toString().padStart(2, '0')}.xlsx`;
            
            // Descargar el archivo
            XLSX.writeFile(wb, fileName);
        });
    });
    </script>
</body>
</html>
