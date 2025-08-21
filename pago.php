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

// Clase para manejar las operaciones de facturas finales
class FinalInvoiceManager {
    private $conn;

    public function __construct() {
        $this->conn = getDbConnection();
    }

    /**
     * Buscar proveedores para autocompletado
     */
    public function searchSuppliers($search_term = '') {
        try {
            $sql = "SELECT DISTINCT nombre FROM invoices WHERE final = 'si'";
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
     * Obtener facturas finales con filtros avanzados y separación por fecha
     */
    public function getFinalInvoicesWithDateSeparation($supplier = '', $date_from = '', $date_to = '', $amount_min = '', $amount_max = '', $search = '') {
        try {
            // Base SQL query - SOLO facturas marcadas como final
            $sql = "SELECT *,
                            DATEDIFF(day, fecha_vencimiento, GETDATE()) as dias_antiguedad,
                            CAST(created_at AS DATE) as fecha_creacion
                     FROM invoices 
                     WHERE final = 'si'
                     AND (status = 'completada' OR status = 'completado')";
            $params = array();

            // Añadir filtro de proveedor si está presente
            if (!empty($supplier)) {
                $sql .= " AND nombre LIKE ?";
                $params[] = '%' . $supplier . '%';
            }

            // Añadir filtro de fecha desde si está presente
            if (!empty($date_from)) {
                $sql .= " AND fecha_vencimiento >= ?";
                $params[] = $date_from;
            }

            // Añadir filtro de fecha hasta si está presente
            if (!empty($date_to)) {
                $sql .= " AND fecha_vencimiento <= ?";
                $params[] = $date_to;
            }

            // Filtro de monto mínimo
            if (!empty($amount_min) && is_numeric($amount_min)) {
                $sql .= " AND saldo_pendiente >= ?";
                $params[] = $amount_min;
            }

            // Filtro de monto máximo
            if (!empty($amount_max) && is_numeric($amount_max)) {
                $sql .= " AND saldo_pendiente <= ?";
                $params[] = $amount_max;
            }

            // Filtro de búsqueda general
            if (!empty($search)) {
                $sql .= " AND (docnum_interno_sap LIKE ? OR nombre LIKE ? OR observaciones LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            // Ordenar por fecha de creación descendente y luego por proveedor
            $sql .= " ORDER BY created_at DESC, nombre ASC, fecha_vencimiento DESC";

            if (is_a($this->conn, 'PDO')) {
                // Para PDO, adaptar la consulta
                $sql = str_replace("DATEDIFF(day, fecha_vencimiento, GETDATE())",
                                   "DATEDIFF(CURDATE(), fecha_vencimiento)", $sql);
                $sql = str_replace("CAST(created_at AS DATE)", "DATE(created_at)", $sql);
                
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = sqlsrv_query($this->conn, $sql, $params);
                if ($stmt === false) {
                    throw new Exception('Error al obtener facturas: ' . print_r(sqlsrv_errors(), true));
                }

                $invoices = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $invoices[] = $row;
                }
            }

            // Separar facturas por fecha de creación
            $today = date('Y-m-d');
            $today_invoices = array();
            $previous_invoices = array();

            foreach ($invoices as $invoice) {
                // Filtrar facturas con ESTADOSAP = 'C'
                if (isset($invoice['ESTADOSAP']) && strtoupper(trim($invoice['ESTADOSAP'])) === 'C') {
                    continue;
                }

                // Obtener fecha de creación
                $created_date = '';
                if (isset($invoice['created_at'])) {
                    if (is_object($invoice['created_at']) && method_exists($invoice['created_at'], 'format')) {
                        $created_date = $invoice['created_at']->format('Y-m-d');
                    } elseif (is_string($invoice['created_at'])) {
                        $created_date = date('Y-m-d', strtotime($invoice['created_at']));
                    }
                } elseif (isset($invoice['fecha_creacion'])) {
                    if (is_object($invoice['fecha_creacion']) && method_exists($invoice['fecha_creacion'], 'format')) {
                        $created_date = $invoice['fecha_creacion']->format('Y-m-d');
                    } elseif (is_string($invoice['fecha_creacion'])) {
                        $created_date = date('Y-m-d', strtotime($invoice['fecha_creacion']));
                    }
                }

                // Separar por fecha
                if ($created_date === $today) {
                    $today_invoices[] = $invoice;
                } else {
                    $previous_invoices[] = $invoice;
                }
            }

            return [
                'today' => $today_invoices,
                'previous' => $previous_invoices,
                'all' => $invoices
            ];

        } catch (Exception $e) {
            error_log("Error en getFinalInvoicesWithDateSeparation: " . $e->getMessage());
            return [
                'today' => array(),
                'previous' => array(),
                'all' => array()
            ];
        }
    }

    /**
     * Obtener estadísticas de facturas finales
     */
    public function getInvoiceStats($invoices) {
        $stats = [
            'total_invoices' => 0,
            'total_amount' => 0,
            'suppliers_count' => 0,
            'priority_breakdown' => ['alta' => 0, 'media' => 0, 'baja' => 0],
            'avg_amount' => 0
        ];

        $unique_suppliers = array();

        foreach ($invoices as $invoice) {
            $stats['total_invoices']++;
            $stats['total_amount'] += ($invoice['saldo_pendiente'] ?? 0);

            $supplier = $invoice['nombre'];
            if (!in_array($supplier, $unique_suppliers)) {
                $unique_suppliers[] = $supplier;
            }

            $priority = strtolower(trim($invoice['priority'] ?? 'baja'));
            if (isset($stats['priority_breakdown'][$priority])) {
                $stats['priority_breakdown'][$priority]++;
            }
        }

        $stats['suppliers_count'] = count($unique_suppliers);
        $stats['avg_amount'] = $stats['total_invoices'] > 0 ? $stats['total_amount'] / $stats['total_invoices'] : 0;

        return $stats;
    }
}

// Función helper para manejar fechas de manera segura
if (!function_exists('safeDateFormat')) {
    function safeDateFormat($date, $format = 'Y-m-d') {
        if (empty($date)) {
            return '';
        }

        // Si es un objeto DateTime (SQL Server)
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format($format);
        }

        // Si es una cadena
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

        // Si es un timestamp numérico
        if (is_numeric($date)) {
            return date($format, $date);
        }

        return '';
    }
}

// Función mejorada para formatear fechas
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (empty($date)) {
            return 'Fecha no disponible';
        }

        // Si es un objeto DateTime (SQL Server)
        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('d/m/Y');
        }

        // Si es una cadena
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

        // Si es un timestamp numérico
        if (is_numeric($date)) {
            return date('d/m/Y', $date);
        }

        return 'Fecha inválida';
    }
}

// Función auxiliar para obtener clase de prioridad
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

// Función para renderizar tabla de facturas por sección
function renderInvoicesSection($invoices, $section_title, $section_id, $badge_color = 'success') {
    if (empty($invoices)) {
        return '';
    }

    // Eliminar duplicados y agrupar por proveedor
    $unique_invoices = [];
    $seen = [];
    foreach ($invoices as $invoice) {
        // Assuming 'id' is unique for each invoice, or 'docnum_interno_sap' if it's the primary identifier
        $key = $invoice['id'] ?? $invoice['docnum_interno_sap']; 
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
    $grand_total = 0;
    
    foreach ($invoices_by_supplier as $supplier => $supplier_invoices) {
        $total = 0;
        $seen_invoices = [];
    
        foreach ($supplier_invoices as $invoice) {
            $factura_id = $invoice['numero_factura_proveedor'];
    
            // Si ya se contó esta factura, la saltamos
            if (in_array($factura_id, $seen_invoices)) {
                continue;
            }
    
            $seen_invoices[] = $factura_id;
            $total += ($invoice['saldo_pendiente'] ?? 0);
        }
    
        $supplier_totals[$supplier] = [
            'total' => $total,
            'count' => count($seen_invoices)
        ];
        $grand_total += $total;
    }
    
    ob_start();
    ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-<?php echo $badge_color; ?> text-white">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-check me-2"></i>
                    <?php echo $section_title; ?>
                    <span class="badge bg-light text-dark ms-2">
                        <?php echo count($unique_invoices); ?> facturas - <?php echo count($invoices_by_supplier); ?> proveedores
                    </span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (count($unique_invoices) > 0): ?>
                    <div class="table-responsive" id="<?php echo $section_id; ?>Container">
                        <?php
                        $supplierIndex = 0;
                        foreach ($invoices_by_supplier as $supplier_name => $supplier_invoices):
                            $supplierIndex++;
                            $unique_section_id = $section_id . '_supplier_' . $supplierIndex;
                        ?>
                            <!-- Encabezado del proveedor -->
                            <div class="supplier-header" data-supplier-id="<?php echo $unique_section_id; ?>">
                                <div class="d-flex justify-content-between align-items-center p-3">
                                    <div class="d-flex align-items-center">
                                        <button class="btn btn-sm btn-toggle me-2" data-bs-toggle="collapse"
                                                data-bs-target=".<?php echo $unique_section_id; ?>"
                                                data-supplier-index="<?php echo $unique_section_id; ?>"
                                                aria-expanded="true" aria-controls="<?php echo $unique_section_id; ?>">
                                            <i class="fas fa-chevron-down"></i>
                                        </button>
                                        <div class="form-check me-2">
                                            <input class="form-check-input select-all-supplier-checkbox" type="checkbox" 
                                                   id="selectAll_<?php echo $unique_section_id; ?>" 
                                                   data-supplier-name="<?php echo htmlspecialchars($supplier_name); ?>"
                                                   data-section-id="<?php echo $section_id; ?>">
                                            <label class="form-check-label sr-only" for="selectAll_<?php echo $unique_section_id; ?>">
                                                Seleccionar todas las facturas de <?php echo htmlspecialchars($supplier_name); ?>
                                            </label>
                                        </div>
                                        <div>
                                            <h6 class="mb-1" style="color: #495057; font-weight: 600;">
                                                <i class="fas fa-building me-2" style="color: #007bff;"></i>
                                                <?php echo htmlspecialchars($supplier_name); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <i class="fas fa-check-double me-1"></i>
                                                <?php echo $supplier_totals[$supplier_name]['count']; ?> factura(s) final(es)
                                            </small>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="supplier-count-badge me-2">
                                            <i class="fas fa-file-invoice me-1"></i>
                                            <?php echo $supplier_totals[$supplier_name]['count']; ?> finales
                                        </span>
                                        <span class="supplier-total-badge">
    <i class="fas fa-dollar-sign me-1"></i>
    $<?php echo number_format($supplier_totals[$supplier_name]['total'], 2, ',', '.'); ?>
</span>

                                    </div>
                                </div>
                            </div>
                                            
                            <!-- Tabla de facturas del proveedor -->
                            <table class="table table-striped table-hover invoice-table <?php echo $unique_section_id; ?> collapse show">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;"><span class="sr-only">Seleccionar</span></th>
                                        <th>N° SAP</th>
                                        <th>Fecha Vencimiento</th>
                                        <th>Fecha Finalización</th>
                                        <th>Valor</th>
                                        <th>Prioridad</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                               <tbody>
    <?php 
    $facturas_mostradas = [];
    foreach ($supplier_invoices as $invoice): 
        $docnum = $invoice['docnum_interno_sap'] ?? null;
        if ($docnum === null || in_array($docnum, $facturas_mostradas)) {
            continue; // Saltar si ya fue mostrada
        }
        $facturas_mostradas[] = $docnum;
    ?>
        <tr class="final-invoice">
            <td>
                <div class="form-check">
                    <input class="form-check-input invoice-checkbox" type="checkbox" 
                           id="invoice_<?php echo htmlspecialchars($invoice['id'] ?? $docnum); ?>" 
                           data-invoice-id="<?php echo htmlspecialchars($invoice['id'] ?? $docnum); ?>"
                           data-invoice-amount="<?php echo htmlspecialchars($invoice['saldo_pendiente'] ?? 0); ?>"
                           data-supplier-name="<?php echo htmlspecialchars($supplier_name); ?>"
                           data-section-id="<?php echo $section_id; ?>">
                    <label class="form-check-label sr-only" for="invoice_<?php echo htmlspecialchars($invoice['id'] ?? $docnum); ?>">
                        Seleccionar factura <?php echo htmlspecialchars($docnum ?? 'N/A'); ?>
                    </label>
                </div>
            </td>
            <td>
                <strong><?php echo htmlspecialchars($docnum ?? 'N/A'); ?></strong>
                <span class="badge bg-<?php echo $badge_color; ?> ms-1" title="Factura Final">
                    <i class="fas fa-check-double"></i>
                </span>
            </td>
            <td>
                <?php echo formatDate($invoice['fecha_vencimiento']); ?>
            </td>
            <td>
                <small class="text-muted">
                    <?php 
                    $created_date = '';
                    if (isset($invoice['created_at'])) {
                        if (is_object($invoice['created_at']) && method_exists($invoice['created_at'], 'format')) {
                            $created_date = $invoice['created_at']->format('d/m/Y H:i');
                        } elseif (is_string($invoice['created_at'])) {
                            $created_date = date('d/m/Y H:i', strtotime($invoice['created_at']));
                        }
                    } elseif (isset($invoice['fecha_creacion'])) {
                        if (is_object($invoice['fecha_creacion']) && method_exists($invoice['fecha_creacion'], 'format')) {
                            $created_date = $invoice['fecha_creacion']->format('d/m/Y H:i');
                        } elseif (is_string($invoice['fecha_creacion'])) {
                            $created_date = date('d/m/Y H:i', strtotime($invoice['fecha_creacion']));
                        }
                    }
                    echo $created_date ?: 'N/A';
                    ?>
                </small>
            </td>
            <td><strong>$<?php echo number_format($invoice['saldo_pendiente'] ?? 0, 2, ',', '.'); ?></strong></td>
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
                <span class="badge badge-final">
                    <i class="fas fa-check-double me-1"></i>
                    Final
                </span>
            </td>
            <td>
                <a href="view_invoice.php?docnum_interno_sap=<?php echo htmlspecialchars($docnum); ?>" class="btn btn-sm btn-info" title="Ver detalles">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>

                            </table>
                        <?php endforeach; ?>
                                            
                        <!-- Controles para expandir/colapsar esta sección -->
                        <div class="d-flex justify-content-end mb-3">
                            <button class="btn btn-sm btn-outline-primary me-2 expand-section-btn" data-section="<?php echo $section_id; ?>">
                                <i class="fas fa-expand me-1"></i> Expandir Sección
                            </button>
                            <button class="btn btn-sm btn-outline-secondary collapse-section-btn" data-section="<?php echo $section_id; ?>">
                                <i class="fas fa-compress me-1"></i> Colapsar Sección
                            </button>
                        </div>
                                            
                    </div>
                </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No hay facturas finales en esta categoría.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php
    return ob_get_clean();
}

// Instanciar el manejador de facturas finales
$finalInvoiceManager = new FinalInvoiceManager();

// API endpoint para búsqueda de proveedores
if (isset($_GET['action']) && $_GET['action'] === 'search_suppliers') {
    header('Content-Type: application/json');
    $search_term = $_GET['q'] ?? '';
    $suppliers = $finalInvoiceManager->searchSuppliers($search_term);
    echo json_encode($suppliers);
    exit();
}

// Inicializar filtros con validación
$filter_supplier = filter_input(INPUT_GET, 'filter_supplier', FILTER_SANITIZE_STRING) ?? '';
$filter_date_from = filter_input(INPUT_GET, 'filter_date_from', FILTER_SANITIZE_STRING) ?? '';
$filter_date_to = filter_input(INPUT_GET, 'filter_date_to', FILTER_SANITIZE_STRING) ?? '';
$filter_amount_min = filter_input(INPUT_GET, 'filter_amount_min', FILTER_VALIDATE_FLOAT) ?? '';
$filter_amount_max = filter_input(INPUT_GET, 'filter_amount_max', FILTER_VALIDATE_FLOAT) ?? '';
$filter_search = filter_input(INPUT_GET, 'filter_search', FILTER_SANITIZE_STRING) ?? '';

// Validar fechas
if (!empty($filter_date_from) && !DateTime::createFromFormat('Y-m-d', $filter_date_from)) {
    $filter_date_from = '';
}
if (!empty($filter_date_to) && !DateTime::createFromFormat('Y-m-d', $filter_date_to)) {
    $filter_date_to = '';
}

// Obtener datos separados por fecha
$invoice_data = $finalInvoiceManager->getFinalInvoicesWithDateSeparation($filter_supplier, $filter_date_from, $filter_date_to, $filter_amount_min, $filter_amount_max, $filter_search);
$today_invoices = $invoice_data['today'];
$previous_invoices = $invoice_data['previous'];
$all_invoices = $invoice_data['all'];

// Calcular estadísticas
$today_stats = $finalInvoiceManager->getInvoiceStats($today_invoices);
$previous_stats = $finalInvoiceManager->getInvoiceStats($previous_invoices);
$total_stats = $finalInvoiceManager->getInvoiceStats($all_invoices);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Facturas Finales - Sistema de Facturación</title>
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
            padding: 1rem;
            text-align: center;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
            margin-top: 2rem; /* Add some space above it */
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
        
        .final-invoice {
            background-color: #e8f5e8;
            border-left: 4px solid #28a745;
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
        
        /* Badge especial para facturas finales */
        .badge-final {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            font-weight: 600;
            animation: pulse-final 2s infinite;
        }
        
        @keyframes pulse-final {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        /* Estilos específicos para las secciones divididas */
        .today-section {
            border-left: 5px solid #28a745;
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        }
        
        .previous-section {
            border-left: 5px solid #ffc107;
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        }
        
        .section-divider {
            height: 3px;
            background: linear-gradient(90deg, #28a745, #ffc107);
            margin: 2rem 0;
            border-radius: 2px;
        }
        
        .date-badge {
            font-size: 0.9em;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .today-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        
        .previous-badge {
            background: linear-gradient(45deg, #ffc107, #ffb300);
            color: #212529;
        }

        /* Estilos para el resumen de total seleccionado */
        .selected-total-summary {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #007bff; /* Bootstrap primary blue */
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            z-index: 1050; /* Above most Bootstrap components */
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
            font-weight: bold;
            transition: transform 0.3s ease-in-out;
            transform: translateX(150%); /* Start off-screen */
        }

        .selected-total-summary.show {
            transform: translateX(0); /* Slide in */
        }

        .selected-total-summary .badge {
            background-color: white;
            color: #007bff;
            font-size: 0.9em;
            padding: 5px 10px;
            border-radius: 15px;
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
                    <h1 class="h2">
                        <i class="fas fa-check-double me-2"></i>
                        Facturas Finales - Divididas por Fecha
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-success me-2" id="exportTodayBtn">
                            <i class="fas fa-file-excel me-1"></i> Exportar Hoy
                        </button>
                        <button type="button" class="btn btn-warning me-2" id="exportPreviousBtn">
                            <i class="fas fa-file-excel me-1"></i> Exportar Anteriores
                        </button>
                        <button type="button" class="btn btn-primary" id="exportAllBtn">
                            <i class="fas fa-file-excel me-1"></i> Exportar Todo
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
                
                <!-- Estadísticas generales -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stats-card today-section">
                            <div class="card-body text-center">
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-day me-2"></i>
                                    Finalizadas Hoy
                                </h5>
                                <h3 class="text-success"><?php echo count($today_invoices); ?></h3>
                                <p class="mb-0">
                                    <strong>$<?php echo number_format($today_stats['total_amount'], 2, ',', '.'); ?></strong>
                                </p>
                                <small class="text-muted"><?php echo $today_stats['suppliers_count']; ?> proveedores</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card previous-section text-dark">
                            <div class="card-body text-center">
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Total
                                </h5>
                                <h3 class="text-dark"><?php echo count($previous_invoices); ?></h3>
                                <p class="mb-0">
                                    <strong>$<?php echo number_format($previous_stats['total_amount'], 2, ',', '.'); ?></strong>
                                </p>
                                <small class="text-muted"><?php echo $previous_stats['suppliers_count']; ?> proveedores</small>
                            </div>
                        </div>
                    </div>
                   
                </div>
                
                <!-- Filtros avanzados -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-filter me-2"></i>
                            Filtros Avanzados de Búsqueda
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filtersForm">
                            <!-- Proveedor con autocompletado -->
                            <div class="col-md-6">
                                <label for="filter_supplier" class="form-label">
                                    <i class="fas fa-building me-1"></i>
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
                            
                            <!-- Búsqueda general -->
                           
                            
                            <!-- Fecha desde -->
                            <div class="col-md-3">
                                <label for="filter_date_from" class="form-label">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Fecha desde
                                </label>
                                <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            
                            <!-- Fecha hasta -->
                            <div class="col-md-3">
                                <label for="filter_date_to" class="form-label">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    Fecha hasta
                                </label>
                                <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                            
                            <!-- Monto mínimo -->
                           
                            
                            <!-- Monto máximo -->
                        
                            
                            <!-- Botones de acción -->
                            <div class="col-12">
                                <div class="d-flex gap-2 justify-content-center">
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
                
                <!-- Controles globales -->
                <div class="d-flex justify-content-between mb-3">
                    <div>
                        <button id="expandAllBtn" class="btn btn-sm btn-outline-primary me-2">
                            <i class="fas fa-expand me-1"></i> Expandir Todo
                        </button>
                        <button id="collapseAllBtn" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-compress me-1"></i> Colapsar Todo
                        </button>
                    </div>
                    <div>
                        <span class="today-badge date-badge me-2">
                            <i class="fas fa-calendar-day me-1"></i>
                            Hoy: <?php echo date('d/m/Y'); ?>
                        </span>
                        <span class="previous-badge date-badge">
                            <i class="fas fa-history me-1"></i>
                            Anteriores
                        </span>
                    </div>
                </div>
                
                <!-- Sección de facturas finalizadas HOY -->
                <?php echo renderInvoicesSection($today_invoices, 'Facturas Finalizadas Hoy (' . date('d/m/Y') . ')', 'today', 'success'); ?>
                
                <!-- Divisor visual -->
                <div class="section-divider"></div>
                
                <!-- Sección de facturas finalizadas en DÍAS ANTERIORES -->
                <?php echo renderInvoicesSection($previous_invoices, 'Facturas Finalizadas en Días Anteriores', 'previous', 'secondary'); ?>
                
            </main>
        </div>
    </div>

    <!-- Resumen flotante del total seleccionado -->
    <div id="totalSelectedSummary" class="selected-total-summary">
        Total Seleccionado: <span id="selectedAmountDisplay">$0,00</span>
        <span class="badge" id="selectedCountDisplay">0 facturas</span>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    
    <script>
        // Variables globales para búsqueda
        let searchTimeout;
        let currentSuggestionIndex = -1;
        
        // Variables para la selección de facturas
        const selectedInvoiceIds = new Set();
        let totalSelectedAmount = 0;

        // Función para actualizar el display del total seleccionado
        function updateTotalDisplay() {
            document.getElementById('selectedAmountDisplay').textContent = `$${totalSelectedAmount.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            document.getElementById('selectedCountDisplay').textContent = `${selectedInvoiceIds.size} factura(s)`;

            const summaryDiv = document.getElementById('totalSelectedSummary');
            if (selectedInvoiceIds.size > 0) {
                summaryDiv.classList.add('show');
            } else {
                summaryDiv.classList.remove('show');
            }
        }

        // Función para manejar la selección individual de facturas
        function handleInvoiceCheckboxChange(event) {
            const checkbox = event.target;
            const invoiceId = checkbox.dataset.invoiceId;
            const invoiceAmount = parseFloat(checkbox.dataset.invoiceAmount);
            const supplierName = checkbox.dataset.supplierName;
            const sectionId = checkbox.dataset.sectionId;

            if (checkbox.checked) {
                selectedInvoiceIds.add(invoiceId);
                totalSelectedAmount += invoiceAmount;
            } else {
                selectedInvoiceIds.delete(invoiceId);
                totalSelectedAmount -= invoiceAmount;
            }
            updateTotalDisplay();
            updateSelectAllCheckbox(supplierName, sectionId);
        }

        // Función para manejar la selección de todas las facturas de un proveedor
        function handleSelectAllSupplierCheckboxChange(event) {
            const selectAllCheckbox = event.target;
            const supplierName = selectAllCheckbox.dataset.supplierName;
            const sectionId = selectAllCheckbox.dataset.sectionId;
            const isChecked = selectAllCheckbox.checked;

            const supplierInvoices = document.querySelectorAll(`.invoice-checkbox[data-supplier-name="${supplierName}"][data-section-id="${sectionId}"]`);
            supplierInvoices.forEach(checkbox => {
                const invoiceId = checkbox.dataset.invoiceId;
                const invoiceAmount = parseFloat(checkbox.dataset.invoiceAmount);

                if (isChecked) {
                    if (!selectedInvoiceIds.has(invoiceId)) {
                        selectedInvoiceIds.add(invoiceId);
                        totalSelectedAmount += invoiceAmount;
                    }
                    checkbox.checked = true;
                } else {
                    if (selectedInvoiceIds.has(invoiceId)) {
                        selectedInvoiceIds.delete(invoiceId);
                        totalSelectedAmount -= invoiceAmount;
                    }
                    checkbox.checked = false;
                }
            });
            updateTotalDisplay();
        }

        // Función para actualizar el estado del checkbox "Seleccionar todo" de un proveedor
        function updateSelectAllCheckbox(supplierName, sectionId) {
            const supplierCheckboxes = document.querySelectorAll(`.invoice-checkbox[data-supplier-name="${supplierName}"][data-section-id="${sectionId}"]`);
            const selectAllCheckbox = document.querySelector(`.select-all-supplier-checkbox[data-supplier-name="${supplierName}"][data-section-id="${sectionId}"]`);

            if (!selectAllCheckbox) return;

            let allChecked = true;
            let anyChecked = false;

            if (supplierCheckboxes.length === 0) {
                allChecked = false; // No invoices, so "select all" should not be checked
            } else {
                supplierCheckboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        anyChecked = true;
                    } else {
                        allChecked = false;
                    }
                });
            }
            
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = anyChecked && !allChecked;
        }

        // Función para buscar proveedores
        function searchSuppliers(query) {
            if (query.length < 2) {
                hideSuggestions();
                return;
            }
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetch(`?action=search_suppliers&q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(suppliers => {
                        showSuggestions(suppliers);
                    })
                    .catch(error => {
                        console.error('Error al buscar proveedores:', error);
                        hideSuggestions();
                    });
            }, 300);
        }
        
        // Mostrar sugerencias
        function showSuggestions(suppliers) {
            const suggestionsContainer = document.getElementById('supplierSuggestions');
            suggestionsContainer.innerHTML = '';
            currentSuggestionIndex = -1;
            
            if (suppliers.length === 0) {
                suggestionsContainer.innerHTML = '<div class="no-results">No se encontraron proveedores</div>';
                suggestionsContainer.style.display = 'block';
                return;
            }
            
            suppliers.forEach((supplier, index) => {
                const suggestionDiv = document.createElement('div');
                suggestionDiv.className = 'supplier-suggestion';
                suggestionDiv.textContent = supplier;
                suggestionDiv.addEventListener('click', () => selectSupplier(supplier));
                suggestionsContainer.appendChild(suggestionDiv);
            });
            
            suggestionsContainer.style.display = 'block';
        }
        
        // Ocultar sugerencias
        function hideSuggestions() {
            document.getElementById('supplierSuggestions').style.display = 'none';
            currentSuggestionIndex = -1;
        }
        
        // Seleccionar proveedor
        function selectSupplier(supplier) {
            document.getElementById('filter_supplier').value = supplier;
            hideSuggestions();
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
                        selectSupplier(suggestions[currentSuggestionIndex].textContent);
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
        
        // Función para exportar tablas específicas
        function exportTableToExcel(sectionId, filename) {
            const tables = document.querySelectorAll(`#${sectionId}Container table`);
            if (tables.length === 0) {
                alert('No hay datos para exportar en esta sección');
                return;
            }
            
            const wb = XLSX.utils.book_new();
            
            tables.forEach((table, index) => {
                // Clone the table to remove the checkbox column before export
                const clonedTable = table.cloneNode(true);
                const headerCells = clonedTable.querySelectorAll('thead th');
                const bodyRows = clonedTable.querySelectorAll('tbody tr');

                // Remove the first header cell (checkbox column)
                if (headerCells.length > 0) {
                    headerCells[0].remove();
                }

                // Remove the first cell (checkbox) from each body row
                bodyRows.forEach(row => {
                    const firstCell = row.querySelector('td');
                    if (firstCell) {
                        firstCell.remove();
                    }
                });

                const ws = XLSX.utils.table_to_sheet(clonedTable);
                const sheetName = `Tabla_${index + 1}`;
                XLSX.utils.book_append_sheet(wb, ws, sheetName);
            });
            
            const fileName = `${filename}_${new Date().toISOString().slice(0,10)}.xlsx`;
            XLSX.writeFile(wb, fileName);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const supplierInput = document.getElementById('filter_supplier');
            
            // Event listeners para búsqueda de proveedores
            supplierInput.addEventListener('input', function(e) {
                searchSuppliers(e.target.value);
            });
            
            supplierInput.addEventListener('keydown', handleKeyNavigation);
            
            // Ocultar sugerencias al hacer clic fuera
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.supplier-search-container')) {
                    hideSuggestions();
                }
            });
            
            // Exportar facturas de hoy
            document.getElementById('exportTodayBtn').addEventListener('click', function() {
                exportTableToExcel('today', 'facturas_finales_hoy');
            });
            
            // Exportar facturas anteriores
            document.getElementById('exportPreviousBtn').addEventListener('click', function() {
                exportTableToExcel('previous', 'facturas_finales_anteriores');
            });
            
            // Exportar todas las facturas
            document.getElementById('exportAllBtn').addEventListener('click', function() {
                const allTables = document.querySelectorAll('.invoice-table');
                if (allTables.length === 0) {
                    alert('No hay datos para exportar');
                    return;
                }
                
                const wb = XLSX.utils.book_new();
                
                allTables.forEach((table, index) => {
                    // Clone the table to remove the checkbox column before export
                    const clonedTable = table.cloneNode(true);
                    const headerCells = clonedTable.querySelectorAll('thead th');
                    const bodyRows = clonedTable.querySelectorAll('tbody tr');

                    // Remove the first header cell (checkbox column)
                    if (headerCells.length > 0) {
                        headerCells[0].remove();
                    }

                    // Remove the first cell (checkbox) from each body row
                    bodyRows.forEach(row => {
                        const firstCell = row.querySelector('td');
                        if (firstCell) {
                            firstCell.remove();
                        }
                    });

                    const ws = XLSX.utils.table_to_sheet(clonedTable);
                    const sheetName = `Facturas_${index + 1}`;
                    XLSX.utils.book_append_sheet(wb, ws, sheetName);
                });
                
                const fileName = `facturas_finales_completo_${new Date().toISOString().slice(0,10)}.xlsx`;
                XLSX.writeFile(wb, fileName);
            });
            
            // Loading state para filtros
            document.getElementById('filtersForm').addEventListener('submit', function() {
                const btn = document.getElementById('filterBtn');
                btn.classList.add('loading');
                btn.disabled = true;
            });
            
            // Expandir todos los proveedores
            document.getElementById('expandAllBtn').addEventListener('click', function() {
                const toggleButtons = document.querySelectorAll('.btn-toggle');
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const collapse = new bootstrap.Collapse(document.querySelector(target), {
                        toggle: false
                    });
                    collapse.show();
                    button.querySelector('i').className = 'fas fa-chevron-down';
                });
            });
            
            // Colapsar todos los proveedores
            document.getElementById('collapseAllBtn').addEventListener('click', function() {
                const toggleButtons = document.querySelectorAll('.btn-toggle');
                toggleButtons.forEach(button => {
                    const target = button.getAttribute('data-bs-target');
                    const collapse = new bootstrap.Collapse(document.querySelector(target), {
                        toggle: false
                    });
                    collapse.hide();
                    button.querySelector('i').className = 'fas fa-chevron-right';
                });
            });
            
            // Expandir/colapsar por sección
            document.querySelectorAll('.expand-section-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const section = this.getAttribute('data-section');
                    const toggleButtons = document.querySelectorAll(`#${section}Container .btn-toggle`);
                    toggleButtons.forEach(button => {
                        const target = button.getAttribute('data-bs-target');
                        const collapse = new bootstrap.Collapse(document.querySelector(target), {
                            toggle: false
                        });
                        collapse.show();
                        button.querySelector('i').className = 'fas fa-chevron-down';
                    });
                });
            });
            
            document.querySelectorAll('.collapse-section-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const section = this.getAttribute('data-section');
                    const toggleButtons = document.querySelectorAll(`#${section}Container .btn-toggle`);
                    toggleButtons.forEach(button => {
                        const target = button.getAttribute('data-bs-target');
                        const collapse = new bootstrap.Collapse(document.querySelector(target), {
                            toggle: false
                        });
                        collapse.hide();
                        button.querySelector('i').className = 'fas fa-chevron-right';
                    });
                });
            });
            
            // Cambiar icono cuando se colapsa/expande individualmente
            document.querySelectorAll('.btn-toggle').forEach(button => {
                button.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    if (icon.classList.contains('fa-chevron-down')) {
                        icon.className = 'fas fa-chevron-right';
                    } else {
                        icon.className = 'fas fa-chevron-down';
                    }
                });
            });
            
            // Auto-dismiss alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert-dismissible');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);

            // --- Lógica de selección de facturas ---
            // Añadir listeners a todos los checkboxes de facturas
            document.querySelectorAll('.invoice-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', handleInvoiceCheckboxChange);
            });

            // Añadir listeners a todos los checkboxes de "seleccionar todo por proveedor"
            document.querySelectorAll('.select-all-supplier-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', handleSelectAllSupplierCheckboxChange);
            });

            // Inicializar el estado de los checkboxes "seleccionar todo" por proveedor
            // y el total seleccionado al cargar la página
            document.querySelectorAll('.select-all-supplier-checkbox').forEach(checkbox => {
                const supplierName = checkbox.dataset.supplierName;
                const sectionId = checkbox.dataset.sectionId;
                updateSelectAllCheckbox(supplierName, sectionId);
            });
            updateTotalDisplay(); // Ensure the total display is correct on load

            console.log("Sistema de facturas finales divididas cargado correctamente con selección de total.");
        });
    </script>
</body>
</html>