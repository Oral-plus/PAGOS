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

$filter_supplier = $_GET['filter_supplier'] ?? '';

// Establecer fechas por defecto al mes actual si no se han especificado
if (empty($_GET['filter_date_from']) && empty($_GET['filter_date_to'])) {
    // Primer día del mes actual
    $filter_date_from = date('Y-m-01');
    // Último día del mes actual
    $filter_date_to = date('Y-m-t');
} else {
    $filter_date_from = $_GET['filter_date_from'] ?? '';
    $filter_date_to = $_GET['filter_date_to'] ?? '';
}

// Usar las mismas fechas para ambas secciones (pendientes y aprobadas)
$filter_approved_date_from = $filter_date_from;
$filter_approved_date_to = $filter_date_to;

// Obtener lista de proveedores para el filtro
$suppliers = getAllSuppliers();

$pending_invoices = getPendingInvoicesForCurrentUser($user_id, $role, $filter_supplier, $filter_date_from, $filter_date_to);

$approved_invoices = getApprovedInvoicesByUser($user_id, $filter_supplier, $filter_approved_date_from, $filter_approved_date_to);

// Procesar facturas aprobadas para evitar duplicados y canceladas
$uniqueApproved = [];
$seenApprovedDocNums = [];

foreach ($approved_invoices as $invoice) {
    // Obtener valores de forma segura
    $estadosap = isset($invoice['ESTADOSAP']) ? trim($invoice['ESTADOSAP']) : '';
    $docnum = isset($invoice['docnum_interno_sap']) ? $invoice['docnum_interno_sap'] : null;
    
    // Log de depuración para las primeras facturas
    if (count($uniqueApproved) < 3) {
        error_log("Procesando factura - docnum: " . ($docnum ?? 'NULL') . ", ESTADOSAP: " . ($estadosap ?: 'NULL'));
    }
    
    // Incluir factura si:
    // 1. Tiene docnum_interno_sap
    // 2. No está duplicada
    // 3. No está cancelada (ESTADOSAP != 'C')
    if ($docnum && !in_array($docnum, $seenApprovedDocNums)) {
        // Solo excluir si explícitamente está cancelada
        if ($estadosap != 'C') {
            $uniqueApproved[] = $invoice;
            $seenApprovedDocNums[] = $docnum;
        } else {
            error_log("Factura excluida por estar cancelada: " . $docnum);
        }
    } else {
        if (!$docnum) {
            error_log("Factura excluida por no tener docnum_interno_sap");
        } else {
            error_log("Factura duplicada excluida: " . $docnum);
        }
    }
}

error_log("Total facturas aprobadas: " . count($approved_invoices) . ", Únicas después de filtrado: " . count($uniqueApproved));

// NUEVO: Calcular totales por proveedor para facturas aprobadas
// ====== FACTURAS PENDIENTES - EVITAR DUPLICADOS ======
$supplier_totals_pending = [];
$supplier_totals_approved = [];
$seenDocNums = []; // Para rastrear TODAS las facturas ya procesadas

// Primero procesamos las facturas aprobadas (solo las que no tienen ESTADOSAP = "C")
foreach ($approved_invoices as $invoice) {
    // Verificar que la factura no esté cancelada y no haya sido procesada antes
    $estadosap = isset($invoice['ESTADOSAP']) ? $invoice['ESTADOSAP'] : '';
    if (!in_array($invoice['docnum_interno_sap'], $seenDocNums) && $estadosap != 'C') {
        $supplier_name = $invoice['nombre'];
        
        if (!isset($supplier_totals_approved[$supplier_name])) {
            $supplier_totals_approved[$supplier_name] = [
                'total' => 0,
                'count' => 0
            ];
        }
        
        $supplier_totals_approved[$supplier_name]['total'] += $invoice['saldo_pendiente'] ?? 0;
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

function getPendingInvoicesForCurrentUser($user_id, $user_role, $supplier = '', $date_from = '', $date_to = '') {
    $conn = getDbConnection();
    $invoices = array();

    try {
        // Base SQL query - Modificado para incluir lógica específica para verificador
        if ($user_role === 'subgerente') {
            // Para subgerente: mostrar facturas aprobadas por contador o verificador y facturas corregidas
            $sql = "SELECT DISTINCT i.*, 
                    DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad,
                    CASE 
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
                    i.status as display_status
                    FROM invoices i 
                    WHERE i.ok = 'ok'
                    AND i.docnum_interno_sap IN (
                        SELECT invoice_id FROM invoice_approvals 
                        WHERE user_role = 'contador' AND action = 'approve'
                    )
                    AND i.docnum_interno_sap NOT IN (
                        SELECT invoice_id FROM approval_logs WHERE user_id = ?
                    )";
            $params = array($user_id);
        } else {
            // Para otros roles (contador, etc.): mostrar facturas con ok = 'ok' y no aprobadas por el usuario actual
            $sql = "SELECT i.*, 
                    DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad,
                    i.status as display_status
                    FROM invoices i 
                    WHERE i.ok = 'ok'
                    AND i.docnum_interno_sap NOT IN (
                        SELECT invoice_id FROM approval_logs WHERE user_id = ?
                    )";
            $params = array($user_id);
        }
        
        if (!empty($date_from) && !empty($date_to)) {
            if ($user_role === 'subgerente') {
                // Para subgerente: filtrar por facturas aprobadas por contador/verificador en el rango de fechas
                $sql .= " AND EXISTS (
                    SELECT 1 FROM invoice_approvals ia 
                    WHERE ia.invoice_id = i.docnum_interno_sap 
                    AND ia.user_role IN ('contador', 'verificador')
                    AND ia.action = 'approve'
                    AND CONVERT(DATE, ia.created_at) BETWEEN ? AND ?
                )";
                $params[] = $date_from;
                $params[] = $date_to;
            } elseif ($user_role === 'verificador') {
                // Para verificador: filtrar por facturas aprobadas por contador en el rango de fechas
                $sql .= " AND EXISTS (
                    SELECT 1 FROM invoice_approvals ia 
                    WHERE ia.invoice_id = i.docnum_interno_sap 
                    AND ia.user_role = 'contador'
                    AND ia.action = 'approve'
                    AND CONVERT(DATE, ia.created_at) BETWEEN ? AND ?
                )";
                $params[] = $date_from;
                $params[] = $date_to;
            } else {
                // Para contador: filtrar por facturas marcadas como 'ok' en el rango de fechas
                $sql .= " AND CONVERT(DATE, i.created_at) BETWEEN ? AND ?";
                $params[] = $date_from;
                $params[] = $date_to;
            }
        }
        
        // Añadir filtro de proveedor si está presente
        if (!empty($supplier)) {
            $sql .= " AND i.nombre = ?";
            $params[] = $supplier;
        }
        
        // MODIFICADO: Ordenar por proveedor alfabéticamente y luego por días de antigüedad
        $sql .= " ORDER BY i.nombre ASC, dias_antiguedad DESC";
        
        // Para depuración
        error_log("=== getPendingInvoicesForCurrentUser ===");
        error_log("User Role: " . $user_role);
        error_log("User ID: " . $user_id);
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
            // Para MySQL, LOWER y LTRIM/RTRIM funcionan igual
            error_log("SQL Query (MySQL converted): " . $sql);
            
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

function getApprovedInvoicesByUser($user_id, $supplier = '', $date_from = '', $date_to = '') {
    $conn = getDbConnection();
    $invoices = array();

    try {
        // SOLUCIÓN SIMPLIFICADA: Usar invoice_approvals como fuente principal
        // Hacer LEFT JOIN con invoices para obtener los datos de la factura
        // LEFT JOIN con approval_logs para obtener el tiempo exacto si existe
        $sql = "SELECT DISTINCT 
                i.*,
                COALESCE(al.approval_time, ia.created_at) as approval_time,
                DATEDIFF(day, i.fecha_vencimiento, GETDATE()) as dias_antiguedad
                FROM invoice_approvals ia
                LEFT JOIN invoices i ON ia.invoice_id = i.docnum_interno_sap
                LEFT JOIN approval_logs al ON ia.invoice_id = al.invoice_id AND al.user_id = ia.user_id
                WHERE ia.user_id = ? AND ia.action = 'approve'
                AND (i.ESTADOSAP IS NULL OR i.ESTADOSAP != 'C')
                AND i.docnum_interno_sap IS NOT NULL";
        $params = array($user_id);
        
        // Filtro de fecha: usar created_at de invoice_approvals o approval_time de approval_logs
        if (!empty($date_from) && !empty($date_to)) {
            $sql .= " AND (
                (al.approval_time IS NOT NULL AND CAST(al.approval_time AS DATE) BETWEEN ? AND ?)
                OR 
                (al.approval_time IS NULL AND CAST(ia.created_at AS DATE) BETWEEN ? AND ?)
            )";
            $params[] = $date_from;
            $params[] = $date_to;
            $params[] = $date_from;
            $params[] = $date_to;
        }
        
        // Añadir filtro de proveedor si está presente
        if (!empty($supplier)) {
            $sql .= " AND i.nombre = ?";
            $params[] = $supplier;
        }
        
        // Ordenar por fecha de aprobación descendente (más recientes primero)
        $sql .= " ORDER BY COALESCE(al.approval_time, ia.created_at) DESC, i.nombre ASC";
        
        // Para depuración
        error_log("=== getApprovedInvoicesByUser ===");
        error_log("User ID: " . $user_id);
        error_log("Date From: " . $date_from);
        error_log("Date To: " . $date_to);
        error_log("Supplier: " . $supplier);
        error_log("SQL Query: " . $sql);
        error_log("Params: " . print_r($params, true));
        
        // Verificar si es una conexión PDO o sqlsrv
        if ($conn instanceof PDO) {
            // Para MySQL/PDO
            $sql = str_replace("DATEDIFF(day, i.fecha_vencimiento, GETDATE())", 
                              "DATEDIFF(CURDATE(), i.fecha_vencimiento)", $sql);
            $sql = str_replace("CAST(al.approval_time AS DATE)", "DATE(al.approval_time)", $sql);
            $sql = str_replace("CAST(ia.created_at AS DATE)", "DATE(ia.created_at)", $sql);
            
            error_log("SQL Query (MySQL): " . $sql);
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("Error al preparar: " . print_r($conn->errorInfo(), true));
                return $invoices;
            }
            
            $result = $stmt->execute($params);
            if (!$result) {
                error_log("Error al ejecutar: " . print_r($stmt->errorInfo(), true));
                return $invoices;
            }
            
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // SQL Server
            error_log("SQL Query (SQL Server): " . $sql);
            
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                error_log("Error sqlsrv: " . print_r($errors, true));
                return $invoices;
            }
            
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        
        // Depuración
        error_log("Facturas encontradas: " . count($invoices));
        if (count($invoices) > 0) {
            error_log("Ejemplo de factura: " . json_encode($invoices[0]));
        }
        
        return $invoices;
    } catch (Exception $e) {
        error_log("EXCEPCIÓN en getApprovedInvoicesByUser: " . $e->getMessage());
        error_log("Stack: " . $e->getTraceAsString());
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

// Funciones de utilidad para obtener el texto y clase de badge de estado

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
.badge-corregido {
    background: linear-gradient(135deg, var(--warning), var(--warning-dark));
    color: white;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: var(--radius-full);
    box-shadow: var(--shadow-md);
    font-size: 0.8125rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.badge-corregido:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.approval-time-badge {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    color: white;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: var(--radius-full);
    box-shadow: var(--shadow-md);
    font-size: 0.8125rem;
    letter-spacing: 0.025em;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.approval-time-badge:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.supplier-total-badge {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    font-weight: 700;
    padding: 0.625rem 1.25rem;
    border-radius: var(--radius-full);
    font-size: 0.9375rem;
    box-shadow: var(--shadow-lg);
    letter-spacing: 0.05em;
    border: 2px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: var(--backdrop-blur);
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.supplier-total-badge:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: var(--shadow-xl);
}

.supplier-count-badge {
    background: linear-gradient(135deg, var(--success-light), var(--success));
    color: white;
    font-weight: 600;
    padding: 0.5rem 0.875rem;
    border-radius: var(--radius-full);
    font-size: 0.8125rem;
    box-shadow: var(--shadow-md);
    letter-spacing: 0.025em;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.supplier-count-badge:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

/* ===========================
   CONTENEDORES DE FILTROS
   =========================== */
.date-filter-container {
    background: linear-gradient(135deg, #1e40af, #2563eb);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    color: white;
    box-shadow: var(--shadow-lg);
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: var(--backdrop-blur);
    position: relative;
    overflow: hidden;
    transition: var(--transition);
}

.date-filter-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
    pointer-events: none;
    opacity: 0;
    transition: var(--transition);
}

.date-filter-container:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-2xl);
}

.date-filter-container:hover::before {
    opacity: 1;
}

.date-filter-container.verificador {
    background: linear-gradient(135deg, var(--purple), var(--purple-dark));
}

.approved-date-filter {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    border-radius: var(--radius-xl);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    color: white;
    box-shadow: var(--shadow-lg);
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: var(--backdrop-blur);
    transition: var(--transition);
}

.approved-date-filter:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-xl);
}

/* ===========================
   TARJETAS Y GRUPOS
   =========================== */
.grand-total-card {
    background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
    color: white;
    border-radius: var(--radius-xl);
    padding: 2rem;
    box-shadow: var(--shadow-xl);
    border: 2px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
    position: relative;
    overflow: hidden;
    transition: var(--transition);
}

.grand-total-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
    pointer-events: none;
}

.grand-total-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-2xl);
}

.grand-total-value {
    font-size: 2.5rem;
    font-weight: 800;
    text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    letter-spacing: -0.03em;
    position: relative;
    z-index: 1;
}

.recent-approval {
    background: linear-gradient(90deg, rgba(5, 150, 105, 0.05), rgba(5, 150, 105, 0.02));
    border-left: 5px solid var(--success);
    border-radius: var(--radius-md);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.recent-approval::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: linear-gradient(180deg, var(--success), var(--success-dark));
    box-shadow: 0 0 10px rgba(5, 150, 105, 0.5);
}

.recent-approval:hover {
    background: linear-gradient(90deg, rgba(5, 150, 105, 0.08), rgba(5, 150, 105, 0.03));
    transform: translateX(4px);
    box-shadow: var(--shadow-md);
}

.today-ok-invoice {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.05), rgba(37, 99, 235, 0.02));
    border-left: 5px solid var(--primary);
    border-radius: var(--radius-md);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.today-ok-invoice::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: linear-gradient(180deg, var(--primary), var(--primary-dark));
    box-shadow: 0 0 10px rgba(37, 99, 235, 0.5);
}

.today-ok-invoice:hover {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.03));
    transform: translateX(4px);
    box-shadow: var(--shadow-md);
}

.supplier-group {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.03), transparent);
    border-left: 4px solid var(--primary);
    border-radius: var(--radius-md);
    transition: var(--transition);
    position: relative;
}

.supplier-group:hover {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.06), rgba(37, 99, 235, 0.02));
    border-left-color: var(--primary-dark);
    border-left-width: 5px;
    box-shadow: var(--shadow-md);
}

.supplier-header {
    background: linear-gradient(135deg, var(--gray-100), var(--gray-50)) !important;
    font-weight: 700;
    color: var(--gray-800);
    border-top: 4px solid var(--primary);
    border-bottom: 2px solid var(--gray-200);
    position: relative;
    box-shadow: var(--shadow-sm);
}

.supplier-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--primary), transparent);
}

.supplier-header td {
    background: linear-gradient(135deg, var(--gray-100), var(--gray-50)) !important;
    border-top: 4px solid var(--primary);
    font-size: 1rem;
    padding: 1rem 1.25rem;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    font-weight: 700;
}

/* ===========================
   PANEL DE CONFIGURACIÓN
   =========================== */
.settings-panel {
    position: fixed;
    top: 1.25rem;
    right: 1.25rem;
    background: var(--glass-bg);
    backdrop-filter: var(--backdrop-blur);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-2xl);
    padding: 1.75rem;
    z-index: 1050;
    max-width: 360px;
    transform: translateX(calc(100% + 1.25rem));
    transition: var(--transition-slow);
    border: 2px solid rgba(255, 255, 255, 0.3);
    overflow: hidden;
}

.settings-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--primary-light));
}

.settings-panel.show {
    transform: translateX(0);
    animation: slideInRight 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes slideInRight {
    from {
        transform: translateX(calc(100% + 1.25rem));
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.settings-toggle {
    position: fixed;
    top: 1.25rem;
    right: 1.25rem;
    z-index: 1051;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius-full);
    width: 60px;
    height: 60px;
    box-shadow: var(--shadow-xl);
    transition: var(--transition);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    border: 3px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: var(--backdrop-blur);
}

.settings-toggle::before {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: var(--radius-full);
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
    opacity: 0;
    transition: var(--transition);
    z-index: -1;
}

.settings-toggle:hover {
    transform: translateY(-4px) scale(1.08) rotate(90deg);
    box-shadow: var(--shadow-2xl);
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
}

.settings-toggle:hover::before {
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

.settings-toggle:active {
    transform: translateY(-2px) scale(1.05) rotate(90deg);
}

.preference-item {
    margin-bottom: 1rem;
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, var(--gray-50), white);
    border: 2px solid var(--gray-200);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.preference-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, var(--primary), var(--primary-light));
    transform: scaleY(0);
    transition: var(--transition);
}

.preference-item:hover {
    background: white;
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
    transform: translateX(4px);
}

.preference-item:hover::before {
    transform: scaleY(1);
}

/* ===========================
   INDICADORES Y NOTIFICACIONES
   =========================== */
.auto-save-indicator {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    background: linear-gradient(135deg, var(--success), var(--success-dark));
    color: white;
    padding: 0.875rem 1.5rem;
    border-radius: var(--radius-full);
    font-size: 0.875rem;
    font-weight: 600;
    opacity: 0;
    transition: var(--transition-slow);
    z-index: 1000;
    box-shadow: var(--shadow-xl);
    border: 2px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: var(--backdrop-blur);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    letter-spacing: 0.025em;
    transform: translateY(20px) scale(0.9);
}

.auto-save-indicator.show {
    opacity: 1;
    transform: translateY(0) scale(1);
    animation: slideUpBounce 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes slideUpBounce {
    0% {
        transform: translateY(20px) scale(0.9);
        opacity: 0;
    }
    60% {
        transform: translateY(-5px) scale(1.05);
    }
    100% {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

.auto-save-indicator::before {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: var(--radius-full);
    background: linear-gradient(135deg, var(--success-light), var(--success));
    opacity: 0;
    z-index: -1;
    transition: var(--transition);
}

.auto-save-indicator.show::before {
    opacity: 0.5;
    animation: pulse 2s infinite;
}

/* ===========================
   BOTÓN DE REGRESO
   =========================== */
.back-button {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border: none;
    border-radius: var(--radius-full);
    color: white;
    font-size: 1.75rem;
    cursor: pointer;
    box-shadow: var(--shadow-2xl);
    transition: var(--transition);
    z-index: 1000;
    display: none;
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
   TABLAS Y ELEMENTOS
   =========================== */
.table {
    border-collapse: separate;
    border-spacing: 0;
}

.table thead th {
    background: linear-gradient(135deg, var(--gray-100), var(--gray-50));
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.8125rem;
    padding: 1rem 1.25rem;
    border-bottom: 3px solid var(--primary);
    color: var(--gray-800);
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
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
    padding: 1rem 1.25rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

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
    padding: 1.5rem;
    border-bottom: 3px solid rgba(255, 255, 255, 0.2);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 1rem;
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

.card-header.bg-success {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
}

.card-body {
    padding: 1.75rem;
}

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

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    border-radius: var(--radius-md);
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

.btn-info {
    background: linear-gradient(135deg, var(--secondary), var(--secondary-dark));
    border-color: var(--secondary-dark);
}

.btn-info:hover {
    background: linear-gradient(135deg, var(--secondary-light), var(--secondary));
    box-shadow: var(--shadow-lg);
}

/* ===========================
   UTILIDADES
   =========================== */
.bg-purple {
    background: linear-gradient(135deg, var(--purple), var(--purple-dark)) !important;
}

.text-purple {
    color: var(--purple) !important;
}

.collapsing {
    transition: height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ===========================
   VISTA COMPACTA
   =========================== */
.compact-view .table td {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

.compact-view .badge {
    font-size: 0.75rem;
    padding: 0.3rem 0.6rem;
}

.compact-view .btn-sm {
    padding: 0.35rem 0.75rem;
    font-size: 0.8125rem;
}

/* ===========================
   RESPONSIVE
   =========================== */
@media (max-width: 768px) {
    :root {
        --radius-lg: 10px;
        --radius-md: 6px;
    }
    
    .settings-panel {
        right: 0.75rem;
        left: 0.75rem;
        max-width: none;
        top: 0.75rem;
    }
    
    .settings-toggle {
        right: 0.75rem;
        top: 0.75rem;
        width: 48px;
        height: 48px;
    }
    
    .back-button {
        width: 56px;
        height: 56px;
        bottom: 1rem;
        right: 1rem;
    }
    
    .grand-total-value {
        font-size: 1.5rem;
    }
    
    .auto-save-indicator {
        bottom: 1rem;
        right: 1rem;
        font-size: 0.8125rem;
    }
}

@media (max-width: 480px) {
    .date-filter-container,
    .approved-date-filter {
        padding: 1rem;
        border-radius: var(--radius-md);
    }
    
    .grand-total-card {
        padding: 1.25rem;
    }
    
    .supplier-header td {
        padding: 0.625rem 0.75rem;
        font-size: 0.875rem;
    }
}

/* ===========================
   ANIMACIONES SUAVES
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

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

.recent-approval,
.today-ok-invoice,
.supplier-group {
    animation: fadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.card {
    animation: slideInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.table tbody tr {
    animation: fadeIn 0.3s ease-out;
    animation-fill-mode: both;
}

.table tbody tr:nth-child(1) { animation-delay: 0.05s; }
.table tbody tr:nth-child(2) { animation-delay: 0.1s; }
.table tbody tr:nth-child(3) { animation-delay: 0.15s; }
.table tbody tr:nth-child(4) { animation-delay: 0.2s; }
.table tbody tr:nth-child(5) { animation-delay: 0.25s; }
.table tbody tr:nth-child(n+6) { animation-delay: 0.3s; }

.badge,
.btn {
    animation: scaleIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* ===========================
   MEJORAS DE ACCESIBILIDAD
   =========================== */
*:focus-visible {
    outline: 3px solid var(--primary);
    outline-offset: 3px;
    border-radius: var(--radius-md);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2);
}

.settings-toggle:focus-visible,
.back-button:focus-visible {
    outline-color: white;
    outline-width: 4px;
    box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.3);
}

/* ===========================
   ELEMENTOS ADICIONALES PREMIUM
   =========================== */
.alert {
    border-radius: var(--radius-lg);
    border: 2px solid transparent;
    box-shadow: var(--shadow-md);
    padding: 1.25rem 1.5rem;
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

.alert-info {
    background: linear-gradient(135deg, rgba(8, 145, 178, 0.1), rgba(8, 145, 178, 0.05));
    border-color: var(--secondary);
    color: var(--secondary-dark);
}

.alert-warning {
    background: linear-gradient(135deg, rgba(217, 119, 6, 0.1), rgba(217, 119, 6, 0.05));
    border-color: var(--warning);
    color: var(--warning-dark);
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

.modal-header.bg-success {
    background: linear-gradient(135deg, var(--success), var(--success-dark));
}

.modal-header.bg-danger {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
}

.modal-body {
    padding: 1.75rem;
}

.modal-footer {
    padding: 1.25rem 1.75rem;
    border-top: 2px solid var(--gray-200);
    background: var(--gray-50);
}

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

.form-check-input {
    border-radius: var(--radius-md);
    border: 2px solid var(--gray-300);
    transition: var(--transition);
    cursor: pointer;
}

.form-check-input:checked {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-color: var(--primary-dark);
    box-shadow: var(--shadow-sm);
}

.form-check-input:focus {
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2);
}

.shadow-sm {
    box-shadow: var(--shadow-sm) !important;
}

.shadow-md {
    box-shadow: var(--shadow-md) !important;
}

.shadow-lg {
    box-shadow: var(--shadow-lg) !important;
}

.shadow-xl {
    box-shadow: var(--shadow-xl) !important;
}
    </style>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-X2F3Z0336Z"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', 'G-X2F3Z0336Z');
    </script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <button class="settings-toggle" onclick="toggleSettingsPanel()" title="Configuración">
        <i class="fas fa-cog"></i>
    </button>
    
    <div id="settingsPanel" class="settings-panel">
        <h6><i class="fas fa-cog me-2"></i>Preferencias</h6>
        <div class="preference-item">
            <label class="form-check-label">
                <input type="checkbox" id="autoSaveFilters" class="form-check-input" checked>
                Auto-guardar filtros
            </label>
        </div>
        <div class="preference-item">
            <label class="form-check-label">
                <input type="checkbox" id="rememberCollapsed" class="form-check-input" checked>
                Recordar proveedores colapsados
            </label>
        </div>
        <div class="preference-item">
            <label class="form-check-label">
                <input type="checkbox" id="autoRefresh" class="form-check-input">
                Auto-actualizar (5 min)
            </label>
        </div>
        <div class="preference-item">
            <label class="form-check-label">
                <input type="checkbox" id="compactView" class="form-check-input">
                Vista compacta
            </label>
        </div>
        <hr>
        <button class="btn btn-sm btn-outline-danger w-100" onclick="clearAllData()">
            <i class="fas fa-trash me-1"></i>Limpiar datos guardados
        </button>
    </div>
    
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-save me-1"></i>Guardado automáticamente
    </div>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
              
                
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
                
                <!-- Filtro unificado compacto -->
                <div class="date-filter-container <?php echo $role === 'contador' ? 'contador-verificador' : ($role === 'verificador' ? 'verificador' : ''); ?>">
    <form method="GET" action="" id="filtersForm" class="minimal-filters">
        <div class="filters-grid">
            <!-- Proveedor -->
            <div class="filter-group">
                <label for="filter_supplier">
                    <i class="fas fa-building"></i> Proveedor
                </label>
                <input type="text"
                       id="filter_supplier"
                       name="filter_supplier"
                       list="suppliersList"
                       value="<?php echo htmlspecialchars($filter_supplier); ?>"
                       placeholder="Todos los proveedores">
                <datalist id="suppliersList">
                    <option value="">Todos los proveedores</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo htmlspecialchars($supplier); ?>">
                            <?php echo htmlspecialchars($supplier); ?>
                            <?php if (isset($supplier_totals_pending[$supplier])): ?>
                                (<?php echo $supplier_totals_pending[$supplier]['count']; ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <!-- Fecha Desde (unificado para ambos) -->
            <div class="filter-group">
                <label for="filter_date_from">
                    <i class="fas fa-calendar-alt"></i> Desde
                </label>
                <input type="date"
                       id="filter_date_from"
                       name="filter_date_from"
                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>

            <!-- Fecha Hasta (unificado para ambos) -->
            <div class="filter-group">
                <label for="filter_date_to">
                    <i class="fas fa-calendar-check"></i> Hasta
                </label>
                <input type="date"
                       id="filter_date_to"
                       name="filter_date_to"
                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>

            <!-- Botones -->
            <div class="filter-actions">
                <button type="submit" class="btn-filter">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <a href="?" class="btn-reset" title="Limpiar filtros y aplicar mes actual" onclick="clearAllFilters(event)">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </div>
    </form>
</div>
<style>
    .minimal-filters {
    font-family: system-ui, -apple-system, sans-serif;
    --primary: #005db9;
    --primary-light: #006edc;
    --primary-dark: #006edc;
    --bg-dark: #006edc;
    --input-bg: rgba(255, 255, 255, 0.1);
    --text-light: #e0e0e0;
    --border: rgba(255, 255, 255, 0.2);
}

.filters-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    align-items: end;
    position: relative;
    z-index: 1;
}

.filters-title i {
    font-size: 1.25rem;
    opacity: 0.9;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: .5rem;
    position: relative;
}

.filter-group label {
    font-size: .8125rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    gap: .375rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.filter-group label i {
    font-size: .875rem;
    opacity: 0.8;
}

.filter-group input {
    padding: .625rem .875rem;
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--input-bg);
    backdrop-filter: blur(10px);
    color: #fff;
    font-size: .875rem;
    font-weight: 500;
    transition: var(--transition);
    box-shadow: var(--shadow-sm);
}

.filter-group input::placeholder {
    color: rgba(255, 255, 255, 0.6);
}

.filter-group input:focus {
    outline: none;
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.15);
    box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.1), var(--shadow-md);
    transform: translateY(-2px);
}

.filter-actions {
    display: flex;
    gap: .75rem;
    align-items: center;
}

.btn-filter {
    padding: .625rem 1.25rem;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: .875rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .375rem;
    transition: var(--transition);
    backdrop-filter: blur(10px);
    box-shadow: var(--shadow-sm);
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.btn-filter:hover {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.2));
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-3px) scale(1.02);
    box-shadow: var(--shadow-lg);
}

.btn-filter:active {
    transform: translateY(-1px) scale(1);
}

.btn-reset {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    color: rgba(255, 255, 255, 0.9);
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: var(--transition);
    box-shadow: var(--shadow-sm);
    font-size: 1rem;
}

.btn-reset:hover {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-3px) rotate(90deg) scale(1.1);
    box-shadow: var(--shadow-md);
}

.btn-reset:active {
    transform: translateY(-1px) rotate(90deg) scale(1.05);
}
</style>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            Facturas Pendientes de su Aprobación
                       
                            <?php if ($role === 'verificador'): ?>
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-user-check me-1"></i>Aprobadas por Contador
                                </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($pending_invoices) > 0): ?>
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
                                            <th style="width: 100px;">Prioridad</th>
                                            <th style="width: 120px;">Estado</th>
                                            <th style="width: 150px;">Acciones</th>
                                        </tr>
                                    </thead>
                                    <?php
                                    $uniquePending = [];
                                    foreach ($pending_invoices as $invoice) {
                                        if ($invoice['ESTADOSAP'] != 'C') {
                                            $uniquePending[$invoice['docnum_interno_sap']] = $invoice;
                                        }
                                    }
                                    $uniquePending = array_values($uniquePending);

                                    $serverName = "192.168.2.244";
                                    $connectionOptions = [
                                        "Database" => "invoice_approval_system",
                                        "Uid" => "sa",
                                        "PWD" => "Sky2022*!",
                                        "Encrypt" => "yes",
                                        "TrustServerCertificate" => "yes"
                                    ];

                                    $conn = sqlsrv_connect($serverName, $connectionOptions);

                                    if ($conn === false) {
                                        die(print_r(sqlsrv_errors(), true));
                                    }

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
                                            $hasViewed = hasUserViewedInvoice($invoice['docnum_interno_sap'], $user_id); 
                                            
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
                                            <tr class="supplier-<?= $supplierIndex ?> collapse show <?= (empty($filter_supplier)) ? 'supplier-group' : '' ?>"
                                                data-parent="supplier-<?= $supplierIndex ?>">
                                                <td>
                                                    <?php
                                                    $idRelacionado = $invoice['docnum_interno_sap'];
                                                    $fechaEncontrada = null;

                                                    $tsql = "SELECT created_at FROM invoices WHERE docnum_interno_sap = ?";
                                                    $params = [$idRelacionado];
                                                    $stmt = sqlsrv_query($conn, $tsql, $params);

                                                    if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                                        $fechaEncontrada = $row['created_at'];
                                                    } else {
                                                        $tsql = "SELECT created_at FROM invoice_approvals WHERE invoice_id = ?";
                                                        $stmt = sqlsrv_query($conn, $tsql, $params);
                                                        
                                                        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                                            $fechaEncontrada = $row['created_at'];
                                                        }
                                                    }

                                                    echo $fechaEncontrada ? formatDate($fechaEncontrada) : "No hay fecha";
                                                    ?>
                                                </td>

                                                <td><?= $invoice['nombre'] ?></td>
                                                <td><?= $invoice['numero_factura_proveedor'] ?></td>
                                                <td><?= $invoice['codigo_sn'] ?></td>
                                                <td>
                                                    <span class="badge <?= ($invoice['dias_antiguedad'] > 30) ? 'bg-danger' : (($invoice['dias_antiguedad'] > 15) ? 'bg-warning' : 'bg-success') ?>">
                                                        <?= $invoice['dias_antiguedad'] ?> días
                                                    </span>
                                                </td>
                                                <td>$<?= number_format($invoice['saldo_pendiente'], 2, ',', '.') ?></td>
                                                <td>
                                                    <?php
                                                    $priority = strtolower(trim($invoice['priority']));
                                                    $priorityStyles = [
                                                        'baja' => ['color' => '#d4edda', 'text' => '#155724', 'border' => '#c3e6cb'],
                                                        'media' => ['color' => '#fff3cd', 'text' => '#856404', 'border' => '#ffeeba'],
                                                        'alta' => ['color' => '#f8d7da', 'text' => '#721c24', 'border' => '#f5c6cb'],
                                                        'default' => ['color' => '#e0e0e0', 'text' => '#000', 'border' => '#ccc']
                                                    ];
                                                    
                                                    $style = $priorityStyles[$priority] ?? $priorityStyles['default'];
                                                    ?>
                                                    <span style="font-weight: 500; letter-spacing: 0.5px; font-family: 'Arial', sans-serif; padding: 4px 8px; border: 1px solid <?= $style['border'] ?>; border-radius: 4px; display: inline-block; text-transform: capitalize; background-color: <?= $style['color'] ?>; color: <?= $style['text'] ?>;">
                                                        <?= ucfirst($priority) ?>
                                                    </span>
                                                </td>

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
                                                        <div class="modal fade" id="approveModal<?= $invoice['docnum_interno_sap'] ?>"tabindex="-1" 
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

                                                        <?php if (in_array($role, ['subgerente', 'contador','Contador'])): ?>
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

                                                            <div class="modal fade" id="rejectModal<?= $invoice['docnum_interno_sap'] ?>"tabindex="-1" 
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

                            <div class="d-flex justify-content-end mb-3">
                                <button id="expandAllBtn" class="btn btn-sm btn-outline-primary me-2">
                                    <i class="fas fa-expand me-1"></i> Expandir Todos
                                </button>
                                <button id="collapseAllBtn" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-compress me-1"></i> Colapsar Todos
                                </button>
                            </div>

                        <?php else: ?>
                            
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php
                function contarFacturasMostradas($pending_invoices) {
                    $uniquePending = [];
                    $seenPendingDocNums = [];
                    
                    foreach ($pending_invoices as $invoice) {
                        if ($invoice['ESTADOSAP'] != 'C' && !in_array($invoice['docnum_interno_sap'], $seenPendingDocNums)) {
                            $uniquePending[] = $invoice;
                            $seenPendingDocNums[] = $invoice['docnum_interno_sap'];
                        }
                    }
                    
                    return count($uniquePending);
                }

                function contarFacturasPorProveedor($pending_invoices) {
                    $uniquePending = [];
                    $seenPendingDocNums = [];
                    $contadorPorProveedor = [];
                    
                    foreach ($pending_invoices as $invoice) {
                        if ($invoice['ESTADOSAP'] != 'C' && !in_array($invoice['docnum_interno_sap'], $seenPendingDocNums)) {
                            $uniquePending[] = $invoice;
                            $seenPendingDocNums[] = $invoice['docnum_interno_sap'];
                            
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

                $totalFacturas = contarFacturasMostradas($pending_invoices);
                $conteoDetallado = contarFacturasPorProveedor($pending_invoices);
                ?>
                
                <!-- SECCIÓN MODIFICADA: Tabla de facturas ya aprobadas con filtro de rango de fechas -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-success text-white">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="mb-0 d-flex align-items-center flex-wrap">
                                <i class="fas fa-check-circle me-2"></i>
                                Facturas Aprobadas por Usted
                            </h5>
                            <div class="mt-2 mt-md-0">
                                <small class="text-white opacity-75">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Los filtros de fechas se aplican desde el formulario principal arriba
                                </small>
                            </div>
                        </div>
                    </div>
             
                    <div class="card-body">
                        <?php 
                        // Inicializar si no está definida (por seguridad)
                        if (!isset($uniqueApproved)) {
                            $uniqueApproved = [];
                        }
                        
                        // Mensaje de depuración visible (temporal)
                        if (count($approved_invoices) > 0 && count($uniqueApproved) == 0): ?>
                            <div class="alert alert-warning">
                                <strong>Depuración:</strong> Se encontraron <?php echo count($approved_invoices); ?> facturas aprobadas, 
                                pero después del filtrado quedan <?php echo count($uniqueApproved); ?>.
                                <br><small>
                                    <?php 
                                    // Mostrar información de depuración
                                    if (count($approved_invoices) > 0) {
                                        $first = $approved_invoices[0];
                                        echo "Primera factura - ESTADOSAP: " . (isset($first['ESTADOSAP']) ? $first['ESTADOSAP'] : 'NULL') . 
                                             ", docnum: " . (isset($first['docnum_interno_sap']) ? $first['docnum_interno_sap'] : 'NULL');
                                    }
                                    ?>
                                </small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (count($uniqueApproved) > 0): ?>
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
                                            $supplierGroups = [];
                                            foreach ($uniqueApproved as $invoice) {
                                                $supplier = trim($invoice['nombre']);
                                                if (!isset($supplierGroups[$supplier])) {
                                                    $supplierGroups[$supplier] = [
                                                        'count' => 0,
                                                        'total' => 0,
                                                        'recent_count' => 0
                                                    ];
                                                }
                                                $supplierGroups[$supplier]['count']++;
                                                $supplierGroups[$supplier]['total'] += $invoice['saldo_pendiente'];
                                                
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
                                                                        <?php echo $data['recent_count']; ?> en rango
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
                                                $approvalDate = '';
                                                $isInRange = false;
                                                if (isset($invoice['approval_time'])) {
                                                    $approvalDate = formatApprovalTime($invoice['approval_time']);
                                                    
                                                    // Convertir approval_time a formato de fecha para comparación
                                                    $approvalDateOnly = '';
                                                    if (is_object($invoice['approval_time']) && method_exists($invoice['approval_time'], 'format')) {
                                                        $approvalDateOnly = $invoice['approval_time']->format('Y-m-d');
                                                    } elseif (is_string($invoice['approval_time'])) {
                                                        $approvalDateOnly = date('Y-m-d', strtotime($invoice['approval_time']));
                                                    } else {
                                                        $approvalDateOnly = date('Y-m-d', strtotime($approvalDate));
                                                    }

                                                    $isInRange = (!empty($filter_date_from) && !empty($filter_date_to)) ? 
                                                        ($approvalDateOnly >= $filter_date_from && $approvalDateOnly <= $filter_date_to) : true;
                                                }
                                                
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
                                                <tr class="<?php echo ($isInRange) ? 'recent-approval' : ''; ?> <?php echo (empty($filter_supplier)) ? 'supplier-group' : ''; ?>">
                                                    <td>
                                                        <?php echo $invoice['docnum_interno_sap']; ?>
                                                        <?php if ($isInRange): ?>
                                                            <span class="badge bg-success ms-1" title="En rango de fechas">
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
                                                        <span class="badge approval-time-badge <?php echo ($isInRange) ? 'bg-warning text-dark' : ''; ?>">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?php echo $approvalDate; ?>
                                                            <?php if ($isInRange): ?>
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

                            <div id="approvedStatusInfo" class="alert alert-info">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-eye-slash me-2"></i>
                                    <div>
                                        <strong>Facturas aprobadas ocultas</strong> - 
                                        Tienes <?php echo count($uniqueApproved); ?> facturas aprobadas
                                        <?php if (!empty($filter_date_from) && !empty($filter_date_to)): ?>
                                            <span class="badge bg-info text-white ms-2">
                                                <?php echo date('d/m/Y', strtotime($filter_date_from)); ?> - 
                                                <?php echo date('d/m/Y', strtotime($filter_date_to)); ?>
                                            </span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            Usa el selector arriba para ver resumen o detalles cuando necesites consultarlas
                                        </small>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                          
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <button class="back-button" onclick="goBack()">
        <span class="arrow">←</span>
    </button>

    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateDateFilter() {
        document.getElementById('filtersForm').submit();
    }

    function goBack() {
        window.history.back();
    }

    function clearAllFilters(event) {
        event.preventDefault();
        // Limpiar todos los campos del formulario
        document.getElementById('filter_supplier').value = '';
        document.getElementById('filter_date_from').value = '';
        document.getElementById('filter_date_to').value = '';
        // Redirigir a la página sin parámetros para aplicar valores por defecto
        window.location.href = window.location.pathname;
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted || performance.getEntriesByType("navigation")[0].type === "back_forward") {
            window.location.reload();
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        // Manejar modales
        document.querySelectorAll('.modal').forEach(modalEl => {
            modalEl.addEventListener('show.bs.modal', function () {
                if (this.parentElement !== document.body) {
                    document.body.appendChild(this);
                }
            });
        });
        const backBtn = document.querySelector('.back-button');
        if (backBtn) backBtn.style.display = 'none';

        window.storageManager = new InvoiceStorageManager();

        const expandAllBtn = document.getElementById('expandAllBtn');
        const collapseAllBtn = document.getElementById('collapseAllBtn');
        const toggleButtons = document.querySelectorAll('.btn-toggle');
        
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

        toggleApprovedView();

        <?php if ($role === 'subgerente'): ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas aprobadas por contador o verificador y facturas corregidas pendientes de su aprobación.");
        <?php elseif ($role === 'verificador'): ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas aprobadas por contador pendientes de su verificación.");
        <?php else: ?>
            console.log("Sistema de facturas cargado correctamente. Mostrando facturas pendientes de su aprobación.");
        <?php endif; ?>
    });

    // Sistema de localStorage mejorado
    class InvoiceStorageManager {
        constructor() {
            this.storageKey = 'invoiceSystem_v2';
            this.userId = '<?php echo $user_id; ?>';
            this.userRole = '<?php echo $role; ?>';
            this.autoSaveInterval = null;
            this.preferences = this.loadPreferences();
            this.init();
        }

        init() {
            this.loadUserState();
            this.setupEventListeners();
            this.startAutoSave();
            this.applyPreferences();
        }

        loadPreferences() {
            const defaultPreferences = {
                autoSaveFilters: true,
                rememberCollapsed: true,
                autoRefresh: false,
                compactView: false,
                approvedView: 'hidden',
                collapsedSuppliers: [],
                lastFilters: {},
                scrollPosition: 0
            };

            try {
                const saved = localStorage.getItem(`${this.storageKey}_preferences_${this.userId}`);
                return saved ? { ...defaultPreferences, ...JSON.parse(saved) } : defaultPreferences;
            } catch (error) {
                console.error('Error loading preferences:', error);
                return defaultPreferences;
            }
        }

        savePreferences() {
            try {
                localStorage.setItem(`${this.storageKey}_preferences_${this.userId}`, JSON.stringify(this.preferences));
                this.showAutoSaveIndicator();
            } catch (error) {
                console.error('Error saving preferences:', error);
            }
        }

        loadUserState() {
            try {
                const saved = localStorage.getItem(`${this.storageKey}_state_${this.userId}`);
                if (saved) {
                    const state = JSON.parse(saved);
                    
                    if (this.preferences.autoSaveFilters && state.filters) {
                        this.restoreFilters(state.filters);
                    }

                    if (this.preferences.rememberCollapsed && state.collapsedSuppliers) {
                        this.restoreCollapsedState(state.collapsedSuppliers);
                    }

                    if (state.approvedView) {
                        this.preferences.approvedView = state.approvedView;
                        const select = document.getElementById('viewApprovedSelect');
                        if (select) {
                            select.value = state.approvedView;
                            toggleApprovedView();
                        }
                    }

                    if (state.scrollPosition) {
                        setTimeout(() => {
                            window.scrollTo(0, state.scrollPosition);
                        }, 100);
                    }
                }
            } catch (error) {
                console.error('Error loading user state:', error);
            }
        }

        saveUserState() {
            try {
                const state = {
                    filters: this.getCurrentFilters(),
                    collapsedSuppliers: this.getCollapsedSuppliers(),
                    approvedView: this.preferences.approvedView,
                    scrollPosition: window.pageYOffset,
                    timestamp: Date.now()
                };

                localStorage.setItem(`${this.storageKey}_state_${this.userId}`, JSON.stringify(state));
            } catch (error) {
                console.error('Error saving user state:', error);
            }
        }

        getCurrentFilters() {
            const form = document.getElementById('filtersForm');
            if (!form) return {};

            const formData = new FormData(form);
            const filters = {};
            
            for (let [key, value] of formData.entries()) {
                if (value) filters[key] = value;
            }

            return filters;
        }

        restoreFilters(filters) {
            Object.keys(filters).forEach(key => {
                const element = document.getElementById(key) || document.querySelector(`[name="${key}"]`);
                if (element) {
                    element.value = filters[key];
                }
            });
        }

        getCollapsedSuppliers() {
            const collapsed = [];
            document.querySelectorAll('.btn-toggle').forEach(button => {
                const icon = button.querySelector('i');
                if (icon && icon.classList.contains('fa-chevron-right')) {
                    const supplierIndex = button.getAttribute('data-supplier-index');
                    if (supplierIndex) collapsed.push(supplierIndex);
                }
            });
            return collapsed;
        }

        restoreCollapsedState(collapsedSuppliers) {
            setTimeout(() => {
                collapsedSuppliers.forEach(supplierIndex => {
                    const button = document.querySelector(`[data-supplier-index="${supplierIndex}"]`);
                    if (button) {
                        const target = button.getAttribute('data-bs-target');
                        const collapse = new bootstrap.Collapse(document.querySelector(target), {
                            toggle: false
                        });
                        collapse.hide();
                        button.querySelector('i').className = 'fas fa-chevron-right';
                    }
                });
            }, 500);
        }

        setupEventListeners() {
            document.addEventListener('change', (e) => {
                if (e.target.closest('#filtersForm')) {
                    if (this.preferences.autoSaveFilters) {
                        this.saveUserState();
                    }
                }
            });

            document.addEventListener('click', (e) => {
                if (e.target.closest('.btn-toggle')) {
                    setTimeout(() => {
                        if (this.preferences.rememberCollapsed) {
                            this.saveUserState();
                        }
                    }, 300);
                }
            });

            let scrollTimeout;
            window.addEventListener('scroll', () => {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    this.saveUserState();
                }, 1000);
            });

            window.addEventListener('beforeunload', () => {
                this.saveUserState();
            });

            document.getElementById('autoSaveFilters').addEventListener('change', (e) => {
                this.preferences.autoSaveFilters = e.target.checked;
                this.savePreferences();
            });

            document.getElementById('rememberCollapsed').addEventListener('change', (e) => {
                this.preferences.rememberCollapsed = e.target.checked;
                this.savePreferences();
            });

            document.getElementById('autoRefresh').addEventListener('change', (e) => {
                this.preferences.autoRefresh = e.target.checked;
                this.savePreferences();
                this.toggleAutoRefresh();
            });

            document.getElementById('compactView').addEventListener('change', (e) => {
                this.preferences.compactView = e.target.checked;
                this.savePreferences();
                this.toggleCompactView();
            });
        }

        applyPreferences() {
            document.getElementById('autoSaveFilters').checked = this.preferences.autoSaveFilters;
            document.getElementById('rememberCollapsed').checked = this.preferences.rememberCollapsed;
            document.getElementById('autoRefresh').checked = this.preferences.autoRefresh;
            document.getElementById('compactView').checked = this.preferences.compactView;

            if (this.preferences.autoRefresh) {
                this.toggleAutoRefresh();
            }

            if (this.preferences.compactView) {
                this.toggleCompactView();
            }
        }

        toggleAutoRefresh() {
            if (this.preferences.autoRefresh) {
                this.autoRefreshInterval = setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        location.reload();
                    }
                }, 300000);
            } else {
                if (this.autoRefreshInterval) {
                    clearInterval(this.autoRefreshInterval);
                }
            }
        }

        toggleCompactView() {
            document.body.classList.toggle('compact-view', this.preferences.compactView);
        }

        startAutoSave() {
            this.autoSaveInterval = setInterval(() => {
                this.saveUserState();
            }, 30000);
        }

        showAutoSaveIndicator() {
            const indicator = document.getElementById('autoSaveIndicator');
            indicator.classList.add('show');
            setTimeout(() => {
                indicator.classList.remove('show');
            }, 2000);
        }

        clearAllData() {
            if (confirm('¿Está seguro de que desea limpiar todos los datos guardados? Esta acción no se puede deshacer.')) {
                try {
                    localStorage.removeItem(`${this.storageKey}_preferences_${this.userId}`);
                    localStorage.removeItem(`${this.storageKey}_state_${this.userId}`);
                    
                    Object.keys(localStorage).forEach(key => {
                        if (key.startsWith(this.storageKey)) {
                            localStorage.removeItem(key);
                        }
                    });

                    alert('Datos limpiados correctamente. La página se recargará.');
                    location.reload();
                } catch (error) {
                    console.error('Error clearing data:', error);
                    alert('Error al limpiar los datos.');
                }
            }
        }
    }

    function toggleSettingsPanel() {
        const panel = document.getElementById('settingsPanel');
        panel.classList.toggle('show');
    }

    function clearAllData() {
        if (window.storageManager) {
            window.storageManager.clearAllData();
        }
    }

    function toggleApprovedView() {
        const select = document.getElementById('viewApprovedSelect');
        const summaryView = document.getElementById('approvedSummaryView');
        const detailedView = document.getElementById('approvedDetailedView');
        const statusInfo = document.getElementById('approvedStatusInfo');
        
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

        if (window.storageManager) {
            window.storageManager.preferences.approvedView = select.value;
            window.storageManager.savePreferences();
        }
    }
    </script>
</body>
</htm