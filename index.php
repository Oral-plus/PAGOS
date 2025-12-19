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
    
    if ($dias < 0) {
        $dias_vencidos = abs($dias);
        
        if ($dias_vencidos <= 15) {
            return [
                'color' => '#ff9800',
                'mensaje' => '(Mora leve - ' . $dias_vencidos . ' días)',
                'class' => 'mora-leve'
            ];
        } elseif ($dias_vencidos <= 30) {
            return [
                'color' => '#f44336',
                'mensaje' => '(Mora grave - ' . $dias_vencidos . ' días)',
                'class' => 'mora-grave'
            ];
        } else {
            return [
                'color' => '#b71c1c',
                'mensaje' => '¡MORA CRÍTICA - ' . $dias_vencidos . ' días!',
                'class' => 'mora-critica'
            ];
        }
    } elseif ($dias == 0) {
        return [
            'color' => '#ff5722',
            'mensaje' => '(Vence hoy)',
            'class' => 'vence-hoy'
        ];
    } else {
        if ($dias <= 7) {
            return [
                'color' => '#ff9800',
                'mensaje' => '(Vence en ' . $dias . ' días)',
                'class' => 'proxima-vencer'
            ];
        } else {
            return [
                'color' => '#4caf50',
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

// <CHANGE> Modificada para excluir facturas con valor 0.00 solo si NO están marcadas como OK
function getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, $is_ok = false, $today_only = false, $approval_date_filter = '') {
    $conn = getDbConnection();
    $invoices = [];
    
    $sql = "SELECT i.*, DATEDIFF(day, GETDATE(), i.fecha_vencimiento) as dias_de_vencido FROM invoices i WHERE 1=1";
    $params = [];
    
    if ($is_ok) {
        $sql .= " AND i.ok = 'ok'";
        // NO filtrar por saldo_pendiente cuando es OK - permitir facturas con valor 0.00
    } else {
        $sql .= " AND (i.ok IS NULL OR i.ok = '')";
        // <CHANGE> Solo filtrar facturas con valor 0.00 cuando NO están marcadas como OK
        $sql .= " AND i.saldo_pendiente > 0";
    }
    
    if ($today_only) {
        $sql .= " AND CAST(i.created_at AS DATE) = CAST(GETDATE() AS DATE)";
    }
    
    if (!empty($date_filter)) {
        $sql .= " AND CAST(i.fecha_vencimiento AS DATE) = ?";
        $params[] = $date_filter;
    }
    
    // Filtro por fecha de aprobación (created_at)
    if (!empty($approval_date_filter)) {
        $sql .= " AND CAST(i.created_at AS DATE) = ?";
        $params[] = $approval_date_filter;
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
        $sql .= " AND DATEDIFF(day, GETDATE(), i.fecha_vencimiento) = ?";
        $params[] = $overdue_days_filter;
    }
    
    $sql .= " ORDER BY i.nombre ASC, i.fecha_vencimiento DESC";
    
    try {
        if ($conn instanceof PDO) {
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

function getTodayOkCount() {
    $conn = getDbConnection();
    $sql = "SELECT COUNT(*) as count FROM invoices WHERE ok = 'ok' AND CAST(created_at AS DATE) = CAST(GETDATE() AS DATE)";
    
    if ($conn instanceof PDO) {
        $sql = str_replace("GETDATE()", "CURDATE()", $sql);
        $sql = str_replace("CAST(GETDATE() AS DATE)", "CURDATE()", $sql);
        
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
}

$today_ok_count = getTodayOkCount();

// Verificar si es una solicitud AJAX para la tabla principal (facturas pendientes)
if(isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
    $invoice_id_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
    $overdue_days_filter = isset($_GET['overdue_days']) ? trim($_GET['overdue_days']) : '';
    $approval_date_filter = isset($_GET['approval_date']) ? trim($_GET['approval_date']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $hasActiveFilter = !empty($date_filter) || !empty($status_filter) || !empty($supplier_filter) || 
                       !empty($invoice_id_filter) || !empty($overdue_days_filter) || !empty($approval_date_filter) || !empty($search_term) || 
                       $selected_only || $today_only;
    
    if (!$hasActiveFilter) {
        echo '<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-search me-2"></i>Use los filtros o la búsqueda para cargar facturas</td></tr>';
        exit();
    }
    
    $invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, false, $today_only, $approval_date_filter);
    
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
                    <td colspan="9">
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
            
            $html .= '<td>' . htmlspecialchars($invoice['numero_factura_proveedor']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['docnum_interno_sap']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['codigo_sn']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['nombre']) . '</td>';
            $html .= '<td>' . formatDate1($invoice['fecha_vencimiento']) . '</td>';
            
            $statusInfo = getDaysStatusAndColor($invoice['dias_de_vencido']);
            $html .= '<td style="color: ' . $statusInfo['color'] . '; font-weight: bold;" class="' . $statusInfo['class'] . '">';
            $html .= '<span>';
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            if ($invoice['dias_de_vencido'] < 0) {
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
                $html .= htmlspecialchars($invoice['dias_de_vencido']);
            }
            $html .= '</span>';
            
            if ($statusInfo['mensaje']) {
                $extraClass = ($statusInfo['class'] === 'mora-critica') ? ' alerta-critica' : '';
                $html .= '<span class="mensaje-alerta' . $extraClass . '">' . $statusInfo['mensaje'] . '</span>';
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
        $html .= '<td colspan="9" class="text-center">No se encontraron facturas pendientes con los criterios especificados</td>';
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
    $approval_date_filter = isset($_GET['approval_date']) ? trim($_GET['approval_date']) : '';
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $hasActiveFilter = !empty($date_filter) || !empty($status_filter) || !empty($supplier_filter) || 
                       !empty($invoice_id_filter) || !empty($overdue_days_filter) || !empty($approval_date_filter) || !empty($search_term) || 
                       $selected_only || $today_only;
    
    if (!$hasActiveFilter) {
        echo '<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-search me-2"></i>Use los filtros o la búsqueda para cargar facturas OK</td></tr>';
        exit();
    }
    
    $ok_invoices = getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, true, $today_only, $approval_date_filter);
    
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
        $unique_invoices = [];
        foreach ($ok_invoices as $invoice) {
            $unique_invoices[$invoice['docnum_interno_sap']] = $invoice;
        }
        $ok_invoices = array_values($unique_invoices);
        
        usort($ok_invoices, function($a, $b) {
            $supplierCompare = strcmp($a['nombre'], $b['nombre']);
            if ($supplierCompare === 0) {
                return $a['dias_de_vencido'] <=> $b['dias_de_vencido'];
            }
            return $supplierCompare;
        });
        
        $currentOkSupplier = '';
        $totalGeneralOk = 0;
        $countOk = 0;
        
        foreach ($ok_invoices as $invoice) {
            $currentPriority = isset($invoice['priority']) ? $invoice['priority'] : 'media';
            
            if ($currentOkSupplier !== $invoice['nombre'] && empty($supplier_filter) && !$selected_only) {
                $currentOkSupplier = $invoice['nombre'];
                $supplierTotal = isset($supplier_totals_ok[$currentOkSupplier]) ? $supplier_totals_ok[$currentOkSupplier] : ['total' => 0, 'count' => 0];
                
                $html .= '<tr class="supplier-header">
                    <td colspan="9">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-building me-2"></i>
                                <strong>' . htmlspecialchars($currentOkSupplier) . '</strong>
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
            
            $statusInfo = getDaysStatusAndColor($invoice['dias_de_vencido']);
            $totalGeneralOk += $invoice['saldo_pendiente'];
            $countOk++;
            
            $html .= '<tr data-invoice-id="' . htmlspecialchars($invoice['docnum_interno_sap']) . '" data-invoice-value="' . htmlspecialchars($invoice['saldo_pendiente']) . '" data-invoice-name="' . htmlspecialchars($invoice['nombre']) . '" class="' . (empty($supplier_filter) && !$selected_only ? 'supplier-group ' . $statusInfo['class'] : $statusInfo['class']) . '">';
            
            $html .= '<td>';
            $html .= '<div class="form-check">';
            $html .= '<input type="checkbox" class="form-check-input invoice-checkbox-ok" id="check_ok_' . htmlspecialchars($invoice['docnum_interno_sap']) . '">';
            $html .= '</div>';
            $html .= '</td>';
            
            $html .= '<td>' . htmlspecialchars($invoice['numero_factura_proveedor']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['docnum_interno_sap']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['codigo_sn']) . '</td>';
            $html .= '<td>' . htmlspecialchars($invoice['nombre']) . '</td>';
            $html .= '<td>' . formatDate1($invoice['fecha_vencimiento']) . '</td>';
            
            $html .= '<td style="color: ' . $statusInfo['color'] . '; font-weight: bold;" class="' . $statusInfo['class'] . '">';
            $html .= '<span>';
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            if ($invoice['dias_de_vencido'] < 0) {
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
                $html .= '+' . $invoice['dias_de_vencido'];
            }
            $html .= '</span>';
            
            if ($statusInfo['mensaje']) {
                $extraClass = ($statusInfo['class'] === 'mora-critica') ? ' alerta-critica' : '';
                $html .= '<span class="mensaje-alerta' . $extraClass . '">' . $statusInfo['mensaje'] . '</span>';
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
        
        $html .= '<tr class="table-success fw-bold">
            <td colspan="7" class="text-end">TOTAL GENERAL (' . $countOk . ' facturas):</td>
            <td>$' . number_format($totalGeneralOk, 2, ',', '.') . '</td>
            <td></td>
        </tr>';
    } else {
        $html .= '<tr>';
        $html .= '<td colspan="9" class="text-center">No se encontraron facturas OK con los criterios especificados</td>';
        $html .= '</tr>';
    }
    
    echo $html;
    exit();
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
    <title>Sistema de Aprobación de Facturas - Optimizado</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* ===========================
           VARIABLES OPTIMIZADAS
           =========================== */
        :root {
            --primary: #0d6efd;
            --primary-dark: #0056b3;
            --primary-light: #3d8bfd;
            --success: #28a745;
            --success-dark: #198754;
            --warning: #ffc107;
            --warning-dark: #ff8f00;
            --danger: #dc3545;
            --danger-dark: #b71c1c;
            --info: #17a2b8;
            --info-dark: #138496;
            
            --gray-50: #f8f9fa;
            --gray-100: #e9ecef;
            --gray-200: #dee2e6;
            --gray-300: #ced4da;
            --gray-400: #adb5bd;
            --gray-500: #6c757d;
            --gray-600: #495057;
            --gray-700: #343a40;
            --gray-800: #212529;
            
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 4px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 4px 8px rgba(0, 0, 0, 0.1);
            
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 10px;
            --radius-full: 50%;
            
            --transition: all 0.2s ease;
        }
        
        /* ===========================
           LOADING Y OVERLAYS
           =========================== */
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .spinner-border {
            width: 2.5rem;
            height: 2.5rem;
            border-width: 3px;
        }
        
        /* ===========================
           NAV TABS
           =========================== */
        .nav-tabs {
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 1.5rem;
        }
        
        .nav-tabs .nav-link {
            font-weight: 600;
            padding: 0.75rem 1.25rem;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--gray-700);
            transition: var(--transition);
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: rgba(13, 110, 253, 0.05);
        }
        
        .nav-tabs .nav-link.active {
            font-weight: 700;
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: transparent;
        }
        
        .tab-content {
            padding-top: 0;
        }
        
        /* ===========================
           BADGES Y ETIQUETAS
           =========================== */
        .badge {
            padding: 0.4rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: var(--radius-md);
        }
        
        .badge-ok {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
            padding: 0.5rem 0.875rem;
            font-size: 0.8125rem;
            font-weight: 700;
            border-radius: var(--radius-full);
            margin-left: 0.5rem;
            box-shadow: var(--shadow-md);
            letter-spacing: 0.025em;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .badge-count {
            background: linear-gradient(135deg, var(--gray-700), var(--gray-800));
            color: white;
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 700;
            border-radius: var(--radius-full);
            margin-left: 0.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .badge.bg-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-dark));
            color: var(--gray-800);
        }
        
        .badge.bg-success {
            background: linear-gradient(135deg, var(--success), var(--success-dark));
            color: white;
        }
        
        /* ===========================
           CARDS Y CONTENEDORES
           =========================== */
        .card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            background: white;
        }
        
        .card-header {
            background: var(--primary);
            color: white;
            font-weight: 700;
            padding: 1rem 1.25rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            font-size: 1rem;
        }
        
        .card-header.bg-success {
            background: var(--success);
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        /* ===========================
           FORMULARIOS Y INPUTS
           =========================== */
        .form-control,
        .form-select {
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-check-input {
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-300);
            cursor: pointer;
            width: 1.1rem;
            height: 1.1rem;
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
        }
        
        /* ===========================
           BOTONES
           =========================== */
        .btn {
            border-radius: var(--radius-md);
            font-weight: 600;
            padding: 0.5rem 1rem;
            transition: var(--transition);
            border: 1px solid transparent;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: var(--success-dark);
            border-color: var(--success-dark);
        }
        
        .btn-outline-primary {
            border: 1px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-outline-success {
            border: 1px solid var(--success);
            color: var(--success);
            background: transparent;
        }
        
        .btn-outline-success:hover {
            background: var(--success);
            color: white;
        }
        
        .btn-secondary {
            background: var(--gray-600);
            border-color: var(--gray-600);
            color: white;
        }
        
        .btn-secondary:hover {
            background: var(--gray-700);
            border-color: var(--gray-700);
        }
        
        .btn-outline-secondary {
            border: 1px solid var(--gray-600);
            color: var(--gray-700);
            background: transparent;
        }
        
        .btn-outline-secondary:hover {
            background: var(--gray-600);
            color: white;
        }
        
        .btn-outline-danger {
            border: 1px solid var(--danger);
            color: var(--danger);
            background: transparent;
        }
        
        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }
        
        .btn-info {
            background: var(--info);
            border-color: var(--info);
            color: white;
        }
        
        .btn-info:hover {
            background: var(--info-dark);
            border-color: var(--info-dark);
        }
        
        /* ===========================
           PRIORIDADES
           =========================== */
        .priority-select {
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-300);
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .priority-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }
        
        .priority-options {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .priority-option {
            flex: 1;
            text-align: center;
            padding: 1.5rem 1rem;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            box-shadow: var(--shadow-sm);
        }
        
        .priority-option:hover {
            box-shadow: var(--shadow-md);
        }
        
        .priority-option.selected {
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }
        
        .priority-option-alta {
            background: #ffebee;
            color: #d32f2f;
            border-color: rgba(211, 47, 47, 0.3);
        }
        
        .priority-option-alta:hover {
            background: #ffcdd2;
        }
        
        .priority-option-media {
            background: #fff8e1;
            color: #ff8f00;
            border-color: rgba(255, 143, 0, 0.3);
        }
        
        .priority-option-media:hover {
            background: #ffecb3;
        }
        
        .priority-option-baja {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: rgba(46, 125, 50, 0.3);
        }
        
        .priority-option-baja:hover {
            background: #c8e6c9;
        }
        
        .priority-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }
        
        .priority-option h5 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .priority-option p {
            font-size: 0.85rem;
            font-weight: 500;
            margin: 0;
        }
        
        /* ===========================
           SECCIÓN SELECCIONADAS
           =========================== */
        .selected-invoices-section {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        
        .selected-invoices-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .selected-invoices-title {
            font-size: 1.375rem;
            font-weight: 800;
            color: var(--gray-800);
            margin: 0;
            letter-spacing: 0.025em;
        }
        
        .selected-invoices-actions {
            display: flex;
            gap: 0.875rem;
        }
        
        .selected-invoices-total {
            background: #d4edda;
            border-radius: var(--radius-md);
            padding: 1rem 1.5rem;
            margin-top: 1rem;
            text-align: right;
            font-weight: 700;
            color: var(--success-dark);
            font-size: 1.25rem;
            border: 2px solid var(--success);
        }
        
        .selected-row {
            background: linear-gradient(90deg, rgba(13, 110, 253, 0.08), rgba(13, 110, 253, 0.03)) !important;
            border-left: 5px solid var(--primary) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        
        .no-selected-message {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-600);
            font-style: italic;
            font-size: 1rem;
            font-weight: 500;
        }
        
        /* ===========================
           PROVEEDORES Y GRUPOS
           =========================== */
        .supplier-group {
            background: rgba(13, 110, 253, 0.03);
            border-left: 3px solid var(--primary);
        }
        
        .supplier-group:hover {
            background: rgba(13, 110, 253, 0.06);
        }
        
        .supplier-header {
            background: var(--gray-100) !important;
            font-weight: 700;
            color: var(--gray-800);
            border-top: 3px solid var(--primary);
            border-bottom: 2px solid var(--gray-200);
        }
        
        .supplier-header td {
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-50) 100%) !important;
            border-top: 4px solid var(--primary);
            font-size: 1.0625rem;
            padding: 1.25rem 1.5rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            font-weight: 800;
            position: relative;
            z-index: 1;
        }
        
        .supplier-total-badge {
            background: var(--primary);
            color: white;
            font-weight: 700;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .supplier-count-badge {
            background: var(--success);
            color: white;
            font-weight: 600;
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        /* ===========================
           ESTADOS Y ALERTAS
           =========================== */
        .no-vencida {
            background: rgba(76, 175, 80, 0.1);
            border-left: 3px solid #4caf50;
        }
        
        .proxima-vencer {
            background: rgba(255, 152, 0, 0.1);
            border-left: 3px solid #ff9800;
        }
        
        .vence-hoy {
            background: rgba(255, 87, 34, 0.15);
            border-left: 3px solid #ff5722;
        }
        
        .mora-leve {
            background: rgba(255, 152, 0, 0.12);
            border-left: 3px solid #ff9800;
        }
        
        .mora-grave {
            background: rgba(244, 67, 54, 0.15);
            border-left: 3px solid #f44336;
        }
        
        .mora-critica {
            background: rgba(183, 28, 28, 0.2);
            border-left: 3px solid #b71c1c;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: var(--radius-full);
            margin-right: 0.5rem;
        }
        
        .status-indicator.no-vencida {
            background: #4caf50;
        }
        
        .status-indicator.proxima-vencer {
            background: #ff9800;
        }
        
        .status-indicator.vence-hoy {
            background: #ff5722;
        }
        
        .status-indicator.mora-leve {
            background: #ff9800;
        }
        
        .status-indicator.mora-grave {
            background: #f44336;
        }
        
        .status-indicator.mora-critica {
            background: #b71c1c;
        }
        
        .mensaje-alerta {
            background: rgba(0, 0, 0, 0.05);
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-sm);
            margin-left: 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .mensaje-alerta.alerta-critica {
            background: rgba(183, 28, 28, 0.15);
            border: 1px solid #b71c1c;
            color: #b71c1c;
        }
        
        /* ===========================
           TABLAS RESPONSIVE
           =========================== */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            background: white;
            border: 1px solid var(--gray-200);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin: 0;
        }
        
        .table thead th {
            background: var(--gray-100);
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            padding: 0.75rem 0.5rem;
            border-bottom: 2px solid var(--primary);
            color: var(--gray-800);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        
        .table thead th:first-child {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
            width: 30px;
            min-width: 30px;
            max-width: 30px;
        }
        
        .table thead th:nth-child(2) {
            min-width: 100px;
            max-width: 120px;
        }
        
        .table thead th:nth-child(3) {
            min-width: 80px;
            max-width: 100px;
        }
        
        .table thead th:nth-child(4) {
            min-width: 80px;
            max-width: 100px;
        }
        
        .table thead th:nth-child(5) {
            min-width: 150px;
            max-width: 200px;
        }
        
        .table thead th:nth-child(6) {
            min-width: 120px;
            max-width: 140px;
        }
        
        .table thead th:nth-child(7) {
            min-width: 120px;
            max-width: 150px;
        }
        
        .table thead th:nth-child(8) {
            min-width: 100px;
            max-width: 120px;
        }
        
        .table thead th:last-child {
            min-width: 140px;
            max-width: 160px;
            padding-right: 1rem;
        }
        
        .table tbody tr {
            border-bottom: 1px solid var(--gray-200);
            background: white;
        }
        
        .table tbody tr:nth-child(even) {
            background: var(--gray-50);
        }
        
        .table tbody tr:hover {
            background: rgba(13, 110, 253, 0.05);
        }
        
        .table tbody td {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 500;
            color: var(--gray-800);
            font-size: 0.85rem;
        }
        
        .table tbody td:first-child {
            padding: 0.5rem;
            width: 40px;
            min-width: 40px;
            text-align: center;
        }
        
        .table tbody td:last-child {
            padding: 0.5rem;
            min-width: 120px;
        }
        
        .table tbody td:nth-child(5) {
            white-space: normal;
            word-break: break-word;
            max-width: 200px;
        }
        
        .table tbody td:nth-child(7) {
            white-space: normal;
            word-break: break-word;
            max-width: 150px;
        }
        
        .table tbody td:nth-child(8) {
            white-space: nowrap;
            text-align: right;
            font-weight: 600;
        }
        
        .table tbody td:nth-child(6) {
            white-space: normal;
            max-width: 140px;
            font-size: 0.8rem;
            padding: 0.5rem;
        }
        
        .table tbody td:nth-child(6) > span:first-child {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .table tbody td:nth-child(6) .status-indicator {
            width: 8px;
            height: 8px;
            margin-right: 0.4rem;
            flex-shrink: 0;
        }
        
        .table tbody td:nth-child(6) .mensaje-alerta {
            font-size: 0.65rem;
            padding: 0.25rem 0.5rem;
            margin-top: 0.25rem;
            display: block;
            width: 100%;
        }
        
        .table .btn-sm {
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
            margin: 0 0.25rem;
            min-width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .table .btn-sm:hover {
            opacity: 0.9;
        }
        
        .table .btn-info {
            background: var(--info);
            color: white;
        }
        
        .table .btn-info:hover {
            background: var(--info-dark);
        }
        
        .table .btn-outline-success {
            background: var(--success);
            color: white;
        }
        
        .table .btn-outline-success:hover {
            background: var(--success-dark);
        }
        
        .table .btn-outline-success:disabled {
            background: var(--gray-300);
            color: var(--gray-600);
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .table .btn-group {
            display: flex;
            gap: 0.4rem;
            flex-wrap: nowrap;
            justify-content: center;
            align-items: center;
        }
        
        .table .btn-group .btn {
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
            min-width: 36px;
            height: 36px;
        }
        
        .table .badge {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-md);
            font-weight: 600;
        }
        
        /* ===========================
           ALERTAS
           =========================== */
        .alert {
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-color: var(--success);
            color: var(--success-dark);
            border-left: 3px solid var(--success);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-color: var(--danger);
            color: #b91c1c;
            border-left: 3px solid var(--danger);
        }
        
        .alert-info {
            background: rgba(23, 162, 184, 0.1);
            border-color: var(--info);
            color: var(--info-dark);
            border-left: 3px solid var(--info);
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border-color: var(--warning);
            color: var(--warning-dark);
            border-left: 3px solid var(--warning);
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        /* ===========================
           MODAL
           =========================== */
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            background: var(--success);
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 1.25rem;
        }
        
        .modal-header .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-body h5 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 1rem;
        }
        
        .modal-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        /* ===========================
           UTILIDADES
           =========================== */
        .highlight {
            background: #fff3cd;
            border-radius: var(--radius-sm);
        }
        
        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.125rem;
        }
        
        .form-check-label {
            font-weight: 600;
            letter-spacing: 0.025em;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .form-check-label:hover {
            color: var(--primary);
        }
        
        /* ===========================
           RESPONSIVE
           =========================== */
        @media (max-width: 1400px) {
            .table thead th {
                font-size: 0.7rem;
                padding: 0.6rem 0.4rem;
            }
            
            .table tbody td {
                padding: 0.6rem 0.4rem;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 1200px) {
            .table thead th {
                font-size: 0.65rem;
                padding: 0.5rem 0.3rem;
            }
            
            .table tbody td {
                padding: 0.5rem 0.3rem;
                font-size: 0.75rem;
            }
            
            .table thead th:nth-child(5),
            .table tbody td:nth-child(5) {
                min-width: 100px;
                max-width: 150px;
            }
        }
        
        @media (max-width: 992px) {
            .table thead th,
            .table tbody td {
                font-size: 0.7rem;
                padding: 0.4rem 0.25rem;
            }
            
            .table thead th:nth-child(2),
            .table thead th:nth-child(3),
            .table thead th:nth-child(4) {
                min-width: 60px;
            }
        }
        
        @media (max-width: 768px) {
            .priority-options {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .priority-option {
                width: 100%;
                padding: 1rem;
            }
            
            .selected-invoices-header {
                flex-direction: column;
                gap: 0.75rem;
                align-items: flex-start;
            }
            
            .selected-invoices-actions {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .nav-tabs .nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .table thead th {
                font-size: 0.6rem;
                padding: 0.4rem 0.2rem;
            }
            
            .table tbody td {
                padding: 0.4rem 0.2rem;
                font-size: 0.65rem;
            }
            
            .table .btn-sm {
                padding: 0.25rem 0.4rem;
                font-size: 0.65rem;
                min-width: 32px;
                height: 32px;
            }
            
            .table tbody td:nth-child(6) {
                max-width: 100px;
                font-size: 0.6rem;
            }
        }
        
        @media (max-width: 576px) {
            .table-responsive {
                font-size: 0.7rem;
            }
            
            .table thead th,
            .table tbody td {
                padding: 0.3rem 0.15rem;
                font-size: 0.6rem;
            }
        }
        
        /* Optimización del contenedor principal */
        .table-responsive {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        main {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        .container-fluid {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        .card {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        
        /* ===========================
           MEJORAS DE ACCESIBILIDAD
           =========================== */
        *:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 3px;
            border-radius: var(--radius-md);
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
        }
        
        .btn:focus-visible {
            outline-color: white;
            outline-width: 4px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
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
                
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtros</h5>
                    </div>
                    
                    <div class="card-body">
                
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="todayOnlyFilter">
                                    <label class="form-check-label fw-bold text-success" for="todayOnlyFilter">
                                        <i class="fas fa-calendar-check me-2"></i>
                                        Mostrar solo facturas marcadas como OK hoy (<?php echo date('d/m/Y'); ?>)
                                    </label>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Activa esta opción para ver únicamente las facturas que fueron marcadas como OK el día de hoy
                                </small>
                            </div>
                        </div>
                        
                        <form id="filter-form" class="row g-3">
                            <div class="col-md-2">
                                <label for="invoice_id" class="form-label">Número de Factura</label>
                                <input type="text" class="form-control filter-field" id="invoice_id" name="invoice_id" placeholder="ID exacto">
                            </div>
                            <div class="col-md-2">
                                <label for="date" class="form-label">Fecha de Vencimiento</label>
                                <input type="date" class="form-control filter-field" id="date" name="date">
                            </div>
                            <div class="col-md-2">
                                <label for="approval_date" class="form-label">Fecha de Aprobación</label>
                                <input type="date" class="form-control filter-field" id="approval_date" name="approval_date" placeholder="Fecha aprobación">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Fecha en que se marcó como OK
                                </small>
                            </div>
                            <div class="col-md-3">
                                <label for="supplier" class="form-label">Proveedor</label>
                                <input type="text" class="form-control filter-field" id="supplier" name="supplier" placeholder="Nombre proveedor">
                            </div>
                            <div class="col-md-2">
                                <label for="overdue_days" class="form-label">Días Vencidos</label>
                                <input type="number" class="form-control filter-field" id="overdue_days" name="overdue_days" placeholder="Días exactos">
                            </div>
                            <?php if (in_array($role, ['admin','Preparador'])): ?>
                        <a href="export_excel.php" class="btn btn-success">
                            <i class="fas fa-file-excel me-2"></i> Exportar a Excel
                        </a>
                    <?php endif; ?>
                            <div class="col-md-12 d-flex justify-content-end">
                                <button type="button" id="clear-filters" class="btn btn-secondary">
                                    <i class="fas fa-eraser me-1"></i> Limpiar
                                </button>
                                <button type="button" id="apply-filters" class="btn btn-primary ms-2">
                                    <i class="fas fa-search me-1"></i> Aplicar Filtros
                                </button>
                                
                            </div>
                            
                        </form>
                    </div>
                </div>
                
                <ul class="nav nav-tabs mb-3" id="invoiceTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-invoices" type="button" role="tab" aria-controls="pending-invoices" aria-selected="true">
                            <i class="fas fa-clock me-2"></i>
                            <span id="pending-tab-title">Facturas Pendientes</span>
                            <span class="badge bg-warning text-dark ms-2" id="pending-count">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="ok-tab" data-bs-toggle="tab" data-bs-target="#ok-invoices" type="button" role="tab" aria-controls="ok-invoices" aria-selected="false">
                            <i class="fas fa-check-circle me-2"></i>
                            <span id="ok-tab-title">Facturas Marcadas como OK</span>
                            <span class="badge bg-success ms-2" id="ok-count">0</span>
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="invoiceTabsContent">
                    <div class="tab-pane fade show active" id="pending-invoices" role="tabpanel" aria-labelledby="pending-tab">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>
                                    Facturas Pendientes
                                </h5>
                                <div id="pending-results-count" class="text-white"></div>
                            </div>
                            <div class="card-body position-relative">
                                <div class="loading-overlay" id="pending-loading">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th></th>
                                                <th>ID</th>
                                                <th>Factura</th>
                                                <th>Codigo sap</th>
                                                <th>Numero proveedor</th>
                                                <th>Proveedor</th>
                                                <th>Fecha vencimiento</th>
                                                <th>Dias vencidos</th>
                                                <th>Valor</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pending-table-body">
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-5">
                                                    <i class="fas fa-search fa-3x mb-3 d-block"></i>
                                                    <h5>Use los filtros o la búsqueda para cargar facturas</h5>
                                                    <p>Las facturas se cargarán automáticamente cuando busque o aplique filtros</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-pane fade" id="ok-invoices" role="tabpanel" aria-labelledby="ok-tab">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-check-double me-2"></i>
                                    Facturas Marcadas como OK
                                </h5>
                                <div id="ok-results-count" class="text-white"></div>
                            </div>
                            <div class="card-body position-relative">
                                <div class="loading-overlay" id="ok-loading">
                                    <div class="spinner-border text-success" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th></th>
                                                <th>Número Factura</th>
                                                <th>ID</th>
                                                <th>Código</th>
                                                <th>Proveedor</th>
                                                <th>Fecha Vencimiento</th>
                                                <th>Días vencidos</th>
                                                <th>Valor</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="ok-table-body">
                                            <tr>
                                                <td colspan="9" class="text-center text-muted py-5">
                                                    <i class="fas fa-search fa-3x mb-3 d-block"></i>
                                                    <h5>Use los filtros o la búsqueda para cargar facturas OK</h5>
                                                    <p>Las facturas se cargarán automáticamente cuando busque o aplique filtros</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <div class="modal fade" id="markOkModal" tabindex="-1" aria-labelledby="markOkModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="markOkModalLabel">Marcar Factura como OK</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Información:</strong> Al marcar esta factura como OK, estará disponible para su aprobación por los usuarios correspondientes.
                        </div>
                        
                        <h5 class="mb-3">Seleccione la prioridad para esta factura:</h5>
                        
                        <div class="priority-options">
                            <div class="priority-option priority-option-alta" data-priority="alta">
                                <div class="priority-icon"><i class="fas fa-exclamation-circle"></i></div>
                                <h5>Alta</h5>
                                <p class="mb-0">Requiere atención inmediata</p>
                            </div>
                            
                            <div class="priority-option priority-option-media selected" data-priority="media">
                                <div class="priority-icon"><i class="fas fa-arrow-circle-up"></i></div>
                                <h5>Media</h5>
                                <p class="mb-0">Atención en tiempo normal</p>
                            </div>
                            
                            <div class="priority-option priority-option-baja" data-priority="baja">
                                <div class="priority-icon"><i class="fas fa-arrow-circle-down"></i></div>
                                <h5>Baja</h5>
                                <p class="mb-0">Puede esperar</p>
                            </div>
                        </div>
                        
                        <input type="hidden" name="invoice_id" id="modal-invoice-id" value="">
                        <input type="hidden" name="priority" id="modal-priority" value="media">
                        <input type="hidden" name="mark_as_ok" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        let searchTimer;
        const selectedInvoices = new Map();
        let isShowingSelectedOnly = false;
        let currentSearchTerm = '';
        let todayOnlyFilter = $('#todayOnlyFilter').prop('checked');
        
        function updateTabTitles() {
            if (todayOnlyFilter) {
                $('#pending-tab-title').text('Facturas Pendientes');
                $('#ok-tab-title').text('Facturas Marcadas OK Hoy');
            } else {
                $('#pending-tab-title').text('Facturas Pendientes');
                $('#ok-tab-title').text('Facturas Marcadas como OK');
            }
        }
        
        $('#todayOnlyFilter').on('change', function() {
            todayOnlyFilter = $(this).prop('checked');
            updateTabTitles();
            saveFiltersToLocalStorage();
            loadPendingData();
            loadOkData();
            updateURL();
        });
        
        function loadSelectedInvoicesFromLocalStorage() {
            try {
                const savedInvoices = localStorage.getItem('selectedInvoices');
                if (savedInvoices) {
                    const parsedInvoices = JSON.parse(savedInvoices);
                    
                    Object.keys(parsedInvoices).forEach(id => {
                        selectedInvoices.set(id, parsedInvoices[id]);
                    });
                    
                    updateSelectedInvoicesSection();
                    
                    selectedInvoices.forEach((invoice, id) => {
                        $(`#check_${id}, #check_ok_${id}`).prop('checked', true);
                    });
                    
                    updateRowHighlighting();
                }
            } catch (error) {
                console.error("Error al cargar facturas desde localStorage:", error);
                localStorage.removeItem('selectedInvoices');
            }
        }
        
        function saveSelectedInvoicesToLocalStorage() {
            try {
                const invoicesObject = {};
                selectedInvoices.forEach((invoice, id) => {
                    invoicesObject[id] = invoice;
                });
                
                localStorage.setItem('selectedInvoices', JSON.stringify(invoicesObject));
            } catch (error) {
                console.error("Error al guardar facturas en localStorage:", error);
            }
        }
        
        function saveFiltersToLocalStorage() {
            const filterData = {
                date: $('#date').val(),
                approval_date: $('#approval_date').val(),
                status: $('#status').val(),
                supplier: $('#supplier').val(),
                invoice_id: $('#invoice_id').val(),
                overdue_days: $('#overdue_days').val(),
                today_only: todayOnlyFilter
            };
            localStorage.setItem('invoiceFilters', JSON.stringify(filterData));
        }
        
        function loadFiltersFromLocalStorage() {
            try {
                const savedFilters = localStorage.getItem('invoiceFilters');
                if (savedFilters) {
                    const filters = JSON.parse(savedFilters);
                    
                    $('#date').val(filters.date || '');
                    $('#approval_date').val(filters.approval_date || '');
                    $('#status').val(filters.status || '');
                    $('#supplier').val(filters.supplier || '');
                    $('#invoice_id').val(filters.invoice_id || '');
                    $('#overdue_days').val(filters.overdue_days || '');
                    
                    if (filters.today_only !== undefined) {
                        $('#todayOnlyFilter').prop('checked', filters.today_only);
                        todayOnlyFilter = filters.today_only;
                    }
                    
                    // Verificar si hay filtros activos y aplicarlos automáticamente
                    const hasActiveFilters = (filters.date && filters.date !== '') ||
                                          (filters.approval_date && filters.approval_date !== '') ||
                                          (filters.status && filters.status !== '') ||
                                          (filters.supplier && filters.supplier !== '') ||
                                          (filters.invoice_id && filters.invoice_id !== '') ||
                                          (filters.overdue_days && filters.overdue_days !== '') ||
                                          (filters.today_only === true);
                    
                    return hasActiveFilters;
                }
            } catch (error) {
                console.error("Error al cargar filtros desde localStorage:", error);
                localStorage.removeItem('invoiceFilters');
            }
            return false;
        }
        
        function formatCurrency(value) {
            return new Intl.NumberFormat('es-CO', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value);
        }
        
        function updateSelectedInvoicesSection() {
            const selectedCount = selectedInvoices.size;
            
            $('#selectedCount').text(selectedCount);
            
            if (selectedCount > 0) {
                $('#selectedInvoicesSection').show();
                
                const tableBody = $('#selectedInvoicesTableBody');
                tableBody.empty();
                
                let totalValue = 0;
                
                selectedInvoices.forEach((invoice) => {
                    const row = $('<tr>');
                    row.append(`<td>${invoice.id}</td>`);
                    row.append(`<td>${invoice.name}</td>`);
                    row.append(`<td>$${formatCurrency(invoice.value)}</td>`);
                    row.append(`<td><span class="badge bg-${invoice.status === 'ok' ? 'success' : 'warning'}">${invoice.status === 'ok' ? 'OK' : 'Pendiente'}</span></td>`);
                    
                    tableBody.append(row);
                    totalValue += parseFloat(invoice.value);
                });
                
                $('#selectedTotal').text('$' + formatCurrency(totalValue));
            } else {
                $('#selectedInvoicesTableBody').html('<tr><td colspan="4" class="no-selected-message">No hay facturas seleccionadas</td></tr>');
                $('#selectedTotal').text('$0,00');
            }
            
            updateRowHighlighting();
            saveSelectedInvoicesToLocalStorage();
        }
        
        function addToSelection(invoiceId, invoiceName, invoiceValue, invoiceStatus = 'pending') {
            selectedInvoices.set(invoiceId, {
                id: invoiceId,
                name: invoiceName,
                value: parseFloat(invoiceValue),
                status: invoiceStatus
            });
            
            updateSelectedInvoicesSection();
        }
        
        function removeFromSelection(invoiceId) {
            selectedInvoices.delete(invoiceId);
            $(`#check_${invoiceId}, #check_ok_${invoiceId}`).prop('checked', false);
            updateSelectedInvoicesSection();
        }
        
        function updateRowHighlighting() {
            $('tr').removeClass('selected-row');
            
            selectedInvoices.forEach((invoice, id) => {
                $(`tr[data-invoice-id="${id}"]`).addClass('selected-row');
            });
        }
        
        function restoreSelection() {
            selectedInvoices.forEach((invoice, id) => {
                $(`#check_${id}, #check_ok_${id}`).prop('checked', true);
            });
            updateRowHighlighting();
        }
        
        function initMarkOkButtons() {
            $('.mark-ok-btn').off('click').on('click', function() {
                const invoiceId = $(this).data('invoice-id');
                const priority = $(this).data('priority') || 'media';
                
                $('#modal-invoice-id').val(invoiceId);
                $('#modal-priority').val(priority);
                $('#markOkModalLabel').text('Marcar Factura #' + invoiceId + ' como OK');
                
                $('.priority-option').removeClass('selected');
                $(`.priority-option[data-priority="${priority}"]`).addClass('selected');
                
                $('#markOkModal').modal('show');
            });
            
            $('.priority-option').off('click').on('click', function() {
                const priority = $(this).data('priority');
                $('.priority-option').removeClass('selected');
                $(this).addClass('selected');
                $('#modal-priority').val(priority);
            });
        }
        
        function initPrioritySelects() {
            $('.priority-select').off('change').on('change', function() {
                $(this).closest('form').submit();
            });
        }
        
        function initCheckboxes() {
            $('.invoice-checkbox').off('change').on('change', function() {
                const $row = $(this).closest('tr');
                const invoiceId = $row.data('invoice-id');
                const invoiceName = $row.data('invoice-name');
                const invoiceValue = $row.data('invoice-value');
                
                if ($(this).prop('checked')) {
                    addToSelection(invoiceId, invoiceName, invoiceValue, 'pending');
                } else {
                    removeFromSelection(invoiceId);
                }
            });
            
            $('.invoice-checkbox-ok').off('change').on('change', function() {
                const $row = $(this).closest('tr');
                const invoiceId = $row.data('invoice-id');
                const invoiceName = $row.data('invoice-name');
                const invoiceValue = $row.data('invoice-value');
                
                if ($(this).prop('checked')) {
                    addToSelection(invoiceId, invoiceName, invoiceValue, 'ok');
                } else {
                    removeFromSelection(invoiceId);
                }
            });
        }
        
        function loadPendingData() {
            $('#pending-loading').css('display', 'flex');
            
            let formData = $('#filter-form').serialize() + '&ajax=1';
            
            if (todayOnlyFilter) {
                formData += '&today_only=true';
            }
            
            if (currentSearchTerm) {
                formData += '&search=' + encodeURIComponent(currentSearchTerm);
            }
            
            if (isShowingSelectedOnly) {
                const selectedIds = Array.from(selectedInvoices.keys()).join(',');
                formData += '&selected_only=true&selected_ids=' + encodeURIComponent(selectedIds);
            }
            
            saveFiltersToLocalStorage();
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: formData,
                success: function(response) {
                    $('#pending-table-body').html(response);
                    
                    const rowCount = $('#pending-table-body tr:not(.supplier-header)').length;
                    const resultCount = rowCount === 1 && $('#pending-table-body tr td').attr('colspan') === '9' ? 0 : rowCount;
                    
                    $('#pending-results-count').text(`${resultCount} resultado(s)`);
                    $('#pending-count').text(resultCount);
                    
                    $('#pending-loading').css('display', 'none');
                    
                    initMarkOkButtons();
                    initPrioritySelects();
                    initCheckboxes();
                    restoreSelection();
                },
                error: function(xhr, status, error) {
                    $('#pending-loading').css('display', 'none');
                    console.error("Error en la solicitud AJAX:", status, error);
                    alert('Error al cargar los datos. Por favor, inténtelo de nuevo.');
                }
            });
        }
        
        function loadOkData() {
            $('#ok-loading').css('display', 'flex');
            
            let formData = $('#filter-form').serialize() + '&ajax=2';
            
            if (todayOnlyFilter) {
                formData += '&today_only=true';
            }
            
            if (currentSearchTerm) {
                formData += '&search=' + encodeURIComponent(currentSearchTerm);
            }
            
            if (isShowingSelectedOnly) {
                const selectedIds = Array.from(selectedInvoices.keys()).join(',');
                formData += '&selected_only=true&selected_ids=' + encodeURIComponent(selectedIds);
            }
            
            saveFiltersToLocalStorage();
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: formData,
                success: function(response) {
                    $('#ok-table-body').html(response);
                    
                    const rowCount = $('#ok-table-body tr:not(.supplier-header)').length;
                    const resultCount = rowCount === 1 && $('#ok-table-body tr td').attr('colspan') === '9' ? 0 : rowCount;
                    
                    $('#ok-results-count').text(`${resultCount} resultado(s)`);
                    $('#ok-count').text(resultCount);
                    
                    $('#ok-loading').css('display', 'none');
                    
                    initCheckboxes();
                    restoreSelection();
                },
                error: function(xhr, status, error) {
                    $('#ok-loading').css('display', 'none');
                    console.error("Error en la solicitud AJAX:", status, error);
                    alert('Error al cargar los datos. Por favor, inténtelo de nuevo.');
                }
            });
        }
        
        $('#realTimeSearch').on('input', function() {
            clearTimeout(searchTimer);
            currentSearchTerm = $(this).val();
            searchTimer = setTimeout(function() {
                loadPendingData();
                loadOkData();
            }, 300);
        });
        
        $('#realTimeSearch').on('keydown', function(e) {
            if (e.key === 'Escape') {
                $(this).val('');
                currentSearchTerm = '';
                loadPendingData();
                loadOkData();
                $(this).blur();
            }
        });
        
        $('#clear-filters').on('click', function() {
            $('#filter-form')[0].reset();
            $('#realTimeSearch').val('');
            $('#todayOnlyFilter').prop('checked', false);
            currentSearchTerm = '';
            todayOnlyFilter = false;
            isShowingSelectedOnly = false;
            $('#showSelectedBtn').show();
            $('#showAllBtn').hide();
            localStorage.removeItem('invoiceFilters');
            updateTabTitles();
            
            $('#pending-table-body').html('<tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-search fa-3x mb-3 d-block"></i><h5>Use los filtros o la búsqueda para cargar facturas</h5><p>Las facturas se cargarán automáticamente cuando busque o aplique filtros</p></td></tr>');
            $('#ok-table-body').html('<tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-search fa-3x mb-3 d-block"></i><h5>Use los filtros o la búsqueda para cargar facturas OK</h5><p>Las facturas se cargarán automáticamente cuando busque o aplique filtros</p></td></tr>');
            $('#pending-count').text('0');
            $('#ok-count').text('0');
            $('#pending-results-count').text('');
            $('#ok-results-count').text('');
            
            history.pushState({}, '', window.location.pathname);
        });
        
        $('#showSelectedBtn').on('click', function() {
            isShowingSelectedOnly = true;
            $(this).hide();
            $('#showAllBtn').show();
            loadPendingData();
            loadOkData();
        });
        
        $('#showAllBtn').on('click', function() {
            isShowingSelectedOnly = false;
            $(this).hide();
            $('#showSelectedBtn').show();
            loadPendingData();
            loadOkData();
        });
        
        $('#clearSelectionBtn').on('click', function() {
            $('.invoice-checkbox, .invoice-checkbox-ok').prop('checked', false);
            selectedInvoices.clear();
            updateSelectedInvoicesSection();
            localStorage.removeItem('selectedInvoices');
            
            if (isShowingSelectedOnly) {
                $('#showAllBtn').click();
            }
        });
        
        $('#apply-filters').on('click', function() {
            saveFiltersToLocalStorage();
            loadPendingData();
            loadOkData();
            updateURL();
        });
        
        $('.filter-field').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                saveFiltersToLocalStorage();
                loadPendingData();
                loadOkData();
            }
        });
        
        // Guardar filtros cuando cambian los campos
        $('.filter-field').on('change blur', function() {
            saveFiltersToLocalStorage();
        });
        
        function updateURL() {
            let formData = $('#filter-form').serialize();
            
            if (todayOnlyFilter) {
                formData += (formData ? '&' : '') + 'today_only=true';
            }
            
            if (formData) {
                const newURL = window.location.pathname + '?' + formData;
                history.pushState({}, '', newURL);
            } else {
                history.pushState({}, '', window.location.pathname);
            }
        }
        
        $('#apply-filters').on('click', function() {
            updateURL();
        });
        
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            const target = $(e.target).attr("data-bs-target");
            if (target === "#pending-invoices") {
                // No cargar automáticamente
            } else if (target === "#ok-invoices") {
                // No cargar automáticamente
            }
        });
        
        // Función para cargar filtros desde la URL si existen
        function loadFiltersFromURL() {
            const urlParams = new URLSearchParams(window.location.search);
            let hasURLFilters = false;
            
            if (urlParams.has('date')) {
                $('#date').val(urlParams.get('date'));
                hasURLFilters = true;
            }
            if (urlParams.has('approval_date')) {
                $('#approval_date').val(urlParams.get('approval_date'));
                hasURLFilters = true;
            }
            if (urlParams.has('status')) {
                $('#status').val(urlParams.get('status'));
                hasURLFilters = true;
            }
            if (urlParams.has('supplier')) {
                $('#supplier').val(urlParams.get('supplier'));
                hasURLFilters = true;
            }
            if (urlParams.has('invoice_id')) {
                $('#invoice_id').val(urlParams.get('invoice_id'));
                hasURLFilters = true;
            }
            if (urlParams.has('overdue_days')) {
                $('#overdue_days').val(urlParams.get('overdue_days'));
                hasURLFilters = true;
            }
            if (urlParams.has('today_only') && urlParams.get('today_only') === 'true') {
                $('#todayOnlyFilter').prop('checked', true);
                todayOnlyFilter = true;
                hasURLFilters = true;
            }
            
            if (hasURLFilters) {
                saveFiltersToLocalStorage();
            }
            
            return hasURLFilters;
        }
        
        // Cargar filtros desde URL primero, luego desde localStorage
        const hasURLFilters = loadFiltersFromURL();
        const hasLocalStorageFilters = loadFiltersFromLocalStorage();
        const hasActiveFilters = hasURLFilters || hasLocalStorageFilters;
        
        updateTabTitles();
        initMarkOkButtons();
        initPrioritySelects();
        initCheckboxes();
        loadSelectedInvoicesFromLocalStorage();
        updateSelectedInvoicesSection();
        
        // Si hay filtros activos, aplicarlos automáticamente al cargar la página
        if (hasActiveFilters) {
            // Pequeño delay para asegurar que el DOM esté completamente cargado
            setTimeout(function() {
                loadPendingData();
                loadOkData();
            }, 100);
        }
        
        // Guardar filtros antes de salir de la página
        $(window).on('beforeunload', function() {
            saveSelectedInvoicesToLocalStorage();
            saveFiltersToLocalStorage();
        });
        
        // Guardar filtros también cuando se hace clic en un enlace de ver factura
        $(document).on('click', 'a[href*="view_invoice.php"]', function() {
            saveFiltersToLocalStorage();
        });
    });
    </script>
</body>
</html>