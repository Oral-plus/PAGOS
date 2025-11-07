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
           VARIABLES Y COLORES
           ==================================== */
        :root {
            --primary-color: #003b7a;
            --secondary-color: #0056b3;
            --accent-color: #f8f9fc;
            --text-color: #333333;
            --border-color: #e4e6ef;
            --box-shadow: 0 5px 20px rgba(0, 59, 122, 0.15);
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
            background-color: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: linear-gradient(to bottom, #f0f2f5, #e6eef8);
        }

        /* ====================================
           CONTENEDOR PRINCIPAL
           ==================================== */
        .login-container {
            width: 100%;
            max-width: 450px;
            padding: 15px;
        }

        .logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .logo {
            max-width: 220px;
            height: auto;
        }

        /* ====================================
           CARD (TARJETA PRINCIPAL)
           ==================================== */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .card-header {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 25px 20px;
            border-bottom: none;
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(to right, #003b7a, #0056b3, #0077cc);
        }

        .card-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1.6rem;
        }

        .system-name {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 5px;
        }

        .card-body {
            padding: 35px 30px;
            background-color: white;
        }

        .card-footer {
            background-color: #f8f9fa;
            text-align: center;
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }

        /* ====================================
           CAMPOS DE ENTRADA
           ==================================== */
        .input-group {
            position: relative;
            margin-bottom: 28px;
        }

        .input-group label {
            display: block;
            font-size: 0.9rem;
            color: #555;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .input-field {
            width: 100%;
            padding: 14px 15px;
            font-size: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.3s ease;
            outline: none;
            background-color: #f9fafc;
        }

        .input-field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 59, 122, 0.15);
            background-color: #fff;
        }

        .input-field::placeholder {
            color: #adb5bd;
        }

        .input-icon {
            position: absolute;
            right: 15px;
            top: 42px;
            color: #6c757d;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .input-icon:hover {
            color: var(--primary-color);
        }

        /* ====================================
           BOTONES
           ==================================== */
        .btn-login {
            background: linear-gradient(to right, #003b7a, #0056b3);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            box-shadow: 0 4px 6px rgba(0, 59, 122, 0.2);
            margin-bottom: 25px;
        }

        .btn-login:hover {
            background: linear-gradient(to right, #00326a, #004a9e);
            box-shadow: 0 6px 8px rgba(0, 59, 122, 0.25);
            transform: translateY(-1px);
        }

        .btn-login i {
            margin-right: 10px;
        }

        .btn-recover-password {
            display: inline-block;
            padding: 10px 18px;
            background-color: transparent;
            color: #003b7a;
            font-weight: bold;
            border-radius: 10px;
            text-decoration: none;
            font-family: 'Segoe UI', sans-serif;
            letter-spacing: 1px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-recover-password:hover {
            transform: scale(1.08);
        }

        /* ====================================
           ALERTAS
           ==================================== */
        .alert {
            padding: 14px 15px;
            margin-bottom: 25px;
            border-radius: 8px;
            border-left: 4px solid #d63031;
            background-color: #feebed;
            color: #d63031;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 10px;
            font-size: 1.1rem;
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

        /* MODAL DE CARGA */
        /* ====================================
           MODAL DE CARGA
           ==================================== */
        .loading-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-modal.active {
            display: flex;
        }

        .loading-modal-content {
            background: white;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 59, 122, 0.2);
        }

        .spinner {
            border: 4px solid #f0f2f5;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
        }

        /* ====================================
           RESPONSIVE
           ==================================== */
        @media (max-width: 576px) {
            .login-container {
                padding: 10px;
            }

            .card-body {
                padding: 25px 20px;
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
