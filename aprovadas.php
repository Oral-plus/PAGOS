<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Verificar autenticación y rol
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'];

if ($role !== 'subgerente') {
    header("Location: unauthorized.php");
    exit();
}

class SubgerenteApprovalManager {
    private $conn;
    
    public function __construct() {
        $this->conn = getDbConnection();
    }
    
    /**
     * Obtener todas las aprobaciones SOLO del subgerente con filtros de fecha corregidos
     */
    public function getMyApprovals($filters = []) {
        try {
            $params = [];
            // IMPORTANTE: Solo registros de subgerente
            $whereConditions = ["ia.user_role = ?"];
            $params[] = 'subgerente';
            
            // Filtro de búsqueda
            if (!empty($filters['search'])) {
                $whereConditions[] = "(ia.invoice_id LIKE ? OR ia.comments LIKE ? OR i.nombre LIKE ? OR i.numero_factura_proveedor LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // Filtro de acción
            if (!empty($filters['action'])) {
                $whereConditions[] = "ia.action = ?";
                $params[] = $filters['action'];
            }
            
            // CORREGIDO: Filtros de fecha - Usar CONVERT para asegurar comparación correcta
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) >= CONVERT(DATE, ?)";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) <= CONVERT(DATE, ?)";
                $params[] = $filters['date_to'];
            }
            
            // CORREGIDO: Solo hoy - Usar CONVERT para comparar solo la fecha
            if (!empty($filters['today_only'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())";
            }
            
            // Filtro por proveedor específico
            if (!empty($filters['provider'])) {
                $whereConditions[] = "ISNULL(i.nombre, 'Proveedor no encontrado') = ?";
                $params[] = $filters['provider'];
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            // Consulta principal con información de fecha para debugging
            $sql = "SELECT TOP 1000
                        ia.id,
                        ia.invoice_id,
                        ia.user_id,
                        ia.user_role,
                        ia.action,
                        ia.comments,
                        ia.created_at,
                        CONVERT(DATE, ia.created_at) as fecha_solo,
                        CONVERT(VARCHAR, ia.created_at, 120) as fecha_completa,
                        i.numero_factura_proveedor,
                        ISNULL(i.nombre, 'Proveedor no encontrado') as proveedor_nombre,
                        i.saldo_pendiente,
                        i.fecha_vencimiento,
                        i.dias_de_vencido,
                        i.status as invoice_status,
                        i.priority,
                        i.docnum_interno_sap
                    FROM invoice_approvals ia
                    LEFT JOIN invoices i ON ia.invoice_id = i.docnum_interno_sap
                    $whereClause
                    ORDER BY ia.created_at DESC";
            
            // DEBUG: Log detallado de la consulta
            error_log("=== SUBGERENTE QUERY DEBUG ===");
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            error_log("Filters aplicados: " . print_r($filters, true));
            error_log("WHERE conditions: " . print_r($whereConditions, true));
            
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                error_log("SQL Error: " . print_r($errors, true));
                throw new Exception('Error al obtener aprobaciones: ' . print_r($errors, true));
            }
            
            $results = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Convertir DateTime objects a strings para consistencia
                if ($row['created_at'] instanceof DateTime) {
                    $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
                }
                if ($row['fecha_solo'] instanceof DateTime) {
                    $row['fecha_solo'] = $row['fecha_solo']->format('Y-m-d');
                }
                $results[] = $row;
            }
            
            error_log("Results count: " . count($results));
            if (count($results) > 0) {
                error_log("Primera fila de ejemplo: " . print_r($results[0], true));
            }
            error_log("=== END DEBUG ===");
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Error en getMyApprovals: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener estadísticas con filtros de fecha corregidos
     */
    public function getStats($filters = []) {
        try {
            $params = [];
            // IMPORTANTE: Solo registros de subgerente
            $whereConditions = ["ia.user_role = ?"];
            $params[] = 'subgerente';
            
            // Aplicar filtros
            if (!empty($filters['search'])) {
                $whereConditions[] = "(ia.invoice_id LIKE ? OR ia.comments LIKE ? OR i.nombre LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($filters['action'])) {
                $whereConditions[] = "ia.action = ?";
                $params[] = $filters['action'];
            }
            
            // CORREGIDO: Filtros de fecha
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) >= CONVERT(DATE, ?)";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) <= CONVERT(DATE, ?)";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['today_only'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())";
            }
            
            // Filtro por proveedor
            if (!empty($filters['provider'])) {
                $whereConditions[] = "ISNULL(i.nombre, 'Proveedor no encontrado') = ?";
                $params[] = $filters['provider'];
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN ia.action = 'approved' THEN 1 ELSE 0 END) as aprobadas,
                        SUM(CASE WHEN ia.action = 'rejected' THEN 1 ELSE 0 END) as rechazadas,
                        SUM(CASE WHEN ia.action = 'pending' THEN 1 ELSE 0 END) as pendientes,
                        COUNT(DISTINCT ia.invoice_id) as facturas_unicas
                    FROM invoice_approvals ia
                    LEFT JOIN invoices i ON ia.invoice_id = i.docnum_interno_sap
                    $whereClause";
            
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception('Error al obtener estadísticas: ' . print_r(sqlsrv_errors(), true));
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            return $result ?: [
                'total' => 0,
                'aprobadas' => 0,
                'rechazadas' => 0,
                'pendientes' => 0,
                'facturas_unicas' => 0
            ];
            
        } catch (Exception $e) {
            error_log("Error en getStats: " . $e->getMessage());
            return [
                'total' => 0,
                'aprobadas' => 0,
                'rechazadas' => 0,
                'pendientes' => 0,
                'facturas_unicas' => 0
            ];
        }
    }
    
    /**
     * Obtener fechas disponibles con formato correcto
     */
    public function getAvailableDates() {
        try {
            $sql = "SELECT DISTINCT
                        CONVERT(DATE, created_at) as fecha,
                        CONVERT(VARCHAR, CONVERT(DATE, created_at), 23) as fecha_string,
                        COUNT(*) as cantidad
                    FROM invoice_approvals 
                    WHERE user_role = 'subgerente'
                    GROUP BY CONVERT(DATE, created_at)
                    ORDER BY fecha DESC";
            
            $stmt = sqlsrv_query($this->conn, $sql);
            
            if ($stmt === false) {
                throw new Exception('Error al obtener fechas: ' . print_r(sqlsrv_errors(), true));
            }
            
            $results = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Asegurar formato de fecha consistente
                if ($row['fecha'] instanceof DateTime) {
                    $row['fecha'] = $row['fecha']->format('Y-m-d');
                }
                $results[] = $row;
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Error en getAvailableDates: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verificar registros y obtener información de fechas
     */
    public function checkSubgerenteRecords() {
        try {
            $sql = "SELECT
                        COUNT(*) as total_subgerente,
                        COUNT(DISTINCT CONVERT(DATE, created_at)) as fechas_distintas,
                        MIN(created_at) as fecha_min,
                        MAX(created_at) as fecha_max,
                        MIN(CONVERT(DATE, created_at)) as fecha_min_solo,
                        MAX(CONVERT(DATE, created_at)) as fecha_max_solo
                    FROM invoice_approvals 
                    WHERE user_role = 'subgerente'";
            
            $stmt = sqlsrv_query($this->conn, $sql);
            
            if ($stmt === false) {
                throw new Exception('Error al verificar registros: ' . print_r(sqlsrv_errors(), true));
            }
            
            $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            // Convertir DateTime objects a strings
            if ($result) {
                if ($result['fecha_min'] instanceof DateTime) {
                    $result['fecha_min'] = $result['fecha_min']->format('Y-m-d H:i:s');
                }
                if ($result['fecha_max'] instanceof DateTime) {
                    $result['fecha_max'] = $result['fecha_max']->format('Y-m-d H:i:s');
                }
                if ($result['fecha_min_solo'] instanceof DateTime) {
                    $result['fecha_min_solo'] = $result['fecha_min_solo']->format('Y-m-d');
                }
                if ($result['fecha_max_solo'] instanceof DateTime) {
                    $result['fecha_max_solo'] = $result['fecha_max_solo']->format('Y-m-d');
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error en checkSubgerenteRecords: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtener aprobaciones agrupadas por proveedor con filtros de fecha corregidos
     */
    public function getApprovalsGroupedByProvider($filters = []) {
        try {
            $params = [];
            // IMPORTANTE: Solo registros de subgerente
            $whereConditions = ["ia.user_role = ?"];
            $params[] = 'subgerente';
            
            // Aplicar filtros
            if (!empty($filters['search'])) {
                $whereConditions[] = "(ia.invoice_id LIKE ? OR ia.comments LIKE ? OR i.nombre LIKE ? OR i.numero_factura_proveedor LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // CORREGIDO: Filtros de fecha
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) >= CONVERT(DATE, ?)";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) <= CONVERT(DATE, ?)";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['today_only'])) {
                $whereConditions[] = "CONVERT(DATE, ia.created_at) = CONVERT(DATE, GETDATE())";
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $sql = "SELECT
                        ISNULL(i.nombre, 'Proveedor no encontrado') as proveedor_nombre,
                        COUNT(ia.id) as total_aprobaciones,
                        COUNT(DISTINCT ia.invoice_id) as facturas_unicas,
                        SUM(ISNULL(i.saldo_pendiente, 0)) as total_monto,
                        STRING_AGG(CONVERT(VARCHAR, ia.invoice_id), ', ') as facturas_ids,
                        MIN(ia.created_at) as fecha_aprobacion_min,
                        MAX(ia.created_at) as fecha_aprobacion_max,
                        SUM(CASE WHEN ia.action = 'approved' THEN 1 ELSE 0 END) as aprobadas,
                        SUM(CASE WHEN ia.action = 'rejected' THEN 1 ELSE 0 END) as rechazadas,
                        SUM(CASE WHEN ia.action = 'pending' THEN 1 ELSE 0 END) as pendientes,
                        SUM(CASE WHEN i.dias_de_vencido > 0 THEN 1 ELSE 0 END) as facturas_vencidas
                    FROM invoice_approvals ia
                    LEFT JOIN invoices i ON ia.invoice_id = i.docnum_interno_sap
                    $whereClause
                    GROUP BY ISNULL(i.nombre, 'Proveedor no encontrado')
                    ORDER BY COUNT(ia.id) DESC";
            
            $stmt = sqlsrv_query($this->conn, $sql, $params);
            
            if ($stmt === false) {
                throw new Exception('Error al obtener aprobaciones agrupadas: ' . print_r(sqlsrv_errors(), true));
            }
            
            $results = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Convertir DateTime objects a strings
                if ($row['fecha_aprobacion_min'] instanceof DateTime) {
                    $row['fecha_aprobacion_min'] = $row['fecha_aprobacion_min']->format('Y-m-d H:i:s');
                }
                if ($row['fecha_aprobacion_max'] instanceof DateTime) {
                    $row['fecha_aprobacion_max'] = $row['fecha_aprobacion_max']->format('Y-m-d H:i:s');
                }
                $results[] = $row;
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Error en getApprovalsGroupedByProvider: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Función de debugging para verificar fechas
     */
    public function debugDates($filters = []) {
        try {
            $sql = "SELECT TOP 10
                        id,
                        invoice_id,
                        created_at,
                        CONVERT(DATE, created_at) as fecha_solo,
                        CONVERT(VARCHAR, created_at, 120) as fecha_completa,
                        CONVERT(VARCHAR, CONVERT(DATE, created_at), 23) as fecha_iso,
                        DATEDIFF(day, created_at, GETDATE()) as dias_desde_hoy
                    FROM invoice_approvals 
                    WHERE user_role = 'subgerente'
                    ORDER BY created_at DESC";
            
            $stmt = sqlsrv_query($this->conn, $sql);
            
            if ($stmt === false) {
                throw new Exception('Error en debug: ' . print_r(sqlsrv_errors(), true));
            }
            
            $results = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['created_at'] instanceof DateTime) {
                    $row['created_at'] = $row['created_at']->format('Y-m-d H:i:s');
                }
                if ($row['fecha_solo'] instanceof DateTime) {
                    $row['fecha_solo'] = $row['fecha_solo']->format('Y-m-d');
                }
                $results[] = $row;
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Error en debugDates: " . $e->getMessage());
            return [];
        }
    }
}

// Procesar filtros con validación mejorada
$filters = [
    'search' => filter_input(INPUT_GET, 'filter_search', FILTER_SANITIZE_STRING) ?? '',
    'action' => filter_input(INPUT_GET, 'filter_action', FILTER_SANITIZE_STRING) ?? '',
    'date_from' => filter_input(INPUT_GET, 'filter_date_from', FILTER_SANITIZE_STRING) ?? '',
    'date_to' => filter_input(INPUT_GET, 'filter_date_to', FILTER_SANITIZE_STRING) ?? '',
    'today_only' => isset($_GET['today_only']) ? true : false,
    'provider' => filter_input(INPUT_GET, 'provider', FILTER_SANITIZE_STRING) ?? ''
];

// Validar fechas mejorado
$dateError = '';
if (!empty($filters['date_from'])) {
    $dateFrom = DateTime::createFromFormat('Y-m-d', $filters['date_from']);
    if (!$dateFrom || $dateFrom->format('Y-m-d') !== $filters['date_from']) {
        $filters['date_from'] = '';
        $dateError = "Fecha 'Desde' inválida. Use formato YYYY-MM-DD";
    }
}

if (!empty($filters['date_to'])) {
    $dateTo = DateTime::createFromFormat('Y-m-d', $filters['date_to']);
    if (!$dateTo || $dateTo->format('Y-m-d') !== $filters['date_to']) {
        $filters['date_to'] = '';
        $dateError = "Fecha 'Hasta' inválida. Use formato YYYY-MM-DD";
    }
}

// Validar que date_from no sea mayor que date_to
if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
    if ($filters['date_from'] > $filters['date_to']) {
        $dateError = "La fecha 'Desde' no puede ser mayor que la fecha 'Hasta'";
        $filters['date_from'] = '';
        $filters['date_to'] = '';
    }
}

// Si "Solo hoy" está activado, limpiar filtros de fecha
if ($filters['today_only']) {
    $filters['date_from'] = '';
    $filters['date_to'] = '';
}

// Obtener datos
$manager = new SubgerenteApprovalManager();
$stats = $manager->getStats($filters);
$availableDates = $manager->getAvailableDates();
$subgerenteInfo = $manager->checkSubgerenteRecords();

// Para debugging - obtener muestra de fechas
$debugDates = $manager->debugDates();

// Determinar qué vista mostrar
if (!empty($filters['provider'])) {
    $approvals = $manager->getMyApprovals($filters);
    $viewMode = 'provider_detail';
} elseif (isset($_GET['view']) && $_GET['view'] === 'list') {
    $approvals = $manager->getMyApprovals($filters);
    $viewMode = 'list';
} else {
    $approvalsByProvider = $manager->getApprovalsGroupedByProvider($filters);
    $viewMode = 'grouped';
}

// Funciones helper para formateo



?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Mis Aprobaciones - Subgerente</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #0891b2;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        .main-header {
            background: linear-gradient(135deg, var(--primary), #1e40af);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 1rem 1rem;
        }
        
        .stats-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .filter-panel {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .data-table {
            background: white;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .table th {
            background-color: #f8fafc;
            border: none;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
        }
        
        .table td {
            border: none;
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.2s;
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .badge-approved {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .badge-rejected {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .btn-modern {
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-modern:hover {
            transform: translateY(-1px);
        }
        
        .search-highlight {
            background-color: #fef3c7;
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .today-badge {
            background: linear-gradient(45deg, #06b6d4, #0891b2);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .view-tabs {
            background: white;
            border-radius: 1rem;
            padding: 0.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
        }
        
        .view-tabs .nav-link {
            border-radius: 0.5rem;
            font-weight: 500;
            color: #6b7280;
            border: none;
        }
        
        .view-tabs .nav-link.active {
            background-color: var(--primary);
            color: white;
        }
        
        .info-banner {
            background: linear-gradient(45deg, #dcfce7, #bbf7d0);
            border: 1px solid #16a34a;
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-banner i {
            color: #166534;
        }
        
        .debug-panel {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .date-error {
            background: #f8d7da;
            border: 1px solid #dc3545;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 1rem;
            color: #721c24;
        }
        
        .subgerente-info {
            background: #e0f2fe;
            border: 1px solid #0891b2;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .active-filters {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-tag {
            background: #0ea5e9;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            margin: 0.25rem;
            display: inline-block;
        }
        
        .debug-dates {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Header Principal -->
              
                <!-- Error de fecha -->
                <?php if ($dateError): ?>
                    <div class="date-error">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> <?= $dateError ?>
                    </div>
                <?php endif; ?>
                
                <!-- Debug de fechas -->
                <?php if (!empty($debugDates) && isset($_GET['debug'])): ?>
                    <div class="debug-dates">
                        <h6><i class="fas fa-bug me-2"></i>Debug de Fechas (últimos 10 registros)</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Invoice ID</th>
                                        <th>Created At</th>
                                        <th>Fecha Solo</th>
                                        <th>Fecha Completa</th>
                                        <th>Fecha ISO</th>
                                        <th>Días desde hoy</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debugDates as $debug): ?>
                                    <tr>
                                        <td><?= $debug['id'] ?></td>
                                        <td><?= $debug['invoice_id'] ?></td>
                                        <td><?= $debug['created_at'] ?></td>
                                        <td><?= $debug['fecha_solo'] ?></td>
                                        <td><?= $debug['fecha_completa'] ?></td>
                                        <td><?= $debug['fecha_iso'] ?></td>
                                        <td><?= $debug['dias_desde_hoy'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">
                            <a href="?debug=1&<?= http_build_query($filters) ?>" class="btn btn-sm btn-outline-info">Actualizar Debug</a>
                            <a href="?" class="btn btn-sm btn-outline-secondary">Ocultar Debug</a>
                        </small>
                    </div>
                <?php endif; ?>
                
                <!-- Información del Subgerente -->
                <?php if ($subgerenteInfo): ?>
                    <div class="subgerente-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Información de Registros</h6>
                        <div class="row">
                            <div class="col-md-2">
                                <strong>Total registros:</strong> <?= number_format($subgerenteInfo['total_subgerente']) ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Fechas distintas:</strong> <?= $subgerenteInfo['fechas_distintas'] ?>
                            </div>
                            <div class="col-md-2">
                                <strong>Rango fechas:</strong><br>
                                <small><?= $subgerenteInfo['fecha_min_solo'] ?? 'N/A' ?> - <?= $subgerenteInfo['fecha_max_solo'] ?? 'N/A' ?></small>
                            </div>
                            <div class="col-md-3">
                                <strong>Fecha más antigua:</strong><br>
                                <small><?= formatDateTime($subgerenteInfo['fecha_min']) ?></small>
                            </div>
                            <div class="col-md-3">
                                <strong>Fecha más reciente:</strong><br>
                                <small><?= formatDateTime($subgerenteInfo['fecha_max']) ?></small>
                            </div>
                        </div>
                        <div class="mt-2">
                            <a href="?debug=1&<?= http_build_query($filters) ?>" class="btn btn-sm btn-outline-info">
                                <i class="fas fa-bug me-1"></i>Ver Debug de Fechas
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
               
                <!-- Panel de Fechas Disponibles -->
                <div class="debug-panel">
                    <h6><i class="fas fa-calendar-alt me-2"></i>Enlaces directos fechas recientes</h6>
                    <div class="row">
                        <?php if (!empty($availableDates)): ?>
                            <?php foreach (array_slice($availableDates, 0, 15) as $dateInfo): ?>
                                <div class="col-auto mb-2">
                                    <a href="?filter_date_from=<?= $dateInfo['fecha'] ?>&filter_date_to=<?= $dateInfo['fecha'] ?><?= isset($_GET['view']) ? '&view='.$_GET['view'] : '' ?><?= !empty($filters['provider']) ? '&provider='.urlencode($filters['provider']) : '' ?>"
                                        class="btn btn-sm btn-outline-primary">
                                        <?= formatDate($dateInfo['fecha']) ?> (<?= $dateInfo['cantidad'] ?> registros)
                                    </a>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($availableDates) > 15): ?>
                                <div class="col-auto">
                                    <span class="text-muted">... y <?= count($availableDates) - 15 ?> fechas más</span>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <span class="text-danger">No se encontraron registros de subgerente en la base de datos</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            Hoy: <?= date('Y-m-d') ?> | 
                            Ayer: <?= date('Y-m-d', strtotime('-1 day')) ?> |
                            <a href="?today_only=1" class="text-decoration-none">Ver solo hoy</a> |
                            <a href="?filter_date_from=<?= date('Y-m-d', strtotime('-1 day')) ?>&filter_date_to=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="text-decoration-none">Ver solo ayer</a>
                        </small>
                    </div>
                </div>
                
                <!-- Estadísticas -->
                
                
                <!-- Tabs de Vista -->
                <div class="view-tabs">
                    <ul class="nav nav-pills">
                        <li class="nav-item">
                            <a class="nav-link <?= $viewMode === 'grouped' ? 'active' : '' ?>" href="?<?= http_build_query(array_filter(['filter_search' => $filters['search'], 'filter_action' => $filters['action'], 'filter_date_from' => $filters['date_from'], 'filter_date_to' => $filters['date_to'], 'today_only' => $filters['today_only'] ? '1' : ''])) ?>">
                                <i class="fas fa-building me-2"></i>
                                Por Proveedor
                            </a>
                        </li>
                     
                    </ul>
                </div>
                
                <!-- Panel de Filtros -->
                <div class="filter-panel">
                    <h5 class="mb-3">
                        <i class="fas fa-filter me-2"></i>
                        Filtros de Búsqueda
                    </h5>
                    
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="provider" value="<?= htmlspecialchars($filters['provider']) ?>">
                        <input type="hidden" name="view" value="<?= $_GET['view'] ?? '' ?>">
                        
                       
                        
                    
                        
                        <div class="col-md-2">
                            <label class="form-label">
                                <i class="fas fa-calendar me-1"></i>
                                Desde
                            </label>
                            <input type="date"
                                   class="form-control"
                                   name="filter_date_from"
                                   value="<?= htmlspecialchars($filters['date_from']) ?>"
                                   <?= $filters['today_only'] ? 'disabled' : '' ?>>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">
                                <i class="fas fa-calendar me-1"></i>
                                Hasta
                            </label>
                            <input type="date"
                                   class="form-control"
                                   name="filter_date_to"
                                   value="<?= htmlspecialchars($filters['date_to']) ?>"
                                   <?= $filters['today_only'] ? 'disabled' : '' ?>>
                        </div>
                        
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="today_only"
                                       id="todayOnly"
                                       <?= $filters['today_only'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="todayOnly">
                                    Solo hoy
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-modern me-2">
                                <i class="fas fa-search me-1"></i>
                                Buscar
                            </button>
                            <a href="?" class="btn btn-outline-secondary btn-modern">
                                <i class="fas fa-undo me-1"></i>
                                Limpiar
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Contenido Principal -->
                <?php if ($viewMode === 'provider_detail'): ?>
                    <!-- Vista de detalle por proveedor -->
                    <div class="data-table">
                        <div class="card-header bg-primary text-white p-3">
                            <h5 class="mb-0">
                                <a href="?" class="text-white me-3"><i class="fas fa-arrow-left"></i></a>
                                Aprobaciones para: <?= htmlspecialchars($filters['provider']) ?>
                                <span class="badge bg-light text-dark ms-2"><?= count($approvals) ?> registros</span>
                            </h5>
                        </div>
                        
                        <?php if (!empty($approvals)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>ID Factura</th>
                                            <th>N° Factura</th>
                                            <th>Monto</th>
                                            
                                            <th>Fecha Aprobación</th>
                                            <th>Fecha Solo</th>
                                            <th>Comentarios</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approvals as $approval): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark"><?= $approval['id'] ?></span></td>
                                            <td>
                                                <a href="view_invoice.php?docnum_interno_sap=<?= $approval['invoice_id'] ?>"
                                                   class="text-primary fw-bold text-decoration-none">
                                                    <?= htmlspecialchars($approval['invoice_id']) ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars($approval['numero_factura_proveedor'] ?? 'N/A') ?></td>
                                            <td>
                                                <?php if ($approval['saldo_pendiente']): ?>
                                                    <strong>$<?= number_format($approval['saldo_pendiente'], 2, ',', '.') ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= strtolower($approval['action']) ?> rounded-pill">
                                                    <?php
                                                    $actionText = [
                                                        'approved' => 'Aprobada',
                                                        'rejected' => 'Rechazada',
                                                        'pending' => 'Pendiente'
                                                    ];
                                                    echo $actionText[$approval['action']] ?? ucfirst($approval['action']);
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= formatDateTime($approval['created_at']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="badge bg-info">
                                                    <?= $approval['fecha_solo'] ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($approval['comments']): ?>
                                                    <span class="text-truncate d-inline-block" style="max-width: 200px;"
                                                          title="<?= htmlspecialchars($approval['comments']) ?>">
                                                        <?= htmlspecialchars($approval['comments']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin comentarios</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($approval['invoice_status']): ?>
                                                    <span class="badge <?= $approval['invoice_status'] === 'completada' ? 'bg-success' : 'bg-warning' ?> rounded-pill">
                                                        <?= ucfirst($approval['invoice_status']) ?>
                                                    </span>
                                                    <?php if ($approval['dias_de_vencido'] > 0): ?>
                                                        <br><small class="text-danger">
                                                            <i class="fas fa-exclamation-triangle"></i>
                                                            <?= $approval['dias_de_vencido'] ?> días vencida
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="view_invoice.php?docnum_interno_sap=<?= $approval['invoice_id'] ?>"
                                                   class="btn btn-sm btn-outline-primary btn-modern"
                                                   title="Ver detalles">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h4>No hay aprobaciones</h4>
                                <p>No se encontraron aprobaciones de subgerente para este proveedor con los filtros aplicados.</p>
                                <small class="text-muted">
                                    Intenta usar una de las fechas disponibles mostradas arriba o activa el debug para ver más información.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($viewMode === 'list'): ?>
                    <!-- Vista de lista completa -->
                    <div class="data-table">
                        <div class="card-header bg-primary text-white p-3">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                Lista Completa de Aprobaciones de Subgerente
                                <span class="badge bg-light text-dark ms-2"><?= count($approvals) ?> registros</span>
                            </h5>
                        </div>
                        
                        <?php if (!empty($approvals)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>ID Factura</th>
                                            <th>Proveedor</th>
                                            <th>N° Factura</th>
                                            <th>Monto</th>
                                            
                                            <th>Fecha Aprobación</th>
                                            <th>Fecha Solo</th>
                                            <th>Comentarios</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approvals as $approval): ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark"><?= $approval['id'] ?></span></td>
                                            <td>
                                                <a href="view_invoice.php?docnum_interno_sap=<?= $approval['invoice_id'] ?>"
                                                   class="text-primary fw-bold text-decoration-none">
                                                    <?= htmlspecialchars($approval['invoice_id']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($approval['proveedor_nombre'] ?? 'N/A') ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($approval['numero_factura_proveedor'] ?? 'N/A') ?></td>
                                            <td>
                                                <?php if ($approval['saldo_pendiente']): ?>
                                                    <strong>$<?= number_format($approval['saldo_pendiente'], 2, ',', '.') ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?= strtolower($approval['action']) ?> rounded-pill">
                                                    <?php
                                                    $actionText = [
                                                        'approved' => 'Aprobada',
                                                        'rejected' => 'Rechazada',
                                                        'pending' => 'Pendiente'
                                                    ];
                                                    echo $actionText[$approval['action']] ?? ucfirst($approval['action']);
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= formatDateTime($approval['created_at']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="badge bg-info">
                                                    <?= $approval['fecha_solo'] ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($approval['comments']): ?>
                                                    <span class="text-truncate d-inline-block" style="max-width: 200px;"
                                                          title="<?= htmlspecialchars($approval['comments']) ?>">
                                                        <?= htmlspecialchars($approval['comments']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Sin comentarios</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="view_invoice.php?docnum_interno_sap=<?= $approval['invoice_id'] ?>"
                                                   class="btn btn-sm btn-outline-primary btn-modern"
                                                   title="Ver detalles">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h4>No hay aprobaciones</h4>
                                <p>No se encontraron aprobaciones de subgerente con los filtros seleccionados.</p>
                                <small class="text-muted">
                                    Verifica las fechas disponibles arriba o activa el debug para más información.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <!-- Vista agrupada por proveedor -->
                    <div class="data-table">
                        <div class="card-header bg-primary text-white p-3">
                            <h5 class="mb-0">
                                <i class="fas fa-building me-2"></i>
                                Aprobaciones de Subgerente 
                                <span class="badge bg-light text-dark ms-2"><?= count($approvalsByProvider) ?> proveedores</span>
                            </h5>
                        </div>
                        
                        <?php if (!empty($approvalsByProvider)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Total Aprobaciones</th>
                                            <th>Facturas Únicas</th>
                                            <th>Monto Total</th>
                                        
                                            <th>Rango Fechas</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approvalsByProvider as $provider): ?>
                                        <tr>
                                            <td class="fw-bold"><?= htmlspecialchars($provider['proveedor_nombre']) ?></td>
                                            <td><?= $provider['total_aprobaciones'] ?></td>
                                            <td><?= $provider['facturas_unicas'] ?></td>
                                            <td>$<?= number_format($provider['total_monto'], 2, ',', '.') ?></td>

                                            <td>
                                                <small>
                                                    <?= formatDateTime($provider['fecha_aprobacion_min']) ?><br>
                                                    <?= formatDateTime($provider['fecha_aprobacion_max']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <a href="?provider=<?= urlencode($provider['proveedor_nombre']) ?>&<?= http_build_query(array_filter(['filter_search' => $filters['search'], 'filter_action' => $filters['action'], 'filter_date_from' => $filters['date_from'], 'filter_date_to' => $filters['date_to'], 'today_only' => $filters['today_only'] ? '1' : ''])) ?>"
                                                       class="btn btn-sm btn-outline-primary btn-modern" title="Ver detalle">
                                                        <i class="fas fa-list"></i>
                                                    </a>
                                                    <?php
                                                    $firstInvoiceId = explode(',', $provider['facturas_ids'])[0];
                                                    ?>
                                                    <a href="view_invoice.php?docnum_interno_sap=<?= trim($firstInvoiceId) ?>"
                                                       class="btn btn-sm btn-info btn-modern" title="Ver factura">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h4>No hay datos</h4>
                                <p>No se encontraron aprobaciones de subgerente con los filtros seleccionados.</p>
                                <small class="text-muted">
                                    Verifica las fechas disponibles arriba o activa el debug para más información.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Botón de exportar -->
                <div class="text-end mt-3">
                    <button type="button" class="btn btn-success btn-modern" id="exportBtn">
                        <i class="fas fa-file-excel me-1"></i>
                        Exportar Excel
                    </button>
                </div>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Exportar a Excel
        $('#exportBtn').click(function() {
            const today = new Date().toISOString().slice(0, 10);
            let fileName, dataToExport = [];
            
            <?php if ($viewMode === 'provider_detail'): ?>
                fileName = `subgerente_detalle_proveedor_<?= rawurlencode($filters['provider']) ?>_${today}.xlsx`;
                dataToExport = [
                    ['ID', 'ID Factura', 'N° Factura', 'Monto', 'Acción', 'Fecha Aprobación', 'Fecha Solo', 'Comentarios', 'Estado']
                ];
                <?php foreach ($approvals as $approval): ?>
                    dataToExport.push([
                        <?= $approval['id'] ?>,
                        '<?= addslashes($approval['invoice_id']) ?>',
                        '<?= addslashes($approval['numero_factura_proveedor'] ?? 'N/A') ?>',
                        <?= $approval['saldo_pendiente'] ?? 0 ?>,
                        '<?= addslashes($approval['action']) ?>',
                        '<?= formatDateTime($approval['created_at']) ?>',
                        '<?= $approval['fecha_solo'] ?>',
                        '<?= addslashes($approval['comments'] ?? '') ?>',
                        '<?= addslashes($approval['invoice_status'] ?? 'N/A') ?>'
                    ]);
                <?php endforeach; ?>
            <?php elseif ($viewMode === 'list'): ?>
                fileName = `subgerente_lista_completa_${today}.xlsx`;
                dataToExport = [
                    ['ID', 'ID Factura', 'Proveedor', 'N° Factura', 'Monto', 'Acción', 'Fecha Aprobación', 'Fecha Solo', 'Comentarios']
                ];
                <?php foreach ($approvals as $approval): ?>
                    dataToExport.push([
                        <?= $approval['id'] ?>,
                        '<?= addslashes($approval['invoice_id']) ?>',
                        '<?= addslashes($approval['proveedor_nombre'] ?? 'N/A') ?>',
                        '<?= addslashes($approval['numero_factura_proveedor'] ?? 'N/A') ?>',
                        <?= $approval['saldo_pendiente'] ?? 0 ?>,
                        '<?= addslashes($approval['action']) ?>',
                        '<?= formatDateTime($approval['created_at']) ?>',
                        '<?= $approval['fecha_solo'] ?>',
                        '<?= addslashes($approval['comments'] ?? '') ?>'
                    ]);
                <?php endforeach; ?>
            <?php else: ?>
                fileName = `subgerente_resumen_por_proveedor_${today}.xlsx`;
                dataToExport = [
                    ['Proveedor', 'Total Aprobaciones', 'Facturas Únicas', 'Monto Total', 'Aprobadas', 'Rechazadas', 'Pendientes']
                ];
                <?php foreach ($approvalsByProvider as $provider): ?>
                    dataToExport.push([
                        '<?= addslashes($provider['proveedor_nombre']) ?>',
                        <?= $provider['total_aprobaciones'] ?>,
                        <?= $provider['facturas_unicas'] ?>,
                        <?= $provider['total_monto'] ?>,
                        <?= $provider['aprobadas'] ?>,
                        <?= $provider['rechazadas'] ?>,
                        <?= $provider['pendientes'] ?>
                    ]);
                <?php endforeach; ?>
            <?php endif; ?>
            
            // Crear Excel
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(dataToExport);
            
            // Ajustar columnas
            ws['!cols'] = Array(dataToExport[0].length).fill({wch: 15});
            
            XLSX.utils.book_append_sheet(wb, ws, "Aprobaciones Subgerente");
            XLSX.writeFile(wb, fileName);
        });
        
        // Resaltar búsqueda
        const searchTerm = "<?= addslashes($filters['search']) ?>";
        if (searchTerm) {
            const regex = new RegExp(searchTerm, 'gi');
            $('td').each(function() {
                const html = $(this).html();
                if (html.match && html.match(regex)) {
                    $(this).html(html.replace(regex, match =>
                        `<span class="search-highlight">${match}</span>`
                    ));
                }
            });
        }
        
        // Manejar "Solo hoy" - deshabilitar campos de fecha cuando está activo
        $('#todayOnly').change(function() {
            const isChecked = $(this).is(':checked');
            $('input[name="filter_date_from"], input[name="filter_date_to"]').prop('disabled', isChecked);
            if (isChecked) {
                $('input[name="filter_date_from"], input[name="filter_date_to"]').val('');
                // Auto-submit cuando se activa "Solo hoy"
                $(this).closest('form').submit();
            }
        });
        
        // Desactivar "Solo hoy" cuando se seleccionan fechas
        $('input[name="filter_date_from"], input[name="filter_date_to"]').change(function() {
            if ($(this).val()) {
                $('#todayOnly').prop('checked', false);
                $('input[name="filter_date_from"], input[name="filter_date_to"]').prop('disabled', false);
            }
        });
        
        // Inicializar estado de campos de fecha
        if ($('#todayOnly').is(':checked')) {
            $('input[name="filter_date_from"], input[name="filter_date_to"]').prop('disabled', true);
        }
        
        // Tooltips
        $('[title]').tooltip();
        
        // Validación de fechas en el cliente
        $('input[name="filter_date_from"], input[name="filter_date_to"]').change(function() {
            const dateFrom = $('input[name="filter_date_from"]').val();
            const dateTo = $('input[name="filter_date_to"]').val();
            
            if (dateFrom && dateTo && dateFrom > dateTo) {
                alert('La fecha "Desde" no puede ser mayor que la fecha "Hasta"');
                $(this).val('');
            }
        });
        
        // Enlaces rápidos para fechas comunes
        $('.quick-date-link').click(function(e) {
            e.preventDefault();
            const date = $(this).data('date');
            $('input[name="filter_date_from"]').val(date);
            $('input[name="filter_date_to"]').val(date);
            $('#todayOnly').prop('checked', false);
            $(this).closest('form').find('button[type="submit"]').click();
        });
    });
    </script>
</body>
</html>
