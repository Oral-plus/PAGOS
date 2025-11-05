<?php
// User functions
function getUserById($id) {
    $conn = getDbConnection();
    $sql = "SELECT * FROM users WHERE id = ?";
    $params = array($id);
    
    // Si usamos sqlsrv nativo
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $result ?: false;
    } 
    // Si usamos PDO
    else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function getUserByEmail($email) {
    $conn = getDbConnection();
    $sql = "SELECT * FROM users WHERE email = ?";
    $params = array($email);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $result ?: false;
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function emailExists($email) {
    $conn = getDbConnection();
    $sql = "SELECT COUNT(*) AS count FROM users WHERE email = ?";
    $params = array($email);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return ($row['count'] > 0);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}

function createUser($name, $email, $password, $role) {
    $conn = getDbConnection();
    $sql = "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)";
    $params = array($name, $email, $password, $role);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error al insertar: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt);
        return true;
    } else {
        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    }
}

// Invoice functions
// Invoice functions
function getFilteredInvoices($date_filter = '', $status_filter = '', $supplier_filter = '', $invoice_id_filter = '', $overdue_days_filter = '', $ok_filter = false) {
    $conn = getDbConnection();
    
    $where = [];
    $params = [];
    
    // Filtro base para facturas pendientes (solo si no estamos buscando las OK)
    if (!$ok_filter) {
        $where[] = "ESTADOSAP = 'O'";
    }
    
    if ($ok_filter) {
        $where[] = "ok = 'ok'";
    } else {
        $where[] = "(ok IS NULL OR ok != 'ok')";
    }
    
    if (!empty($date_filter)) {
        $where[] = "CONVERT(date, fecha_vencimiento) = ?";
        $params[] = $date_filter;
    }
    
    if (!empty($status_filter)) {
        $where[] = "status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($supplier_filter)) {
        $where[] = "nombre LIKE ?";
        $params[] = '%' . $supplier_filter . '%';
    }
    
    if (!empty($invoice_id_filter)) {
        $where[] = "docnum_interno_sap = ?";
        $params[] = $invoice_id_filter;
    }
    
    if (!empty($overdue_days_filter)) {
        $where[] = "dias_de_vencido = ?";
        $params[] = $overdue_days_filter;
    }
    
    $sql = "SELECT * FROM invoices";
    
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $invoices = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $invoices[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $invoices;
    }
}

function getInvoiceById($id) {
    $conn = getDbConnection();
    $sql = "SELECT * FROM invoices WHERE docnum_interno_sap = ?";
    $params = array($id);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $result ?: false;
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function getInvoiceItems($invoice_id) {
    $conn = getDbConnection();
    $sql = "SELECT * FROM invoice_items WHERE docnum_interno_sap = ?";
    $params = array($invoice_id);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $results = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $results;
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getInvoiceApprovals($invoice_id) {
    $conn = getDbConnection();
    $sql = "
        SELECT a.*, u.name as user_name, u.role as user_role
        FROM invoice_approvals a
        JOIN users u ON a.user_id = u.id
        WHERE a.invoice_id = ?
        ORDER BY a.created_at ASC
    ";
    $params = array($invoice_id);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $results = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $results;
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function markInvoiceAsViewed($invoice_id, $user_id) {
    $conn = getDbConnection();
    
    // Check if already viewed
    $sql_check = "SELECT COUNT(*) AS count FROM invoice_views WHERE invoice_id = ? AND user_id = ?";
    $params = array($invoice_id, $user_id);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql_check, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($row['count'] == 0) {
            // Insert new view record
            $sql_insert = "INSERT INTO invoice_views (invoice_id, user_id) VALUES (?, ?)";
            $stmt = sqlsrv_query($conn, $sql_insert, $params);
            if ($stmt === false) {
                throw new Exception("Error al insertar: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);
        } else {
            // Update view timestamp
            $sql_update = "UPDATE invoice_views SET viewed_at = GETDATE() WHERE invoice_id = ? AND user_id = ?";
            $stmt = sqlsrv_query($conn, $sql_update, $params);
            if ($stmt === false) {
                throw new Exception("Error al actualizar: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);
        }
    } else {
        $stmt = $conn->prepare($sql_check);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
        
        if ($count == 0) {
            // Insert new view record
            $sql_insert = "INSERT INTO invoice_views (invoice_id, user_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql_insert);
            $stmt->execute($params);
        } else {
            // Update view timestamp
            $sql_update = "UPDATE invoice_views SET viewed_at = GETDATE() WHERE invoice_id = ? AND user_id = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute($params);
        }
    }
}

function hasViewedInvoice($invoice_id, $user_id) {
    $conn = getDbConnection();
    $sql = "SELECT COUNT(*) AS count FROM invoice_views WHERE invoice_id = ? AND user_id = ?";
    $params = array($invoice_id, $user_id);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row['count'] > 0;
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }
}

function addInvoice($invoice_number, $date, $supplier_name, $nit, $amount, $description, $file_path, $sap_code, $purchase_order, $cost_center, $payment_method, $bank_account, $due_date, $payment_terms, $user_id) {
    $conn = getDbConnection();
    
    // Calculate subtotal and tax
    $subtotal = $amount / 1.19; // Assuming 19% tax
    $tax = $amount - $subtotal;
    
    $sql = "
        INSERT INTO invoices (
            invoice_number, date, supplier_name, nit, amount, subtotal, tax,
            description, file_path, sap_code, purchase_order, cost_center,
            payment_method, bank_account, due_date, payment_terms, created_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
    ";
    
    $params = array(
        $invoice_number, $date, $supplier_name, $nit, $amount, $subtotal, $tax,
        $description, $file_path, $sap_code, $purchase_order, $cost_center,
        $payment_method, $bank_account, $due_date, $payment_terms, $user_id
    );
    
    if (!($conn instanceof PDO)) {
        // Usar SCOPE_IDENTITY() para SQL Server
        sqlsrv_query($conn, "SET NOCOUNT ON"); // Para asegurar que SCOPE_IDENTITY() funcione correctamente
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error al insertar: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt);
        
        // Obtener el último ID insertado
        $stmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
        if ($stmt === false) {
            throw new Exception("Error al obtener ID: " . print_r(sqlsrv_errors(), true));
        }
        
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $last_id = $row['id'];
            sqlsrv_free_stmt($stmt);
            return $last_id;
        }
        
        return false;
    } else {
        try {
            $stmt = $conn->prepare($sql);
            if ($stmt->execute($params)) {
                return $conn->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            throw new Exception("Error PDO: " . $e->getMessage());
        }
    }
}

function addInvoiceItem($invoice_id, $concept, $description, $quantity, $unit_price, $total) {
    $conn = getDbConnection();
    
    $sql = "
        INSERT INTO invoice_items (invoice_id, concept, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ";
    
    $params = array($invoice_id, $concept, $description, $quantity, $unit_price, $total);
    
    if (!($conn instanceof PDO)) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error al insertar: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt);
        return true;
    } else {
        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    }
}

function approveInvoice($invoice_id, $user_id, $role, $comments = '') {
    $conn = getDbConnection();

    try {
        if (!($conn instanceof PDO)) {
            // Iniciar transacción con sqlsrv
            if (sqlsrv_begin_transaction($conn) === false) {
                throw new Exception("Error al iniciar transacción: " . print_r(sqlsrv_errors(), true));
            }

            // Registrar la aprobación del rol actual
            $sql_insert = "
                INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments)
                VALUES (?, ?, ?, 'approve', ?)
            ";
            $params_insert = array($invoice_id, $user_id, $role, $comments);
            
            $stmt = sqlsrv_query($conn, $sql_insert, $params_insert);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                throw new Exception("Error al insertar aprobación: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);

            // Verificar si ya existe una aprobación de cada uno de los 3 roles
            $sql_check = "
                SELECT DISTINCT user_role FROM invoice_approvals
                WHERE invoice_id = ? AND action = 'approve'
            ";
            $params_check = array($invoice_id);
            
            $stmt = sqlsrv_query($conn, $sql_check, $params_check);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                throw new Exception("Error al verificar aprobaciones: " . print_r(sqlsrv_errors(), true));
            }
            
            $roles_approved = array();
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $roles_approved[] = $row['user_role'];
            }
            sqlsrv_free_stmt($stmt);

            // Si los tres roles han aprobado, marcar como completado
            $required_roles = array('subgerente', 'gerente', 'contador');
            $roles_intersection = array_intersect($required_roles, $roles_approved);
            
            if (count($roles_intersection) === 3) {
                $sql_update = "UPDATE invoices SET status = 'completado' WHERE id = ?";
            } else {
                // Si no está completado aún, poner el estado como 'en proceso'
                $sql_update = "UPDATE invoices SET status = 'en_proceso' WHERE id = ?";
            }
            
            $params_update = array($invoice_id);
            $stmt = sqlsrv_query($conn, $sql_update, $params_update);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                throw new Exception("Error al actualizar estado: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);

            // Commit transaction
            if (sqlsrv_commit($conn) === false) {
                throw new Exception("Error al confirmar transacción: " . print_r(sqlsrv_errors(), true));
            }
            
            return true;
        } 
        // Usar PDO
        else {
            $conn->beginTransaction();

            // Registrar la aprobación del rol actual
            $sql_insert = "
                INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments)
                VALUES (?, ?, ?, 'approve', ?)
            ";
            $params_insert = array($invoice_id, $user_id, $role, $comments);
            
            $stmt = $conn->prepare($sql_insert);
            $stmt->execute($params_insert);

            // Verificar si ya existe una aprobación de cada uno de los 3 roles
            $sql_check = "
                SELECT DISTINCT user_role FROM invoice_approvals
                WHERE invoice_id = ? AND action = 'approve'
            ";
            $stmt = $conn->prepare($sql_check);
            $stmt->execute(array($invoice_id));
            $roles_approved = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Si los tres roles han aprobado, marcar como completado
            $required_roles = array('subgerente', 'gerente', 'contador');
            if (count(array_intersect($required_roles, $roles_approved)) === 3) {
                $sql_update = "UPDATE invoices SET status = 'completado' WHERE id = ?";
            } else {
                // Si no está completado aún, poner el estado como 'en proceso'
                $sql_update = "UPDATE invoices SET status = 'en_proceso' WHERE id = ?";
            }
            
            $stmt = $conn->prepare($sql_update);
            $stmt->execute(array($invoice_id));

            $conn->commit();
            return true;
        }
    } catch (Exception $e) {
        // Rollback en caso de PDO
        if ($conn instanceof PDO && $conn->inTransaction()) {
            $conn->rollBack();
        }
        // En caso de sqlsrv, el rollback ya se ha manejado en los bloques internos
        
        error_log("Error al aprobar factura: " . $e->getMessage());
        return false;
    }
}

function rejectInvoice($invoice_id, $user_id, $role, $comments = '') {
    $conn = getDbConnection();
    
    try {
        if (!($conn instanceof PDO)) {
            // Iniciar transacción con sqlsrv
            if (sqlsrv_begin_transaction($conn) === false) {
                throw new Exception("Error al iniciar transacción: " . print_r(sqlsrv_errors(), true));
            }
            
            // Update invoice status to rejected
            $status = 'rechazado';
            $sql_update = "UPDATE invoices SET status = ? WHERE id = ?";
            $params_update = array($status, $invoice_id);
            
            $stmt = sqlsrv_query($conn, $sql_update, $params_update);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                throw new Exception("Error al actualizar estado: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);
            
            // Record rejection action
            $action = 'reject';
            $sql_insert = "
                INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments)
                VALUES (?, ?, ?, ?, ?)
            ";
            $params_insert = array($invoice_id, $user_id, $role, $action, $comments);
            
            $stmt = sqlsrv_query($conn, $sql_insert, $params_insert);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                throw new Exception("Error al insertar rechazo: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);
            
            // Commit transaction
            if (sqlsrv_commit($conn) === false) {
                throw new Exception("Error al confirmar transacción: " . print_r(sqlsrv_errors(), true));
            }
            
            return true;
        } 
        // Usar PDO
        else {
            $conn->beginTransaction();
            
            // Update invoice status to rejected
            $status = 'rechazado';
            $sql_update = "UPDATE invoices SET status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute(array($status, $invoice_id));
            
            // Record rejection action
            $action = 'reject';
            $sql_insert = "
                INSERT INTO invoice_approvals (invoice_id, user_id, user_role, action, comments)
                VALUES (?, ?, ?, ?, ?)
            ";
            $stmt = $conn->prepare($sql_insert);
            $stmt->execute(array($invoice_id, $user_id, $role, $action, $comments));
            
            $conn->commit();
            return true;
        }
    } catch (Exception $e) {
        // Rollback en caso de PDO
        if ($conn instanceof PDO && $conn->inTransaction()) {
            $conn->rollBack();
        }
        // En caso de sqlsrv, el rollback ya se ha manejado en los bloques internos
        
        error_log("Error al rechazar factura: " . $e->getMessage());
        return false;
    }
}

// Modificar la función canApproveInvoice para dar permisos completos a todos los roles
function canApproveInvoice($role, $status) {
    // Si la factura ya está completada o rechazada, nadie puede aprobarla
    if ($status == 'completado' || $status == 'rechazado') {
        return false;
    }
    
    // Todos los roles pueden aprobar en cualquier estado válido
    switch ($status) {
        case 'pendiente':
        case 'aprobado_subgerente':
        case 'aprobado_gerente':
            return true;
        default:
            return false;
    }
}

function canRejectInvoice($role, $status) {
    // Cualquier rol puede rechazar si no está completado o rechazado
    return $status != 'completado' && $status != 'rechazado';
}

// Helper functions
function formatDate($date) {
    if (empty($date)) return 'N/A';
    
    if ($date instanceof DateTime) {
        return $date->format('d/m/Y');
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if ($dateObj) {
        return $dateObj->format('d/m/Y');
    }
    
    return $date;
}

function formatDateTime($datetime) {
    if (empty($datetime)) return 'N/A';
    
    if ($datetime instanceof DateTime) {
        return $datetime->format('d/m/Y H:i:s');
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if ($dateObj) {
        return $dateObj->format('d/m/Y H:i:s');
    }
    
    return $datetime;
}
function getStatusLabel($status) {
    switch ($status) {
        case 'pendiente':
            return 'Pendiente';
        case 'aprobado_subgerente':
            return 'Aprobado por Subgerente';
        case 'aprobado_gerente':
            return 'Aprobado por Gerente';
        case 'aprobado_contador':
            return 'Aprobado por Contador';
        case 'completado':
            return 'Completado';
        case 'rechazado':
            return 'Rechazado';
        default:
            return 'En proceso';
    }
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pendiente':
            return 'bg-warning';
        case 'aprobado_subgerente':
            return 'bg-info';
        case 'aprobado_gerente':
            return 'bg-primary';
        case 'aprobado_contador':
            return 'bg-info';
        case 'completado':
            return 'bg-success';
        case 'rechazado':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

// Definir getDbConnection() solo si no existe ya
if (!function_exists('getDbConnection')) {
    function getDbConnection() {
        $connectionInfo = array(
            "Database" => DB_NAME,
            "UID" => DB_USER,
            "PWD" => DB_PASS
        );
        
        // Intentar usar sqlsrv si está disponible
        if (function_exists('sqlsrv_connect')) {
            $conn = sqlsrv_connect(DB_HOST, $connectionInfo);
            
            if ($conn === false) {
                throw new Exception("Error de conexión: " . print_r(sqlsrv_errors(), true));
            }
            
            return $conn;
        } else {
            // Si sqlsrv no está disponible, usar PDO
            try {
                $conn = new PDO("sqlsrv:Server=" . DB_HOST . ";Database=" . DB_NAME, DB_USER, DB_PASS);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $conn;
            } catch (PDOException $e) {
                throw new Exception("Error de conexión PDO: " . $e->getMessage());
            }
        }
    }
}