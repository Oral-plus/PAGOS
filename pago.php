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

// Clase para manejar las operaciones de facturas FINALIZADAS
class FinalizedInvoiceManager {
    private $conn;
    
    public function __construct() {
        $this->conn = getDbConnection();
    }
    
    /**
     * Marcar una factura como NO final (revertir)
     */
    public function unmarkInvoiceAsFinal($invoice_id) {
        try {
            if (is_a($this->conn, 'PDO')) {
                $sql = "UPDATE invoices SET final = NULL WHERE docnum_interno_sap = ? AND final = 'si'";
                $stmt = $this->conn->prepare($sql);
                return $stmt->execute([$invoice_id]);
            } else {
                $sql = "UPDATE invoices SET final = NULL WHERE docnum_interno_sap = ? AND final = 'si'";
                $params = array($invoice_id);
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al revertir factura: ' . print_r(sqlsrv_errors(), true));
                }
                return true;
            }
        } catch (Exception $e) {
            error_log("Error en unmarkInvoiceAsFinal: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Buscar proveedores para autocompletado (solo facturas finalizadas)
     */
    public function searchSuppliers($search_term = '') {
        try {
            $sql = "SELECT DISTINCT nombre FROM invoices WHERE (final = 'si' OR final IS NOT NULL)";
            $params = array();
            
            if (!empty($search_term)) {
                $sql .= " AND nombre LIKE ?";
                $params[] = '%' . $search_term . '%';
            }
            
            $sql .= " ORDER BY nombre ASC LIMIT 10";
            
            if (is_a($this->conn, 'PDO')) {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } else {
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al buscar proveedores: ' . print_r(sqlsrv_errors(), true));
                }
                
                $suppliers = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC)) {
                    $suppliers[] = $row[0];
                }
                return $suppliers;
            }
        } catch (Exception $e) {
            error_log("Error en searchSuppliers: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * CAMBIO PRINCIPAL: Obtener facturas FINALIZADAS con filtros avanzados
     * Muestra facturas donde final = 'si' O final IS NOT NULL
     */
    public function getFinalizedInvoices($supplier = '', $date_from = '', $date_to = '', $today_only = '') {
        try {
            // CAMBIO: Buscar facturas FINALIZADAS (final = 'si' o final NOT NULL)
            $sql = "SELECT *,
                     DATEDIFF(day, fecha_vencimiento, GETDATE()) as dias_antiguedad,
                     DATEDIFF(day, created_at, GETDATE()) as dias_finalizada
                    FROM invoices 
                    WHERE (final = 'si' OR final IS NOT NULL)
                    AND ESTADOSAP = 'O'";
            $params = array();
            
            if (!empty($today_only) && $today_only === '1') {
                // CAMBIO: Filtrar por fecha de finalización de HOY
                $sql .= " AND CONVERT(DATE, updated_at) = CONVERT(DATE, GETDATE())";
            }
            
            if (!empty($supplier)) {
                $sql .= " AND nombre LIKE ?";
                $params[] = '%' . $supplier . '%';
            }
            
            if (!empty($date_from)) {
                $sql .= " AND fecha_vencimiento >= ?";
                $params[] = $date_from;
            }
            
            if (!empty($date_to)) {
                $sql .= " AND fecha_vencimiento <= ?";
                $params[] = $date_to;
            }
            
            $sql .= " ORDER BY nombre ASC, fecha_vencimiento DESC";
            
            if (is_a($this->conn, 'PDO')) {
                // Adaptar para MySQL
                $sql = str_replace("DATEDIFF(day, fecha_vencimiento, GETDATE())",
                                   "DATEDIFF(CURDATE(), fecha_vencimiento)", $sql);
                $sql = str_replace("CONVERT(DATE, GETDATE())", "CURDATE()", $sql);
                $sql = str_replace("CONVERT(DATE, updated_at)", "DATE(updated_at)", $sql);
                $sql = str_replace("DATEDIFF(day, created_at, GETDATE())", 
                                   "DATEDIFF(CURDATE(), created_at)", $sql);
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // SQL Server
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al obtener facturas finalizadas: ' . print_r(sqlsrv_errors(), true));
                }
                
                $invoices = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $invoices[] = $row;
                }
                return $invoices;
            }
        } catch (Exception $e) {
            error_log("Error en getFinalizedInvoices: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Obtener estadísticas de facturas FINALIZADAS
     */
    public function getFinalizedInvoiceStats($invoices) {
        $stats = [
            'total_invoices' => 0,
            'total_amount' => 0,
            'suppliers_count' => 0,
            'priority_breakdown' => ['alta' => 0, 'media' => 0, 'baja' => 0],
            'today_count' => 0
        ];
        
        $unique_suppliers = array();
        $today = date('Y-m-d');
        
        foreach ($invoices as $invoice) {
            $stats['total_invoices']++;
            $stats['total_amount'] += abs(floatval($invoice['saldo_pendiente'] ?? 0));
            
            $supplier = $invoice['nombre'];
            if (!in_array($supplier, $unique_suppliers)) {
                $unique_suppliers[] = $supplier;
            }
            
            $priority = strtolower(trim($invoice['priority'] ?? 'baja'));
            if (isset($stats['priority_breakdown'][$priority])) {
                $stats['priority_breakdown'][$priority]++;
            }
            
            // CAMBIO: Verificar fecha de FINALIZACIÓN (updated_at o created_at)
            $finalized_date = safeDateFormat($invoice['updated_at'] ?? $invoice['created_at'] ?? '', 'Y-m-d');
            if ($finalized_date === $today) {
                $stats['today_count']++;
            }
        }
        
        $stats['suppliers_count'] = count($unique_suppliers);
        
        $stats['average_amount'] = $stats['total_invoices'] > 0 ? $stats['total_amount'] / $stats['total_invoices'] : 0;
        $stats['high_priority'] = $stats['priority_breakdown']['alta'];
        $stats['medium_priority'] = $stats['priority_breakdown']['media'];
        $stats['low_priority'] = $stats['priority_breakdown']['baja'];
        $stats['finalized_today'] = $stats['today_count'];
        
        return $stats;
    }
    
    /**
     * Generar archivo Excel para facturas FINALIZADAS
     */
    public function generateExcel($supplier = '', $date_from = '', $date_to = '', $today_only = '') {
        try {
            $invoices = $this->getFinalizedInvoices($supplier, $date_from, $date_to, $today_only);
            
            if (empty($invoices)) {
                return false;
            }

            $filename = 'facturas_finalizadas_' . date('Y-m-d_H-i-s') . '.xls';
            
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            
            echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>Facturas Finalizadas</title></head>';
            echo '<body>';
            
            // Hoja 1: Resumen por Proveedores
            echo '<table border="1">';
            echo '<tr><td colspan="6" style="background-color: #28a745; color: white; font-weight: bold; text-align: center;">RESUMEN DE FACTURAS FINALIZADAS POR PROVEEDORES</td></tr>';
            echo '<tr style="background-color: #d4edda; font-weight: bold;">';
            echo '<td>Proveedor</td><td>Total Facturas</td><td>Valor Total</td><td>Promedio</td><td>Más Antigua</td><td>Más Reciente</td>';
            echo '</tr>';
            
            $grouped = array();
            foreach ($invoices as $invoice) {
                $supplier_name = $invoice['nombre'] ?? 'Sin nombre';
                if (!isset($grouped[$supplier_name])) {
                    $grouped[$supplier_name] = array(
                        'count' => 0,
                        'total' => 0,
                        'dates' => array()
                    );
                }
                $grouped[$supplier_name]['count']++;
                $grouped[$supplier_name]['total'] += abs(floatval($invoice['saldo_pendiente'] ?? 0));
                $grouped[$supplier_name]['dates'][] = $invoice['fecha_vencimiento'];
            }
            
            foreach ($grouped as $supplier_name => $data) {
                $average = $data['count'] > 0 ? $data['total'] / $data['count'] : 0;
                $oldest = min($data['dates']);
                $newest = max($data['dates']);
                
                echo '<tr>';
                echo '<td>' . htmlspecialchars($supplier_name) . '</td>';
                echo '<td>' . $data['count'] . '</td>';
                echo '<td>$' . number_format($data['total'], 2) . '</td>';
                echo '<td>$' . number_format($average, 2) . '</td>';
                echo '<td>' . safeDateFormat($oldest, 'd/m/Y') . '</td>';
                echo '<td>' . safeDateFormat($newest, 'd/m/Y') . '</td>';
                echo '</tr>';
            }
            echo '</table><br><br>';
            
            // Hoja 2: Detalle de Facturas Finalizadas
            echo '<table border="1">';
            echo '<tr><td colspan="9" style="background-color: #28a745; color: white; font-weight: bold; text-align: center;">DETALLE DE FACTURAS FINALIZADAS</td></tr>';
            echo '<tr style="background-color: #d4edda; font-weight: bold;">';
            echo '<td>N° SAP</td><td>NIT</td><td>Proveedor</td><td>Fecha Vencimiento</td><td>Valor</td><td>Prioridad</td><td>Estado</td><td>Días Finalizada</td><td>Estado Final</td>';
            echo '</tr>';
            
            foreach ($invoices as $invoice) {
                $priority_color = '';
                switch (strtolower($invoice['priority'] ?? '')) {
                    case 'alta':
                        $priority_color = 'background-color: #f8d7da;';
                        break;
                    case 'media':
                        $priority_color = 'background-color: #fff3cd;';
                        break;
                    case 'baja':
                        $priority_color = 'background-color: #d4edda;';
                        break;
                }
                
                $final_status = $invoice['final'] === 'si' ? 'FINALIZADA' : 'MARCADA';
                
                echo '<tr style="' . $priority_color . '">';
                echo '<td>' . htmlspecialchars($invoice['docnum_interno_sap'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['codigo_sn'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['nombre'] ?? '') . '</td>';
                echo '<td>' . safeDateFormat($invoice['fecha_vencimiento'], 'd/m/Y') . '</td>';
                echo '<td>$' . number_format(abs(floatval($invoice['saldo_pendiente'] ?? 0)), 2) . '</td>';
                echo '<td>' . htmlspecialchars($invoice['priority'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['status'] ?? '') . '</td>';
                echo '<td>' . ($invoice['dias_finalizada'] ?? 0) . '</td>';
                echo '<td><strong style="color: #28a745;">' . $final_status . '</strong></td>';
                echo '</tr>';
            }
            echo '</table><br><br>';
            
            // Hoja 3: Estadísticas
            $stats = $this->getFinalizedInvoiceStats($invoices);
            echo '<table border="1">';
            echo '<tr><td colspan="2" style="background-color: #17a2b8; color: white; font-weight: bold; text-align: center;">ESTADÍSTICAS DE FACTURAS FINALIZADAS</td></tr>';
            echo '<tr><td style="font-weight: bold;">Total de Facturas Finalizadas:</td><td>' . $stats['total_invoices'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Valor Total:</td><td>$' . number_format($stats['total_amount'], 2) . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Promedio por Factura:</td><td>$' . number_format($stats['average_amount'], 2) . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Alta Prioridad:</td><td>' . $stats['high_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Media Prioridad:</td><td>' . $stats['medium_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Baja Prioridad:</td><td>' . $stats['low_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Finalizadas Hoy:</td><td>' . $stats['finalized_today'] . '</td></tr>';
            echo '</table>';
            
            echo '</body></html>';
            exit();
            
        } catch (Exception $e) {
            error_log("Error generando Excel: " . $e->getMessage());
            return false;
        }
    }
}

// Funciones helper
if (!function_exists('safeDateFormat')) {
    function safeDateFormat($date, $format = 'Y-m-d') {
        if (empty($date)) return '';
        
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format($format);
        }
        
        if (is_string($date)) {
            try {
                $timestamp = strtotime($date);
                if ($timestamp !== false) {
                    return date($format, $timestamp);
                }
            } catch (Exception $e) {
                error_log("Error formatting date: " . $e->getMessage());
            }
        }
        
        if (is_numeric($date)) {
            return date($format, $date);
        }
        
        return '';
    }
}

if (!function_exists('getDateString')) {
    function getDateString($date) {
        if (empty($date)) return '';
        
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('Y-m-d H:i:s');
        }
        
        if (is_string($date)) {
            return $date;
        }
        
        if (is_numeric($date)) {
            return date('Y-m-d H:i:s', $date);
        }
        
        return '';
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (empty($date)) return 'Fecha no disponible';
        
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('d/m/Y');
        }
        
        if (is_string($date)) {
            try {
                $timestamp = strtotime($date);
                if ($timestamp !== false) {
                    return date('d/m/Y', $timestamp);
                }
            } catch (Exception $e) {
                error_log("Error formatting date: " . $e->getMessage());
            }
        }
        
        if (is_numeric($date)) {
            return date('d/m/Y', $date);
        }
        
        return 'Fecha inválida';
    }
}

if (!function_exists('getPriorityClass')) {
    function getPriorityClass($priority) {
        $priority = strtolower(trim($priority));
        switch ($priority) {
            case 'alta':
                return ['bg' => '#f8d7da', 'text' => '#721c24', 'border' => '#f5c6cb'];
            case 'media':
                return ['bg' => '#fff3cd', 'text' => '#856404', 'border' => '#ffeeba'];
            case 'baja':
                return ['bg' => '#d4edda', 'text' => '#155724', 'border' => '#c3e6cb'];
            default:
                return ['bg' => '#e0e0e0', 'text' => '#000', 'border' => '#ccc'];
        }
    }
}

if (!function_exists('sanitizeNumber')) {
    function sanitizeNumber($amount) {
        if (is_null($amount) || $amount === '' || !is_numeric($amount)) {
            return 0.0;
        }
        return abs(floatval($amount));
    }
}

if (!function_exists('formatColombiaPesos')) {
    function formatColombiaPesos($amount) {
        $positiveAmount = sanitizeNumber($amount);
        return '$' . number_format($positiveAmount, 0, ',', '.') . ' COP';
    }
}

if (!function_exists('getPositiveValue')) {
    function getPositiveValue($amount) {
        return sanitizeNumber($amount);
    }
}

if (!function_exists('formatColombiaNumber')) {
    function formatColombiaNumber($amount) {
        $positiveAmount = sanitizeNumber($amount);
        return number_format($positiveAmount, 0, ',', '.');
    }
}

// Instanciar el manejador de facturas FINALIZADAS
$invoiceManager = new FinalizedInvoiceManager();

// API endpoint para búsqueda de proveedores FINALIZADOS
if (isset($_GET['action']) && $_GET['action'] === 'search_suppliers') {
    header('Content-Type: application/json');
    $search_term = $_GET['q'] ?? '';
    $suppliers = $invoiceManager->searchSuppliers($search_term);
    echo json_encode($suppliers);
    exit();
}

// CAMBIO: Procesar la REVERTIR del campo "final"
if (isset($_POST['unmark_final']) && isset($_POST['invoice_id'])) {
    $invoice_id = $_POST['invoice_id'];
    $success = $invoiceManager->unmarkInvoiceAsFinal($invoice_id);
    
    if ($success) {
        $_SESSION['success_message'] = "Factura #$invoice_id revertida exitosamente. Ya no está marcada como final.";
    } else {
        $_SESSION['error_message'] = "Error al revertir la factura #$invoice_id.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_excel') {
    $supplier = $_GET['supplier'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $today_only = $_GET['today_only'] ?? '';
    
    $invoiceManager->generateExcel($supplier, $date_from, $date_to, $today_only);
    exit();
}

// Inicializar filtros
$filter_supplier = filter_input(INPUT_GET, 'filter_supplier', FILTER_SANITIZE_STRING) ?? '';
$filter_date_from = filter_input(INPUT_GET, 'filter_date_from', FILTER_SANITIZE_STRING) ?? '';
$filter_date_to = filter_input(INPUT_GET, 'filter_date_to', FILTER_SANITIZE_STRING) ?? '';
$filter_today_only = $_GET['filter_today_only'] ?? '';

// Validar fechas
if (!empty($filter_date_from) && !DateTime::createFromFormat('Y-m-d', $filter_date_from)) {
    $filter_date_from = '';
}
if (!empty($filter_date_to) && !DateTime::createFromFormat('Y-m-d', $filter_date_to)) {
    $filter_date_to = '';
}

// CAMBIO: Obtener facturas FINALIZADAS
$finalized_invoices = $invoiceManager->getFinalizedInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_today_only);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Finalizadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <style>
        /* ===========================
           VARIABLES PREMIUM
           =========================== */
        :root {
            --primary: #0d6efd;
            --primary-dark: #0056b3;
            --primary-light: #3d8bfd;
            --success: #28a745;
            --success-dark: #198754;
            --success-light: #34ce57;
            --warning: #ffc107;
            --warning-dark: #ff8f00;
            --danger: #dc3545;
            --danger-dark: #b71c1c;
            --info: #17a2b8;
            --info-dark: #138496;
            --secondary: #6c757d;
            --secondary-dark: #5a6268;
            
            --gray-50: #f8f9fa;
            --gray-100: #e9ecef;
            --gray-200: #dee2e6;
            --gray-300: #ced4da;
            --gray-700: #495057;
            --gray-800: #343a40;
            
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* ===========================
           HEADER DE FACTURAS FINALIZADAS PREMIUM
           =========================== */
        .finalized-header {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-left: 4px solid var(--success);
            border-top: 3px solid var(--success);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
            margin-top: 1.5rem;
            font-weight: 700;
            color: #155724;
            border-bottom: 2px solid rgba(40, 167, 69, 0.2);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .finalized-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--success), var(--success-light));
            opacity: 0.8;
        }
        
        .finalized-header:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-left-width: 5px;
        }
        
        /* ===========================
           FACTURAS FINALIZADAS PREMIUM
           =========================== */
        .finalized-invoice {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.05), rgba(40, 167, 69, 0.02)) !important;
            border-left: 4px solid var(--success) !important;
            transition: var(--transition);
        }
        
        .finalized-invoice:hover {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.08), rgba(40, 167, 69, 0.05)) !important;
            border-left-width: 5px;
            transform: translateX(2px);
        }
        
        .finalized-status {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
            font-weight: 700;
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            box-shadow: var(--shadow-sm);
            border: 2px solid rgba(255, 255, 255, 0.2);
            letter-spacing: 0.025em;
            transition: var(--transition);
        }
        
        .finalized-status:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-md);
        }
        
        .revert-btn {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            border-color: var(--danger-dark);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        
        .revert-btn:hover {
            background: linear-gradient(135deg, #ef4444, var(--danger));
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .today-finalized-invoice {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.1), rgba(76, 175, 80, 0.05)) !important;
            border-left: 4px solid #4caf50 !important;
            transition: var(--transition);
        }
        
        .today-finalized-invoice:hover {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.15), rgba(76, 175, 80, 0.08)) !important;
            border-left-width: 5px;
            transform: translateX(2px);
        }
        
        /* ===========================
           HEADER DE PROVEEDOR PREMIUM
           =========================== */
        .supplier-header {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.05), rgba(40, 167, 69, 0.02));
            border-left: 4px solid var(--success);
            border-top: 3px solid var(--success);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
            margin-top: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            border-bottom: 2px solid var(--gray-200);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .supplier-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--success), var(--success-light));
            opacity: 0.8;
        }
        
        .supplier-header:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-left-width: 5px;
        }
        
        /* ===========================
           TABLA DE FACTURAS PREMIUM
           =========================== */
        .invoice-table {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .invoice-table:hover {
            box-shadow: var(--shadow-md);
        }
        
        /* ===========================
           RESUMEN TOTAL PREMIUM
           =========================== */
        .total-summary {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-radius: var(--radius-xl);
            border: 3px solid var(--success);
            position: sticky;
            bottom: 0;
            z-index: 5;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            transition: var(--transition);
        }
        
        .total-summary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-2xl);
        }
        
        /* ===========================
           TARJETAS DE ESTADÍSTICAS PREMIUM
           =========================== */
        .stats-card {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-left: 4px solid var(--success);
            border-radius: var(--radius-lg);
            transition: var(--transition);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            position: relative;
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
            pointer-events: none;
        }
        
        .stats-card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: var(--shadow-xl);
            border-left-width: 5px;
        }
        
        /* ===========================
           BADGE DE PRIORIDAD PREMIUM
           =========================== */
        .priority-badge {
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-transform: capitalize;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
        }
        
        .priority-badge:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-md);
        }
        
        /* ===========================
           FILTRO DE HOY PREMIUM
           =========================== */
        .today-filter-container {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .today-filter-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
            pointer-events: none;
        }
        
        .today-filter-switch {
            transform: scale(1.3);
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
        }
        
        /* ===========================
           BÚSQUEDA DE PROVEEDORES PREMIUM
           =========================== */
        .supplier-search-container {
            position: relative;
        }
        
        .supplier-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--success);
            border-top: none;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(10px);
        }
        
        .supplier-suggestion {
            padding: 0.875rem 1.25rem;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
            font-weight: 500;
            color: var(--gray-700);
        }
        
        .supplier-suggestion:hover,
        .supplier-suggestion.active {
            background: linear-gradient(90deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            transform: translateX(4px);
            border-left: 3px solid var(--success);
        }
        
        .search-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-700);
            font-size: 1.125rem;
        }
        
        .no-results {
            padding: 1rem 1.25rem;
            color: var(--gray-700);
            font-style: italic;
            text-align: center;
        }
        
        /* ===========================
           PANEL DE CONFIGURACIÓN PREMIUM
           =========================== */
        .settings-panel {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-2xl);
            padding: 1.75rem;
            z-index: 1050;
            max-width: 380px;
            transform: translateX(100%);
            transition: var(--transition);
            border: 3px solid var(--success);
            backdrop-filter: blur(12px);
        }
        
        .settings-panel.show {
            transform: translateX(0);
        }
        
        .settings-toggle {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 1051;
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
            border: none;
            border-radius: var(--radius-full);
            width: 60px;
            height: 60px;
            box-shadow: var(--shadow-xl);
            font-size: 1.375rem;
            transition: var(--transition);
            border: 3px solid rgba(255, 255, 255, 0.2);
        }
        
        .settings-toggle:hover {
            background: linear-gradient(135deg, var(--success-light), var(--success));
            transform: rotate(90deg) scale(1.1);
            box-shadow: var(--shadow-2xl);
        }
        
        .preference-item {
            margin-bottom: 1.25rem;
            padding: 1.125rem;
            border-radius: var(--radius-lg);
            background: linear-gradient(135deg, var(--gray-50), white);
            border-left: 3px solid var(--success);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .preference-item:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow-md);
            border-left-width: 4px;
        }
        
        .auto-save-indicator {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-full);
            font-size: 0.9375rem;
            opacity: 0;
            transition: var(--transition);
            z-index: 1000;
            box-shadow: var(--shadow-xl);
            font-weight: 600;
            letter-spacing: 0.025em;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .auto-save-indicator.show {
            opacity: 1;
            transform: translateY(-4px);
        }
        
        /* ===========================
           AGRUPACIÓN POR PROVEEDORES PREMIUM
           =========================== */
        .supplier-group {
            background: linear-gradient(135deg, var(--gray-50), white);
            border-left: 4px solid var(--success);
            border-radius: var(--radius-md);
            transition: var(--transition);
        }
        
        .supplier-group:hover {
            border-left-width: 5px;
            box-shadow: var(--shadow-sm);
        }
        
        .supplier-total-badge {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
            font-weight: 700;
            padding: 0.625rem 1.125rem;
            border-radius: var(--radius-full);
            font-size: 0.9375rem;
            box-shadow: var(--shadow-md);
            border: 2px solid rgba(255, 255, 255, 0.2);
            letter-spacing: 0.025em;
            transition: var(--transition);
        }
        
        .supplier-total-badge:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-lg);
        }
        
        .supplier-count-badge {
            background: linear-gradient(135deg, var(--info), var(--info-dark));
            color: white;
            font-weight: 600;
            padding: 0.5rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: 0.875rem;
            box-shadow: var(--shadow-sm);
            border: 2px solid rgba(255, 255, 255, 0.2);
            letter-spacing: 0.025em;
            transition: var(--transition);
        }
        
        .supplier-count-badge:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-md);
        }
        
        /* ===========================
           ALERTA PERSONALIZADA PREMIUM
           =========================== */
        .custom-alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(4px);
        }
        
        .custom-alert {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-2xl);
            width: 100%;
            max-width: 500px;
            padding: 0;
            overflow: hidden;
            animation: slideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 3px solid var(--danger);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-40px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        
        .custom-alert-header {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
            padding: 1.5rem;
            text-align: center;
            font-weight: 800;
            font-size: 1.375rem;
            letter-spacing: 0.025em;
            position: relative;
            overflow: hidden;
        }
        
        .custom-alert-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
            pointer-events: none;
        }
        
        .custom-alert-body {
            padding: 2rem;
            text-align: center;
        }
        
        .custom-alert-body p {
            margin-bottom: 1.5rem;
            font-size: 1.125rem;
            color: var(--gray-700);
            line-height: 1.6;
            font-weight: 500;
        }
        
        .custom-alert-invoice {
            font-weight: 700;
            color: var(--danger);
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            margin: 1.5rem auto;
            display: inline-block;
            border: 3px solid var(--danger);
            box-shadow: var(--shadow-md);
            letter-spacing: 0.025em;
        }
        
        .custom-alert-footer {
            padding: 1.25rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            border-top: 2px solid var(--gray-200);
            background: var(--gray-50);
        }
        
        .btn-revert {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-lg);
            font-weight: 700;
            letter-spacing: 0.025em;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        
        .btn-revert:hover {
            background: linear-gradient(135deg, #ef4444, var(--danger));
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        /* ===========================
           CARDS Y CONTENEDORES PREMIUM
           =========================== */
        .card {
            border: none;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition);
            background: white;
        }
        
        .card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-2px);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
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
        
        .card-body {
            padding: 1.75rem;
        }
        
        /* ===========================
           TABLAS PREMIUM
           =========================== */
        .table {
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }
        
        .table thead {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-bottom: 3px solid var(--success);
        }
        
        .table thead th {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.8125rem;
            padding: 1rem 1.25rem;
            border-bottom: 3px solid var(--success);
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
            background: linear-gradient(90deg, rgba(40, 167, 69, 0.05), rgba(40, 167, 69, 0.02));
            transform: translateX(2px);
            box-shadow: var(--shadow-sm);
        }
        
        .table tbody td {
            padding: 1rem 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 500;
            color: var(--gray-700);
        }
        
        /* ===========================
           BOTONES PREMIUM
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
        
        .btn-outline-success {
            border: 2px solid var(--success);
            color: var(--success);
            background: transparent;
        }
        
        .btn-outline-success:hover {
            background: var(--success);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            border: 2px solid var(--gray-300);
            color: var(--gray-700);
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: var(--gray-700);
            color: white;
            border-color: var(--gray-700);
        }
        
        /* ===========================
           BADGES PREMIUM
           =========================== */
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
        
        .badge.bg-info {
            background: linear-gradient(135deg, var(--info), var(--info-dark)) !important;
        }
        
        .badge.bg-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-dark)) !important;
        }
        
        /* ===========================
           FORMULARIOS E INPUTS PREMIUM
           =========================== */
        .form-control,
        .form-select {
            border-radius: var(--radius-lg);
            border: 2px solid var(--gray-300);
            padding: 0.75rem 1rem;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9375rem;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: var(--success);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.1), var(--shadow-md);
            transform: translateY(-2px);
            outline: none;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
            letter-spacing: 0.025em;
        }
        
        /* ===========================
           ALERTAS PREMIUM
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
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-color: var(--success);
            color: var(--success-dark);
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border-color: var(--danger);
            color: #b91c1c;
        }
        
        .alert-info {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
            border-color: var(--info);
            color: var(--info-dark);
        }
        
        /* ===========================
           INDICADOR DE ESTADO PREMIUM
           =========================== */
        .status-indicator {
            position: fixed;
            top: 50%;
            right: 2rem;
            transform: translateY(-50%);
            background: white;
            border-radius: var(--radius-xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-2xl);
            border-left: 4px solid var(--success);
            opacity: 0;
            transition: var(--transition);
            z-index: 1000;
            max-width: 280px;
            backdrop-filter: blur(12px);
        }
        
        .status-indicator.show {
            opacity: 1;
            transform: translateY(-50%) translateX(-12px);
        }
        
        .status-indicator.success {
            border-left-color: var(--success);
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.05), white);
        }
        
        /* ===========================
           PREFERENCIAS PREMIUM
           =========================== */
        .preference-control {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preference-label {
            font-weight: 700;
            display: block;
            color: var(--gray-800);
            letter-spacing: 0.025em;
        }
        
        .preference-description {
            font-size: 0.875rem;
            color: var(--gray-700);
            margin-top: 0.25rem;
        }
        
        .settings-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .settings-section h6 {
            margin-bottom: 1rem;
            color: var(--success);
            font-weight: 700;
            letter-spacing: 0.025em;
            font-size: 1.0625rem;
        }
        
        .btn-toggle {
            background: transparent;
            border: none;
            color: var(--success);
            padding: 0.5rem 0.875rem;
            cursor: pointer;
            transition: var(--transition);
            border-radius: var(--radius-md);
        }
        
        .btn-toggle:hover {
            background: rgba(40, 167, 69, 0.1);
            transform: scale(1.05);
        }
        
        /* ===========================
           UTILIDADES PREMIUM
           =========================== */
        .loading-spinner {
            display: none;
        }
        
        .loading .loading-spinner {
            display: inline-block;
        }
        
        .loading .btn-text {
            display: none;
        }
        
        .compact-view .table td {
            padding: 0.375rem 0.625rem;
            font-size: 0.875rem;
        }
        
        .alert-dismissible {
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(15px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card {
            animation: fadeIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .table tbody tr {
            animation: slideInUp 0.3s ease-out;
            animation-fill-mode: both;
        }
        
        .table tbody tr:nth-child(1) { animation-delay: 0.05s; }
        .table tbody tr:nth-child(2) { animation-delay: 0.1s; }
        .table tbody tr:nth-child(3) { animation-delay: 0.15s; }
        .table tbody tr:nth-child(4) { animation-delay: 0.2s; }
        .table tbody tr:nth-child(5) { animation-delay: 0.25s; }
        .table tbody tr:nth-child(n+6) { animation-delay: 0.3s; }
        
        /* ===========================
           ACCESIBILIDAD
           =========================== */
        *:focus-visible {
            outline: 3px solid var(--success);
            outline-offset: 3px;
            border-radius: var(--radius-md);
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.2);
        }
        
        .btn:focus-visible {
            outline-color: white;
            outline-width: 4px;
        }
        
        /* ===========================
           RESPONSIVE
           =========================== */
        @media (max-width: 768px) {
            .settings-panel {
                max-width: 90%;
                right: 1rem;
                top: 1rem;
            }
            
            .settings-toggle {
                width: 50px;
                height: 50px;
                font-size: 1.125rem;
            }
            
            .finalized-header,
            .supplier-header {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <button class="settings-toggle" onclick="toggleSettingsPanel()" title="Configuración del Sistema">
        <i class="fas fa-cog"></i>
    </button>
    
    <div id="settingsPanel" class="settings-panel">
        <div class="settings-section">
            <h6><i class="fas fa-save me-2"></i>Guardado Automático</h6>
            <div class="preference-item">
                <div class="preference-control">
                    <div>
                        <span class="preference-label">Auto-guardar filtros</span>
                        <div class="preference-description">Guarda automáticamente los filtros que uses</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoSaveFilters" checked>
                    </div>
                </div>
            </div>
        </div>
        <div class="settings-section">
            <h6><i class="fas fa-sync me-2"></i>Actualización</h6>
            <div class="preference-item">
                <div class="preference-control">
                    <div>
                        <span class="preference-label">Auto-actualizar</span>
                        <div class="preference-description">Actualiza la página cada 5 minutos</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoRefresh">
                    </div>
                </div>
            </div>
        </div>
        <div class="settings-section">
            <button class="btn btn-outline-danger w-100" onclick="clearAllData()">
                <i class="fas fa-trash me-1"></i>Limpiar datos guardados
            </button>
        </div>
    </div>
    
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-check-circle me-2"></i>Configuración guardada
    </div>
    <div id="statusIndicator" class="status-indicator">
        <div id="statusMessage"></div>
    </div>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-file-check me-2" style="color: #28a745;"></i>
                        Facturas Finalizadas
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-success" id="exportBtn">
                            <i class="fas fa-file-excel me-1"></i> Exportar Excel
                        </button>
                    </div>
                </div>
                
                <!-- Mensajes -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <!-- Filtro especial para facturas finalizadas hoy con formulario PHP -->
                <div class="today-filter-container">
                    <form method="GET" action="" id="todayFilterForm">
                        <!-- Mantener todos los filtros existentes al cambiar el filtro de hoy -->
                        <?php if (!empty($filter_supplier)): ?>
                            <input type="hidden" name="filter_supplier" value="<?php echo htmlspecialchars($filter_supplier); ?>">
                        <?php endif; ?>
                        <?php if (!empty($filter_date_from)): ?>
                            <input type="hidden" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        <?php endif; ?>
                        <?php if (!empty($filter_date_to)): ?>
                            <input type="hidden" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        <?php endif; ?>
                        
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h5 class="mb-1">
                                    <i class="fas fa-calendar-check me-2"></i>
                                    Ver facturas finalizadas hoy
                                </h5>
                                <p class="mb-0 opacity-75">
                                    Muestra únicamente las facturas marcadas como finalizadas hoy
                                </p>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input today-filter-switch" type="checkbox"
                                       id="todayOnlyFilter"
                                       name="filter_today_only"
                                       value="1"
                                       <?php echo (!empty($filter_today_only) && $filter_today_only === '1') ? 'checked' : ''; ?>
                                       onchange="this.form.submit()">
                                <label class="form-check-label fw-bold" for="todayOnlyFilter">
                                    Solo Hoy
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Estadísticas -->
                <?php 
                $stats = $invoiceManager->getFinalizedInvoiceStats($finalized_invoices);
                ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="fas fa-file-check fa-2x mb-2" style="color: #28a745;"></i>
                                <h4 class="mb-0"><?php echo $stats['total_invoices']; ?></h4>
                                <small class="text-muted">Finalizadas</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="fas fa-building fa-2x text-primary mb-2"></i>
                                <h4 class="mb-0"><?php echo $stats['suppliers_count']; ?></h4>
                                <small class="text-muted">Proveedores</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="fas fa-dollar-sign fa-2x text-warning mb-2"></i>
                                <h4 class="mb-0">$<?php echo number_format($stats['total_amount'], 0, ',', '.'); ?></h4>
                                <small class="text-muted">Total</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check fa-2x mb-2" style="color: #28a745;"></i>
                                <h4 class="mb-0"><?php echo $stats['today_count']; ?></h4>
                                <small class="text-muted">Finalizadas Hoy</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-filter me-2"></i>
                            Filtros de Facturas Finalizadas
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filtersForm">
                            <input type="hidden" name="filter_today_only" value="<?php echo $filter_today_only; ?>">
                            
                            <div class="col-md-4">
                                <label for="filter_supplier" class="form-label">
                                    <i class="fas fa-search me-1"></i>
                                    Buscar Proveedor
                                </label>
                                <div class="supplier-search-container">
                                    <input type="text"
                                           class="form-control"
                                           id="filter_supplier"
                                           name="filter_supplier"
                                           value="<?php echo htmlspecialchars($filter_supplier); ?>"
                                           placeholder="Escriba para buscar proveedor finalizado..."
                                           autocomplete="off">
                                    <i class="fas fa-search search-icon"></i>
                                    <div class="supplier-suggestions" id="supplierSuggestions"></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label for="filter_date_from" class="form-label">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Fecha desde
                                </label>
                                <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="filter_date_to" class="form-label">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Fecha hasta
                                </label>
                                <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-success" id="filterBtn">
                                        <span class="btn-text">
                                            <i class="fas fa-filter me-1"></i> Filtrar
                                        </span>
                                        <span class="loading-spinner">
                                            <i class="fas fa-spinner fa-spin me-1"></i> Filtrando...
                                        </span>
                                    </button>
                                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-undo me-1"></i> Limpiar
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list-check me-2"></i>
                            Listado de Facturas Finalizadas
                            <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                <span class="badge bg-warning text-dark ms-2">
                                    <i class="fas fa-calendar-day me-1"></i>Solo Hoy
                                </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($finalized_invoices) > 0): ?>
                            <?php
                            // Eliminar duplicados
                            $unique_invoices = [];
                            $seen = [];
                            foreach ($finalized_invoices as $invoice) {
                                $key = $invoice['docnum_interno_sap'];
                                if (!in_array($key, $seen)) {
                                    $seen[] = $key;
                                    $unique_invoices[] = $invoice;
                                }
                            }
                            
                            // Agrupar por proveedor
                            $invoices_by_supplier = [];
                            foreach ($unique_invoices as $invoice) {
                                $supplier_name = $invoice['nombre'];
                                if (!isset($invoices_by_supplier[$supplier_name])) {
                                    $invoices_by_supplier[$supplier_name] = [];
                                }
                                $invoices_by_supplier[$supplier_name][] = $invoice;
                            }
                            
                            ksort($invoices_by_supplier);
                            
                            // Calcular totales
                            $supplier_totals = [];
                            foreach ($invoices_by_supplier as $supplier => $invoices) {
                                $total = 0;
                                $today_count = 0;
                                $today = date('Y-m-d');
                                
                                foreach ($invoices as $invoice) {
                                    $total += abs(floatval($invoice['saldo_pendiente'] ?? 0));
                                    
                                    $finalized_date = safeDateFormat($invoice['updated_at'] ?? $invoice['created_at'] ?? '', 'Y-m-d');
                                    if ($finalized_date === $today) {
                                        $today_count++;
                                    }
                                }
                                $supplier_totals[$supplier] = [
                                    'total' => $total,
                                    'count' => count($invoices),
                                    'today_count' => $today_count
                                ];
                            }
                            ?>
                            <div class="table-responsive" id="invoicesContainer">
                                <?php 
                                $supplierIndex = 0;
                                foreach ($invoices_by_supplier as $supplier_name => $supplier_invoices): 
                                    $supplierIndex++;
                                ?>
                                    <div class="finalized-header" data-supplier-id="supplier-<?= $supplierIndex ?>">
                                        <div class="d-flex justify-content-between align-items-center p-3">
                                            <div class="d-flex align-items-center">
                                                <button class="btn btn-sm btn-toggle me-2" data-bs-toggle="collapse"
                                                        data-bs-target=".supplier-<?= $supplierIndex ?>"
                                                        data-supplier-index="<?= $supplierIndex ?>"
                                                        aria-expanded="true">
                                                    <i class="fas fa-chevron-down"></i>
                                                </button>
                                                <div>
                                                    <h6 class="mb-1" style="color: #155724; font-weight: 600;">
                                                        <i class="fas fa-building me-2" style="color: #28a745;"></i>
                                                        <?php echo htmlspecialchars($supplier_name); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <i class="fas fa-file-check me-1" style="color: #28a745;"></i>
                                                        <?php echo $supplier_totals[$supplier_name]['count']; ?> finalizada(s)
                                                        <?php if ($supplier_totals[$supplier_name]['today_count'] > 0): ?>
                                                            | <i class="fas fa-calendar-check me-1" style="color: #28a745;"></i>
                                                            <?php echo $supplier_totals[$supplier_name]['today_count']; ?> hoy
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <?php if ($supplier_totals[$supplier_name]['today_count'] > 0): ?>
                                                    <span class="supplier-count-badge me-2">
                                                        <i class="fas fa-calendar-check me-1"></i>
                                                        <?php echo $supplier_totals[$supplier_name]['today_count']; ?> hoy
                                                    </span>
                                                <?php endif; ?>
                                                <span class="supplier-total-badge">
                                                    <i class="fas fa-dollar-sign me-1"></i>
                                                    <?php echo formatColombiaPesos($supplier_totals[$supplier_name]['total']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <table class="table table-striped table-hover invoice-table supplier-<?= $supplierIndex ?> collapse show finalized-invoice">
                                        <thead class="table-success">
                                            <tr>
                                                <th><i class="fas fa-hashtag me-1"></i>N° SAP</th>
                                                <th><i class="fas fa-id-card me-1"></i>NIT</th>
                                                <th><i class="fas fa-calendar-alt me-1"></i>Fecha Venc.</th>
                                                <th><i class="fas fa-dollar-sign me-1"></i>Valor</th>
                                                <th><i class="fas fa-exclamation-triangle me-1"></i>Prioridad</th>
                                                <th><i class="fas fa-info-circle me-1"></i>Estado</th>
                                                <th><i class="fas fa-eye me-1"></i>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $today = date('Y-m-d');
                                            foreach ($supplier_invoices as $invoice):
                                                $finalized_date = safeDateFormat($invoice['updated_at'] ?? $invoice['created_at'] ?? '', 'Y-m-d');
                                                $isFinalizedToday = ($finalized_date === $today);
                                                $positiveValue = getPositiveValue($invoice['saldo_pendiente'] ?? 0);
                                                $final_status = $invoice['final'] === 'si' ? 'FINALIZADA' : 'MARCADA';
                                            ?>
                                                <tr class="<?= $isFinalizedToday ? 'today-finalized-invoice' : 'finalized-invoice' ?>" data-normal-price="<?= $positiveValue ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($invoice['docnum_interno_sap'] ?? 'N/A'); ?></strong>
                                                        <?php if ($isFinalizedToday): ?>
                                                            <span class="badge bg-success ms-1" title="Finalizada hoy">
                                                                <i class="fas fa-star"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($invoice['codigo_sn'] ?? 'N/A'); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php echo formatDate($invoice['fecha_vencimiento']); ?>
                                                        <?php if ($isFinalizedToday): ?>
                                                            <br><small class="text-success">
                                                                <i class="fas fa-check-circle me-1"></i>Finalizada hoy
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo formatColombiaPesos($invoice['saldo_pendiente'] ?? 0); ?></strong></td>
                                                    <td>
                                                        <?php
                                                        $priority = strtolower(trim($invoice['priority'] ?? 'baja'));
                                                        $priorityClass = getPriorityClass($priority);
                                                        ?>
                                                        <span class="priority-badge" style="background-color: <?php echo $priorityClass['bg']; ?>; color: <?php echo $priorityClass['text']; ?>;">
                                                            <?php echo ucfirst($priority); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info">
                                                            <i class="fas fa-check-circle me-1"></i>
                                                            <?php echo htmlspecialchars($invoice['status'] ?? ''); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view_invoice.php?docnum_interno_sap=<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?>" class="btn btn-sm btn-outline-success" title="Ver detalles">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endforeach; ?>
                                
                                <div class="d-flex justify-content-end mb-3">
                                    <button id="expandAllBtn" class="btn btn-sm btn-outline-success me-2">
                                        <i class="fas fa-expand me-1"></i> Expandir Todos
                                    </button>
                                    <button id="collapseAllBtn" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-compress me-1"></i> Colapsar Todos
                                    </button>
                                </div>
                                
                                <div class="total-summary mt-4 p-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="mb-2" style="color: #155724;">
                                                <i class="fas fa-chart-bar me-2"></i>Resumen Total Finalizadas
                                            </h6>
                                            <p class="mb-1">
                                                <strong>Proveedores:</strong> <?php echo count($invoices_by_supplier); ?>
                                            </p>
                                            <p class="mb-1">
                                                <strong>Facturas:</strong> <?php echo count($unique_invoices); ?>
                                            </p>
                                            <p class="mb-0">
                                                <strong>Finalizadas hoy:</strong> <?php echo $stats['today_count']; ?> |
                                                <strong>Prioridad Alta:</strong> <?php echo $stats['priority_breakdown']['alta']; ?> |
                                                <strong>Media:</strong> <?php echo $stats['priority_breakdown']['media']; ?> |
                                                <strong>Baja:</strong> <?php echo $stats['priority_breakdown']['baja']; ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6 text-end">
                                            <h5 class="mb-0" style="color: #155724;">
                                                <i class="fas fa-dollar-sign me-1"></i>
                                                <?php echo formatColombiaPesos(array_sum(array_column($supplier_totals, 'total'))); ?>
                                            </h5>
                                            <small class="text-muted">Total finalizado</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No hay facturas finalizadas
                                <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                    finalizadas hoy
                                <?php endif; ?>
                                <?php if (!empty($filter_supplier) || !empty($filter_date_from) || !empty($filter_date_to)): ?>
                                    con los filtros aplicados.
                                <?php else: ?>
                                    en el sistema.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    
    <script>
    function showRevertConfirm(form, invoiceId) {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        
        const alertBox = document.createElement('div');
        alertBox.className = 'custom-alert';
        
        alertBox.innerHTML = `
            <div class="custom-alert-header">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ¿Revertir Factura Finalizada?
            </div>
            <div class="custom-alert-body">
                <p>¿Está seguro que desea revertir la factura?</p>
                <div class="custom-alert-invoice">
                    <i class="fas fa-file-invoice me-2"></i>
                    Factura N° ${invoiceId}
                </div>
                <p class="text-muted mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Esta acción quitará la marca de "finalizada" de la factura.
                </p>
            </div>
            <div class="custom-alert-footer">
                <button type="button" class="btn btn-secondary" id="btnCancel">
                    <i class="fas fa-times me-1"></i> Cancelar
                </button>
                <button type="button" class="btn btn-revert" id="btnConfirm">
                    <i class="fas fa-undo me-1"></i> Revertir Factura
                </button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        overlay.appendChild(alertBox);
        
        setTimeout(() => {
            document.getElementById('btnConfirm').focus();
        }, 100);
        
        document.getElementById('btnCancel').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });
        
        document.getElementById('btnConfirm').addEventListener('click', function() {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'unmark_final';
            hiddenInput.value = '1';
            form.appendChild(hiddenInput);
            
            const confirmBtn = document.getElementById('btnConfirm');
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Revirtiendo...';
            confirmBtn.disabled = true;
            
            form.submit();
        });
        
        const escHandler = function(e) {
            if (e.key === 'Escape' && document.body.contains(overlay)) {
                document.body.removeChild(overlay);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
        
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
            }
        });
    }
    
    function exportToExcel() {
        try {
            const params = new URLSearchParams(window.location.search);
            params.set('action', 'generate_excel');
            window.location.href = '?' + params.toString();
        } catch (error) {
            console.error('Error exportando facturas finalizadas:', error);
            alert('Error al generar el archivo Excel');
        }
    }
    
    function toggleSettingsPanel() {
        const panel = document.getElementById('settingsPanel');
        panel.classList.toggle('show');
    }
    
    function clearAllData() {
        if (confirm('¿Está seguro que desea limpiar todos los datos guardados?')) {
            localStorage.clear();
            sessionStorage.clear();
            alert('Datos limpiados exitosamente');
            location.reload();
        }
    }
    
    // Inicialización
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 Sistema de facturas FINALIZADAS iniciado');
        
        // Configurar exportación
        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) {
            exportBtn.addEventListener('click', exportToExcel);
        }
        
        const expandAllBtn = document.getElementById('expandAllBtn');
        const collapseAllBtn = document.getElementById('collapseAllBtn');
        
        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.collapse').forEach(function(element) {
                    const bsCollapse = new bootstrap.Collapse(element, {toggle: false});
                    bsCollapse.show();
                });
                document.querySelectorAll('.btn-toggle i').forEach(function(icon) {
                    icon.className = 'fas fa-chevron-down';
                });
            });
        }
        
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.collapse').forEach(function(element) {
                    const bsCollapse = new bootstrap.Collapse(element, {toggle: false});
                    bsCollapse.hide();
                });
                document.querySelectorAll('.btn-toggle i').forEach(function(icon) {
                    icon.className = 'fas fa-chevron-right';
                });
            });
        }
        
        document.querySelectorAll('.btn-toggle').forEach(function(button) {
            button.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (icon.classList.contains('fa-chevron-down')) {
                    icon.className = 'fas fa-chevron-right';
                } else {
                    icon.className = 'fas fa-chevron-down';
                }
            });
        });
        
        const autoSaveFilters = document.getElementById('autoSaveFilters');
        const autoRefresh = document.getElementById('autoRefresh');
        
        if (autoSaveFilters) {
            autoSaveFilters.checked = localStorage.getItem('autoSaveFilters') !== 'false';
            autoSaveFilters.addEventListener('change', function() {
                localStorage.setItem('autoSaveFilters', this.checked);
                showAutoSaveIndicator();
            });
        }
        
        if (autoRefresh) {
            autoRefresh.checked = localStorage.getItem('autoRefresh') === 'true';
            autoRefresh.addEventListener('change', function() {
                localStorage.setItem('autoRefresh', this.checked);
                showAutoSaveIndicator();
                if (this.checked) {
                    startAutoRefresh();
                } else {
                    stopAutoRefresh();
                }
            });
            
            if (autoRefresh.checked) {
                startAutoRefresh();
            }
        }
    });
    
    function showAutoSaveIndicator() {
        const indicator = document.getElementById('autoSaveIndicator');
        if (indicator) {
            indicator.classList.add('show');
            setTimeout(() => {
                indicator.classList.remove('show');
            }, 2000);
        }
    }
    
    let autoRefreshInterval;
    
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(() => {
            location.reload();
        }, 300000); // 5 minutos
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
    </script>
</body>
</html>
