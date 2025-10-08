<?php
/**
 * Sistema de Gestión de Facturas - Página Principal
 * 
 * Este módulo gestiona la visualización y aprobación de facturas pendientes.
 * Implementa controles de seguridad y validación de permisos de usuario.
 * 
 * @author Sistema de Pagos
 * @version 2.0
 */

// Inicialización de sesión y configuración
session_start();

// Configuración de seguridad
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

require_once 'config/database.php';
require_once 'includes/functions.php';

// ============================================================================
// AUTENTICACIÓN Y AUTORIZACIÓN
// ============================================================================

/**
 * Verifica que el usuario esté autenticado
 */
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header("Location: login.php");
    exit();
}

// Obtener información del usuario autenticado
$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'] ?? 'user';

// Generar token CSRF si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================================
// PROCESAMIENTO DE FORMULARIOS
// ============================================================================

/**
 * Procesa la solicitud de marcado de factura como OK
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_ok'])) {
    
    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Error de seguridad: Token CSRF inválido";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    $invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_SANITIZE_STRING);
    
    if (empty($invoice_id)) {
        $_SESSION['error_message'] = "ID de factura inválido";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    
    try {
        // Verificar que el usuario haya visualizado los detalles de la factura
        if (!hasUserViewedInvoice($invoice_id, $user_id)) {
            throw new Exception("Debe revisar los detalles de la factura antes de aprobarla");
        }
        
        // Marcar factura como aprobada
        $result = markInvoiceAsOk($invoice_id, $user_id);
        
        if ($result) {
            logAuditAction($user_id, 'INVOICE_APPROVED', $invoice_id);
            $_SESSION['success_message'] = "Factura #$invoice_id aprobada correctamente";
        } else {
            throw new Exception("No se pudo aprobar la factura #$invoice_id");
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        logAuditAction($user_id, 'INVOICE_APPROVAL_FAILED', $invoice_id, $e->getMessage());
    }
    
    // Redireccionar para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ============================================================================
// FUNCIONES DE NEGOCIO
// ============================================================================

/**
 * Marca una factura como aprobada (OK)
 * 
 * @param string $invoice_id ID de la factura
 * @param int $user_id ID del usuario que aprueba
 * @return bool True si se actualizó correctamente
 * @throws Exception Si hay error en la base de datos
 */
function markInvoiceAsOk($invoice_id, $user_id) {
    $conn = getDbConnection();
    
    $sql = "UPDATE invoices 
            SET ok = 'ok', 
                approved_by = ?, 
                approved_at = GETDATE() 
            WHERE docnum_interno_sap = ?";
    
    $params = array($user_id, $invoice_id);
    
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return ($stmt->rowCount() > 0);
        } else {
            // SQL Server nativo
            $stmt = sqlsrv_query($conn, $sql, $params);
            
            if ($stmt === false) {
                $errors = sqlsrv_errors();
                throw new Exception("Error en base de datos: " . print_r($errors, true));
            }
            
            $affected = sqlsrv_rows_affected($stmt);
            sqlsrv_free_stmt($stmt);
            
            return ($affected > 0);
        }
    } catch (Exception $e) {
        error_log("Error al aprobar factura $invoice_id: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Registra acciones de auditoría en el sistema
 * 
 * @param int $user_id ID del usuario
 * @param string $action Acción realizada
 * @param string $invoice_id ID de factura (opcional)
 * @param string $details Detalles adicionales (opcional)
 */
function logAuditAction($user_id, $action, $invoice_id = null, $details = null) {
    $conn = getDbConnection();
    
    $sql = "INSERT INTO audit_log (user_id, action, invoice_id, details, created_at) 
            VALUES (?, ?, ?, ?, GETDATE())";
    
    $params = array($user_id, $action, $invoice_id, $details);
    
    try {
        if ($conn instanceof PDO) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
        } else {
            sqlsrv_query($conn, $sql, $params);
        }
    } catch (Exception $e) {
        error_log("Error al registrar auditoría: " . $e->getMessage());
    }
}

// ============================================================================
// OBTENCIÓN DE DATOS
// ============================================================================

// Filtros de búsqueda con sanitización
$date_filter = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING) ?? '';
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?? '';
$supplier_filter = filter_input(INPUT_GET, 'supplier', FILTER_SANITIZE_STRING) ?? '';
$invoice_id_filter = filter_input(INPUT_GET, 'invoice_id', FILTER_SANITIZE_STRING) ?? '';
$overdue_days_filter = filter_input(INPUT_GET, 'overdue_days', FILTER_VALIDATE_INT) ?? '';

// Obtener facturas según estado
$pending_invoices = getFilteredInvoices(
    $date_filter, 
    $status_filter, 
    $supplier_filter, 
    $invoice_id_filter, 
    $overdue_days_filter, 
    false
);

$ok_invoices = getFilteredInvoices(
    $date_filter, 
    $status_filter, 
    $supplier_filter, 
    $invoice_id_filter, 
    $overdue_days_filter, 
    true
);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    
    <title>Sistema de Gestión de Pagos - Panel Principal</title>
    
     Bootstrap CSS 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    
     Font Awesome 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* ================================================================
           VARIABLES CSS - SISTEMA DE DISEÑO CORPORATIVO
           ================================================================ */
        :root {
            /* Colores principales */
            --primary-color: #0052CC;
            --primary-dark: #003D99;
            --primary-light: #4C9AFF;
            
            /* Colores secundarios */
            --secondary-color: #00875A;
            --accent-color: #FF5630;
            
            /* Escala de grises */
            --gray-50: #F7F8F9;
            --gray-100: #EBECF0;
            --gray-200: #DFE1E6;
            --gray-300: #C1C7D0;
            --gray-400: #A5ADBA;
            --gray-500: #7A869A;
            --gray-600: #505F79;
            --gray-700: #344563;
            --gray-800: #253858;
            --gray-900: #172B4D;
            
            /* Colores de estado */
            --success-color: #00875A;
            --warning-color: #FF991F;
            --error-color: #DE350B;
            --info-color: #0065FF;
            
            /* Tipografía */
            --font-primary: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', 'Helvetica Neue', sans-serif;
            --font-size-base: 16px;
            --font-weight-normal: 400;
            --font-weight-medium: 500;
            --font-weight-semibold: 600;
            --font-weight-bold: 700;
            
            /* Espaciado */
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;
            --spacing-xxl: 48px;
            
            /* Sombras */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.12);
            
            /* Bordes */
            --border-radius-sm: 4px;
            --border-radius-md: 8px;
            --border-radius-lg: 12px;
            
            /* Transiciones */
            --transition-fast: 150ms ease-in-out;
            --transition-normal: 250ms ease-in-out;
            --transition-slow: 350ms ease-in-out;
        }
        
        /* ================================================================
           ESTILOS GLOBALES
           ================================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-primary);
            font-size: var(--font-size-base);
            color: var(--gray-800);
            background-color: var(--gray-50);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* ================================================================
           CONTENEDOR PRINCIPAL
           ================================================================ */
        .main-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-lg);
        }
        
        /* ================================================================
           TARJETA DE BIENVENIDA
           ================================================================ */
        .welcome-card {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: transform var(--transition-normal), box-shadow var(--transition-normal);
        }
        
        .welcome-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(0, 0, 0, 0.15);
        }
        
        /* ================================================================
           ENCABEZADO DE LA TARJETA
           ================================================================ */
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: var(--spacing-xxl) var(--spacing-xl);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .card-header-custom::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 8s ease-in-out infinite;
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
        
        .card-header-custom h1 {
            font-size: 2.25rem;
            font-weight: var(--font-weight-bold);
            margin-bottom: var(--spacing-sm);
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
        }
        
        .card-header-custom .subtitle {
            font-size: 1rem;
            font-weight: var(--font-weight-normal);
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }
        
        /* ================================================================
           CUERPO DE LA TARJETA
           ================================================================ */
        .card-body-custom {
            padding: var(--spacing-xl);
        }
        
        .welcome-message {
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }
        
        .welcome-message h2 {
            font-size: 1.5rem;
            font-weight: var(--font-weight-semibold);
            color: var(--gray-900);
            margin-bottom: var(--spacing-md);
        }
        
        .welcome-message p {
            font-size: 1rem;
            color: var(--gray-600);
            line-height: 1.7;
            max-width: 600px;
            margin: 0 auto var(--spacing-lg);
        }
        
        /* ================================================================
           DIVISOR
           ================================================================ */
        .divider {
            height: 1px;
            background: linear-gradient(
                to right, 
                transparent, 
                var(--gray-200) 20%, 
                var(--gray-200) 80%, 
                transparent
            );
            margin: var(--spacing-xl) 0;
        }
        
        /* ================================================================
           SECCIÓN DE CARACTERÍSTICAS
           ================================================================ */
        .features-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-top: var(--spacing-xl);
        }
        
        .feature-item {
            text-align: center;
            padding: var(--spacing-lg);
            background: var(--gray-50);
            border-radius: var(--border-radius-md);
            transition: all var(--transition-normal);
            border: 1px solid var(--gray-100);
        }
        
        .feature-item:hover {
            background: white;
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .feature-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto var(--spacing-md);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .feature-item h3 {
            font-size: 1rem;
            font-weight: var(--font-weight-semibold);
            color: var(--gray-900);
            margin-bottom: var(--spacing-xs);
        }
        
        .feature-item p {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin: 0;
        }
        
        /* ================================================================
           PIE DE LA TARJETA
           ================================================================ */
        .card-footer-custom {
            background: var(--gray-50);
            padding: var(--spacing-lg) var(--spacing-xl);
            text-align: center;
            border-top: 1px solid var(--gray-200);
        }
        
        .card-footer-custom p {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin: 0;
        }
        
        .support-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: var(--font-weight-medium);
            transition: color var(--transition-fast);
        }
        
        .support-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* ================================================================
           ALERTAS Y MENSAJES
           ================================================================ */
        .alert-custom {
            padding: var(--spacing-md) var(--spacing-lg);
            border-radius: var(--border-radius-md);
            margin-bottom: var(--spacing-lg);
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background-color: #E3FCEF;
            border-left: 4px solid var(--success-color);
            color: #006644;
        }
        
        .alert-error {
            background-color: #FFEBE6;
            border-left: 4px solid var(--error-color);
            color: #BF2600;
        }
        
        /* ================================================================
           BADGE DE USUARIO
           ================================================================ */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            background: white;
            border-radius: var(--border-radius-lg);
            font-size: 0.875rem;
            font-weight: var(--font-weight-medium);
            color: var(--gray-700);
            box-shadow: var(--shadow-sm);
        }
        
        .user-badge i {
            color: var(--primary-color);
        }
        
        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .main-container {
                padding: var(--spacing-md);
            }
            
            .card-header-custom {
                padding: var(--spacing-xl) var(--spacing-lg);
            }
            
            .card-header-custom h1 {
                font-size: 1.75rem;
            }
            
            .card-body-custom {
                padding: var(--spacing-lg);
            }
            
            .features-section {
                grid-template-columns: 1fr;
                gap: var(--spacing-md);
            }
        }
        
        /* ================================================================
           ACCESIBILIDAD
           ================================================================ */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
        
        /* Focus visible para accesibilidad */
        *:focus-visible {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <?php 
    // Incluir sidebar si existe
    if (file_exists('includes/sidebar.php')) {
        try {
            include 'includes/sidebar.php';
        } catch (Exception $e) {
            error_log("Error cargando sidebar: " . $e->getMessage());
        }
    }
    ?>
    
    <main class="main-container" role="main">
        <div class="welcome-card">
                         <header class="card-header-custom">
                <h1>Sistema de Gestión de Pagos</h1>
                <p class="subtitle">Plataforma Corporativa de Aprobación de Facturas</p>
            </header>
            
          
            <div class="card-body-custom">
               
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert-custom alert-success" role="alert">
                        <i class="fas fa-check-circle" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert-custom alert-error" role="alert">
                        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                  
                <div class="welcome-message">
                    <h2>Bienvenido, <?php echo htmlspecialchars($user['name'] ?? 'Usuario'); ?></h2>
                    <p>
                        Le damos la bienvenida al Sistema de Gestión de Pagos. 
                        Esta plataforma le permite gestionar, revisar y aprobar facturas 
                        de manera segura y eficiente. Todas las operaciones quedan 
                        registradas para garantizar la trazabilidad y el cumplimiento normativo.
                    </p>
                    
                    <div class="user-badge">
                        <i class="fas fa-user-circle"></i>
                        <span>Rol: <?php echo htmlspecialchars(ucfirst($role)); ?></span>
                    </div>
                </div>
                
                <div class="divider"></div>
                
                 
                <section class="features-section">
                    <article class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Seguridad</h3>
                        <p>Protección avanzada de datos y transacciones</p>
                    </article>
                    
                    <article class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3>Eficiencia</h3>
                        <p>Procesos optimizados y automatizados</p>
                    </article>
                    
                    <article class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3>Trazabilidad</h3>
                        <p>Registro completo de todas las operaciones</p>
                    </article>
                    
                    <article class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h3>Soporte</h3>
                        <p>Asistencia técnica disponible 24/7</p>
                    </article>
                </section>
            </div>
            
             
                    
        </div>
    </main>
    
     
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        /**
         * Inicialización del sistema
         */
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-ocultar alertas después de 5 segundos
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
            
            // Log de acceso (solo en desarrollo)
            if (console && typeof console.log === 'function') {
                console.log('%cSistema de Gestión de Pagos', 'color: #0052CC; font-size: 16px; font-weight: bold;');
                console.log('%cVersión 2.0 - Acceso autorizado', 'color: #00875A; font-size: 12px;');
            }
        });
    </script>
</body>
</html>