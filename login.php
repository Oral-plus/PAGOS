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
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            
            // Redirect to dashboard
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
        :root {
            --primary-color: #003b7a; /* Color azul de Oral Plus */
            --secondary-color: #0056b3; /* Azul secundario */
            --accent-color: #f8f9fc;
            --text-color: #333333;
            --border-color: #e4e6ef;
            --box-shadow: 0 5px 20px rgba(0, 59, 122, 0.15);
        }
        
        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background-color: #f0f2f5;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
            background-image: linear-gradient(to bottom, #f0f2f5, #e6eef8);
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            padding: 15px;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--box-shadow);
            overflow: hidden;
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
        
        .card-header {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 25px 20px;
            border-bottom: none;
            position: relative;
        }
        
        .card-header:after {
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
        
        .card-body {
            padding: 35px 30px;
            background-color: white;
        }
        
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
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .checkbox-container input {
            margin-right: 8px;
        }
        
        .checkbox-container label {
            font-size: 0.9rem;
            color: var(--text-color);
        }
        
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
        }
        
        .btn-login i {
            margin-right: 10px;
        }
        
        .btn-login:hover {
            background: linear-gradient(to right, #00326a, #004a9e);
            box-shadow: 0 6px 8px rgba(0, 59, 122, 0.25);
            transform: translateY(-1px);
        }
        
        .login-footer {
            background-color: #f8f9fa;
            text-align: center;
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .login-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
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
        
        .system-name {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 5px;
        }
        
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
    <div class="login-container">
        <div class="logo-container">
            <img src="assets/65x45.png" alt="Oral Plus" class="logo">
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>Iniciar Sesión</h3>
                <div class="system-name">Sistema de Aprobación de Facturas</div>
            </div>
            
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" class="input-field" placeholder="Escribe tu correo " required>
                        <span class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </span>
                    </div>
                    
                    <div class="input-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" class="input-field" placeholder="Escribe la contraseña" required>
                        <span class="input-icon" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                    
                    <div style="margin-bottom: 25px;">
                        <button type="submit" class="btn-login">
                            <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                        </button>
                    </div>

                    <a href="forgot-password.php"> RECUPERAR CONTRASEÑA</a>
                </form>
            </div>
            
            <div class="login-footer">
                <a href="register.php">.</a>
            </div>
        </div>
    </div>
    
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
    </script>
</body>
</html>