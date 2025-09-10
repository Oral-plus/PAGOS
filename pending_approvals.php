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

// Procesar formulario de aprobación si se ha enviado
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $comments = $_POST['comments'] ?? '';
    
    // Verificar que el usuario haya visto los detalles de la factura
    if (!hasUserViewedInvoice($invoice_id, $user_id)) {
        $_SESSION['error_message'] = "Debe ver los detalles de la factura antes de aprobarla";
    }
    // Verificar que se haya confirmado la aprobación
    elseif (!isset($_POST['confirm_approval'])) {
        $_SESSION['error_message'] = "Debe confirmar la aprobación de la factura";
    }
    else {
        // Usar la función estándar para aprobar facturas
        $result = approveInvoice($invoice_id, $user_id, $role, $comments);
        
        if ($result) {
            // Registrar la hora exacta de la aprobación
            logApprovalTime($invoice_id, $user_id, date('Y-m-d H:i:s'));
            
            // Si es subgerente y la factura estaba corregida, actualizar el estado a aprobado
            if ($role === 'subgerente') {
                $conn = getDbConnection();
                
                // Verificar si la factura estaba corregida
                $was_corrected = false;
                
                if ($conn instanceof PDO) {
                    $stmt = $conn->prepare("SELECT DISTINCT COUNT(*) as count FROM invoice_approvals WHERE invoice_id = ? AND action = 'corregida'");
                    $stmt->execute([$invoice_id]);
                    $result = $stmt->fetch();
                    $was_corrected = ($result['count'] > 0);
                } else {
                    $sql = "SELECT DISTINCT COUNT(*) as count FROM invoice_approvals WHERE invoice_id = ? AND action = 'corregida'";
                    $params = array($invoice_id);
                    $stmt = sqlsrv_query($conn, $sql, $params);
                    if ($stmt !== false) {
                        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                        $was_corrected = ($row['count'] > 0);
                        sqlsrv_free_stmt($stmt);
                    }
                }
                
                // Si estaba corregida, actualizar a aprobado
                if ($was_corrected) {
                    if ($conn instanceof PDO) {
                        $stmt = $conn->prepare("UPDATE invoices SET status = 'aprobado' WHERE docnum_interno_sap = ?");
                        $stmt->execute([$invoice_id]);
                    } else {
                        $sql = "UPDATE invoices SET status = 'aprobado' WHERE docnum_interno_sap = ?";
                        $params = array($invoice_id);
                        $stmt = sqlsrv_query($conn, $sql, $params);
                        if ($stmt !== false) {
                            sqlsrv_free_stmt($stmt);
                        }
                    }
                }
            }
            
            $_SESSION['success_message'] = "Factura #$invoice_id aprobada correctamente";
        } else {
            $_SESSION['error_message'] = "Error al aprobar la factura #$invoice_id";
        }
    }
    
    // Redireccionar para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// NUEVA FUNCIONALIDAD: Procesar formulario de rechazo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $reject_reason = $_POST['reject_reason'] ?? '';
    
    // Verificar que el usuario haya visto los detalles de la factura
    if (!hasUserViewedInvoice($invoice_id, $user_id)) {
        $_SESSION['error_message'] = "Debe ver los detalles de la factura antes de rechazarla";
    }
    // Verificar que se haya proporcionado una razón de rechazo
    elseif (empty($reject_reason)) {
        $_SESSION['error_message'] = "Debe proporcionar una razón para el rechazo";
    }
    // Verificar que se haya confirmado el rechazo
    elseif (!isset($_POST['confirm_rejection'])) {
        $_SESSION['error_message'] = "Debe confirmar el rechazo de la factura";
    }
    else {
        // Procesar el rechazo
        $result = rejectInvoice($invoice_id, $user_id, $role, $reject_reason);
        
        if ($result) {
            $_SESSION['success_message'] = "Factura #$invoice_id rechazada correctamente";
        } else {
            $_SESSION['error_message'] = "Error al rechazar la factura #$invoice_id";
        }
    }
    
    // Redireccionar para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Inicializar filtros
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
// MODIFICADO: Filtro para mostrar solo facturas de hoy (para todos los roles)
$filter_today_only = $_GET['filter_today_only'] ?? '';
// NUEVO: Filtro para facturas aprobadas de hoy
$filter_approved_today = $_GET['filter_approved_today'] ?? '';

// NUEVO: Filtros de fecha de creación y aprobación
$filter_creation_date_from = $_GET['filter_creation_date_from'] ?? '';
$filter_creation_date_to = $_GET['filter_creation_date_to'] ?? '';
$filter_approval_date_from = $_GET['filter_approval_date_from'] ?? '';
$filter_approval_date_to = $_GET['filter_approval_date_to'] ?? '';

// Obtener lista de proveedores para el filtro
$suppliers = getAllSuppliers();

// Obtener facturas pendientes filtrando las que el usuario actual ya ha aprobado
$pending_invoices = getPendingInvoicesForCurrentUser($user_id, $role, $filter_supplier, $filter_date_from, $filter_date_to, $filter_today_only, $filter_creation_date_from, $filter_creation_date_to);

// Calcular el total de las facturas filtradas
// MODIFICADO: Obtener facturas ya aprobadas por el usuario actual con filtro de hoy
$approved_invoices = getApprovedInvoicesByUser($user_id, $filter_supplier, $filter_date_from, $filter_date_to, $filter_approved_today, $filter_creation_date_from, $filter_creation_date_to, $filter_approval_date_from, $filter_approval_date_to);

// NUEVO: Calcular totales por proveedor para facturas aprobadas
// ====== FACTURAS PENDIENTES - EVITAR DUPLICADOS ======
$supplier_totals_pending = [];
$supplier_totals_approved = [];
$seenDocNums = []; // Para rastrear TODAS las facturas ya procesadas

// Primero procesamos las facturas aprobadas (solo las que no tienen ESTADOSAP = "C")
foreach ($approved_invoices as $invoice) {
    if (!in_array($invoice['docnum_interno_sap'], $seenDocNums) && $invoice['ESTADOSAP'] != 'C') {
        $supplier_name = $invoice['nombre'];
        
        if (!isset($supplier_totals_approved[$supplier_name])) {
            $supplier_totals_approved[$supplier_name] = [
                'total' => 0,
                'count' => 0
            ];
        }
        
        $supplier_totals_approved[$supplier_name]['total'] += $invoice['saldo_pendiente'];
        $supplier_totals_approved[$supplier_name]['count']++;
        
        // Marcar esta factura como procesada
        $seenDocNums[] = $invoice['docnum_interno_sap'];
    }
}

// Luego procesamos las facturas pendientes (solo las que no tienen ESTADOSAP = "C")
foreach ($pending_invoices as $invoice) {
    if (!in_array($invoice['docnum_interno_sap'], $seenDocNums) && $invoice['ESTADOSAP'] != 'C') {
        $supplier_name = $invoice['nombre'];
        
        if (!isset($supplier_totals_pending[$supplier_name])) {
            $supplier_totals_pending[$supplier_name] = [
                'total' => 0,
                'count' => 0
            ];
        }
        
        $supplier_totals_pending[$supplier_name]['total'] += $invoice['saldo_pendiente'];
        $supplier_totals_pending[$supplier_name]['count']++;
        
        // Marcar esta factura como procesada
        $seenDocNums[] = $invoice['docnum_interno_sap'];
    }
}

// NUEVA FUNCIÓN: Rechazar factura
// MODIFICADO: Función para obtener todos los proveedores únicos - ORDENADOS ALFABÉTICAMENTE
function getAllSuppliers() {
    $conn = getDbConnection();
    // CAMBIO PRINCIPAL: Ordenar por nombre ASC para orden alfabético
    $sql = "SELECT DISTINCT nombre FROM invoices WHERE nombre IS NOT NULL AND nombre != '' ORDER BY nombre ASC";
    
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

// FUNCIÓN MODIFICADA: Obtener facturas pendientes para el usuario actual - CORREGIDO EL FILTRO DE HOY
function getPendingInvoicesForCurrentUser($user_id, $user_role, $supplier = '', $date_from = '', $date_to = '', $today_only = '', $creation_date_from = '', $creation_date_to = '') {
    $conn = getDbConnection();
    $invoices = array();
    try {
        // Base SQL query - Modificado para incluir lógica específica para verificador
        if ($user_role === 'subgerente') {
            // Para subgerente: mostrar facturas aprobadas por contador/verificador y facturas corregidas
            $sql = "SELECT i.*,
                     DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM invoice_approvals 
                            WHERE invoice_id = i.docnum_interno_sap 
                            AND action = 'reject'
                        ) THEN 'rechazada'
                        WHEN EXISTS (
                            SELECT 1 FROM invoice_approvals 
                            WHERE invoice_id = i.docnum_interno_sap 
                            AND action = 'corregida'
                            AND NOT EXISTS (
                                SELECT 1 FROM invoice_approvals 
                                WHERE invoice_id = i.docnum_interno_sap 
                                AND action = 'approve' 
                                AND user_role = 'subgerente'
                            )
                        ) THEN 'corregida'
                        ELSE i.status
                    END as display_status
                    FROM invoices i 
                    WHERE (
                        -- Facturas aprobadas por contador o verificador
                        (i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE user_role IN ('contador', 'verificador') AND action = 'approve'
                        ))
                        OR
                        -- Facturas corregidas que no han sido aprobadas por el subgerente
                        (i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE action = 'corregida'
                            AND invoice_id NOT IN (
                                SELECT invoice_id FROM invoice_approvals 
                                WHERE action = 'approve' AND user_role = 'subgerente'
                            )
                        ))
                        OR
                        -- Facturas rechazadas
                        (i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE action = 'reject'
                        ))
                    )
                    AND i.docnum_interno_sap NOT IN (
                        SELECT invoice_id FROM invoice_approvals 
                        WHERE user_id = ? AND action = 'approve' AND user_role = 'subgerente'
                    )";
            $params = array($user_id);
        } elseif ($user_role === 'verificador') {
            // NUEVO: Para verificador: mostrar solo facturas aprobadas por contador
            $sql = "SELECT i.*,
                     DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM invoice_approvals 
                            WHERE invoice_id = i.docnum_interno_sap 
                            AND action = 'reject'
                        ) THEN 'rechazada'
                        ELSE i.status
                    END as display_status
                    FROM invoices i 
                    WHERE (
                        (i.ok = 'ok'
                        AND i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE user_role = 'contador' AND action = 'approve'
                        )
                        AND i.docnum_interno_sap NOT IN (
                            SELECT invoice_id FROM approval_logs WHERE user_id = ?
                        ))
                        OR
                        (i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE action = 'reject'
                        ))
                    )";
            $params = array($user_id);
        } else {
            // Para otros roles (contador, etc.): mostrar facturas con ok = 'ok' y no aprobadas por el usuario actual
            $sql = "SELECT i.*,
                     DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM invoice_approvals 
                            WHERE invoice_id = i.docnum_interno_sap 
                            AND action = 'reject'
                        ) THEN 'rechazada'
                        ELSE i.status
                    END as display_status
                    FROM invoices i 
                    WHERE (
                        (i.ok = 'ok'
                        AND i.docnum_interno_sap NOT IN (
                            SELECT invoice_id FROM approval_logs WHERE user_id = ?
                        ))
                        OR
                        (i.docnum_interno_sap IN (
                            SELECT invoice_id FROM invoice_approvals 
                            WHERE action = 'reject'
                        ))
                    )";
            $params = array($user_id);
        }
        
        // CORREGIDO: Filtro específico para mostrar solo facturas de hoy (para todos los roles)
        if (!empty($today_only) && $today_only === '1') {
            if ($user_role === 'subgerente') {
                // Para subgerente: filtrar por facturas aprobadas por contador/verificador hoy
                $sql .= " AND EXISTS (
                    SELECT 1 FROM invoice_approvals ia 
                    WHERE ia.invoice_id = i.docnum_interno_sap 
                    AND ia.user_role IN ('contador', 'verificador')
                    AND ia.action = 'approve'
                    AND CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())
                )";
            } elseif ($user_role === 'verificador') {
                // Para verificador: filtrar por facturas aprobadas por contador hoy
                $sql .= " AND EXISTS (
                    SELECT 1 FROM invoice_approvals ia 
                    WHERE ia.invoice_id = i.docnum_interno_sap 
                    AND ia.user_role = 'contador'
                    AND ia.action = 'approve'
                    AND CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())
                )";
            } else {
                // Para contador: filtrar por facturas marcadas como 'ok' hoy
                $sql .= " AND CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())";
            }
        }
        
        // NUEVO: Filtros de fecha de creación
        if (!empty($creation_date_from)) {
            $sql .= " AND CONVERT(DATE, i.created_at) >= ?";
            $params[] = $creation_date_from;
        }
        
        if (!empty($creation_date_to)) {
            $sql .= " AND CONVERT(DATE, i.created_at) <= ?";
            $params[] = $creation_date_to;
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
        
        // MODIFICADO: Ordenar por proveedor alfabéticamente y luego por días de antigüedad
        $sql .= " ORDER BY i.nombre ASC, dias_antiguedad DESC";
        
        // Para depuración
        error_log("SQL Query: " . $sql);
        error_log("Params: " . print_r($params, true));
        
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            // Para PDO, necesitamos modificar la consulta para usar la función DATE_DIFF de MySQL
            $sql = str_replace("DATEDIFF(day, i.fecha_vencimiento, GETDATE())",
                               "DATEDIFF(CURDATE(), i.fecha_vencimiento)", $sql);
            $sql = str_replace("CONVERT(DATE, GETDATE())", "CURDATE()", $sql);
            $sql = str_replace("CONVERT(DATE, i.created_at)", "DATE(i.created_at)", $sql);
            $sql = str_replace("CONVERT(DATE, ia.created_at)", "DATE(ia.created_at)", $sql);
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("Error al preparar la consulta: " . print_r($conn->errorInfo(), true));
                return $invoices;
            }
            
            $result = $stmt->execute($params);
            if (!$result) {
                error_log("Error al ejecutar la consulta: " . print_r($stmt->errorInfo(), true));
                return $invoices;
            }
            
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Usar funciones nativas de sqlsrv
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                error_log("Error en la consulta sqlsrv: " . print_r(sqlsrv_errors(), true));
                return $invoices;
            }
            
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        
        // Para depuración
        error_log("Número de facturas encontradas: " . count($invoices));
        
        return $invoices;
    } catch (Exception $e) {
        error_log("Error en getPendingInvoicesForCurrentUser: " . $e->getMessage());
        return $invoices;
    }
}

// FUNCIÓN MODIFICADA: Obtener facturas ya aprobadas por el usuario actual - ORDENADAS POR PROVEEDOR Y FECHA
function getApprovedInvoicesByUser($user_id, $supplier = '', $date_from = '', $date_to = '', $approved_today = '', $creation_date_from = '', $creation_date_to = '', $approval_date_from = '', $approval_date_to = '') {
    $conn = getDbConnection();
    $invoices = array();
    try {
        // Base SQL query - Obtener facturas aprobadas por el usuario actual
        $sql = "SELECT DISTINCT i.*, al.approval_time,
                DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad 
                FROM invoices i 
                INNER JOIN approval_logs al ON i.docnum_interno_sap = al.invoice_id
                WHERE al.user_id = ?";
        $params = array($user_id);
        
        // NUEVO: Filtro para mostrar solo facturas aprobadas hoy
        if (!empty($approved_today) && $approved_today === '1') {
            $sql .= " AND CAST(al.approval_time AS DATE) = CAST(GETDATE() AS DATE)";
        }
        
        // NUEVO: Filtros de fecha de creación
        if (!empty($creation_date_from)) {
            $sql .= " AND CONVERT(DATE, i.created_at) >= ?";
            $params[] = $creation_date_from;
        }
        
        if (!empty($creation_date_to)) {
            $sql .= " AND CONVERT(DATE, i.created_at) <= ?";
            $params[] = $creation_date_to;
        }
        
        // NUEVO: Filtros de fecha de aprobación
        if (!empty($approval_date_from)) {
            $sql .= " AND CONVERT(DATE, al.approval_time) >= ?";
            $params[] = $approval_date_from;
        }
        
        if (!empty($approval_date_to)) {
            $sql .= " AND CONVERT(DATE, al.approval_time) <= ?";
            $params[] = $approval_date_to;
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
        
        // MODIFICADO: Ordenar por proveedor alfabéticamente y luego por fecha de aprobación descendente
        $sql .= " ORDER BY i.nombre ASC, al.approval_time DESC";
        
        // Limitar a 50 resultados para mejor rendimiento
        $sql .= " LIMIT 1000";
        
        // Para depuración
        error_log("SQL Query Approved: " . $sql);
        error_log("Params Approved: " . print_r($params, true));
        
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            // Para PDO, necesitamos modificar la consulta para usar la función DATE_DIFF de MySQL
            $sql = str_replace("DATEDIFF(day, i.fecha_vencimiento, GETDATE())",
                               "DATEDIFF(CURDATE(), i.fecha_vencimiento)", $sql);
            $sql = str_replace("CAST(GETDATE() AS DATE)", "CURDATE()", $sql);
            $sql = str_replace("CAST(al.approval_time AS DATE)", "DATE(al.approval_time)", $sql);
            $sql = str_replace("CONVERT(DATE, i.created_at)", "DATE(i.created_at)", $sql);
            $sql = str_replace("CONVERT(DATE, al.approval_time)", "DATE(al.approval_time)", $sql);
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("Error al preparar la consulta: " . print_r($conn->errorInfo(), true));
                return $invoices;
            }
            
            $result = $stmt->execute($params);
            if (!$result) {
                error_log("Error al ejecutar la consulta: " . print_r($stmt->errorInfo(), true));
                return $invoices;
            }
            
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Usar funciones nativas de sqlsrv
            // Nota: LIMIT no funciona en SQL Server, usar TOP
            $sql = str_replace("LIMIT 1000", "", $sql);
            $sql = str_replace("SELECT DISTINCT i.*, al.approval_time,", "SELECT TOP 1000 i.*, al.approval_time,", $sql);
            
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                error_log("Error en la consulta sqlsrv: " . print_r(sqlsrv_errors(), true));
                return $invoices;
            }
            
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        
        // Para depuración
        error_log("Número de facturas aprobadas encontradas: " . count($invoices));
        
        return $invoices;
    } catch (Exception $e) {
        error_log("Error en getApprovedInvoicesByUser: " . $e->getMessage());
        return $invoices;
    }
}

// Función para verificar si un usuario ha visto los detalles de una factura
function hasUserViewedInvoice($invoice_id, $user_id) {
    // MODIFICADO: Verificar realmente si el usuario ha visto la factura
    $conn = getDbConnection();
    $sql = "SELECT DISTINCT COUNT(*) as viewed FROM invoice_views WHERE invoice_id = ? AND user_id = ?";
    $params = array($invoice_id, $user_id);
    
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return ($result['viewed'] > 0);
        } else {
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
            }
            
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            return ($row['viewed'] > 0);
        }
    } catch (Exception $e) {
        error_log("Error en hasUserViewedInvoice: " . $e->getMessage());
        return false;
    }
}

// Función para registrar la hora exacta de aprobación
function logApprovalTime($invoice_id, $user_id, $timestamp) {
    $conn = getDbConnection();
    $sql = "INSERT INTO approval_logs (invoice_id, user_id, approval_time) VALUES (?, ?, ?)";
    $params = array($invoice_id, $user_id, $timestamp);
    
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return ($stmt->rowCount() > 0);
        } else {
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                error_log("Error al insertar en approval_logs: " . print_r(sqlsrv_errors(), true));
                return false;
            }
            
            $affected = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            return ($affected > 0);
        }
    } catch (Exception $e) {
        error_log("Error en logApprovalTime: " . $e->getMessage());
        return false;
    }
}

// Función para formatear la fecha y hora de aprobación
function formatApprovalTime($timestamp) {
    if (is_object($timestamp) && method_exists($timestamp, 'format')) {
        return $timestamp->format('d/m/Y H:i:s');
    } elseif (is_string($timestamp)) {
        return date('d/m/Y H:i:s', strtotime($timestamp));
    } else {
        return 'Fecha no disponible';
    }
}




?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Pendientes de Aprobación - Sistema de Aprobación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <!-- Añadir Bootstrap Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
    <style>
        /* Estilo para la etiqueta de corregido */
        .badge-corregido {
            background-color: #ffc107;
            color: #212529;
        }
        
        /* Agregando estilo específico para facturas rechazadas en rojo */
        .badge-rechazada {
            background-color: #dc3545 !important;
            color: #ffffff !important;
            border: 1px solid #dc3545;
        }
        
        /* MODIFICADO: Estilo para el filtro de hoy - para todos los roles */
        .today-filter-container {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: white;
        }
        
        /* NUEVO: Estilo específico para contador/verificador */
        .today-filter-container.contador-verificador {
            background: linear-gradient(135deg, #17a2b8, #138496);
        }
        
        /* NUEVO: Estilo específico para verificador */
        .today-filter-container.verificador {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
        }
        
        .today-filter-switch {
            transform: scale(1.2);
        }
        
        /* NUEVO: Estilo para el filtro de facturas aprobadas hoy */
        .approved-today-filter {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            color: white;
        }
        
        /* Estilo para destacar facturas aprobadas recientemente */
        .recent-approval {
            background-color: #f8f9fa;
            border-left: 4px solid #28a745;
        }
        
        .approval-time-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            font-weight: 500;
        }
        
        /* NUEVO: Estilo para facturas marcadas como OK hoy */
        .today-ok-invoice {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        
        /* NUEVO: Estilo para agrupar proveedores visualmente */
        .supplier-group {
            background-color: #f8f9fa;
            border-left: 3px solid #007bff;
        }
        
        /* MODIFICADO: Estilo mejorado para el header de proveedor con totales */
        .supplier-header {
            background: linear-gradient(135deg, #e9ecef, #f8f9fa) !important;
            font-weight: bold;
            color: #495057;
            border-top: 3px solid #007bff;
            border-bottom: 1px solid #dee2e6;
        }
        
        .supplier-total-badge {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        
        .supplier-count-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            font-weight: 500;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 0.8em;
        }
        
        /* NUEVO: Estilo para el gran total */
        .grand-total-card {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .grand-total-value {
            font-size: 1.8em;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        
        /* NUEVO: Estilos para los filtros de fecha */
        .date-filters-container {
            background: linear-gradient(135deg, #007bff, #007bff);
            border-radius: 8px;
            padding: 100px;
            margin-bottom: 20px;
            color: white;
        }
        
        .date-filter-group {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .date-filter-group:last-child {
            margin-bottom: 0;
        }
        
        .date-filter-label {
            font-weight: 600;
            margin-bottom: 10px;
            display: block;
        }
        
        .date-input-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .date-input-row input[type="date"] {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            padding: 8px 12px;
            color: #333;
            font-weight: 500;
            min-width: 150px;
            flex: 1;
        }
        
        .date-input-row input[type="date"]:focus {
            background: white;
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .date-separator {
            color: white;
            font-weight: bold;
            font-size: 1.1em;
            padding: 0 10px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
        }
        
        .filter-buttons .btn {
            padding: 8px 20px;
            font-weight: 500;
            border-radius: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .date-input-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .date-separator {
                text-align: center;
                padding: 5px 0;
            }
            
            .filter-buttons {
                flex-direction: column;
            }
        }
        
        /* Nuevos estilos para localStorage mejorado */
        .settings-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 15px;
            z-index: 1050;
            max-width: 1000px;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }
        
        .settings-panel.show {
            transform: translateX(0);
        }
        
        .settings-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1051;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .preference-item {
            margin-bottom: 10px;
            padding: 8px;
            border-radius: 5px;
            background: #f8f9fa;
        }
        
        .auto-save-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 1000;
        }
        
        .auto-save-indicator.show {
            opacity: 1;
        }
        .collapsing {
            transition: height 0.2s ease;
        }
    </style>
</head>

<body>
    <?php include 'includes/header.php'; ?>
    
    <!-- Panel de configuración mejorado -->
    
    <!-- Indicador de auto-guardado -->
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-save me-1"></i>Guardado automáticamente
    </div>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Facturas Pendientes de Aprobación</h1>
                    <div class="badge bg-success text-white p-2">
                        <?php if ($role === 'subgerente'): ?>
                            <i class="fas fa-info-circle me-1"></i> Mostrando facturas aprobadas por contador y verificador y facturas corregidas pendientes de su aprobación
                        <?php elseif ($role === 'verificador'): ?>
                            <i class="fas fa-info-circle me-1"></i> Mostrando facturas aprobadas por contador pendientes de su verificación
                        <?php else: ?>
                            <i class="fas fa-info-circle me-1"></i> Mostrando facturas pendientes de su aprobación
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $_SESSION['success_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <!-- MODIFICADO: Filtro especial para todos los roles - Solo facturas de hoy -->
                <div class="today-filter-container <?php echo ($role === 'contador') ? 'contador-verificador' : (($role === 'verificador') ? 'verificador' : ''); ?>">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-calendar-day me-2"></i>
                                Ver facturas de hoy 
                            </h5>
                            <p class="mb-0 opacity-75">
                                <?php if ($role === 'subgerente'): ?>
                                    Muestra únicamente las facturas que fueron aprobadas por contador/verificador hoy
                                <?php elseif ($role === 'verificador'): ?>
                                    Muestra únicamente las facturas que fueron aprobadas por contador hoy
                                <?php else: ?>
                                    Muestra únicamente las facturas marcadas como OK hoy
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input today-filter-switch" type="checkbox"
                                   id="todayOnlyFilter"
                                   <?php echo (!empty($filter_today_only) && $filter_today_only === '1') ? 'checked' : ''; ?>
                                   onchange="toggleTodayFil ter()">
                            <label class="form-check-label fw-bold" for="todayOnlyFilter">
                                Solo Hoy
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- NUEVO: Filtros de fecha de creación y aprobación -->
        <div class="date-filters">
    <h6><i class="fas fa-calendar-alt"></i> Filtros de Fecha</h6>
    
    <form method="GET" action="" id="dateFiltersForm">
        <!-- Filtros existentes -->
        <input type="hidden" name="filter_supplier" value="<?php echo htmlspecialchars($filter_supplier); ?>">
        <input type="hidden" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
        <input type="hidden" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
        <input type="hidden" name="filter_today_only" value="<?php echo htmlspecialchars($filter_today_only); ?>">
        <input type="hidden" name="filter_approved_today" value="<?php echo htmlspecialchars($filter_approved_today); ?>">
        
        <div class="filter-group">
            <label><i class="fas fa-plus-circle"></i> Creación</label>
            <div class="date-row">
                <input type="date" name="filter_creation_date_from" value="<?php echo htmlspecialchars($filter_creation_date_from); ?>">
                <input type="date" name="filter_creation_date_to" value="<?php echo htmlspecialchars($filter_creation_date_to); ?>">
            </div>
        </div>
        
        <div class="filter-group">
            <label><i class="fas fa-check-circle"></i> Aprobación</label>
            <div class="date-row">
                <input type="date" name="filter_approval_date_from" value="<?php echo htmlspecialchars($filter_approval_date_from); ?>">
                <input type="date" name="filter_approval_date_to" value="<?php echo htmlspecialchars($filter_approval_date_to); ?>">
            </div>
        </div>
        
        <div class="filter-buttons">
            <button type="submit" class="btn btn-sm btn-light">
                <i class="fas fa-filter"></i> Aplicar
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" onclick="clearDateFilters()">
                <i class="fas fa-times"></i> Limpiar
            </button>
        </div>
    </form>
</div>

<style>
.date-filters {
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #f8f9fa;
}

.date-filters h6 {
    margin: 0 0 12px 0;
    font-size: 14px;
    color: #495057;
}

.filter-group {
    margin-bottom: 12px;
}

.filter-group label {
    display: block;
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 4px;
}

.date-row {
    display: flex;
    gap: 8px;
}

.date-row input {
    flex: 1;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 12px;
}

.filter-buttons {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.filter-buttons .btn {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 4px;
}
</style>
                
                <!-- Filtros -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Filtros de Búsqueda</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filtersForm">
                            <!-- NUEVO: Campo oculto para mantener el filtro de hoy -->
                            <input type="hidden" name="filter_today_only" value="<?php echo $filter_today_only; ?>">
                            <input type="hidden" name="filter_approved_today" value="<?php echo $filter_approved_today; ?>">
                            <input type="hidden" name="filter_creation_date_from" value="<?php echo $filter_creation_date_from; ?>">
                            <input type="hidden" name="filter_creation_date_to" value="<?php echo $filter_creation_date_to; ?>">
                            <input type="hidden" name="filter_approval_date_from" value="<?php echo $filter_approval_date_from; ?>">
                            <input type="hidden" name="filter_approval_date_to" value="<?php echo $filter_approval_date_to; ?>">
                            
                            <div class="col-md-4">
                                <label for="filter_supplier" class="form-label">Proveedor</label>
                                <select class="form-select" id="filter_supplier" name="filter_supplier">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier); ?>
                                            <?php if (isset($supplier_totals_pending[$supplier])): ?>
                                                (<?php echo $supplier_totals_pending[$supplier]['count']; ?> facturas - $<?php echo number_format($supplier_totals_pending[$supplier]['total'], 0, ',', '.'); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            Facturas Pendientes de su Aprobación
                            <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                <span class="badge bg-warning text-dark ms-2">
                                    <i class="fas fa-calendar-day me-1"></i>Solo Hoy
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_creation_date_from) || !empty($filter_creation_date_to)): ?>
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-calendar-plus me-1"></i>Filtro Creación
                                </span>
                            <?php endif; ?>
                            <?php if ($role === 'verificador'): ?>
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-user-check me-1"></i>Aprobadas por Contador
                                </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($pending_invoices) > 0): ?>
                            <?php if (!empty($filter_supplier)): ?>
                                
                            <?php endif; ?>
                            
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 120px;">Fecha</th>
                                            <th>Proveedor</th>
                                            <th>N Factura</th>
                                            <th>Nit</th>
                                            <th style="width: 120px;">Días Antigüedad</th>
                                            <th style="width: 120px;">Valor</th>
                                            
                                            <th style="width: 120px;">Estado</th>
                                            <th style="width: 150px;">Acciones</th>
                                        </tr>
                                    </thead>
                                    <?php
// SOLUCIÓN MEJORADA PARA DUPLICADOS - Usamos array asociativo
$uniquePending = [];
foreach ($pending_invoices as $invoice) {
    // Filtrar facturas no canceladas Y no rechazadas, usar docnum como clave única
    if ($invoice['ESTADOSAP'] != 'C' && 
        (!isset($invoice['status']) || $invoice['status'] !== 'rechazada') &&
        (!isset($invoice['display_status']) || $invoice['display_status'] !== 'rechazada')) {
        $uniquePending[$invoice['docnum_interno_sap']] = $invoice;
    }
}
// Convertir a array indexado si es necesario
$uniquePending = array_values($uniquePending);

// --- CONEXIÓN A SQL SERVER ---
$serverName = "HERCULES";
$connectionOptions = [
    "Database" => "invoice_approval_system",
    "Uid" => "sa",
    "PWD" => "Sky2022*!",
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

// Función para formatear fechas
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if ($date instanceof DateTime) {
            return $date->format('d/m/Y');
        }
        try {
            $dt = new DateTime($date);
            return $dt->format('d/m/Y');
        } catch (Exception $e) {
            return $date;
        }
    }
}
?>
<tbody>
<?php 
$currentSupplier = '';
$supplierIndex = 0;
foreach ($uniquePending as $invoice):
    // YA NO ES NECESARIO ESTE FILTRO AQUÍ porque ya se filtró arriba
    // Las facturas rechazadas ya fueron excluidas del array $uniquePending

    $hasViewed = hasUserViewedInvoice($invoice['docnum_interno_sap'], $user_id);
    $isProcessedToday = (!empty($filter_today_only) && $filter_today_only === '1');
    
    // Mostrar separador por proveedor con totales
    if ($currentSupplier !== $invoice['nombre'] && empty($filter_supplier)) {
        $currentSupplier = $invoice['nombre'];
        $supplierTotal = $supplier_totals_pending[$currentSupplier] ?? ['total' => 0, 'count' => 0];
        $supplierIndex++;
        ?>
        <tr class="supplier-header" data-supplier-id="supplier-<?= $supplierIndex ?>">
            <td colspan="9">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm btn-toggle me-2" data-bs-toggle="collapse"
                                data-bs-target=".supplier-<?= $supplierIndex ?>"
                                data-supplier-index="<?= $supplierIndex ?>"
                                aria-expanded="true" aria-controls="supplier-<?= $supplierIndex ?>">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div>
                            <i class="fas fa-building me-2"></i>
                            <strong><?= htmlspecialchars($currentSupplier) ?></strong>
                        </div>
                    </div>
                    <div>
                        <span class="supplier-count-badge me-2">
                            <i class="fas fa-file-invoice me-1"></i>
                            <?= $supplierTotal['count'] ?> facturas
                        </span>
                        <span class="supplier-total-badge">
                            <i class="fas fa-dollar-sign me-1"></i>
                            $<?= number_format($supplierTotal['total'], 2, ',', '.') ?>
                        </span>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }
?>
    <tr class="supplier-<?= $supplierIndex ?> collapse show <?= ($isProcessedToday) ? 'today-ok-invoice' : '' ?> <?= (empty($filter_supplier)) ? 'supplier-group' : '' ?>"
        data-parent="supplier-<?= $supplierIndex ?>">
        <td>
            <?php
            $idRelacionado = $invoice['docnum_interno_sap'];
            $fechaEncontrada = null;
            
            // Buscar fecha en invoices
            $tsql = "SELECT created_at FROM invoices WHERE docnum_interno_sap = ?";
            $params = [$idRelacionado];
            $stmt = sqlsrv_query($conn, $tsql, $params);
            if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $fechaEncontrada = $row['created_at'];
            } else {
                // Buscar en invoice_approvals si no se encontró
                $tsql = "SELECT created_at FROM invoice_approvals WHERE invoice_id = ?";
                $stmt = sqlsrv_query($conn, $tsql, $params);
                
                if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $fechaEncontrada = $row['created_at'];
                }
            }
            
            echo $fechaEncontrada ? formatDate($fechaEncontrada) : "No hay fecha";
            
            if ($isProcessedToday) {
                echo '<br><small class="'.($role === 'subgerente' ? 'text-primary' : ($role === 'verificador' ? 'text-purple' : 'text-info')).'">';
                echo '<i class="fas '.($role === 'subgerente' ? 'fa-star' : ($role === 'verificador' ? 'fa-user-check' : 'fa-check-circle')).'"></i> ';
                echo $role === 'subgerente' ? 'Aprobada hoy' : ($role === 'verificador' ? 'Aprobada por contador hoy' : 'Marcada OK hoy');
                echo '</small>';
            }
            ?>
        </td>
        <td>
            <?= $invoice['nombre'] ?>
            <?php if ($isProcessedToday): ?>
                <span class="badge <?= ($role === 'verificador') ? 'bg-purple' : 'bg-info' ?> ms-1" title="<?= ($role === 'verificador') ? 'Aprobada por contador hoy' : 'Marcada como OK hoy' ?>">
                    <i class="fas <?= ($role === 'verificador') ? 'fa-user-check' : 'fa-calendar-check' ?>"></i>
                </span>
            <?php endif; ?>
        </td>
        <td><?= $invoice['numero_factura_proveedor'] ?></td>
        <td><?= $invoice['codigo_sn'] ?></td>
        <td>
            <span class="badge <?= ($invoice['dias_antiguedad'] < 0) ? 'bg-success' : 'bg-danger' ?>">
                <?= $invoice['dias_antiguedad'] ?> días
            </span>
        </td>
        <td>$<?= number_format($invoice['saldo_pendiente'], 2, ',', '.') ?></td>
        <td>
            <?php if (isset($invoice['display_status']) && $invoice['display_status'] == 'corregida'): ?>
                <span class="badge badge-corregido">
                    <i class="fas fa-check-circle me-1"></i> Corregida
                </span>
            <?php else: ?>
                <span class="badge <?= getStatusBadgeClass($invoice['status']) ?>">
                    <?= getStatusLabel($invoice['status']) ?>
                </span>
                <?php if ($role === 'verificador'): ?>
                    <br><small class="text-muted">
                        <i class="fas fa-user-check me-1"></i>Aprobada por contador
                    </small>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <td>
            <div class="btn-group">
                <a href="view_invoice.php?docnum_interno_sap=<?= $invoice['docnum_interno_sap'] ?>" class="btn btn-sm btn-info" title="Ver detalles">
                    <i class="fas fa-eye"></i>
                </a>
                
                <?php if ($hasViewed): ?>
                    <button type="button" class="btn btn-sm btn-success" title="Aprobar"
                            data-bs-toggle="modal" data-bs-target="#approveModal<?= $invoice['docnum_interno_sap'] ?>">
                        <i class="fas fa-check"></i>
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" title="Debe ver los detalles antes de aprobar"
                            onclick="alert('Debe ver los detalles de la factura antes de aprobarla')">
                        <i class="fas fa-check"></i>
                    </button>
                <?php endif; ?>
                
                <!-- Modal de Aprobación -->
                <div class="modal fade" id="approveModal<?= $invoice['docnum_interno_sap'] ?>" tabindex="-1"
                     aria-labelledby="approveModalLabel<?= $invoice['docnum_interno_sap'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title" id="approveModalLabel<?= $invoice['docnum_interno_sap'] ?>">
                                    Aprobar Factura #<?= $invoice['docnum_interno_sap'] ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Aviso importante:</strong> Al aprobar esta factura, quedará registrado su nombre (<?= $user['name'] ?>),
                                        rol (<?= ucfirst($role) ?>) y la fecha/hora exacta de la acción.
                                    </div>
                                    <?php if (isset($invoice['display_status']) && $invoice['display_status'] == 'corregida'): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Nota:</strong> Esta factura ha sido corregida. Al aprobarla, cambiará su estado a "Aprobada".
                                        </div>
                                    <?php endif; ?>
                                    <input type="hidden" name="invoice_id" value="<?= $invoice['docnum_interno_sap'] ?>">
                                    <div class="mb-3">
                                        <label for="comments<?= $invoice['docnum_interno_sap'] ?>" class="form-label">Comentarios (opcional)</label>
                                        <textarea class="form-control" id="comments<?= $invoice['docnum_interno_sap'] ?>" name="comments" rows="3"></textarea>
                                    </div>
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="confirmCheck<?= $invoice['docnum_interno_sap'] ?>" name="confirm_approval" checked required>
                                        <label class="form-check-label" for="confirmCheck<?= $invoice['docnum_interno_sap'] ?>">
                                            Confirmo que he revisado los detalles de esta factura y autorizo su aprobación
                                        </label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" name="approve_invoice" class="btn btn-success">
                                        <i class="fas fa-check me-1"></i> Confirmar Aprobación
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Botón de Rechazo -->
                <?php if (in_array($role, ['subgerente', 'admin','contador'])): ?>
                    <?php if ($hasViewed): ?>
                        <button type="button" class="btn btn-sm btn-danger" title="Rechazar"
                                data-bs-toggle="modal" data-bs-target="#rejectModal<?= $invoice['docnum_interno_sap'] ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Debe ver los detalles antes de rechazar"
                                onclick="alert('Debe ver los detalles de la factura antes de rechazarla')">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                    
                    <!-- Modal de Rechazo -->
                    <div class="modal fade" id="rejectModal<?= $invoice['docnum_interno_sap'] ?>" tabindex="-1"
                         aria-labelledby="rejectModalLabel<?= $invoice['docnum_interno_sap'] ?>" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title" id="rejectModalLabel<?= $invoice['docnum_interno_sap'] ?>">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Rechazar Factura #<?= $invoice['docnum_interno_sap'] ?>
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="POST" action="">
                                    <div class="modal-body">
                                        <div class="alert alert-danger">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>¡Atención!</strong> Esta acción rechazará permanentemente la factura.
                                        </div>
                                        <input type="hidden" name="invoice_id" value="<?= $invoice['docnum_interno_sap'] ?>">
                                        
                                        <div class="mb-3">
                                            <label for="reject_reason<?= $invoice['docnum_interno_sap'] ?>" class="form-label">
                                                <strong>Razón del rechazo (obligatorio)</strong>
                                            </label>
                                            <textarea class="form-control" id="reject_reason<?= $invoice['docnum_interno_sap'] ?>"
                                                      name="reject_reason" rows="4" required></textarea>
                                        </div>
                                        <div class="mb-3 form-check">
                                            <input type="checkbox" class="form-check-input" id="confirmRejectCheck<?= $invoice['docnum_interno_sap'] ?>"
                                                   name="confirm_rejection" required>
                                            <label class="form-check-label" for="confirmRejectCheck<?= $invoice['docnum_interno_sap'] ?>">
                                                <strong>Confirmo el rechazo de esta factura</strong>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" name="reject_invoice" class="btn btn-danger">
                                            <i class="fas fa-times me-1"></i> Confirmar Rechazo
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>

                                </table>
                            </div>
                            
                            <!-- Agregar controles globales para expandir/colapsar -->
                            <div class="d-flex justify-content-end mb-3">
                                <button id="expandAllBtn" class="btn btn-sm btn-outline-primary me-2">
                                    <i class="fas fa-expand me-1"></i> Expandir Todos
                                </button>
                                <button id="collapseAllBtn" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-compress me-1"></i> Colapsar Todos
                                </button>
                            </div>
                            
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php if ($role === 'subgerente'): ?>
                                    <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                        <strong>Información:</strong> No hay facturas aprobadas por contador o verificador hoy que estén pendientes de su aprobación.
                                    <?php else: ?>
                                        <strong>Información:</strong> No hay facturas aprobadas por contador o verificador ni facturas corregidas pendientes de su aprobación.
                                    <?php endif; ?>
                                <?php elseif ($role === 'verificador'): ?>
                                    <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                        <strong>Información:</strong> No hay facturas aprobadas por contador hoy que estén pendientes de su verificación.
                                    <?php else: ?>
                                        <strong>Información:</strong> No hay facturas aprobadas por contador pendientes de su verificación.
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                        <strong>Información:</strong> No hay facturas marcadas como "OK" hoy que estén pendientes de su aprobación.
                                    <?php else: ?>
                                        <strong>Información:</strong> No se encontraron facturas pendientes de aprobación.
                                    <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                function contarFacturasMostradas($pending_invoices) {
                    $uniquePending = [];
                    $seenPendingDocNums = [];
                    
                    foreach ($pending_invoices as $invoice) {
                        // Filtrar facturas donde ESTADOSAP no sea "c"
                        if ($invoice['ESTADOSAP'] != 'C' && !in_array($invoice['docnum_interno_sap'], $seenPendingDocNums)) {
                            $uniquePending[] = $invoice;
                            $seenPendingDocNums[] = $invoice['docnum_interno_sap'];
                        }
                    }
                    
                    return count($uniquePending);
                }
                
                // Función alternativa que cuenta por proveedor
                function contarFacturasPorProveedor($pending_invoices) {
                    $uniquePending = [];
                    $seenPendingDocNums = [];
                    $contadorPorProveedor = [];
                    
                    foreach ($pending_invoices as $invoice) {
                        // Filtrar facturas donde ESTADOSAP no sea "c"
                        if ($invoice['ESTADOSAP'] != 'C' && !in_array($invoice['docnum_interno_sap'], $seenPendingDocNums)) {
                            $uniquePending[] = $invoice;
                            $seenPendingDocNums[] = $invoice['docnum_interno_sap'];
                            
                            // Contar por proveedor
                            $proveedor = $invoice['nombre'];
                            if (isset($contadorPorProveedor[$proveedor])) {
                                $contadorPorProveedor[$proveedor]++;
                            } else {
                                $contadorPorProveedor[$proveedor] = 1;
                            }
                        }
                    }
                    
                    return [
                        'total' => count($uniquePending),
                        'por_proveedor' => $contadorPorProveedor
                    ];
                }
                
                // Ejemplo de uso en tu código:
                // 1. Contar total de facturas
                $totalFacturas = contarFacturasMostradas($pending_invoices);
                
                // // 1. Contar total de facturas
                $totalFacturas = contarFacturasMostradas($pending_invoices);
                
                // 2. Contar por proveedor
                $conteoDetallado = contarFacturasPorProveedor($pending_invoices);
                
                // Para mostrar en la interfaz:
                // También puedes agregar la función directamente en tu código existente:
                
                $uniqueApproved = [];
                $seenApprovedDocNums = [];
                foreach ($approved_invoices as $invoice) {
                    if (!in_array($invoice['docnum_interno_sap'], $seenApprovedDocNums)) {
                        $uniqueApproved[] = $invoice;
                        $seenApprovedDocNums[] = $invoice['docnum_interno_sap'];
                    }
                }
                ?>
                
                <!-- SECCIÓN MODIFICADA: Tabla de facturas ya aprobadas por el usuario con filtro de hoy y totales por proveedor -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-success text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-check-circle me-2"></i> Facturas Aprobadas por Usted
                                <?php if (!empty($filter_approved_today) && $filter_approved_today === '1'): ?>
                                    <span class="badge bg-warning text-dark ms-2">
                                        <i class="fas fa-calendar-day me-1"></i>Solo Hoy
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($filter_creation_date_from) || !empty($filter_creation_date_to)): ?>
                                    <span class="badge bg-info ms-2">
                                        <i class="fas fa-calendar-plus me-1"></i>Filtro Creación
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($filter_approval_date_from) || !empty($filter_approval_date_to)): ?>
                                    <span class="badge bg-warning text-dark ms-2">
                                        <i class="fas fa-calendar-check me-1"></i>Filtro Aprobación
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <!-- Filtro rápido para facturas aprobadas hoy -->
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       id="approvedTodayFilter"
                                       <?php echo (!empty($filter_approved_today) && $filter_approved_today === '1') ? 'checked' : ''; ?>
                                       onchange="toggleApprovedTodayFilter()">
                                <label class="form-check-label text-white fw-bold" for="approvedTodayFilter">
                                    Solo Hoy
                                </label>
                            </div>
                        </div>
                        <a href="aprovadas.php"><button>Aprobadas</button></a>
                    </div>
                    
                    <div class="card-body">
                        <?php if (count($approved_invoices) > 0): ?>
                            <!-- Resumen de totales para facturas aprobadas -->
                            <?php if (empty($filter_supplier) && count($supplier_totals_approved) > 0): ?>
                                <div class="alert alert-success mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-chart-line me-2"></i>
                                            <strong>Total de facturas aprobadas por usted:</strong>
                                        </div>
                                        <div class="h5 mb-0">
                                            <?php
                                            $total_approved_value = 0;
                                            $total_approved_count = 0;
                                            foreach ($supplier_totals_approved as $supplier_data) {
                                                $total_approved_value += $supplier_data['total'];
                                                $total_approved_count += $supplier_data['count'];
                                            }
                                            ?>
                                            <?php echo $total_approved_count; ?> facturas - $<?php echo number_format($total_approved_value, 2, ',', '.'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- NUEVO: Control desplegable para mostrar/ocultar facturas aprobadas -->
                            <div class="mb-3">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="form-group mb-0">
                                            <label for="viewApprovedSelect" class="form-label fw-bold">
                                                <i class="fas fa-eye me-2"></i>Ver facturas aprobadas:
                                            </label>
                                            <select id="viewApprovedSelect" class="form-select" onchange="toggleApprovedView()">
                                                <option value="hidden">🔽 Ocultar facturas aprobadas (recomendado)</option>
                                                <option value="summary">📊 Ver solo resumen por proveedor</option>
                                                <option value="detailed">📋 Ver lista detallada completa</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Mantén ocultas para priorizar facturas pendientes
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NUEVO: Vista de resumen por proveedor (solo totales) -->
                            <div id="approvedSummaryView" class="d-none">
                                <div class="card border-success">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">
                                            <i class="fas fa-chart-pie me-2"></i>Resumen por Proveedor
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php 
                                            // Cambia esta parte en el código donde agrupas por proveedor
                                            $supplierGroups = [];
                                            foreach ($uniqueApproved as $invoice) {
                                                $supplier = trim($invoice['nombre']); // Añade trim() para eliminar espacios
                                                if (!isset($supplierGroups[$supplier])) {
                                                    $supplierGroups[$supplier] = [
                                                        'count' => 0,
                                                        'total' => 0,
                                                        'recent_count' => 0
                                                    ];
                                                }
                                                $supplierGroups[$supplier]['count']++;
                                                $supplierGroups[$supplier]['total'] += $invoice['saldo_pendiente'];
                                                
                                                // Verificar si fue aprobada hoy (asegúrate de que approval_time esté definido)
                                                if (!empty($invoice['approval_time'])) {
                                                    $approvalDate = formatApprovalTime($invoice['approval_time']);
                                                    $today = date('d/m/Y');
                                                    if (strpos($approvalDate, $today) === 0) {
                                                        $supplierGroups[$supplier]['recent_count']++;
                                                    }
                                                }
                                            }
                                            
                                            foreach ($supplierGroups as $supplierName => $data): ?>
                                                <div class="col-md-6 col-lg-4 mb-3">
                                                    <div class="card border-success h-100">
                                                        <div class="card-body text-center">
                                                            <h6 class="card-title text-truncate" title="<?php echo htmlspecialchars($supplierName); ?>">
                                                                <i class="fas fa-building me-1"></i>
                                                                <?php echo htmlspecialchars($supplierName); ?>
                                                            </h6>
                                                            <div class="mb-2">
                                                                <span class="badge bg-success">
                                                                    <?php echo $data['count']; ?> facturas
                                                                </span>
                                                                <?php if ($data['recent_count'] > 0): ?>
                                                                    <span class="badge bg-warning text-dark ms-1">
                                                                        <?php echo $data['recent_count']; ?> hoy
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="h6 text-success">
                                                                $<?php echo number_format($data['total'], 2, ',', '.'); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Vista detallada completa (tabla original) -->
                            <div id="approvedDetailedView" class="d-none">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Fecha Factura</th>
                                                <th>Proveedor</th>
                                                <th>Días Antigüedad</th>
                                                <th>Prioridad</th>
                                                <th>Valor</th>
                                                <th>Estado</th>
                                                <th>Fecha Aprobación</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $currentApprovedSupplier = '';
                                            foreach ($uniqueApproved as $invoice):
                                                // Verificar si la factura fue aprobada hoy para destacarla
                                                $approvalDate = '';
                                                $isToday = false;
                                                if (isset($invoice['approval_time'])) {
                                                    $approvalDate = formatApprovalTime($invoice['approval_time']);
                                                    $today = date('d/m/Y');
                                                    $isToday = (strpos($approvalDate, $today) === 0);
                                                }
                                                
                                                // Mostrar separador visual por proveedor en facturas aprobadas con totales
                                                if ($currentApprovedSupplier !== $invoice['nombre'] && empty($filter_supplier)) {
                                                    $currentApprovedSupplier = $invoice['nombre'];
                                                    $supplierApprovedTotal = isset($supplier_totals_approved[$currentApprovedSupplier]) ? $supplier_totals_approved[$currentApprovedSupplier] : ['total' => 0, 'count' => 0];
                                                    echo '<tr class="supplier-header">
                                                            <td colspan="9">
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <div>
                                                                        <i class="fas fa-building me-2"></i>
                                                                        <strong>' . htmlspecialchars($currentApprovedSupplier) . '</strong>
                                                                    </div>
                                                                    <div>
                                                                        <span class="supplier-count-badge me-2">
                                                                            <i class="fas fa-check-circle me-1"></i>
                                                                            ' . $supplierApprovedTotal['count'] . ' aprobadas
                                                                        </span>
                                                                        <span class="supplier-total-badge">
                                                                            <i class="fas fa-dollar-sign me-1"></i>
                                                                            $' . number_format($supplierApprovedTotal['total'], 2, ',', '.') . '
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                          </tr>';
                                                }
                                            ?>
                                                <tr class="<?php echo ($isToday) ? 'recent-approval' : ''; ?> <?php echo (empty($filter_supplier)) ? 'supplier-group' : ''; ?>">
                                                    <td>
                                                        <?php echo $invoice['docnum_interno_sap']; ?>
                                                        <?php if ($isToday): ?>
                                                            <span class="badge bg-success ms-1" title="Aprobada hoy">
                                                                <i class="fas fa-star"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo formatDate($invoice['fecha_vencimiento']); ?></td>
                                                    <td><?php echo $invoice['nombre']; ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $invoice['dias_antiguedad'] > 30 ? 'bg-danger' : ($invoice['dias_antiguedad'] > 15 ? 'bg-warning' : 'bg-success'); ?>">
                                                            <?php echo $invoice['dias_antiguedad']; ?> días
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                            $priority = strtolower(trim($invoice['priority']));
                                                            $color = '#e0e0e0';
                                                            $textColor = '#000';
                                                            $borderColor = '#ccc';
                                                            switch ($priority) {
                                                                case 'baja':
                                                                    $color = '#d4edda';
                                                                    $textColor = '#155724';
                                                                    $borderColor = '#c3e6cb';
                                                                    break;
                                                                case 'media':
                                                                    $color = '#fff3cd';
                                                                    $textColor = '#856404';
                                                                    $borderColor = '#ffeeba';
                                                                    break;
                                                                case 'alta':
                                                                    $color = '#f8d7da';
                                                                    $textColor = '#721c24';
                                                                    $borderColor = '#f5c6cb';
                                                                    break;
                                                            }
                                                        ?>
                                                        <span style="font-weight: 500; padding: 4px 8px; border: 1px solid <?php echo $borderColor; ?>; border-radius: 4px; display: inline-block; text-transform: capitalize; background-color: <?php echo $color; ?>; color: <?php echo $textColor; ?>;">
                                                            <?php echo ucfirst($priority); ?>
                                                        </span>
                                                    </td>
                                                    <td>$<?php echo number_format($invoice['saldo_pendiente'], 2, ',', '.'); ?></td>
                                                    <td>
                                                        <span class="badge bg-success text-white">
                                                            <i class="fas fa-check-circle me-1"></i>Aprobada
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge approval-time-badge <?php echo ($isToday) ? 'bg-warning text-dark' : ''; ?>">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo $approvalDate; ?>
                                                            <?php if ($isToday): ?>
                                                                <i class="fas fa-star ms-1"></i>
                                                            <?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view_invoice.php?docnum_interno_sap=<?php echo $invoice['docnum_interno_sap']; ?>" class="btn btn-sm btn-info" title="Ver detalles">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Información sobre el estado actual -->
                            <div id="approvedStatusInfo" class="alert alert-info">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-eye-slash me-2"></i>
                                    <div>
                                        <strong>Facturas aprobadas ocultas</strong> -
                                        Tienes <?php echo count($approved_invoices); ?> facturas aprobadas
                                        <?php if (!empty($filter_approved_today) && $filter_approved_today === '1'): ?>
                                            <span class="badge bg-info text-white ms-2">Solo hoy</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            Usa el selector arriba para ver resumen o detalles cuando necesites consultarlas
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Información:</strong>
                                <?php if (!empty($filter_approved_today) && $filter_approved_today === '1'): ?>
                                    No ha aprobado ninguna factura hoy.
                                <?php else: ?>
                                    No ha aprobado ninguna factura todavía.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            </main>
        </div>
    </div>
       <button class="back-button" onclick="goBack()">
    <span class="arrow">←</span>
</button>

<script>
    function goBack() {
        window.history.back();
    }

    // Detecta si se accedió a la página mediante "historial" (volver) y recarga silenciosamente
    window.addEventListener('pageshow', function (event) {
        if (event.persisted || performance.getEntriesByType("navigation")[0].type === "back_forward") {
            // Esto ocurre al volver con el botón atrás o desde el historial
            window.location.reload();
        }
    });
     document.addEventListener("DOMContentLoaded", function () {
    const backBtn = document.querySelector('.back-button');
    if (backBtn) backBtn.style.display = 'none';
  });
</script>

     <style>
          .back-button {
    display: none;
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
 
        /* Contenido de ejemplo para demostrar el botón */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
            background: #f5f5f5;
        }
 
        .content {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@5.3.0/dist/js/bootstrap-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@5.3.0/dist/locales/bootstrap-datepicker.es.min.js"></script>
    <script>
    // Funciones para manejar los filtros
    function toggleApprovedTodayFilter() {
        const checkbox = document.getElementById('approvedTodayFilter');
        const currentUrl = new URL(window.location);
        
        if (checkbox.checked) {
            currentUrl.searchParams.set('filter_approved_today', '1');
        } else {
            currentUrl.searchParams.delete('filter_approved_today');
        }
        
        window.location.href = currentUrl.toString();
    }
    
    function toggleTodayFilter() {
        const checkbox = document.getElementById('todayOnlyFilter');
        const currentUrl = new URL(window.location.href);
        
        if (checkbox.checked) {
            currentUrl.searchParams.set('filter_today_only', '1');
        } else {
            currentUrl.searchParams.delete('filter_today_only');
        }
        
        // Mantener otros filtros
        const form = document.querySelector('form');
        const formData = new FormData(form);
        
        for (let [key, value] of formData.entries()) {
            if (key !== 'filter_today_only' && value) {
                currentUrl.searchParams.set(key, value);
            }
        }
        
        window.location.href = currentUrl.toString();
    }
    
    // NUEVA: Función para limpiar filtros de fecha
    function clearDateFilters() {
        const form = document.getElementById('dateFiltersForm');
        const dateInputs = form.querySelectorAll('input[type="date"]');
        dateInputs.forEach(input => input.value = '');
        
        // Enviar formulario para aplicar cambios
        form.submit();
    }
    
    function toggleApprovedView() {
        const select = document.getElementById('viewApprovedSelect');
        const summaryView = document.getElementById('approvedSummaryView');
        const detailedView = document.getElementById('approvedDetailedView');
        const statusInfo = document.getElementById('approvedStatusInfo');
        
        // Ocultar todas las vistas primero
        summaryView.classList.add('d-none');
        detailedView.classList.add('d-none');
        statusInfo.classList.add('d-none');
        
        switch(select.value) {
            case 'summary':
                summaryView.classList.remove('d-none');
                break;
            case 'detailed':
                detailedView.classList.remove('d-none');
                break;
            case 'hidden':
            default:
                statusInfo.classList.remove('d-none');
                break;
        }
    }
    
    // Inicialización cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        // Inicializar datepicker para los campos de fecha
        $('.datepicker').datepicker({
            format: 'yyyy-mm-dd',
            language: 'es',
            autoclose: true,
            todayHighlight: true
        });
        
        // JavaScript para manejar la funcionalidad de colapsar/expandir
        const expandAllBtn = document.getElementById('expandAllBtn');
        const collapseAllBtn = document.getElementById('collapseAllBtn');
        const toggleButtons = document.querySelectorAll('.btn-toggle');
        
        // Expandir todos los proveedores
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function() {
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const collapse = new bootstrap.Collapse(document.querySelector(target), {
                        toggle: false
                    });
                    collapse.show();
                    button.querySelector('i').className = 'fas fa-chevron-down';
                });
            });
        }
        
        // Colapsar todos los proveedores
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function() {
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const collapse = new bootstrap.Collapse(document.querySelector(target), {
                        toggle: false
                    });
                    collapse.hide();
                    button.querySelector('i').className = 'fas fa-chevron-right';
                });
            });
        }
        
        // Cambiar icono cuando se colapsa/expande individualmente
        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (icon.classList.contains('fa-chevron-down')) {
                    icon.className = 'fas fa-chevron-right';
                } else {
                    icon.className = 'fas fa-chevron-down';
                }
            });
        });
        
        // Inicializar vista de facturas aprobadas
        toggleApprovedView();
        
        // Mostrar mensaje de éxito al cargar la página
        <?php if ($role === 'subgerente'): ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas aprobadas por contador o verificador y facturas corregidas pendientes de su aprobación.");
        <?php elseif ($role === 'verificador'): ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas aprobadas por contador pendientes de su verificación.");
        <?php else: ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas pendientes de su aprobación.");
        <?php endif; ?>
    });
    </script>
    
    <style>
    /* Estilo adicional para el rol verificador */
    .bg-purple {
        background-color: #6f42c1 !important;
    }
    
    .text-purple {
        color: #6f42c1 !important;
    }
    
    /* Mejorar la visualización de separadores de proveedores */
    .supplier-header td {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef) !important;
        border-top: 2px solid #007bff;
        font-size: 0.95em;
        padding: 8px 12px;
    }
    
    .supplier-group {
        border-left: 2px solid transparent;
    }
    
    .supplier-group:hover {
        border-left-color: #007bff;
        background-color: #f8f9fa;
    }
    
    /* Vista compacta */
    .compact-view .table td {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
    .compact-view .badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
    .compact-view .btn-sm {
        padding: 0.125rem 0.25rem;
        font-size: 0.75rem;
    }
    
    /* Animaciones mejoradas */
    .settings-panel {
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .auto-save-indicator {
        transition: all 0.3s ease;
    }
    
    /* Responsive mejoras */
    @media (max-width: 768px) {
        .settings-panel {
            right: 10px;
            left: 10px;
            max-width: none;
        }
        
        .settings-toggle {
            right: 10px;
        }
    }
    </style>
</body>
</html>
