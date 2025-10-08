<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// require_once 'vendor/autoload.php';
// use PhpOffice\PhpSpreadsheet\Spreadsheet;
// use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// use PhpOffice\PhpSpreadsheet\Style\Alignment;
// use PhpOffice\PhpSpreadsheet\Style\Border;
// use PhpOffice\PhpSpreadsheet\Style\Fill;

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Obtener rol del usuario
$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'];

// Clase para manejar las operaciones de facturas
class InvoiceManager {
    private $conn;
    
    public function __construct() {
        $this->conn = getDbConnection();
    }
    
    /**
     * Marcar una factura como final
     */
    public function markInvoiceAsFinal($invoice_id) {
        try {
            if (is_a($this->conn, 'PDO')) {
                $sql = "UPDATE invoices SET final = 'si' WHERE docnum_interno_sap = ? AND ESTADOSAP = 'O'";
                $stmt = $this->conn->prepare($sql);
                return $stmt->execute([$invoice_id]);
            } else {
                $sql = "UPDATE invoices SET final = 'si' WHERE docnum_interno_sap = ? AND ESTADOSAP = 'O'";
                $params = array($invoice_id);
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al actualizar la factura: ' . print_r(sqlsrv_errors(), true));
                }
                return true;
            }
        } catch (Exception $e) {
            error_log("Error en markInvoiceAsFinal: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Buscar proveedores para autocompletado
     */
    public function searchSuppliers($search_term = '') {
        try {
            $sql = "SELECT DISTINCT nombre FROM invoices WHERE ESTADOSAP = 'O'";
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
     * Obtener facturas completadas con filtros avanzados
     */
    public function getCompletedInvoices($supplier = '', $date_from = '', $date_to = '', $today_only = '') {
        try {
            $sql = "SELECT *,
                     DATEDIFF(day, fecha_vencimiento, GETDATE()) as dias_antiguedad
                    FROM invoices 
                    WHERE ESTADOSAP = 'O'
                    AND (status = 'completada' OR status = 'completado')
                    AND (final IS NULL OR final != 'si')";
            $params = array();
            
            if (!empty($today_only) && $today_only === '1') {
                $sql .= " AND CONVERT(DATE, created_at) = CONVERT(DATE, GETDATE())";
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
                $sql = str_replace("DATEDIFF(day, fecha_vencimiento, GETDATE())",
                                   "DATEDIFF(CURDATE(), fecha_vencimiento)", $sql);
                $sql = str_replace("CONVERT(DATE, GETDATE())", "CURDATE()", $sql);
                $sql = str_replace("CONVERT(DATE, created_at)", "DATE(created_at)", $sql);
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al obtener facturas: ' . print_r(sqlsrv_errors(), true));
                }
                
                $invoices = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $invoices[] = $row;
                }
                return $invoices;
            }
        } catch (Exception $e) {
            error_log("Error en getCompletedInvoices: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Obtener estadísticas de facturas
     */
    public function getInvoiceStats($invoices) {
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
            
            $created_date = safeDateFormat($invoice['created_at'] ?? '', 'Y-m-d');
            if ($created_date === $today) {
                $stats['today_count']++;
            }
        }
        
        $stats['suppliers_count'] = count($unique_suppliers);
        
        $stats['average_amount'] = $stats['total_invoices'] > 0 ? $stats['total_amount'] / $stats['total_invoices'] : 0;
        $stats['high_priority'] = $stats['priority_breakdown']['alta'];
        $stats['medium_priority'] = $stats['priority_breakdown']['media'];
        $stats['low_priority'] = $stats['priority_breakdown']['baja'];
        $stats['completed_today'] = $stats['today_count'];
        
        return $stats;
    }
    
    /**
     * Generar archivo Excel usando funciones nativas de PHP
     */
    public function generateExcel($supplier = '', $date_from = '', $date_to = '', $today_only = '') {
        try {
            // Obtener facturas con filtros
            $invoices = $this->getCompletedInvoices($supplier, $date_from, $date_to, $today_only);
            
            if (empty($invoices)) {
                return false;
            }

            $filename = 'facturas_completadas_' . date('Y-m-d_H-i-s') . '.xls';
            
            // Headers para descarga
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            
            // Iniciar output
            echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
            echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>Facturas Completadas</title></head>';
            echo '<body>';
            
            // Hoja 1: Resumen por Proveedores
            echo '<table border="1">';
            echo '<tr><td colspan="6" style="background-color: #4472C4; color: white; font-weight: bold; text-align: center;">RESUMEN POR PROVEEDORES</td></tr>';
            echo '<tr style="background-color: #D9E2F3; font-weight: bold;">';
            echo '<td>Proveedor</td><td>Total Facturas</td><td>Valor Total</td><td>Promedio</td><td>Más Antigua</td><td>Más Reciente</td>';
            echo '</tr>';
            
            // Agrupar por proveedor
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
            
            // Hoja 2: Detalle de Facturas
            echo '<table border="1">';
            echo '<tr><td colspan="8" style="background-color: #70AD47; color: white; font-weight: bold; text-align: center;">DETALLE DE FACTURAS</td></tr>';
            echo '<tr style="background-color: #E2EFDA; font-weight: bold;">';
            echo '<td>N° SAP</td><td>NIT</td><td>Proveedor</td><td>Fecha Vencimiento</td><td>Valor</td><td>Prioridad</td><td>Estado</td><td>Días Antigüedad</td>';
            echo '</tr>';
            
            foreach ($invoices as $invoice) {
                $priority_color = '';
                switch (strtolower($invoice['priority'] ?? '')) {
                    case 'alta':
                        $priority_color = 'background-color: #FFE6E6;';
                        break;
                    case 'media':
                        $priority_color = 'background-color: #FFF2E6;';
                        break;
                    case 'baja':
                        $priority_color = 'background-color: #E6F7E6;';
                        break;
                }
                
                echo '<tr style="' . $priority_color . '">';
                echo '<td>' . htmlspecialchars($invoice['docnum_interno_sap'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['codigo_sn'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['nombre'] ?? '') . '</td>';
                echo '<td>' . safeDateFormat($invoice['fecha_vencimiento'], 'd/m/Y') . '</td>';
                echo '<td>$' . number_format(abs(floatval($invoice['saldo_pendiente'] ?? 0)), 2) . '</td>';
                echo '<td>' . htmlspecialchars($invoice['priority'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($invoice['status'] ?? '') . '</td>';
                echo '<td>' . ($invoice['dias_antiguedad'] ?? 0) . '</td>';
                echo '</tr>';
            }
            echo '</table><br><br>';
            
            // Hoja 3: Estadísticas
            $stats = $this->getInvoiceStats($invoices);
            echo '<table border="1">';
            echo '<tr><td colspan="2" style="background-color: #FFC000; color: black; font-weight: bold; text-align: center;">ESTADÍSTICAS GENERALES</td></tr>';
            echo '<tr><td style="font-weight: bold;">Total de Facturas:</td><td>' . $stats['total_invoices'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Valor Total:</td><td>$' . number_format($stats['total_amount'], 2) . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Promedio por Factura:</td><td>$' . number_format($stats['average_amount'], 2) . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Alta Prioridad:</td><td>' . $stats['high_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Media Prioridad:</td><td>' . $stats['medium_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Facturas Baja Prioridad:</td><td>' . $stats['low_priority'] . '</td></tr>';
            echo '<tr><td style="font-weight: bold;">Completadas Hoy:</td><td>' . $stats['completed_today'] . '</td></tr>';
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
        // Si es null, vacío o no numérico, devolver 0
        if (is_null($amount) || $amount === '' || !is_numeric($amount)) {
            return 0.0;
        }
        // Convertir a float y tomar valor absoluto
        return abs(floatval($amount));
    }
}

// Formatea un valor como pesos colombianos ($X.XXX.XXX COP)
if (!function_exists('formatColombiaPesos')) {
    function formatColombiaPesos($amount) {
        $positiveAmount = sanitizeNumber($amount);
        return '$' . number_format($positiveAmount, 0, ',', '.') . ' COP';
    }
}

// Devuelve el valor absoluto de un número
if (!function_exists('getPositiveValue')) {
    function getPositiveValue($amount) {
        return sanitizeNumber($amount);
    }
}

// Formatea un número según el formato colombiano (X.XXX.XXX)
if (!function_exists('formatColombiaNumber')) {
    function formatColombiaNumber($amount) {
        $positiveAmount = sanitizeNumber($amount);
        return number_format($positiveAmount, 0, ',', '.');
    }
}

// Instanciar el manejador de facturas
$invoiceManager = new InvoiceManager();

// API endpoint para búsqueda de proveedores
if (isset($_GET['action']) && $_GET['action'] === 'search_suppliers') {
    header('Content-Type: application/json');
    $search_term = $_GET['q'] ?? '';
    $suppliers = $invoiceManager->searchSuppliers($search_term);
    echo json_encode($suppliers);
    exit();
}

// Procesar la actualización del campo "final"
if (isset($_POST['mark_final']) && isset($_POST['invoice_id'])) {
    $invoice_id = $_POST['invoice_id'];
    $success = $invoiceManager->markInvoiceAsFinal($invoice_id);
    
    if ($success) {
        $_SESSION['success_message'] = "Factura #$invoice_id marcada como final exitosamente.";
    } else {
        $_SESSION['error_message'] = "Error al marcar la factura #$invoice_id como final.";
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

// Inicializar filtros con validación
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

// Obtener datos
$completed_invoices = $invoiceManager->getCompletedInvoices($filter_supplier, $filter_date_from, $filter_date_to, $filter_today_only);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Completadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <style>
        /* Estilos del sistema avanzado */
        .supplier-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid #007bff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
            margin-top: 1.5rem;
            font-weight: bold;
            color: #495057;
            border-top: 3px solid #007bff;
            border-bottom: 1px solid #dee2e6;
        }
        
        .invoice-table {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .total-summary {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: 8px;
            border: 1px solid #2196f3;
            position: sticky;
            bottom: 0;
            z-index: 5;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #f1f8e9 0%, #dcedc8 100%);
            border-left: 4px solid #4caf50;
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .priority-badge {
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            text-transform: capitalize;
        }
        
        /* Filtro de hoy */
        .today-filter-container {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: white;
        }
        
        .today-filter-switch {
            transform: scale(1.2);
        }
        
        /* Estilos para búsqueda de proveedores */
        .supplier-search-container {
            position: relative;
        }
        
        .supplier-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .supplier-suggestion {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        
        .supplier-suggestion:hover,
        .supplier-suggestion.active {
            background-color: #f8f9fa;
        }
        
        .search-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .no-results {
            padding: 10px 15px;
            color: #6c757d;
            font-style: italic;
        }
        
        /* Sistema de preferencias mejorado */
        .settings-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 20px;
            z-index: 1050;
            max-width: 350px;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            border: 2px solid #007bff;
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
            width: 55px;
            height: 55px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            font-size: 1.2rem;
        }
        
        .settings-toggle:hover {
            background: #0056b3;
            transform: rotate(90deg);
        }
        
        .preference-item {
            margin-bottom: 15px;
            padding: 12px;
            border-radius: 8px;
            background: #f8f9fa;
            border-left: 3px solid #007bff;
        }
        
        .preference-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            display: block;
        }
        
        .preference-description {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
        }
        
        .preference-control {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .auto-save-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 0.9em;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .auto-save-indicator.show {
            opacity: 1;
        }
        
        /* Agrupación por proveedores */
        .supplier-group {
            background-color: #f8f9fa;
            border-left: 3px solid #007bff;
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
        
        .today-completed-invoice {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        
        /* Estilos para la alerta personalizada mejorada */
        .custom-alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .custom-alert {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 480px;
            padding: 0;
            overflow: hidden;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-30px) scale(0.95); }
            to { transform: translateY(0) scale(1); }
        }
        
        .custom-alert-header {
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            color: white;
            padding: 20px;
            text-align: center;
            font-weight: bold;
            font-size: 1.3rem;
        }
        
        .custom-alert-body {
            padding: 25px;
            text-align: center;
        }
        
        .custom-alert-body p {
            margin-bottom: 20px;
            font-size: 1.1rem;
            color: #555;
            line-height: 1.5;
        }
        
        .custom-alert-invoice {
            font-weight: bold;
            color: #007bff;
            background: #e3f2fd;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 20px auto;
            display: inline-block;
            border: 2px solid #007bff;
        }
        
        .custom-alert-footer {
            display: flex;
            padding: 15px;
            border-top: 1px solid #eee;
            gap: 10px;
        }
        
        .custom-alert-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 1rem;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .btn-confirm {
            background: #007bff;
            color: white;
        }
        
        .btn-confirm:hover {
            background: #0056b3;
        }
        
        .loading-spinner {
            display: none;
        }
        
        .loading .loading-spinner {
            display: inline-block;
        }
        
        .loading .btn-text {
            display: none;
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
        
        .alert-dismissible {
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        /* Mejoras en el sistema de configuración */
        .settings-section {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .settings-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .settings-section h6 {
            color: #007bff;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .switch-label {
            font-size: 0.9rem;
            color: #333;
        }
        .form-switch .form-check-input {
            width: 2.5em;
            height: 1.25em;
        }
        /* Indicadores de estado mejorados */
        .status-indicator {
            position: fixed;
            top: 50%;
            right: 30px;
            transform: translateY(-50%);
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
            max-width: 250px;
        }
        .status-indicator.show {
            opacity: 1;
            transform: translateY(-50%) translateX(-10px);
        }
        .status-indicator.success {
            border-left-color: #28a745;
        }
        .status-indicator.warning {
            border-left-color: #ffc107;
        }
        .status-indicator.error {
            border-left-color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <!-- Panel de configuración mejorado -->
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
            <div class="preference-item">
                <div class="preference-control">
                    <div>
                        <span class="preference-label">Recordar vista</span>
                        <div class="preference-description">Recuerda qué proveedores tienes abiertos/cerrados</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="rememberCollapsed" checked>
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
            <h6><i class="fas fa-eye me-2"></i>Vista</h6>
            <div class="preference-item">
                <div class="preference-control">
                    <div>
                        <span class="preference-label">Vista compacta</span>
                        <div class="preference-description">Reduce el tamaño de las filas para ver más datos</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="compactView">
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
    
    <!-- Indicador de auto-guardado mejorado -->
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-check-circle me-2"></i>Configuración guardada
    </div>
    <!-- Indicador de estado general -->
    <div id="statusIndicator" class="status-indicator">
        <div id="statusMessage"></div>
    </div>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Facturas Completadas
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-success" id="exportBtn">
                            <i class="fas fa-file-excel me-1"></i> Exportar Excel
                        </button>
                    </div>
                </div>
                
                <!-- Mensajes de éxito/error -->
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
                
                <!-- Filtro especial para facturas completadas hoy -->
                <div class="today-filter-container">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-calendar-day me-2"></i>
                                Ver facturas completadas hoy
                            </h5>
                            <p class="mb-0 opacity-75">
                                Muestra únicamente las facturas que fueron marcadas como completadas hoy
                            </p>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input today-filter-switch" type="checkbox"
                                   id="todayOnlyFilter"
                                   <?php echo (!empty($filter_today_only) && $filter_today_only === '1') ? 'checked' : ''; ?>
                                   onchange="toggleTodayFilter()">
                            <label class="form-check-label fw-bold" for="todayOnlyFilter">
                                Solo Hoy
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Estadísticas rápidas -->
                <?php 
                $stats = $invoiceManager->getInvoiceStats($completed_invoices);
                ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <i class="fas fa-file-invoice fa-2x text-success mb-2"></i>
                                <h4 class="mb-0"><?php echo $stats['total_invoices']; ?></h4>
                                <small class="text-muted">Facturas</small>
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
                                <i class="fas fa-calendar-check fa-2x text-info mb-2"></i>
                                <h4 class="mb-0"><?php echo $stats['today_count']; ?></h4>
                                <small class="text-muted">Completadas Hoy</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-filter me-2"></i>
                            Filtros de Búsqueda
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filtersForm">
                            <!-- Campo oculto para mantener el filtro de hoy -->
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
                                           placeholder="Escriba para buscar proveedor..."
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
                                    <button type="submit" class="btn btn-primary" id="filterBtn">
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
                            <i class="fas fa-list me-2"></i>
                            Listado de Facturas Completadas
                            <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                <span class="badge bg-warning text-dark ms-2">
                                    <i class="fas fa-calendar-day me-1"></i>Solo Hoy
                                </span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($completed_invoices) > 0): ?>
                            <?php
                            // Eliminar duplicados y agrupar por proveedor
                            $unique_invoices = [];
                            $seen = [];
                            foreach ($completed_invoices as $invoice) {
                                $key = $invoice['docnum_interno_sap'];
                                if (!in_array($key, $seen)) {
                                    $seen[] = $key;
                                    $unique_invoices[] = $invoice;
                                }
                            }
                            // Agrupar facturas por proveedor
                            $invoices_by_supplier = [];
                            foreach ($unique_invoices as $invoice) {
                                $supplier_name = $invoice['nombre'];
                                if (!isset($invoices_by_supplier[$supplier_name])) {
                                    $invoices_by_supplier[$supplier_name] = [];
                                }
                                $invoices_by_supplier[$supplier_name][] = $invoice;
                            }
                            // Ordenar proveedores alfabéticamente
                            ksort($invoices_by_supplier);
                            // Calcular totales por proveedor
                            $supplier_totals = [];
                            foreach ($invoices_by_supplier as $supplier => $invoices) {
                                $total = 0;
                                $today_count = 0;
                                $today = date('Y-m-d');
                                
                                foreach ($invoices as $invoice) {
                                    $total += abs(floatval($invoice['saldo_pendiente'] ?? 0));
                                    
                                    // Contar facturas completadas hoy - CORREGIDO
                                    $created_date = safeDateFormat($invoice['created_at'] ?? '', 'Y-m-d');
                                    if ($created_date === $today) {
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
                                    <!-- Encabezado del proveedor -->
                                    <div class="supplier-header" data-supplier-id="supplier-<?= $supplierIndex ?>">
                                        <div class="d-flex justify-content-between align-items-center p-3">
                                            <div class="d-flex align-items-center">
                                                <button class="btn btn-sm btn-toggle me-2" data-bs-toggle="collapse"
                                                        data-bs-target=".supplier-<?= $supplierIndex ?>"
                                                        data-supplier-index="<?= $supplierIndex ?>"
                                                        aria-expanded="true" aria-controls="supplier-<?= $supplierIndex ?>">
                                                    <i class="fas fa-chevron-down"></i>
                                                </button>
                                                <div>
                                                    <h6 class="mb-1" style="color: #495057; font-weight: 600;">
                                                        <i class="fas fa-building me-2" style="color: #007bff;"></i>
                                                        <?php echo htmlspecialchars($supplier_name); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <i class="fas fa-file-invoice me-1"></i>
                                                        <?php echo $supplier_totals[$supplier_name]['count']; ?> factura(s)
                                                        <?php if ($supplier_totals[$supplier_name]['today_count'] > 0): ?>
                                                            | <i class="fas fa-calendar-check me-1"></i>
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
                                    <!-- Tabla de facturas del proveedor -->
                                    <table class="table table-striped table-hover invoice-table supplier-<?= $supplierIndex ?> collapse show" id="invoicesTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th>N° SAP</th>
                                                <th>NIT</th>
                                                <th>Fecha Vencimiento</th>
                                                <th>Valor</th>
                                                <th>Prioridad</th>
                                                <th>Estado</th>
                                                <th>Marcar Final</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $today = date('Y-m-d');
                                            foreach ($supplier_invoices as $invoice):
                                                // CORREGIDO: Usar la función safeDateFormat
                                                $created_date = safeDateFormat($invoice['created_at'] ?? '', 'Y-m-d');
                                                $isCompletedToday = ($created_date === $today);
                                                $positiveValue = getPositiveValue($invoice['saldo_pendiente'] ?? 0);
                                            ?>
                                                <tr class="<?= $isCompletedToday ? 'today-completed-invoice' : '' ?>" data-normal-price="<?= $positiveValue ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($invoice['docnum_interno_sap'] ?? 'N/A'); ?></strong>
                                                        <?php if ($isCompletedToday): ?>
                                                            <span class="badge bg-info ms-1" title="Completada hoy">
                                                                <i class="fas fa-star"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($invoice['codigo_sn'] ?? 'N/A'); ?></strong>
                                                    </td>
                                                    <td>
                                                        <?php echo formatDate($invoice['fecha_vencimiento']); ?>
                                                        <?php if ($isCompletedToday): ?>
                                                            <br><small class="text-info">
                                                                <i class="fas fa-check-circle me-1"></i>Completada hoy
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo formatColombiaPesos($invoice['saldo_pendiente'] ?? 0); ?></strong></td>
                                                    <td>
                                                        <?php
                                                        $priority = strtolower(trim($invoice['priority'] ?? 'baja'));
                                                        $priorityClass = getPriorityClass($priority);
                                                        ?>
                                                        <span class="priority-badge" style="background-color: <?php echo $priorityClass['bg']; ?>; color: <?php echo $priorityClass['text']; ?>; border: 1px solid <?php echo $priorityClass['border']; ?>;">
                                                            <?php echo ucfirst($priority); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success">
                                                            <i class="fas fa-check-circle me-1"></i>
                                                            <?php echo htmlspecialchars($invoice['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="invoice_id" value="<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?>">
                                                            <button type="button" name="mark_final" class="btn btn-warning btn-sm position-relative"
                                                                    onclick="showCustomConfirm(this.form, '<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?>')">
                                                                <i class="fas fa-check-square me-1"></i>
                                                                Marcar Final
                                                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                                                    <i class="fas fa-exclamation-circle"></i>
                                                                </span>
                                                            </button>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <a href="view_invoice.php?docnum_interno_sap=<?php echo htmlspecialchars($invoice['docnum_interno_sap']); ?>" class="btn btn-sm btn-info" title="Ver detalles">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endforeach; ?>
                                <!-- Controles globales para expandir/colapsar -->
                                <div class="d-flex justify-content-end mb-3">
                                    <button id="expandAllBtn" class="btn btn-sm btn-outline-primary me-2">
                                        <i class="fas fa-expand me-1"></i> Expandir Todos
                                    </button>
                                    <button id="collapseAllBtn" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-compress me-1"></i> Colapsar Todos
                                    </button>
                                </div>
                                <!-- Resumen total -->
                                <div class="total-summary mt-4 p-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="mb-2" style="color: #1976d2;">
                                                <i class="fas fa-chart-bar me-2"></i>Resumen Total
                                            </h6>
                                            <p class="mb-1">
                                                <strong>Proveedores:</strong> <?php echo count($invoices_by_supplier); ?>
                                            </p>
                                            <p class="mb-1">
                                                <strong>Facturas:</strong> <?php echo count($unique_invoices); ?>
                                            </p>
                                            <p class="mb-0">
                                                <strong>Completadas hoy:</strong> <?php echo $stats['today_count']; ?> |
                                                <strong>Prioridad Alta:</strong> <?php echo $stats['priority_breakdown']['alta']; ?> |
                                                <strong>Media:</strong> <?php echo $stats['priority_breakdown']['media']; ?> |
                                                <strong>Baja:</strong> <?php echo $stats['priority_breakdown']['baja']; ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6 text-end">
                                            <h5 class="mb-0" style="color: #1976d2;">
                                                <i class="fas fa-dollar-sign me-1"></i>
                                                <?php echo formatColombiaPesos(array_sum(array_column($supplier_totals, 'total'))); ?>
                                            </h5>
                                            <small class="text-muted">Total general</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No hay facturas completadas
                                <?php if (!empty($filter_today_only) && $filter_today_only === '1'): ?>
                                    completadas hoy
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
    function exportToExcel() {
        try {
            window.storageManager?.showStatusMessage('Generando archivo Excel con facturas visibles...', 'info');
            
            // Crear nuevo libro de trabajo
            const wb = XLSX.utils.book_new();
            
            // 1. HOJA DE RESUMEN POR PROVEEDORES (solo visibles)
            const supplierData = [];
            
            // Encabezados para resumen por proveedores
            supplierData.push(['Nombre Proveedor', 'NIT', 'Total a Pagar (COP)', 'Facturas por Proveedor']);
            
            // Obtener solo proveedores visibles (expandidos)
            const visibleSupplierHeaders = document.querySelectorAll('.supplier-header');
            let totalGeneral = 0;
            let totalFacturas = 0;
            
            visibleSupplierHeaders.forEach(header => {
                const supplierName = header.querySelector('h6').textContent.trim().replace(/^\s*\S+\s*/, '');
                const supplierIndex = header.querySelector('.btn-toggle').getAttribute('data-supplier-index');
                const table = document.querySelector(`.supplier-${supplierIndex}`);
                
                // Solo procesar si la tabla está visible (expandida)
                if (table && table.classList.contains('show')) {
                    const visibleRows = table.querySelectorAll('tbody tr:not([style*="display: none"])');
                    let supplierTotal = 0;
                    let supplierNIT = '';
                    let invoiceCount = visibleRows.length;
                    
                    // Calcular total del proveedor usando valores positivos
                    visibleRows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length >= 4) {
                            // NIT está en la segunda columna
                            if (!supplierNIT) {
                                supplierNIT = cells[1].textContent.trim();
                            }
                            // Usar el valor positivo almacenado en data-normal-price
                            const normalPrice = parseFloat(row.getAttribute('data-normal-price')) || 0;
                            supplierTotal += normalPrice;
                        }
                    });
                    
                    if (invoiceCount > 0) {
                        supplierData.push([
                            supplierName,
                            supplierNIT,
                            supplierTotal,
                            invoiceCount
                        ]);
                        
                        totalGeneral += supplierTotal;
                        totalFacturas += invoiceCount;
                    }
                }
            });
            
            // Agregar fila de totales
            supplierData.push(['', '', '', '']);
            supplierData.push(['TOTALES', '', totalGeneral, totalFacturas]);
            
            // Crear hoja de resumen
            const wsSuppliers = XLSX.utils.aoa_to_sheet(supplierData);
            
            // Formatear columnas
            wsSuppliers['!cols'] = [
                { width: 35 }, // Nombre Proveedor
                { width: 15 }, // NIT
                { width: 20 }, // Total a Pagar (COP)
                { width: 25 }  // Facturas por Proveedor
            ];
            
            XLSX.utils.book_append_sheet(wb, wsSuppliers, "Resumen por Proveedores");
            
            // 2. HOJA DE DETALLE DE FACTURAS VISIBLES
            const detailData = [];
            detailData.push(['Proveedor', 'NIT', 'N° SAP', 'Fecha Vencimiento', 'Valor Individual (COP)', 'Prioridad', 'Estado']);
            
            visibleSupplierHeaders.forEach(header => {
                const supplierName = header.querySelector('h6').textContent.trim().replace(/^\s*\S+\s*/, '');
                const supplierIndex = header.querySelector('.btn-toggle').getAttribute('data-supplier-index');
                const table = document.querySelector(`.supplier-${supplierIndex}`);
                
                // Solo procesar tablas visibles
                if (table && table.classList.contains('show')) {
                    const visibleRows = table.querySelectorAll('tbody tr:not([style*="display: none"])');
                    
                    visibleRows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length >= 6) {
                            const normalPrice = parseFloat(row.getAttribute('data-normal-price')) || 0;
                            
                            detailData.push([
                                supplierName,
                                cells[1].textContent.trim(), // NIT
                                cells[0].textContent.trim(), // N° SAP
                                cells[2].textContent.trim().split('\n')[0], // Fecha
                                normalPrice, // Valor positivo
                                cells[4].textContent.trim(), // Prioridad
                                cells[5].textContent.trim()  // Estado
                            ]);
                        }
                    });
                    
                    // Agregar subtotal por proveedor
                    const supplierTotal = supplierData.find(row => row[0] === supplierName);
                    if (supplierTotal) {
                        detailData.push([
                            `SUBTOTAL ${supplierName}`,
                            '',
                            '',
                            '',
                            supplierTotal[2],
                            '',
                            ''
                        ]);
                        detailData.push(['', '', '', '', '', '', '']); // Fila vacía
                    }
                }
            });
            
            const wsDetail = XLSX.utils.aoa_to_sheet(detailData);
            wsDetail['!cols'] = [
                { width: 35 }, // Proveedor
                { width: 15 }, // NIT
                { width: 15 }, // N° SAP
                { width: 20 }, // Fecha Vencimiento
                { width: 20 }, // Valor Individual (COP)
                { width: 12 }, // Prioridad
                { width: 15 }  // Estado
            ];
            
            XLSX.utils.book_append_sheet(wb, wsDetail, "Detalle Facturas Visibles");
            
            // 3. HOJA DE ESTADÍSTICAS DE FACTURAS VISIBLES
            const statsData = [];
            statsData.push(['ESTADÍSTICAS DE FACTURAS VISIBLES', '']);
            statsData.push(['', '']);
            statsData.push(['Total de Proveedores Visibles', supplierData.length - 2]); // Excluir encabezados y totales
            statsData.push(['Total de Facturas Visibles', totalFacturas]);
            statsData.push(['Total General a Pagar (COP)', `$${totalGeneral.toLocaleString()} COP`]);
            statsData.push(['Promedio por Factura (COP)', totalFacturas > 0 ? `$${Math.round(totalGeneral / totalFacturas).toLocaleString()} COP` : '$0 COP']);
            statsData.push(['Promedio por Proveedor (COP)', (supplierData.length - 2) > 0 ? `$${Math.round(totalGeneral / (supplierData.length - 2)).toLocaleString()} COP` : '$0 COP']);
            statsData.push(['', '']);
            statsData.push(['TOP 5 PROVEEDORES VISIBLES POR MONTO', '']);
            
            // Ordenar proveedores visibles por monto
            const sortedSuppliers = supplierData
                .slice(1, -2) // Excluir encabezados y totales
                .sort((a, b) => b[2] - a[2])
                .slice(0, 5);
            
            sortedSuppliers.forEach((supplier, index) => {
                statsData.push([`${index + 1}. ${supplier[0]}`, `$${supplier[2].toLocaleString()} COP`]);
            });
            
            const wsStats = XLSX.utils.aoa_to_sheet(statsData);
            wsStats['!cols'] = [{ width: 35 }, { width: 25 }];
            
            XLSX.utils.book_append_sheet(wb, wsStats, "Estadísticas Visibles");
            
            // Generar nombre de archivo descriptivo
            const now = new Date();
            const dateStr = now.toISOString().slice(0, 10);
            const timeStr = now.toTimeString().slice(0, 5).replace(':', '');
            const fileName = `facturas_visibles_pesos_colombianos_${dateStr}_${timeStr}.xlsx`;
            
            // Descargar archivo
            XLSX.writeFile(wb, fileName);
            
            window.storageManager?.showStatusMessage(`Excel generado: ${totalFacturas} facturas visibles en pesos colombianos`, 'success');
            
        } catch (error) {
            console.error('Error exportando a Excel:', error);
            window.storageManager?.showStatusMessage('Error al generar archivo Excel', 'error');
        }
    }

    
    // Sistema de localStorage mejorado y más fácil de usar
    class ImprovedInvoiceStorage {
        constructor() {
            this.storageKey = 'invoiceSystemV2';
            this.userId = '<?php echo $user_id; ?>';
            this.userRole = '<?php echo $role; ?>';
            this.autoSaveInterval = null;
            this.preferences = this.loadPreferences();
            this.init();
        }

        init() {
            console.log('🚀 Sistema de almacenamiento mejorado iniciado');
            this.loadUserState();
            this.setupEventListeners();
            this.startAutoSave();
            this.applyPreferences();
        }

        // Cargar preferencias con valores por defecto más inteligentes
        loadPreferences() {
            const defaultPreferences = {
                autoSaveFilters: true,
                rememberCollapsed: true,
                autoRefresh: false,
                compactView: false,
                collapsedSuppliers: [],
                lastFilters: {},
                scrollPosition: 0,
                lastVisit: Date.now()
            };

            try {
                const saved = localStorage.getItem(`${this.storageKey}_prefs_${this.userId}`);
                if (saved) {
                    const parsed = JSON.parse(saved);
                    return { ...defaultPreferences, ...parsed };
                }
                return defaultPreferences;
            } catch (error) {
                console.warn('Error cargando preferencias, usando valores por defecto');
                return defaultPreferences;
            }
        }

        // Guardar preferencias con validación
        savePreferences() {
            try {
                this.preferences.lastVisit = Date.now();
                localStorage.setItem(`${this.storageKey}_prefs_${this.userId}`, JSON.stringify(this.preferences));
                this.showStatusMessage('Configuración guardada', 'success');
            } catch (error) {
                console.error('Error guardando preferencias:', error);
                this.showStatusMessage('Error al guardar configuración', 'error');
            }
        }

        // Cargar estado del usuario de manera más robusta
        loadUserState() {
            try {
                const saved = localStorage.getItem(`${this.storageKey}_state_${this.userId}`);
                if (!saved) return;

                const state = JSON.parse(saved);
                
                // Verificar si los datos no son muy antiguos (más de 7 días)
                if (state.timestamp && (Date.now() - state.timestamp) > 7 * 24 * 60 * 60 * 1000) {
                    console.log('Datos antiguos encontrados, limpiando...');
                    this.clearUserState();
                    return;
                }

                // Restaurar filtros si está habilitado
                if (this.preferences.autoSaveFilters && state.filters) {
                    this.restoreFilters(state.filters);
                }

                // Restaurar estado de proveedores colapsados
                if (this.preferences.rememberCollapsed && state.collapsedSuppliers) {
                    setTimeout(() => {
                        this.restoreCollapsedState(state.collapsedSuppliers);
                    }, 500);
                }

                // Restaurar posición de scroll gradualmente
                if (state.scrollPosition && state.scrollPosition > 0) {
                    setTimeout(() => {
                        window.scrollTo({
                            top: state.scrollPosition,
                            behavior: 'smooth'
                        });
                    }, 1000);
                }
            } catch (error) {
                console.error('Error cargando estado del usuario:', error);
                this.clearUserState();
            }
        }

        // Guardar estado del usuario de manera más eficiente
        saveUserState() {
            if (!this.preferences.autoSaveFilters && !this.preferences.rememberCollapsed) {
                return; // No guardar si ambas opciones están desactivadas
            }

            try {
                const state = {
                    timestamp: Date.now(),
                    userId: this.userId,
                    userRole: this.userRole
                };

                if (this.preferences.autoSaveFilters) {
                    state.filters = this.getCurrentFilters();
                }

                if (this.preferences.rememberCollapsed) {
                    state.collapsedSuppliers = this.getCollapsedSuppliers();
                }

                state.scrollPosition = Math.max(0, window.pageYOffset);

                localStorage.setItem(`${this.storageKey}_state_${this.userId}`, JSON.stringify(state));
                
            } catch (error) {
                console.error('Error guardando estado:', error);
                // Si hay error de espacio, limpiar datos antiguos
                this.cleanupOldData();
            }
        }

        // Obtener filtros actuales de manera más robusta
        getCurrentFilters() {
            const form = document.getElementById('filtersForm');
            if (!form) return {};

            const filters = {};
            const formData = new FormData(form);
            
            for (let [key, value] of formData.entries()) {
                if (value && value.trim() !== '') {
                    filters[key] = value.trim();
                }
            }

            return filters;
        }

        // Restaurar filtros con validación
        restoreFilters(filters) {
            if (!filters || typeof filters !== 'object') return;

            Object.keys(filters).forEach(key => {
                const element = document.getElementById(key) || document.querySelector(`[name="${key}"]`);
                if (element && filters[key]) {
                    element.value = filters[key];
                    // Disparar evento change para actualizar la UI
                    element.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }

        // Obtener proveedores colapsados de manera más eficiente
        getCollapsedSuppliers() {
            const collapsed = [];
            document.querySelectorAll('.btn-toggle').forEach(button => {
                const icon = button.querySelector('i');
                if (icon && icon.classList.contains('fa-chevron-right')) {
                    const supplierIndex = button.getAttribute('data-supplier-index');
                    if (supplierIndex) {
                        collapsed.push(parseInt(supplierIndex));
                    }
                }
            });
            return collapsed;
        }

        // Restaurar estado de proveedores colapsados de manera más suave
        restoreCollapsedState(collapsedSuppliers) {
            if (!Array.isArray(collapsedSuppliers)) return;

            collapsedSuppliers.forEach(supplierIndex => {
                const button = document.querySelector(`[data-supplier-index="${supplierIndex}"]`);
                if (button) {
                    const target = button.getAttribute('data-bs-target');
                    const elements = document.querySelectorAll(target);
                    
                    elements.forEach(element => {
                        element.classList.remove('show');
                    });
                    
                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-chevron-right';
                    }
                }
            });
        }

        // Configurar event listeners mejorados
        setupEventListeners() {
            // Listeners para preferencias
            const preferenceElements = [
                'autoSaveFilters', 'rememberCollapsed', 'autoRefresh', 'compactView'
            ];

            preferenceElements.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', (e) => {
                        this.preferences[id] = e.target.checked;
                        this.savePreferences();
                        this.handlePreferenceChange(id, e.target.checked);
                    });
                }
            });

            // Guardar estado al cambiar filtros (con debounce)
            let filterTimeout;
            document.addEventListener('change', (e) => {
                if (e.target.closest('#filtersForm')) {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(() => {
                        if (this.preferences.autoSaveFilters) {
                            this.saveUserState();
                        }
                    }, 500);
                }
            });

            // Guardar estado al colapsar/expandir proveedores
            document.addEventListener('click', (e) => {
                if (e.target.closest('.btn-toggle')) {
                    setTimeout(() => {
                        if (this.preferences.rememberCollapsed) {
                            this.saveUserState();
                        }
                    }, 300);
                }
            });

            // Guardar posición de scroll (con throttle)
            let scrollTimeout;
            let isScrolling = false;
            window.addEventListener('scroll', () => {
                if (!isScrolling) {
                    isScrolling = true;
                    clearTimeout(scrollTimeout);
                    scrollTimeout = setTimeout(() => {
                        this.saveUserState();
                        isScrolling = false;
                    }, 1000);
                }
            });

            // Guardar estado antes de salir
            window.addEventListener('beforeunload', () => {
                this.saveUserState();
            });

            // Limpiar datos al cambiar de pestaña por mucho tiempo
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    // Usuario regresó, verificar si necesita actualizar
                    const lastVisit = this.preferences.lastVisit || 0;
                    const timeDiff = Date.now() - lastVisit;
                    
                    if (timeDiff > 30 * 60 * 1000) { // 30 minutos
                        this.showStatusMessage('Datos actualizados por inactividad', 'info');
                    }
                }
            });
        }

        // Manejar cambios de preferencias
        handlePreferenceChange(preference, value) {
            switch (preference) {
                case 'autoRefresh':
                    this.toggleAutoRefresh(value);
                    break;
                case 'compactView':
                    this.toggleCompactView(value);
                    break;
                case 'autoSaveFilters':
                    if (!value) {
                        this.clearFiltersFromStorage();
                    }
                    break;
                case 'rememberCollapsed':
                    if (!value) {
                        this.clearCollapsedFromStorage();
                    }
                    break;
            }
        }

        // Aplicar preferencias al cargar
        applyPreferences() {
            const elements = {
                autoSaveFilters: document.getElementById('autoSaveFilters'),
                rememberCollapsed: document.getElementById('rememberCollapsed'),
                autoRefresh: document.getElementById('autoRefresh'),
                compactView: document.getElementById('compactView')
            };

            Object.keys(elements).forEach(key => {
                if (elements[key]) {
                    elements[key].checked = this.preferences[key];
                }
            });

            if (this.preferences.autoRefresh) {
                this.toggleAutoRefresh(true);
            }

            if (this.preferences.compactView) {
                this.toggleCompactView(true);
            }
        }

        // Toggle auto-refresh mejorado
        toggleAutoRefresh(enable) {
            if (this.autoRefreshInterval) {
                clearInterval(this.autoRefreshInterval);
                this.autoRefreshInterval = null;
            }

            if (enable) {
                this.autoRefreshInterval = setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        this.showStatusMessage('Actualizando página automáticamente...', 'info');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    }
                }, 5 * 60 * 1000); // 5 minutos

                this.showStatusMessage('Auto-actualización activada (cada 5 min)', 'success');
            } else {
                this.showStatusMessage('Auto-actualización desactivada', 'info');
            }
        }

        // Toggle vista compacta
        toggleCompactView(enable) {
            document.body.classList.toggle('compact-view', enable);
            this.showStatusMessage(
                enable ? 'Vista compacta activada' : 'Vista normal activada', 
                'info'
            );
        }

        // Iniciar auto-guardado inteligente
        startAutoSave() {
            // Guardar cada 30 segundos si hay cambios
            setInterval(() => {
                if (this.hasUnsavedChanges()) {
                    this.saveUserState();
                }
            }, 30000);
        }

        // Verificar si hay cambios sin guardar
        hasUnsavedChanges() {
            try {
                const currentState = {
                    filters: this.getCurrentFilters(),
                    collapsedSuppliers: this.getCollapsedSuppliers(),
                    scrollPosition: window.pageYOffset
                };

                const savedState = localStorage.getItem(`${this.storageKey}_state_${this.userId}`);
                if (!savedState) return true;

                const parsed = JSON.parse(savedState);
                return JSON.stringify(currentState) !== JSON.stringify({
                    filters: parsed.filters || {},
                    collapsedSuppliers: parsed.collapsedSuppliers || [],
                    scrollPosition: parsed.scrollPosition || 0
                });
            } catch (error) {
                return true; // En caso de error, asumir que hay cambios
            }
        }

        // Mostrar mensajes de estado mejorados
        showStatusMessage(message, type = 'info', duration = 3000) {
            const indicator = document.getElementById('statusIndicator');
            const messageElement = document.getElementById('statusMessage');
            
            if (!indicator || !messageElement) return;

            // Limpiar clases anteriores
            indicator.className = 'status-indicator';
            indicator.classList.add('show', type);
            
            messageElement.innerHTML = `
                <i class="fas fa-${this.getIconForType(type)} me-2"></i>
                ${message}
            `;

            setTimeout(() => {
                indicator.classList.remove('show');
            }, duration);
        }

        // Obtener icono según el tipo de mensaje
        getIconForType(type) {
            const icons = {
                success: 'check-circle',
                error: 'exclamation-triangle',
                warning: 'exclamation-circle',
                info: 'info-circle'
            };
            return icons[type] || 'info-circle';
        }

        // Limpiar datos antiguos para liberar espacio
        cleanupOldData() {
            try {
                const keysToRemove = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key && key.includes('invoiceSystem') && !key.includes(this.userId)) {
                        keysToRemove.push(key);
                    }
                }
                
                keysToRemove.forEach(key => {
                    localStorage.removeItem(key);
                });

                this.showStatusMessage('Datos antiguos limpiados', 'info');
            } catch (error) {
                console.error('Error limpiando datos antiguos:', error);
            }
        }

        // Limpiar estado del usuario actual
        clearUserState() {
            try {
                localStorage.removeItem(`${this.storageKey}_state_${this.userId}`);
            } catch (error) {
                console.error('Error limpiando estado del usuario:', error);
            }
        }

        // Limpiar filtros del almacenamiento
        clearFiltersFromStorage() {
            try {
                const saved = localStorage.getItem(`${this.storageKey}_state_${this.userId}`);
                if (saved) {
                    const state = JSON.parse(saved);
                    delete state.filters;
                    localStorage.setItem(`${this.storageKey}_state_${this.userId}`, JSON.stringify(state));
                }
            } catch (error) {
                console.error('Error limpiando filtros:', error);
            }
        }

        // Limpiar estado de colapsado del almacenamiento
        clearCollapsedFromStorage() {
            try {
                const saved = localStorage.getItem(`${this.storageKey}_state_${this.userId}`);
                if (saved) {
                    const state = JSON.parse(saved);
                    delete state.collapsedSuppliers;
                    localStorage.setItem(`${this.storageKey}_state_${this.userId}`, JSON.stringify(state));
                }
            } catch (error) {
                console.error('Error limpiando estado de colapsado:', error);
            }
        }

        // Limpiar todos los datos
        clearAllData() {
            const confirmMessage = `¿Está seguro de que desea limpiar todos los datos guardados?

Esto incluye:
• Configuraciones personales
• Filtros guardados
• Estado de proveedores (expandido/colapsado)
• Posición de scroll

Esta acción no se puede deshacer.`;

            if (confirm(confirmMessage)) {
                try {
                    // Limpiar datos específicos del usuario
                    localStorage.removeItem(`${this.storageKey}_prefs_${this.userId}`);
                    localStorage.removeItem(`${this.storageKey}_state_${this.userId}`);
                    
                    // Limpiar datos generales del sistema
                    Object.keys(localStorage).forEach(key => {
                        if (key.includes(this.storageKey)) {
                            localStorage.removeItem(key);
                        }
                    });

                    this.showStatusMessage('Todos los datos han sido limpiados', 'success', 5000);
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                    
                } catch (error) {
                    console.error('Error limpiando datos:', error);
                    this.showStatusMessage('Error al limpiar los datos', 'error');
                }
            }
        }
    }

    // Variables globales para búsqueda
    let searchTimeout;
    let currentSuggestionIndex = -1;

    // Función para mostrar alerta personalizada mejorada
    function showCustomConfirm(form, invoiceId) {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        
        const alertBox = document.createElement('div');
        alertBox.className = 'custom-alert';
        
        alertBox.innerHTML = `
            <div class="custom-alert-header">
                <i class="fas fa-question-circle me-2"></i> Confirmación Requerida
            </div>
            <div class="custom-alert-body">
                <p>¿Está seguro de marcar esta factura como final?</p>
                <div class="custom-alert-invoice">
                    <i class="fas fa-file-invoice me-2"></i> Factura: ${invoiceId}
                </div>
                <p><strong>Importante:</strong> Una vez marcada como final, esta factura ya no aparecerá en la lista de facturas pendientes y no se podrá deshacer esta acción.</p>
            </div>
            <div class="custom-alert-footer">
                <button type="button" class="custom-alert-btn btn-cancel" id="btnCancel">
                    <i class="fas fa-times me-2"></i>Cancelar
                </button>
                <button type="button" class="custom-alert-btn btn-confirm" id="btnConfirm">
                    <i class="fas fa-check me-2"></i>Confirmar
                </button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        overlay.appendChild(alertBox);
        
        // Enfocar el botón de confirmar
        setTimeout(() => {
            document.getElementById('btnConfirm').focus();
        }, 100);
        
        // Manejar eventos
        document.getElementById('btnCancel').addEventListener('click', function() {
            document.body.removeChild(overlay);
        });
        
        document.getElementById('btnConfirm').addEventListener('click', function() {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'mark_final';
            hiddenInput.value = '1';
            form.appendChild(hiddenInput);
            
            // Mostrar indicador de carga
            const confirmBtn = document.getElementById('btnConfirm');
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Procesando...';
            confirmBtn.disabled = true;
            
            form.submit();
        });
        
        // Cerrar con ESC
        const escHandler = function(e) {
            if (e.key === 'Escape' && document.body.contains(overlay)) {
                document.body.removeChild(overlay);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
        
        // Cerrar al hacer clic fuera
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
            }
        });
    }

    // Función para buscar proveedores mejorada
    function searchSuppliers(query) {
        if (query.length < 2) {
            hideSuggestions();
            return;
        }
        
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            fetch(`?action=search_suppliers&q=${encodeURIComponent(query)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return response.json();
                })
                .then(suppliers => {
                    showSuggestions(suppliers);
                })
                .catch(error => {
                    console.error('Error al buscar proveedores:', error);
                    hideSuggestions();
                    window.storageManager?.showStatusMessage('Error al buscar proveedores', 'error');
                });
        }, 300);
    }

    // Mostrar sugerencias mejorado
    function showSuggestions(suppliers) {
        const suggestionsContainer = document.getElementById('supplierSuggestions');
        if (!suggestionsContainer) return;
        
        suggestionsContainer.innerHTML = '';
        currentSuggestionIndex = -1;
        
        if (!suppliers || suppliers.length === 0) {
            suggestionsContainer.innerHTML = '<div class="no-results"><i class="fas fa-search me-2"></i>No se encontraron proveedores</div>';
            suggestionsContainer.style.display = 'block';
            return;
        }
        
        suppliers.forEach((supplier, index) => {
            const suggestionDiv = document.createElement('div');
            suggestionDiv.className = 'supplier-suggestion';
            suggestionDiv.innerHTML = `<i class="fas fa-building me-2"></i>${supplier}`;
            suggestionDiv.addEventListener('click', () => selectSupplier(supplier));
            suggestionsContainer.appendChild(suggestionDiv);
        });
        
        suggestionsContainer.style.display = 'block';
    }

    // Ocultar sugerencias
    function hideSuggestions() {
        const container = document.getElementById('supplierSuggestions');
        if (container) {
            container.style.display = 'none';
        }
        currentSuggestionIndex = -1;
    }

    // Seleccionar proveedor
    function selectSupplier(supplier) {
        const input = document.getElementById('filter_supplier');
        if (input) {
            input.value = supplier;
            hideSuggestions();
            
            // Guardar automáticamente si está habilitado
            if (window.storageManager?.preferences.autoSaveFilters) {
                setTimeout(() => {
                    window.storageManager.saveUserState();
                }, 100);
            }
        }
    }

    // Navegación con teclado en sugerencias
    function handleKeyNavigation(e) {
        const suggestions = document.querySelectorAll('.supplier-suggestion');
        
        if (suggestions.length === 0) return;
        
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                currentSuggestionIndex = Math.min(currentSuggestionIndex + 1, suggestions.length - 1);
                updateActiveSuggestion(suggestions);
                break;
            case 'ArrowUp':
                e.preventDefault();
                currentSuggestionIndex = Math.max(currentSuggestionIndex - 1, -1);
                updateActiveSuggestion(suggestions);
                break;
            case 'Enter':
                e.preventDefault();
                if (currentSuggestionIndex >= 0) {
                    const supplierText = suggestions[currentSuggestionIndex].textContent.replace(/^\s*\S+\s*/, ''); // Remover icono
                    selectSupplier(supplierText);
                }
                break;
            case 'Escape':
                hideSuggestions();
                break;
        }
    }

    // Actualizar sugerencia activa
    function updateActiveSuggestion(suggestions) {
        suggestions.forEach((suggestion, index) => {
            suggestion.classList.toggle('active', index === currentSuggestionIndex);
        });
    }

    // Funciones globales
    function toggleSettingsPanel() {
        const panel = document.getElementById('settingsPanel');
        if (panel) {
            panel.classList.toggle('show');
        }
    }

    function clearAllData() {
        if (window.storageManager) {
            window.storageManager.clearAllData();
        }
    }

    function toggleTodayFilter() {
        const checkbox = document.getElementById('todayOnlyFilter');
        if (!checkbox) return;
        
        const currentUrl = new URL(window.location.href);
        
        if (checkbox.checked) {
            currentUrl.searchParams.set('filter_today_only', '1');
        } else {
            currentUrl.searchParams.delete('filter_today_only');
        }
        
        // Mantener otros filtros
        const form = document.querySelector('#filtersForm');
        if (form) {
            const formData = new FormData(form);
            
            for (let [key, value] of formData.entries()) {
                if (key !== 'filter_today_only' && value) {
                    currentUrl.searchParams.set(key, value);
                }
            }
        }
        
        // Mostrar indicador de carga
        window.storageManager?.showStatusMessage('Aplicando filtro...', 'info');
        
        setTimeout(() => {
            window.location.href = currentUrl.toString();
        }, 500);
    }

    function generateExcel() {
        const supplier = document.getElementById('filter_supplier').value;
        const dateFrom = document.getElementById('filter_date_from').value;
        const dateTo = document.getElementById('filter_date_to').value;
        const todayOnly = document.getElementById('todayOnlyFilter').checked ? '1' : '';
        
        const params = new URLSearchParams({
            action: 'generate_excel',
            supplier: supplier,
            date_from: dateFrom,
            date_to: dateTo,
            today_only: todayOnly
        });
        
        // Crear enlace temporal para descarga
        const link = document.createElement('a');
        link.href = '?' + params.toString();
        link.download = 'facturas_completadas.xlsx';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Inicialización cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 Iniciando sistema mejorado de facturas completadas');
        
        // Inicializar el sistema de storage mejorado
        window.storageManager = new ImprovedInvoiceStorage();

        // Configurar búsqueda de proveedores
        const supplierInput = document.getElementById('filter_supplier');
        if (supplierInput) {
            supplierInput.addEventListener('input', function(e) {
                searchSuppliers(e.target.value);
            });
            
            supplierInput.addEventListener('keydown', handleKeyNavigation);
            
            // Limpiar sugerencias al perder el foco
            supplierInput.addEventListener('blur', function() {
                setTimeout(hideSuggestions, 200); // Delay para permitir clicks en sugerencias
            });
        }

        // Ocultar sugerencias al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.supplier-search-container')) {
                hideSuggestions();
            }
        });

        // Cerrar panel de configuración al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.settings-panel') && !e.target.closest('.settings-toggle')) {
                const panel = document.getElementById('settingsPanel');
                if (panel) {
                    panel.classList.remove('show');
                }
            }
        });

        // Exportar a Excel mejorado con función específica
        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) {
            exportBtn.addEventListener('click', generateExcel);
        }

        // Loading state para filtros
        const filtersForm = document.getElementById('filtersForm');
        if (filtersForm) {
            filtersForm.addEventListener('submit', function() {
                const btn = document.getElementById('filterBtn');
                if (btn) {
                    btn.classList.add('loading');
                    btn.disabled = true;
                }
                
                window.storageManager?.showStatusMessage('Aplicando filtros...', 'info');
            });
        }

        // Controles globales para expandir/colapsar
        const expandAllBtn = document.getElementById('expandAllBtn');
        const collapseAllBtn = document.getElementById('collapseAllBtn');
        const toggleButtons = document.querySelectorAll('.btn-toggle');

        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', function() {
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const elements = document.querySelectorAll(target);
                    
                    elements.forEach(element => {
                        element.classList.add('show');
                    });
                    
                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-chevron-down';
                    }
                });
                
                window.storageManager?.showStatusMessage('Todos los proveedores expandidos', 'info');
                setTimeout(() => {
                    window.storageManager?.saveUserState();
                }, 500);
            });
        }

        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function() {
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const elements = document.querySelectorAll(target);
                    
                    elements.forEach(element => {
                        element.classList.remove('show');
                    });
                    
                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-chevron-right';
                    }
                });
                
                window.storageManager?.showStatusMessage('Todos los proveedores colapsados', 'info');
                setTimeout(() => {
                    window.storageManager?.saveUserState();
                }, 500);
            });
        }

        // Cambiar icono cuando se colapsa/expande individualmente
        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                setTimeout(() => {
                    const icon = this.querySelector('i');
                    const target = this.getAttribute('data-bs-target');
                    const element = document.querySelector(target);
                    
                    if (element && icon) {
                        if (element.classList.contains('show')) {
                            icon.className = 'fas fa-chevron-down';
                        } else {
                            icon.className = 'fas fa-chevron-right';
                        }
                    }
                }, 100);
            });
        });

        // Auto-dismiss alerts mejorado
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                try {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                } catch (error) {
                    // Fallback si bootstrap no está disponible
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 300);
                }
            });
        }, 5000);

        console.log('✅ Sistema mejorado de facturas completadas cargado correctamente');
    });
    </script>
</body>
</html>