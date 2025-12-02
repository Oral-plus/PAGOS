<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: hola.php");
    exit();
}

$error = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor ingrese su correo y contraseña';
    } else {
        $user = getUserByEmail($email);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            header("Location: hola.php");
            exit();
        } else {
            $error = 'Correo o contraseña incorrectos';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Aprobación</title>
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ====================================
           VARIABLES PREMIUM
           ==================================== */
        :root {
            --primary-color: #003b7a;
            --primary-dark: #002d5c;
            --primary-light: #0056b3;
            --secondary-color: #0056b3;
            --accent-color: #f8f9fc;
            --text-color: #333333;
            --border-color: #e4e6ef;
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 59, 122, 0.25);
            --box-shadow: var(--shadow-xl);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            --radius-full: 9999px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --backdrop-blur: blur(12px);
            --glass-bg: rgba(255, 255, 255, 0.9);
        }

        /* ====================================
           ESTILOS GLOBALES
           ==================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #f0f2f5 0%, #e6eef8 50%, #dde5f0 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(0, 59, 122, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(0, 86, 179, 0.06) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ====================================
           CONTENEDOR PRINCIPAL PREMIUM
           ==================================== */
        .login-container {
            width: 100%;
            max-width: 480px;
            padding: 20px;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            animation: fadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .logo {
            max-width: 240px;
            height: auto;
            filter: drop-shadow(0 4px 8px rgba(0, 59, 122, 0.15));
            transition: var(--transition);
        }

        .logo:hover {
            transform: translateY(-2px) scale(1.02);
            filter: drop-shadow(0 6px 12px rgba(0, 59, 122, 0.2));
        }

        /* ====================================
           CARD (TARJETA PRINCIPAL) PREMIUM
           ==================================== */
        .card {
            border: none;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-2xl);
            overflow: hidden;
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
            position: relative;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-2xl), 0 0 40px rgba(0, 59, 122, 0.15);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            text-align: center;
            padding: 32px 24px;
            border-bottom: none;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
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

        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-light), var(--primary-color));
            background-size: 200% 100%;
            animation: shimmer 3s linear infinite;
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .card-header h3 {
            margin: 0;
            font-weight: 700;
            font-size: 1.75rem;
            letter-spacing: -0.5px;
            position: relative;
            z-index: 1;
        }

        .system-name {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.9);
            margin-top: 8px;
            font-weight: 500;
            position: relative;
            z-index: 1;
            letter-spacing: 0.025em;
        }

        .card-body {
            padding: 40px 32px;
            background-color: white;
            position: relative;
        }

        .card-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #f0f2f5 100%);
            text-align: center;
            padding: 24px;
            border-top: 2px solid var(--border-color);
            position: relative;
        }

        /* ====================================
           CAMPOS DE ENTRADA PREMIUM
           ==================================== */
        .input-group {
            position: relative;
            margin-bottom: 28px;
            animation: slideInUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation-fill-mode: both;
        }

        .input-group:nth-child(1) { animation-delay: 0.1s; }
        .input-group:nth-child(2) { animation-delay: 0.2s; }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .input-group label {
            display: block;
            font-size: 0.875rem;
            color: var(--text-color);
            margin-bottom: 10px;
            font-weight: 600;
            letter-spacing: 0.025em;
        }

        .input-field {
            width: 100%;
            padding: 16px 18px;
            font-size: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            transition: var(--transition);
            outline: none;
            background-color: #f9fafc;
            font-weight: 500;
            color: var(--text-color);
        }

        .input-field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 59, 122, 0.1), var(--shadow-md);
            background-color: #fff;
            transform: translateY(-2px);
        }

        .input-field::placeholder {
            color: #adb5bd;
            font-weight: 400;
        }

        .input-icon {
            position: absolute;
            right: 18px;
            top: 46px;
            color: #6c757d;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.125rem;
            z-index: 2;
        }

        .input-icon:hover {
            color: var(--primary-color);
            transform: scale(1.1);
        }

        /* ====================================
           BOTONES PREMIUM
           ==================================== */
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-lg);
            padding: 16px 24px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            box-shadow: var(--shadow-lg);
            margin-bottom: 28px;
            letter-spacing: 0.025em;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            box-shadow: var(--shadow-xl);
            transform: translateY(-3px) scale(1.02);
        }

        .btn-login:active {
            transform: translateY(-1px) scale(1);
        }

        .btn-login i {
            margin-right: 10px;
            font-size: 1.125rem;
        }

        .btn-recover-password {
            display: inline-block;
            padding: 12px 20px;
            background: linear-gradient(135deg, rgba(0, 59, 122, 0.05), rgba(0, 86, 179, 0.05));
            color: var(--primary-color);
            font-weight: 700;
            border-radius: var(--radius-lg);
            text-decoration: none;
            font-family: 'Segoe UI', sans-serif;
            letter-spacing: 0.05em;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border: 2px solid rgba(0, 59, 122, 0.1);
            cursor: pointer;
        }

        .btn-recover-password:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-md);
            background: linear-gradient(135deg, rgba(0, 59, 122, 0.1), rgba(0, 86, 179, 0.1));
            border-color: var(--primary-color);
        }

        /* ====================================
           ALERTAS PREMIUM
           ==================================== */
        .alert {
            padding: 16px 18px;
            margin-bottom: 28px;
            border-radius: var(--radius-lg);
            border-left: 4px solid #d63031;
            background: linear-gradient(135deg, rgba(254, 235, 237, 0.9), rgba(254, 235, 237, 0.7));
            color: #d63031;
            display: flex;
            align-items: center;
            box-shadow: var(--shadow-md);
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
            transition: var(--transition);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.25rem;
        }

        /* ====================================
           ENLACES
           ==================================== */
        .forgot-password {
            font-size: 0.9rem;
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .forgot-password:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .card-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .card-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        /* ====================================
           MODAL DE CARGA PREMIUM
           ==================================== */
        .loading-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .loading-modal.active {
            display: flex;
        }

        .loading-modal-content {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            padding: 48px 40px;
            border-radius: var(--radius-2xl);
            text-align: center;
            box-shadow: var(--shadow-2xl);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: scaleIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            min-width: 280px;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .spinner {
            border: 4px solid rgba(0, 59, 122, 0.1);
            border-top: 4px solid var(--primary-color);
            border-radius: var(--radius-full);
            width: 56px;
            height: 56px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
            box-shadow: var(--shadow-md);
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.125rem;
            letter-spacing: 0.025em;
        }

        /* ====================================
           ACCESIBILIDAD
           ==================================== */
        *:focus-visible {
            outline: 3px solid var(--primary-color);
            outline-offset: 3px;
            border-radius: var(--radius-md);
            box-shadow: 0 0 0 4px rgba(0, 59, 122, 0.2);
        }

        .btn-login:focus-visible {
            outline-color: white;
            outline-width: 4px;
        }

        /* ====================================
           RESPONSIVE PREMIUM
           ==================================== */
        @media (max-width: 576px) {
            .login-container {
                padding: 15px;
                max-width: 100%;
            }

            .card-body {
                padding: 32px 24px;
            }

            .card-header {
                padding: 28px 20px;
            }

            .card-header h3 {
                font-size: 1.5rem;
            }

            .logo {
                max-width: 200px;
            }

            .btn-login {
                padding: 14px 20px;
                font-size: 0.9375rem;
            }
        }
    </style>
</head>
<body>
    <!-- MODAL DE CARGA AGREGADO -->
    <div class="loading-modal" id="loadingModal">
        <div class="loading-modal-content">
            <div class="spinner"></div>
            <p class="loading-text">Iniciando sesión...</p>
        </div>
    </div>

    <div class="login-container">
        <!-- Logo -->
        <div class="logo-container">
            <img src="assets/65x45.png" alt="Oral Plus" class="logo">
        </div>

        <!-- Tarjeta de Login -->
        <div class="card">
            <!-- Header -->
            <div class="card-header">
                <h3>Iniciar Sesión</h3>
                <div class="system-name">Sistema de Aprobación de Facturas</div>
            </div>

            <!-- Body -->
            <div class="card-body">
                <!-- Mensaje de Error -->
                <?php if (!empty($error)): ?>
                    <div class="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Login -->
                <form method="POST" action="" id="loginForm">
                    <!-- Campo Email -->
                    <div class="input-group">
                        <label for="email">Correo Electrónico</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="input-field" 
                            placeholder="Escribe tu correo" 
                            required>
                        <span class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </span>
                    </div>

                    <!-- Campo Contraseña -->
                    <div class="input-group">
                        <label for="password">Contraseña</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="input-field" 
                            placeholder="Escribe la contraseña" 
                            required>
                        <span class="input-icon" onclick="togglePassword()" style="cursor: pointer;">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>

                    <!-- Botón Login -->
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                    </button>

                    <!-- Botón Recuperar Contraseña -->
                    <div style="text-align: center;">
                        <a href="forgot-password.php" class="btn-recover-password">
                            🔐 RECUPERAR CONTRASEÑA
                        </a>
                    </div>
                </form>
            </div>

            <!-- Footer -->
            <div class="card-footer">
                <a href="register.php">.</a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loadingModal = document.getElementById('loadingModal');
            loadingModal.classList.add('active');
            
            setTimeout(function() {
                loadingModal.classList.remove('active');
            }, 1000);
        });
    </script>
</body>
</html>
