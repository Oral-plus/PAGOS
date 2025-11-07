<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Validar sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Obtener datos del usuario
$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'] ?? 'user';
$user_name = $user['name'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Sistema de Gestión de Pagos - Panel Principal</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">

    <style>
        :root {
            --primary-color: #0052CC;
            --primary-dark: #003D99;
            --primary-light: #4C9AFF;
            --secondary-color: #00875A;
            --accent-color: #FF5630;
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
            --success-color: #00875A;
            --warning-color: #FF991F;
            --error-color: #DE350B;
            --info-color: #0065FF;
            --font-primary: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', 'Helvetica Neue', sans-serif;
            --font-size-base: 16px;
            --font-weight-normal: 400;
            --font-weight-medium: 500;
            --font-weight-semibold: 600;
            --font-weight-bold: 700;
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;
            --spacing-xxl: 48px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 20px rgba(0, 0, 0, 0.12);
            --border-radius-sm: 4px;
            --border-radius-md: 8px;
            --border-radius-lg: 12px;
            --transition-fast: 150ms ease-in-out;
            --transition-normal: 250ms ease-in-out;
            --transition-slow: 350ms ease-in-out;
        }

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
            margin: 0;
            height: 100vh;
            overflow: hidden; /* Evita scroll */
        }

        /* ================================================================
           CONTENEDOR FIJO CENTRADO EN PANTALLA
           ================================================================ */
        .fixed-center-container {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-lg);
            z-index: 1000;
            overflow: auto;
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
           ENCABEZADO
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
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
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
           CUERPO
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

        .divider {
            height: 1px;
            background: linear-gradient(to right, transparent, var(--gray-200) 20%, var(--gray-200) 80%, transparent);
            margin: var(--spacing-xl) 0;
        }

        /* ================================================================
           ALERTAS
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
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .fixed-center-container {
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
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <!-- CONTENEDOR FIJO CENTRADO -->
    <div class="fixed-center-container">
        <?php include 'includes/sidebar.php'; ?>

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
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-ocultar alertas
            document.querySelectorAll('.alert-custom').forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Log de bienvenida
            console.log('%cSistema de Gestión de Pagos', 'color: #0052CC; font-size: 16px; font-weight: bold;');
            console.log('%cVersión 2.0 - Acceso autorizado', 'color: #00875A; font-size: 12px;');
        });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html>