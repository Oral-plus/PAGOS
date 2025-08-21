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

// FUNCIÓN CORREGIDA: Determinar el color y mensaje según días de vencimiento
function getDaysStatusAndColor($dias_vencido) {
    $dias = (int)trim($dias_vencido);
    
    // LÓGICA CORREGIDA:
    // Si dias_vencido es POSITIVO = días que faltan para vencer (futuro)
    // Si dias_vencido es NEGATIVO = días que lleva vencida (pasado)
    // Si dias_vencido es 0 = vence hoy
    
    if ($dias < 0) {
        // Factura vencida (días negativos = días de atraso)
        $dias_vencidos = abs($dias); // Convertir a positivo para mostrar días de atraso
        
        if ($dias_vencidos <= 15) {
            return [
                'color' => '#ff9800', // Naranja
                'mensaje' => '(Mora leve - ' . $dias_vencidos . ' días)',
                'class' => 'mora-leve'
            ];
        } elseif ($dias_vencidos <= 30) {
            return [
                'color' => '#f44336', // Rojo
                'mensaje' => '(Mora grave - ' . $dias_vencidos . ' días)',
                'class' => 'mora-grave'
            ];
        } else {
            return [
                'color' => '#b71c1c', // Rojo oscuro
                'mensaje' => '¡MORA CRÍTICA - ' . $dias_vencidos . ' días!',
                'class' => 'mora-critica'
            ];
        }
    } elseif ($dias == 0) {
        // Vence hoy
        return [
            'color' => '#ff5722', // Naranja rojizo
            'mensaje' => '(Vence hoy)',
            'class' => 'vence-hoy'
        ];
    } else {
        // Factura no vencida (días positivos = días restantes para vencer)
        if ($dias <= 7) {
            return [
                'color' => '#ff9800', // Naranja - próxima a vencer
                'mensaje' => '(Vence en ' . $dias . ' días)',
                'class' => 'proxima-vencer'
            ];
        } else {
            return [
                'color' => '#4caf50', // Verde - no vencida
                'mensaje' => '(Vence en ' . $dias . ' días)',
                'class' => 'no-vencida'
            ];
        }
    }
}

// Procesar la actualización del campo "ok" si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_ok'])) {
    $invoice_id = $_POST['invoice_id'];
    $priority = $_POST['priority'] ?? 'media';
    
    if (hasUserViewedInvoice($invoice_id, $user_id)) {
        updateInvoicePriority($invoice_id, $priority);
        $result = markInvoiceAsOk($invoice_id);
        
        if ($result) {
            $_SESSION['success_message'] = "Factura #$invoice_id marcada como OK correctamente con prioridad " . getPriorityLabel($priority);
        } else {
            $_SESSION['error_message'] = "Error al marcar la factura #$invoice_id como OK";
        }
    } else {
        $_SESSION['error_message'] = "Debe ver los detalles de la factura antes de marcarla como OK";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Procesar la actualización del campo "priority" si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_priority'])) {
    $invoice_id = $_POST['invoice_id'];
    $priority = $_POST['priority'];
    
    $result = updateInvoicePriority($invoice_id, $priority);
    
    if ($result) {
        $_SESSION['success_message'] = "Prioridad de la factura #$invoice_id actualizada correctamente";
    } else {
        $_SESSION['error_message'] = "Error al actualizar la prioridad de la factura #$invoice_id";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Función para actualizar la prioridad de una factura
function updateInvoicePriority($invoice_id, $priority) {
    $conn = getDbConnection();
    $sql = "UPDATE invoices SET priority = ? WHERE docnum_interno_sap = ?";
    $params = array($priority, $invoice_id);
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return ($stmt->rowCount() > 0);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        return ($affected > 0);
    }
}

// Función para marcar una factura como OK
function markInvoiceAsOk($invoice_id) {
    $conn = getDbConnection();
    $sql = "UPDATE invoices SET ok = 'ok' WHERE docnum_interno_sap = ?";
    $params = array($invoice_id);
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return ($stmt->rowCount() > 0);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        return ($affected > 0);
    }
}

// Verificar si una factura ya está marcada como OK
function isInvoiceMarkedAsOk($invoice_id) {
    $conn = getDbConnection();
    $sql = "SELECT ok FROM invoices WHERE docnum_interno_sap = ?";
    $params = array($invoice_id);
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return ($result && $result['ok'] === 'ok');
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return ($row && $row['ok'] === 'ok');
    }
}

// Función para verificar si un usuario ha visto los detalles de una factura
function hasUserViewedInvoice($invoice_id, $user_id) {
    $conn = getDbConnection();
    $sql = "SELECT COUNT(*) as viewed FROM invoice_views WHERE invoice_id = ? AND user_id = ?";
    $params = array($invoice_id, $user_id);
    
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
}

// Función para obtener el texto de la prioridad
function getPriorityLabel($priority) {
    switch ($priority) {
        case 'alta':
            return 'Alta';
        case 'media':
            return 'Media';
        case 'baja':
            return 'Baja';
        default:
            return 'No definida';
    }
}

// Calcular totales por proveedor SIN DUPLICADOS
function calculateSupplierTotals($invoices) {
    $supplier_totals = [];
    $total_value = 0;
    $seenDocNums = [];
    
    foreach ($invoices as $invoice) {
        $docnum = $invoice['docnum_interno_sap'] ?? null;
        
        if ($docnum && !in_array($docnum, $seenDocNums)) {
            $supplier_name = $invoice['nombre'] ?? 'Sin nombre';
            $invoice_value = $invoice['saldo_pendiente'] ?? 0;
            
            if (!isset($supplier_totals[$supplier_name])) {
                $supplier_totals[$supplier_name] = [
                    'total' => 0,
                    'count' => 0
                ];
            }
            
            $supplier_totals[$supplier_name]['total'] += $invoice_value;
            $supplier_totals[$supplier_name]['count']++;
            $total_value += $invoice_value;
            
            $seenDocNums[] = $docnum;
        }
    }
    
    return ['supplier_totals' => $supplier_totals, 'total_value' => $total_value];
}

// FUNCIÓN CORREGIDA: Obtener facturas filtradas - FILTRO CORREGIDO PARA OK HOY
function getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, $is_ok = false, $today_only = false) {
    $conn = getDbConnection();
    $invoices = [];
    
    // CONSULTA SQL CORREGIDA: 
    // DATEDIFF(day, GETDATE(), i.fecha_vencimiento) = días hasta vencimiento
    // Positivo = días que faltan para vencer
    // Negativo = días que lleva vencida
    // 0 = vence hoy
    $sql = "SELECT i.*, DATEDIFF(day, GETDATE(), i.fecha_vencimiento) as dias_de_vencido FROM invoices i WHERE 1=1";
    $params = [];
    
    if ($is_ok) {
        $sql .= " AND i.ok = 'ok'";
    } else {
        $sql .= " AND (i.ok IS NULL OR i.ok = '')";
    }
    
    if ($today_only) {
        $sql .= " AND CAST(i.created_at AS DATE) = CAST(GETDATE() AS DATE) AND i.ok = 'ok'";
    }
    
    if (!empty($date_filter)) {
        $sql .= " AND CAST(i.fecha_vencimiento AS DATE) = ?";
        $params[] = $date_filter;
    }
    
    if (!empty($status_filter)) {
        $sql .= " AND i.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($supplier_filter)) {
        $sql .= " AND i.nombre LIKE ?";
        $params[] = "%" . $supplier_filter . "%";
    }
    
    if (!empty($invoice_id_filter)) {
        $sql .= " AND i.docnum_interno_sap = ?";
        $params[] = $invoice_id_filter;
    }
    
    if (!empty($overdue_days_filter)) {
        // Para filtrar por días específicos de vencimiento
        $sql .= " AND DATEDIFF(day, GETDATE(), i.fecha_vencimiento) = ?";
        $params[] = $overdue_days_filter;
    }
    
    $sql .= " ORDER BY i.nombre ASC, i.fecha_vencimiento DESC";
    
    try {
        if ($conn instanceof PDO) {
            // Para MySQL, ajustar la función DATEDIFF
            $sql = str_replace("DATEDIFF(day, GETDATE(), i.fecha_vencimiento)",
                               "DATEDIFF(i.fecha_vencimiento, CURDATE())", $sql);
            $sql = str_replace("GETDATE()", "CURDATE()", $sql);
            $sql = str_replace("CAST(GETDATE() AS DATE)", "CURDATE()", $sql);
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = sqlsrv_query($conn, $sql, $params);
            if ($stmt === false) {
                throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
            }
            
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $invoices[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
    } catch (Exception $e) {
        error_log("Error en getFilteredInvoices1: " . $e->getMessage());
    }
    
    return $invoices;
}

// NUEVA FUNCIÓN: Obtener el total de facturas marcadas como OK hoy
function getTodayOkCount() {
    $conn = getDbConnection();
    $sql = "SELECT COUNT(*) as count FROM invoices WHERE ok = 'ok' AND CAST(created_at AS DATE) = CAST(GETDATE() AS DATE)";
    
    try {
        if ($conn instanceof PDO) {
            $sql = str_replace("GETDATE()", "CURDATE()", $sql);
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } else {
            $stmt = sqlsrv_query($conn, $sql);
            if ($stmt === false) {
                throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
            }
            
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            return $row['count'] ?? 0;
        }
    } catch (Exception $e) {
        error_log("Error en getTodayOkCount: " . $e->getMessage());
        return 0;
    }
}

$today_ok_count = getTodayOkCount();

// Verificar si es una solicitud AJAX para la tabla principal (facturas pendientes)
if(isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $invoice_id_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
    $overdue_days_filter = isset($_GET['overdue_days']) ? trim($_GET['overdue_days']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, false, $today_only);
    
    // Aplicar búsqueda en tiempo real
    if (!empty($search_term)) {
        $invoices = array_filter($invoices, function($invoice) use ($search_term) {
            $searchFields = [
                $invoice['docnum_interno_sap'] ?? '',
                $invoice['codigo_sn'] ?? '',
                $invoice['nombre'] ?? ''
            ];
            
            foreach ($searchFields as $field) {
                if (stripos($field, $search_term) !== false) {
                    return true;
                }
            }
            return false;
        });
    }
    
    // Filtrar solo seleccionadas si se solicita
    if ($selected_only && !empty($selected_ids)) {
        $invoices = array_filter($invoices, function($invoice) use ($selected_ids) {
            return in_array($invoice['docnum_interno_sap'], $selected_ids);
        });
    }
    
    $totals_data = calculateSupplierTotals($invoices);
    $supplier_totals = $totals_data['supplier_totals'];
    $html = '';
    
    if (count($invoices) > 0) {
        $uniqueInvoices = [];
        $seenDocnums = [];
        
        foreach ($invoices as $invoice) {
            $docnum = $invoice['docnum_interno_sap'];
            if (!in_array($docnum, $seenDocnums)) {
                $seenDocnums[] = $docnum;
                $uniqueInvoices[] = $invoice;
            }
        }
        
        usort($uniqueInvoices, function($a, $b) {
            $supplierCompare = strcmp($a['nombre'], $b['nombre']);
            if ($supplierCompare === 0) {
                return $a['dias_de_vencido'] <=> $b['dias_de_vencido'];
            }
            return $supplierCompare;
        });
        
        $currentSupplier = '';
        foreach ($uniqueInvoices as $invoice) {
            $hasViewed = hasUserViewedInvoice($invoice['docnum_interno_sap'], $user_id);
            $currentPriority = isset($invoice['priority']) ? $invoice['priority'] : 'media';
            
            if ($currentSupplier !== $invoice['nombre'] && empty($supplier_filter) && !$selected_only) {
                $currentSupplier = $invoice['nombre'];
                $supplierTotal = isset($supplier_totals[$currentSupplier]) ? $supplier_totals[$currentSupplier] : ['total' => 0, 'count' => 0];
                
                $html .= '<tr class="supplier-header">
                    <td colspan="8">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-building me-2"></i>
                                <strong>' . htmlspecialchars($currentSupplier) . '</strong>
                            </div>
                            <div>
                                <span class="supplier-count-badge me-2">
                                    <i class="fas fa-file-invoice me-1"></i>
                                    ' . $supplierTotal['count'] . ' facturas
                                </span>
                                <span class="supplier-total-badge">
                                    <i class="fas fa-dollar-sign me-1"></i>
                                    $' . number_format($supplierTotal['total'], 2, ',', '.') . '
                                </span>
                            </div>
                        </div>
                    </td>
                </tr>';
            }
            
            $html .= '<tr data-invoice-id="' . htmlspecialchars($invoice['docnum_interno_sap']) . '" data-invoice-value="' . htmlspecialchars($invoice['saldo_pendiente']) . '" data-invoice-name="' . htmlspecialchars($invoice['nombre']) . '" class="' . (empty($supplier_filter) && !$selected_only ? 'supplier-group' : '') . '">';
            
            $html .= '<td>';
            $html .= '<div class="form-check">';
            $html .= '<input type="checkbox" class="form-check-input invoice-checkbox" id="check_' . htmlspecialchars($invoice['docnum_interno_sap']) . '">';
            $html .= '</div>';
            $html .= '</td>';
            
            $html .= '<td>' . htmlspecialchars($invoice['docnum_interno_sap']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['codigo_sn']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['nombre']) . '</td>';
            $html .= '<td>' . formatDate1($invoice['fecha_vencimiento']) . '</td>';
            
            // Aplicar la lógica CORREGIDA de días de vencimiento
            $statusInfo = getDaysStatusAndColor($invoice['dias_de_vencido']);
            $html .= '<td style="color: ' . $statusInfo['color'] . '; font-weight: bold;" class="' . $statusInfo['class'] . '">';
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            // Mostrar el valor correcto según el estado
            if ($invoice['dias_de_vencido'] < 0) {
                // Factura vencida - mostrar días de atraso como positivo
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
                // Factura vigente o vence hoy - mostrar días restantes
                $html .= htmlspecialchars($invoice['dias_de_vencido']);
            }
            
            if ($statusInfo['mensaje']) {
                $extraClass = ($statusInfo['class'] === 'mora-critica') ? ' alerta-critica' : '';
                $html .= ' <span class="mensaje-alerta' . $extraClass . '">' . $statusInfo['mensaje'] . '</span>';
            }
            $html .= '</td>';
            $html .= '<td>$' . number_format($invoice['saldo_pendiente'], 2, ',', '.') . '</td>';
            
            $html .= '<td>';
            $html .= '<div class="btn-group">';
            $html .= '<a href="view_invoice.php?docnum_interno_sap=' . htmlspecialchars($invoice['docnum_interno_sap']) . '" class="btn btn-sm btn-info" title="Ver detalles">';
            $html .= '<i class="fas fa-eye"></i>';
            $html .= '</a>';
            
            if ($hasViewed) {
                $html .= '<button type="button" class="btn btn-sm btn-outline-success mark-ok-btn"';
                $html .= ' data-invoice-id="' . htmlspecialchars($invoice['docnum_interno_sap']) . '"';
                $html .= ' data-priority="' . $currentPriority . '"';
                $html .= ' title="Marcar como OK">';
                $html .= '<i class="fas fa-check"></i>';
                $html .= '</button>';
            } else {
                $html .= '<button class="btn btn-sm btn-outline-secondary" disabled title="Ver detalles primero">';
                $html .= '<i class="fas fa-check"></i>';
                $html .= '</button>';
            }
            
            $html .= '</div>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $html .= '<tr>';
        $html .= '<td colspan="8" class="text-center">No se encontraron facturas pendientes</td>';
        $html .= '</tr>';
    }
    echo $html;
    exit();
}

// Verificar si es una solicitud AJAX para la tabla de facturas marcadas como OK
if(isset($_GET['ajax']) && $_GET['ajax'] == 2) {
    $date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $invoice_id_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
    $overdue_days_filter = isset($_GET['overdue_days']) ? trim($_GET['overdue_days']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $ok_invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, true, $today_only);
    
    // Aplicar búsqueda en tiempo real
    if (!empty($search_term)) {
        $ok_invoices = array_filter($ok_invoices, function($invoice) use ($search_term) {
            $searchFields = [
                $invoice['docnum_interno_sap'] ?? '',
                $invoice['codigo_sn'] ?? '',
                $invoice['nombre'] ?? ''
            ];
            
            foreach ($searchFields as $field) {
                if (stripos($field, $search_term) !== false) {
                    return true;
                }
            }
            return false;
        });
    }
    
    // Filtrar solo seleccionadas si se solicita
    if ($selected_only && !empty($selected_ids)) {
        $ok_invoices = array_filter($ok_invoices, function($invoice) use ($selected_ids) {
            return in_array($invoice['docnum_interno_sap'], $selected_ids);
        });
    }
    
    $totals_data = calculateSupplierTotals($ok_invoices);
    $supplier_totals_ok = $totals_data['supplier_totals'];
    $html = '';
    
    if (count($ok_invoices) > 0) {
        usort($ok_invoices, function($a, $b) {
            $supplierCompare = strcmp($a['nombre'], $b['nombre']);
            if ($supplierCompare === 0) {
                return $a['dias_de_vencido'] <=> $b['dias_de_vencido'];
            }
            return $supplierCompare;
        });
        
        $currentSupplier = '';
        foreach ($ok_invoices as $invoice) {
            $currentPriority = isset($invoice['priority']) ? $invoice['priority'] : 'media';
            
            if ($currentSupplier !== $invoice['nombre'] && empty($supplier_filter) && !$selected_only) {
                $currentSupplier = $invoice['nombre'];
                $supplierTotal = isset($supplier_totals_ok[$currentSupplier]) ? $supplier_totals_ok[$currentSupplier] : ['total' => 0, 'count' => 0];
                
                $html .= '<tr class="supplier-header">
                    <td colspan="8">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-building me-2"></i>
                                <strong>' . htmlspecialchars($currentSupplier) . '</strong>
                            </div>
                            <div>
                                <span class="supplier-count-badge me-2">
                                    <i class="fas fa-check-circle me-1"></i>
                                    ' . $supplierTotal['count'] . ' OK
                                </span>
                                <span class="supplier-total-badge">
                                    <i class="fas fa-dollar-sign me-1"></i>
                                    $' . number_format($supplierTotal['total'], 2, ',', '.') . '
                                </span>
                            </div>
                        </div>
                    </td>
                </tr>';
            }
            
            $html .= '<tr data-invoice-id="' . htmlspecialchars($invoice['docnum_interno_sap']) . '" data-invoice-value="' . htmlspecialchars($invoice['saldo_pendiente']) . '" data-invoice-name="' . htmlspecialchars($invoice['nombre']) . '" class="' . (empty($supplier_filter) && !$selected_only ? 'supplier-group' : '') . '">';
            
            $html .= '<td>';
            $html .= '<div class="form-check">';
            $html .= '<input type="checkbox" class="form-check-input invoice-checkbox-ok" id="check_ok_' . htmlspecialchars($invoice['docnum_interno_sap']) . '">';
            $html .= '</div>';
            $html .= '</td>';
            
            $html .= '<td>' . htmlspecialchars($invoice['docnum_interno_sap']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['codigo_sn']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['nombre']) . '</td>';
            $html .= '<td>' . formatDate1($invoice['fecha_vencimiento']) . '</td>';
            
            // Aplicar la lógica CORREGIDA de días de vencimiento
            $statusInfo = getDaysStatusAndColor($invoice['dias_de_vencido']);
            $html .= '<td style="color: ' . $statusInfo['color'] . '; font-weight: bold;" class="' . $statusInfo['class'] . '">';
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            // Mostrar el valor correcto según el estado
            if ($invoice['dias_de_vencido'] < 0) {
                // Factura vencida - mostrar días de atraso como positivo
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
                // Factura vigente o vence hoy - mostrar días restantes
                $html .= htmlspecialchars($invoice['dias_de_vencido']);
            }
            
            if ($statusInfo['mensaje']) {
                $extraClass = ($statusInfo['class'] === 'mora-critica') ? ' alerta-critica' : '';
                $html .= ' <span class="mensaje-alerta' . $extraClass . '">' . $statusInfo['mensaje'] . '</span>';
            }
            $html .= '</td>';
            $html .= '<td>$' . number_format($invoice['saldo_pendiente'], 2, ',', '.') . '</td>';
            
            $html .= '<td>';
            $html .= '<div class="btn-group">';
            $html .= '<a href="view_invoice.php?docnum_interno_sap=' . htmlspecialchars($invoice['docnum_interno_sap']) . '" class="btn btn-sm btn-info" title="Ver detalles">';
            $html .= '<i class="fas fa-eye"></i>';
            $html .= '</a>';
            $html .= '<span class="badge bg-success ms-2">OK</span>';
            $html .= '</div>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    } else {
        $html .= '<tr>';
        $html .= '<td colspan="8" class="text-center">No hay facturas marcadas como OK</td>';
        $html .= '</tr>';
    }
    echo $html;
    exit();
}

// Para la carga inicial de la página
$date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$invoice_id_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
$overdue_days_filter = isset($_GET['overdue_days']) ? trim($_GET['overdue_days']) : '';
$today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;

$pending_invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, false, $today_only);
$ok_invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, true, $today_only);

$pending_totals_data = calculateSupplierTotals($pending_invoices);
$supplier_totals_pending = $pending_totals_data['supplier_totals'];
$total_pending_value = $pending_totals_data['total_value'];

$ok_totals_data = calculateSupplierTotals($ok_invoices);
$supplier_totals_ok = $ok_totals_data['supplier_totals'];
$total_ok_value = $ok_totals_data['total_value'];

function recordInvoiceView($invoice_id, $user_id) {
    $conn = getDbConnection();
    
    $sql_check = "SELECT COUNT(*) as count FROM invoice_views WHERE invoice_id = ? AND user_id = ?";
    $params = array($invoice_id, $user_id);
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql_check);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            $sql_insert = "INSERT INTO invoice_views (invoice_id, user_id, view_date) VALUES (?, ?, ?)";
            $params_insert = array($invoice_id, $user_id, date('Y-m-d H:i:s'));
            
            $stmt = $conn->prepare($sql_insert);
            $stmt->execute($params_insert);
        }
    } else {
        $stmt = sqlsrv_query($conn, $sql_check, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($row['count'] == 0) {
            $sql_insert = "INSERT INTO invoice_views (invoice_id, user_id, view_date) VALUES (?, ?, ?)";
            $params_insert = array($invoice_id, $user_id, date('Y-m-d H:i:s'));
            
            $stmt = sqlsrv_query($conn, $sql_insert, $params_insert);
            if ($stmt === false) {
                throw new Exception("Error al insertar: " . print_r(sqlsrv_errors(), true));
            }
        }
    }
}

// Función para formatear fechas
function formatDate1($date) {
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Sistema de Aprobación de Facturas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        .highlight {
            background-color: #fff3cd;
        }
        
        .badge-ok {
            background-color: #28a745;
            color: white;
            padding: 0.25em 0.4em;
            font-size: 75%;
            font-weight: 700;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .nav-tabs .nav-link {
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 600;
            border-bottom: 3px solid #0d6efd;
        }
        
        .tab-content {
            padding-top: 1rem;
        }
        
        .badge-count {
            background-color: #6c757d;
            color: white;
            padding: 0.2em 0.6em;
            font-size: 75%;
            font-weight: 700;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .priority-select {
            border-radius: 4px;
            border: 1px solid #ced4da;
            padding: 6px 12px;
            font-size: 14px;
            transition: all 0.3s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23343a40' strokeLinecap='round' strokeLinejoin='round' strokeWidth='2' d='M2 5l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            background-size: 16px 12px;
            padding-right: 2rem;
        }
        
        .priority-alta {
            background-color: #ffebee;
            border-color: #f44336;
            color: #d32f2f;
            font-weight: bold;
        }
        
        .priority-media {
            background-color: #fff8e1;
            border-color: #ffc107;
            color: #ff8f00;
            font-weight: bold;
        }
        
        .priority-baja {
            background-color: #e8f5e9;
            border-color: #4caf50;
            color: #2e7d32;
            font-weight: bold;
        }
        
        .priority-badge-alta {
            background-color: #dc3545;
            color: white;
        }
        
        .priority-badge-media {
            background-color: #ffc107;
            color: #212529;
        }
        
        .priority-badge-baja {
            background-color: #28a745;
            color: white;
        }
        
        .priority-options {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }
        
        .priority-option {
            flex: 1;
            margin: 0 5px;
            text-align: center;
            padding: 15px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .priority-option:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .priority-option.selected {
            border-color: #0d6efd;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }
        
        .priority-option-alta {
            background-color: #ffebee;
            color: #d32f2f;
        }
        
        .priority-option-media {
            background-color: #fff8e1;
            color: #ff8f00;
        }
        
        .priority-option-baja {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .priority-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .selected-invoices-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .selected-invoices-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .selected-invoices-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin: 0;
        }
        
        .selected-invoices-actions {
            display: flex;
            gap: 8px;
        }
        
        .selected-invoices-total {
            background-color: #e9f7ef;
            border-radius: 6px;
            padding: 10px 15px;
            margin-top: 15px;
            text-align: right;
            font-weight: 600;
            color: #198754;
            font-size: 1.1rem;
        }
        
        .selected-row {
            background-color: #e9f7ef !important;
        }
        
        .no-selected-message {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-style: italic;
        }
        
        .supplier-group {
            background-color: #f8f9fa;
            border-left: 3px solid #007bff;
        }
        
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
        
        .supplier-group:hover {
            border-left-color: #007bff;
            background-color: #f8f9fa;
        }
        
        .supplier-header td {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef) !important;
            border-top: 2px solid #007bff;
            font-size: 0.95em;
            padding: 8px 12px;
        }
        
        .search-container {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .search-input {
            border: 2px solid #e9ecef;
            border-radius: 25px;
            padding: 10px 20px 10px 45px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .view-mode-buttons {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .view-mode-btn {
            padding: 8px 16px;
            border: 1px solid #dee2e6;
            background-color: #fff;
            color: #495057;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .view-mode-btn:hover {
            background-color: #f8f9fa;
            border-color: #007bff;
        }
        
        .view-mode-btn.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* ESTILOS CORREGIDOS para los estados de vencimiento */
        .no-vencida {
            background-color: rgba(76, 175, 80, 0.1);
            border-left: 4px solid #4caf50;
        }
        
        .proxima-vencer {
            background-color: rgba(255, 152, 0, 0.1);
            border-left: 4px solid #ff9800;
        }
        
        .vence-hoy {
            background-color: rgba(255, 87, 34, 0.1);
            border-left: 4px solid #ff5722;
            animation: pulso-suave 2s infinite;
        }
        
        .mora-leve {
            background-color: rgba(255, 152, 0, 0.15);
            border-left: 4px solid #ff9800;
        }
        
        .mora-grave {
            background-color: rgba(244, 67, 54, 0.15);
            border-left: 4px solid #f44336;
        }
        
        .mora-critica {
            background-color: rgba(183, 28, 28, 0.2);
            border-left: 4px solid #b71c1c;
            animation: parpadeo-critico 1.5s infinite;
        }
        
        .mensaje-alerta {
            background-color: transparent;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
            font-size: 0.9em;
            font-weight: 600;
        }
        
        .mensaje-alerta.alerta-critica {
            background-color: rgba(183, 28, 28, 0.1);
            border: 1px solid #b71c1c;
            color: #b71c1c;
        }
        
        @keyframes parpadeo-critico {
            0% { opacity: 1; background-color: rgba(183, 28, 28, 0.2); }
            50% { opacity: 0.7; background-color: rgba(183, 28, 28, 0.3); }
            100% { opacity: 1; background-color: rgba(183, 28, 28, 0.2); }
        }
        
        @keyframes pulso-suave {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        
        /* Indicadores visuales adicionales */
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-indicator.no-vencida {
            background-color: #4caf50;
        }
        
        .status-indicator.proxima-vencer {
            background-color: #ff9800;
        }
        
        .status-indicator.vence-hoy {
            background-color: #ff5722;
            animation: pulso-suave 2s infinite;
        }
        
        .status-indicator.mora-leve {
            background-color: #ff9800;
        }
        
        .status-indicator.mora-grave {
            background-color: #f44336;
        }
        
        .status-indicator.mora-critica {
            background-color: #b71c1c;
            animation: parpadeo-critico 1.5s infinite;
        }
        
        /* Alerta especial para explicar la corrección */
        .correction-alert {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(33, 150, 243, 0.2);
        }
        
        .correction-alert h5 {
            color: #1976d2;
            margin-bottom: 10px;
        }
        
        .correction-alert ul {
            margin-bottom: 0;
            color: #1565c0;
        }
        
        .correction-alert li {
            margin-bottom: 5px;
        }

        /* CSS para el contador de OK hoy */
        .today-ok-counter .card {
            min-width: 120px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        
        .today-ok-counter .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .search-container {
            margin-bottom: 20px;
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
                    <h1 class="h2">Panel de Control - Sistema Corregido</h1>
                    <?php if (in_array($role, ['admin'])): ?>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="add_invoice.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Nueva Factura
                            </a>
                        </div>
                        <a href="export_excel.php" class="btn btn-success">
                            <i class="fas fa-file-excel me-2"></i> Exportar a Excel
                        </a>
                    <?php endif; ?>
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
                
                <!-- Sección de facturas seleccionadas -->
                <div id="selectedInvoicesSection" class="selected-invoices-section" style="display: none;">
                    <div class="selected-invoices-header">
                        <h5 class="selected-invoices-title">
                            <i class="fas fa-clipboard-check me-2"></i>
                            Facturas Seleccionadas (<span id="selectedCount">0</span>)
                        </h5>
                        <div class="selected-invoices-actions">
                            <button id="showSelectedBtn" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-filter me-1"></i> Ver Solo Seleccionadas
                            </button>
                            <button id="showAllBtn" class="btn btn-sm btn-outline-secondary" style="display: none;">
                                <i class="fas fa-list me-1"></i> Ver Todas
                            </button>
                            <button id="clearSelectionBtn" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-times me-1"></i> Limpiar Selección
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Proveedor</th>
                                    <th>Valor</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="selectedInvoicesTableBody">
                                <tr>
                                    <td colspan="4" class="no-selected-message">No hay facturas seleccionadas</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="selected-invoices-total">
                        Total: <span id="selectedTotal">$0,00</span>
                    </div>
                </div>
                
                <!-- Búsqueda en tiempo real -->
                <div class="search-container">
                    <!-- Contador de facturas OK hoy -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="position-relative flex-grow-1 me-3">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchInput" class="form-control search-input" placeholder="Buscar por proveedor, número de factura, cuenta contable...">
                        </div>
                        <div class="today-ok-counter">
                            <div class="card border-success">
                                <div class="card-body text-center py-2 px-3">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-check-circle text-success me-2"></i>
                                        <div>
                                            <div class="fw-bold text-success fs-4"><?php echo $today_ok_count; ?></div>
                                            <small class="text-muted">Marcadas OK Hoy</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Filtros</h5>
                    </div>
                    <div class="card-body">
                        <!-- Checkbox para filtrar facturas marcadas como OK hoy -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="todayOnlyFilter" <?php echo $today_only ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold text-success" for="todayOnlyFilter">
                                        <i class="fas fa-calendar-check me-2"></i>
                                        <!-- Updated label to clarify it shows invoices marked as OK today -->
                                        Mostrar solo facturas marcadas como OK hoy (<?php echo date('d/m/Y'); ?>)
                                    </label>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    <!-- Updated description to be more specific about OK status -->
                                    Activa esta opción para ver únicamente las facturas que fueron marcadas como OK el día de hoy
                                </small>
                            </div>
                        </div>
                        
                        <form id="filter-form" class="row g-3">
                            <div class="col-md-2">
                                <label for="invoice_id" class="form-label">Número de Factura</label>
                                <input type="text" class="form-control filter-field" id="invoice_id" name="invoice_id" value="<?php echo htmlspecialchars($invoice_id_filter); ?>" placeholder="ID exacto">
                            </div>
                            <div class="col-md-2">
                                <label for="date" class="form-label">Fecha</label>
                                <input type="date" class="form-control filter-field" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label">Estado</label>
                                <select class="form-select filter-field" id="status" name="status">
                                    <option value="">Todos</option>
                                    <option value="pendiente" <?php echo $status_filter == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="aprobado_subgerente" <?php echo $status_filter == 'aprobado_subgerente' ? 'selected' : ''; ?>>Aprobado por Subgerente</option>
                                    <option value="aprobado_gerente" <?php echo $status_filter == 'aprobado_gerente' ? 'selected' : ''; ?>>Aprobado por Gerente</option>
                                    <option value="aprobado_contador" <?php echo $status_filter == 'aprobado_contador' ? 'selected' : ''; ?>>Aprobado por Contador</option>
                                    <option value="completado" <?php echo $status_filter == 'completado' ? 'selected' : ''; ?>>Completado</option>
                                    <option value="rechazado" <?php echo $status_filter == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="supplier" class="form-label">Proveedor</label>
                                <input type="text" class="form-control filter-field" id="supplier" name="supplier" value="<?php echo htmlspecialchars($supplier_filter); ?>" placeholder="Nombre proveedor">
                            </div>
                            <div class="col-md-2">
                                <label for="overdue_days" class="form-label">Días Vencidos</label>
                                <input type="number" class="form-control filter-field" id="overdue_days" name="overdue_days" value="<?php echo htmlspecialchars($overdue_days_filter); ?>" placeholder="Días exactos">
                            </div>
                            <div class="col-md-12 d-flex justify-content-end">
                                <button type="button" id="clear-filters" class="btn btn-secondary">Limpiar</button>
                                <button type="button" id="apply-filters" class="btn btn-primary ms-2">Aplicar Filtros</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Pestañas para las tablas -->
                <ul class="nav nav-tabs mb-3" id="invoiceTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">
                            <i class="fas fa-clock me-2"></i>
                            Facturas Pendientes
                            <span class="badge-count"><?php echo count($pending_invoices); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab" aria-controls="approved" aria-selected="false">
                            <i class="fas fa-check-circle me-2"></i>
                            Facturas Marcadas como OK
                            <span class="badge-count"><?php echo count($ok_invoices); ?></span>
                        </button>
                    </li>
                </ul>
                
                <!-- Contenido de las pestañas -->
                <div class="tab-content" id="invoiceTabsContent">
                    <!-- Pestaña de Facturas Pendientes -->
                    <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
                        <div class="card shadow-sm">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Facturas Pendientes de Aprobación
                                </h5>
                            </div>
                            <div class="card-body position-relative">
                                <div class="loading-overlay" id="loadingOverlay">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="selectAllPending">
                                                    </div>
                                                </th>
                                                <th>ID Factura</th>
                                                <th>Código SN</th>
                                                <th>Proveedor</th>
                                                <th>Fecha Vencimiento</th>
                                                <th>Días Vencimiento</th>
                                                <th>Saldo Pendiente</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pendingInvoicesTableBody">
                                            <!-- El contenido se carga via AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Totales por proveedor -->
                                <div class="row mt-4">
                                    <div class="col-md-8">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Totales por Proveedor</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row" id="supplierTotalsPending">
                                                    <!-- Se llena dinámicamente -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card grand-total-card">
                                            <div class="card-body text-center">
                                                <h6 class="card-title">Total General</h6>
                                                <div class="grand-total-value" id="grandTotalPending">
                                                    $<?php echo number_format($total_pending_value, 2, ',', '.'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestaña de Facturas Marcadas como OK -->
                    <div class="tab-pane fade" id="approved" role="tabpanel" aria-labelledby="approved-tab">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Facturas Marcadas como OK
                                </h5>
                            </div>
                            <div class="card-body position-relative">
                                <div class="loading-overlay" id="loadingOverlayOk">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="selectAllOk">
                                                    </div>
                                                </th>
                                                <th>ID Factura</th>
                                                <th>Código SN</th>
                                                <th>Proveedor</th>
                                                <th>Fecha Vencimiento</th>
                                                <th>Días Vencimiento</th>
                                                <th>Saldo Pendiente</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody id="okInvoicesTableBody">
                                            <!-- El contenido se carga via AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Totales por proveedor OK -->
                                <div class="row mt-4">
                                    <div class="col-md-8">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0">Totales por Proveedor (OK)</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row" id="supplierTotalsOk">
                                                    <!-- Se llena dinámicamente -->
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card grand-total-card">
                                            <div class="card-body text-center">
                                                <h6 class="card-title">Total General (OK)</h6>
                                                <div class="grand-total-value" id="grandTotalOk">
                                                    $<?php echo number_format($total_ok_value, 2, ',', '.'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para marcar como OK -->
    <div class="modal fade" id="markOkModal" tabindex="-1" aria-labelledby="markOkModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="markOkModalLabel">Marcar Factura como OK</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="markOkForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="mark_as_ok" value="1">
                        <input type="hidden" name="invoice_id" id="modalInvoiceId">
                        
                        <p>¿Está seguro de que desea marcar esta factura como OK?</p>
                        <p><strong>Factura ID:</strong> <span id="modalInvoiceIdDisplay"></span></p>
                        
                        <div class="mb-3">
                            <label for="modalPriority" class="form-label">Seleccione la prioridad:</label>
                            <div class="priority-options">
                                <div class="priority-option priority-option-alta" data-priority="alta">
                                    <div class="priority-icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div><strong>Alta</strong></div>
                                    <small>Urgente</small>
                                </div>
                                <div class="priority-option priority-option-media selected" data-priority="media">
                                    <div class="priority-icon">
                                        <i class="fas fa-minus-circle"></i>
                                    </div>
                                    <div><strong>Media</strong></div>
                                    <small>Normal</small>
                                </div>
                                <div class="priority-option priority-option-baja" data-priority="baja">
                                    <div class="priority-icon">
                                        <i class="fas fa-arrow-down"></i>
                                    </div>
                                    <div><strong>Baja</strong></div>
                                    <small>No urgente</small>
                                </div>
                            </div>
                            <input type="hidden" name="priority" id="modalPriority" value="media">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i>
                            Marcar como OK
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let selectedInvoices = [];
        let isShowingSelectedOnly = false;
        
        // Cargar datos al inicializar la página
        document.addEventListener('DOMContentLoaded', function() {
            loadInvoices();
            loadOkInvoices();
            
            // Configurar eventos
            setupEventListeners();
            
            // Cargar selecciones desde localStorage
            loadSelectedInvoices();
        });
        
        function setupEventListeners() {
            // Búsqueda en tiempo real
            document.getElementById('searchInput').addEventListener('input', debounce(function() {
                loadInvoices();
                loadOkInvoices();
            }, 300));
            
            // Filtros
            document.querySelectorAll('.filter-field').forEach(field => {
                field.addEventListener('change', function() {
                    loadInvoices();
                    loadOkInvoices();
                });
            });
            
            // Filtro de solo hoy
            document.getElementById('todayOnlyFilter').addEventListener('change', function() {
                loadInvoices();
                loadOkInvoices();
            });
            
            // Botones de filtros
            document.getElementById('apply-filters').addEventListener('click', function() {
                loadInvoices();
                loadOkInvoices();
            });
            
            document.getElementById('clear-filters').addEventListener('click', function() {
                document.querySelectorAll('.filter-field').forEach(field => {
                    field.value = '';
                });
                document.getElementById('todayOnlyFilter').checked = false;
                loadInvoices();
                loadOkInvoices();
            });
            
            // Selección de facturas
            document.getElementById('selectAllPending').addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.invoice-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    handleInvoiceSelection(checkbox);
                });
            });
            
            document.getElementById('selectAllOk').addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.invoice-checkbox-ok');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    handleInvoiceSelection(checkbox);
                });
            });
            
            // Botones de selección
            document.getElementById('showSelectedBtn').addEventListener('click', function() {
                isShowingSelectedOnly = true;
                this.style.display = 'none';
                document.getElementById('showAllBtn').style.display = 'inline-block';
                loadInvoices();
                loadOkInvoices();
            });
            
            document.getElementById('showAllBtn').addEventListener('click', function() {
                isShowingSelectedOnly = false;
                this.style.display = 'none';
                document.getElementById('showSelectedBtn').style.display = 'inline-block';
                loadInvoices();
                loadOkInvoices();
            });
            
            document.getElementById('clearSelectionBtn').addEventListener('click', function() {
                selectedInvoices = [];
                updateSelectedInvoicesDisplay();
                saveSelectedInvoices();
                loadInvoices();
                loadOkInvoices();
            });
            
            // Modal de prioridad
            document.querySelectorAll('.priority-option').forEach(option => {
                option.addEventListener('click', function() {
                    document.querySelectorAll('.priority-option').forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    document.getElementById('modalPriority').value = this.dataset.priority;
                });
            });
        }
        
        function loadInvoices() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'flex';
            
            const params = new URLSearchParams({
                ajax: '1',
                date: document.getElementById('date').value,
                status: document.getElementById('status').value,
                supplier: document.getElementById('supplier').value,
                invoice_id: document.getElementById('invoice_id').value,
                overdue_days: document.getElementById('overdue_days').value,
                search: document.getElementById('searchInput').value,
                selected_only: isShowingSelectedOnly,
                selected_ids: selectedInvoices.join(','),
                today_only: document.getElementById('todayOnlyFilter').checked
            });
            
            fetch(`?${params.toString()}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('pendingInvoicesTableBody').innerHTML = html;
                    loadingOverlay.style.display = 'none';
                    
                    // Reconfigurar eventos después de cargar contenido
                    setupInvoiceEvents();
                    restoreSelections();
                })
                .catch(error => {
                    console.error('Error:', error);
                    loadingOverlay.style.display = 'none';
                });
        }
        
        function loadOkInvoices() {
            const loadingOverlay = document.getElementById('loadingOverlayOk');
            loadingOverlay.style.display = 'flex';
            
            const params = new URLSearchParams({
                ajax: '2',
                date: document.getElementById('date').value,
                status: document.getElementById('status').value,
                supplier: document.getElementById('supplier').value,
                invoice_id: document.getElementById('invoice_id').value,
                overdue_days: document.getElementById('overdue_days').value,
                search: document.getElementById('searchInput').value,
                selected_only: isShowingSelectedOnly,
                selected_ids: selectedInvoices.join(','),
                today_only: document.getElementById('todayOnlyFilter').checked
            });
            
            fetch(`?${params.toString()}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('okInvoicesTableBody').innerHTML = html;
                    loadingOverlay.style.display = 'none';
                    
                    // Reconfigurar eventos después de cargar contenido
                    setupInvoiceEvents();
                    restoreSelections();
                })
                .catch(error => {
                    console.error('Error:', error);
                    loadingOverlay.style.display = 'none';
                });
        }
        
        function setupInvoiceEvents() {
            // Checkboxes de facturas
            document.querySelectorAll('.invoice-checkbox, .invoice-checkbox-ok').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    handleInvoiceSelection(this);
                });
            });
            
            // Botones de marcar como OK
            document.querySelectorAll('.mark-ok-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const invoiceId = this.dataset.invoiceId;
                    const priority = this.dataset.priority || 'media';
                    
                    document.getElementById('modalInvoiceId').value = invoiceId;
                    document.getElementById('modalInvoiceIdDisplay').textContent = invoiceId;
                    document.getElementById('modalPriority').value = priority;
                    
                    // Seleccionar la opción de prioridad correcta
                    document.querySelectorAll('.priority-option').forEach(opt => opt.classList.remove('selected'));
                    document.querySelector(`[data-priority="${priority}"]`).classList.add('selected');
                    
                    const modal = new bootstrap.Modal(document.getElementById('markOkModal'));
                    modal.show();
                });
            });
        }
        
        function handleInvoiceSelection(checkbox) {
            const row = checkbox.closest('tr');
            const invoiceId = row.dataset.invoiceId;
            const invoiceValue = parseFloat(row.dataset.invoiceValue) || 0;
            const invoiceName = row.dataset.invoiceName || '';
            
            if (checkbox.checked) {
                if (!selectedInvoices.includes(invoiceId)) {
                    selectedInvoices.push(invoiceId);
                }
                row.classList.add('selected-row');
            } else {
                selectedInvoices = selectedInvoices.filter(id => id !== invoiceId);
                row.classList.remove('selected-row');
            }
            
            updateSelectedInvoicesDisplay();
            saveSelectedInvoices();
        }
        
        function updateSelectedInvoicesDisplay() {
            const section = document.getElementById('selectedInvoicesSection');
            const count = document.getElementById('selectedCount');
            const tbody = document.getElementById('selectedInvoicesTableBody');
            const total = document.getElementById('selectedTotal');
            
            count.textContent = selectedInvoices.length;
            
            if (selectedInvoices.length > 0) {
                section.style.display = 'block';
                
                let html = '';
                let totalValue = 0;
                
                selectedInvoices.forEach(invoiceId => {
                    const row = document.querySelector(`tr[data-invoice-id="${invoiceId}"]`);
                    if (row) {
                        const value = parseFloat(row.dataset.invoiceValue) || 0;
                        const name = row.dataset.invoiceName || '';
                        const isOk = row.closest('#approved') !== null;
                        
                        totalValue += value;
                        
                        html += `
                            <tr>
                                <td>${invoiceId}</td>
                                <td>${name}</td>
                                <td>$${value.toLocaleString('es-CO', {minimumFractionDigits: 2})}</td>
                                <td>${isOk ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-warning">Pendiente</span>'}</td>
                            </tr>
                        `;
                    }
                });
                
                tbody.innerHTML = html;
                total.textContent = `$${totalValue.toLocaleString('es-CO', {minimumFractionDigits: 2})}`;
            } else {
                section.style.display = 'none';
            }
        }
        
        function restoreSelections() {
            selectedInvoices.forEach(invoiceId => {
                const checkbox = document.querySelector(`#check_${invoiceId}, #check_ok_${invoiceId}`);
                if (checkbox) {
                    checkbox.checked = true;
                    checkbox.closest('tr').classList.add('selected-row');
                }
            });
            
            updateSelectedInvoicesDisplay();
        }
        
        function saveSelectedInvoices() {
            localStorage.setItem('selectedInvoices', JSON.stringify(selectedInvoices));
        }
        
        function loadSelectedInvoices() {
            const saved = localStorage.getItem('selectedInvoices');
            if (saved) {
                selectedInvoices = JSON.parse(saved);
            }
        }
        
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    </script>
</body>
</html>
