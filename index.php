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
function getFilteredInvoices1($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, $is_ok = false, $today_only = false) {
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
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $hasActiveFilter = !empty($date_filter) || !empty($status_filter) || !empty($supplier_filter) || 
                       !empty($invoice_id_filter) || !empty($overdue_days_filter) || !empty($search_term) || 
                       $selected_only || $today_only;
    
    if (!$hasActiveFilter) {
        echo '<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-search me-2"></i>Use los filtros o la búsqueda para cargar facturas</td></tr>';
        exit();
    }
    
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
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            if ($invoice['dias_de_vencido'] < 0) {
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
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
    $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
    $selected_only = isset($_GET['selected_only']) ? $_GET['selected_only'] === 'true' : false;
    $selected_ids = isset($_GET['selected_ids']) ? explode(',', $_GET['selected_ids']) : [];
    $today_only = isset($_GET['today_only']) ? $_GET['today_only'] === 'true' : false;
    
    $hasActiveFilter = !empty($date_filter) || !empty($status_filter) || !empty($supplier_filter) || 
                       !empty($invoice_id_filter) || !empty($overdue_days_filter) || !empty($search_term) || 
                       $selected_only || $today_only;
    
    if (!$hasActiveFilter) {
        echo '<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-search me-2"></i>Use los filtros o la búsqueda para cargar facturas OK</td></tr>';
        exit();
    }
    
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
            $html .= '<span class="status-indicator ' . $statusInfo['class'] . '"></span>';
            
            if ($invoice['dias_de_vencido'] < 0) {
                $html .= '-' . abs($invoice['dias_de_vencido']);
            } else {
                $html .= '+' . $invoice['dias_de_vencido'];
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
        
        .today-ok-counter .card {
            min-width: 120px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        
        .today-ok-counter .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .optimization-notice {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
        }
        
        .optimization-notice h5 {
            color: #ff8f00;
            margin-bottom: 10px;
        }
        
        .optimization-notice p {
            margin-bottom: 0;
            color: #f57c00;
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
                        <i class="fas fa-tachometer-alt me-2"></i>
                        Panel de Control - Optimizado
                    </h1>
                    <?php if (in_array($role, ['admin','Preparador'])): ?>
                        <a href="export_excel.php" class="btn btn-success">
                            <i class="fas fa-file-excel me-2"></i> Exportar a Excel
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="optimization-notice">
                    <h5><i class="fas fa-rocket me-2"></i>Sistema Optimizado para Carga Rápida</h5>
                    <p>
                        <i class="fas fa-info-circle me-2"></i>
                        Las facturas se cargan solo cuando usas los filtros o la búsqueda. Esto hace que la página cargue mucho más rápido.
                        <strong>Usa la búsqueda o los filtros para ver las facturas.</strong>
                    </p>
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
                                <label for="date" class="form-label">Fecha</label>
                                <input type="date" class="form-control filter-field" id="date" name="date">
                            </div>
                            <div class="col-md-3">
                                <label for="supplier" class="form-label">Proveedor</label>
                                <input type="text" class="form-control filter-field" id="supplier" name="supplier" placeholder="Nombre proveedor">
                            </div>
                            <div class="col-md-2">
                                <label for="overdue_days" class="form-label">Días Vencidos</label>
                                <input type="number" class="form-control filter-field" id="overdue_days" name="overdue_days" placeholder="Días exactos">
                            </div>
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
                    $('#status').val(filters.status || '');
                    $('#supplier').val(filters.supplier || '');
                    $('#invoice_id').val(filters.invoice_id || '');
                    $('#overdue_days').val(filters.overdue_days || '');
                    
                    if (filters.today_only !== undefined) {
                        $('#todayOnlyFilter').prop('checked', filters.today_only);
                        todayOnlyFilter = filters.today_only;
                    }
                }
            } catch (error) {
                console.error("Error al cargar filtros desde localStorage:", error);
                localStorage.removeItem('invoiceFilters');
            }
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
            loadPendingData();
            loadOkData();
        });
        
        $('.filter-field').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                loadPendingData();
                loadOkData();
            }
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
        
        loadFiltersFromLocalStorage();
        updateTabTitles();
        initMarkOkButtons();
        initPrioritySelects();
        initCheckboxes();
        loadSelectedInvoicesFromLocalStorage();
        updateSelectedInvoicesSection();
        
        $(window).on('beforeunload', function() {
            saveSelectedInvoicesToLocalStorage();
            saveFiltersToLocalStorage();
        });
    });
    </script>
</body>
</html>