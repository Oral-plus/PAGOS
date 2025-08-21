<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? '';
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
        $error = 'Por favor complete todos los campos';
    } elseif ($password !== $confirm_password) {
        $error = 'Las contraseñas no coinciden';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor ingrese un correo electrónico válido';
    } elseif (emailExists($email)) {
        $error = 'Este correo electrónico ya está registrado';
    } else {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Create user
        $result = createUser($name, $email, $hashed_password, $role);
        
        if ($result) {
            $success = 'Registro exitoso. Ahora puede iniciar sesión.';
        } else {
            $error = 'Error al registrar el usuario. Por favor intente nuevamente.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Registro - Sistema de Aprobación de Facturas</title>
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px 0;
            background-image: linear-gradient(to bottom, #f0f2f5, #e6eef8);
        }
        
        .register-container {
            width: 100%;
            max-width: 520px;
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
        
        .system-name {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 5px;
        }
        
        .card-body {
            padding: 30px;
            background-color: white;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 22px;
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
        
        .select-field {
            width: 100%;
            padding: 14px 15px;
            font-size: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: #f9fafc;
            transition: all 0.3s ease;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            cursor: pointer;
        }
        
        .select-field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 59, 122, 0.15);
            background-color: #fff;
        }
        
        .select-wrapper {
            position: relative;
        }
        
        .select-wrapper::after {
            content: '\f107';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: 42px;
            right: 15px;
            color: #6c757d;
            pointer-events: none;
        }
        
        .btn-register {
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
            box-shadow: 0 4px 6px rgba(0, 59, 122, 0.2);
        }
        
        .btn-register i {
            margin-right: 10px;
        }
        
        .btn-register:hover {
            background: linear-gradient(to right, #00326a, #004a9e);
            box-shadow: 0 6px 8px rgba(0, 59, 122, 0.25);
            transform: translateY(-1px);
        }
        
        .login-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        .login-link:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
        .alert {
            padding: 14px 15px;
            margin-bottom: 25px;
            border-radius: 8px;
            display: flex;
            align-items: center;
        }
        
        .alert i {
            margin-right: 10px;
            font-size: 1.1rem;
        }
        
        .alert-danger {
            background-color: #feebed;
            color: #d63031;
            border-left: 4px solid #d63031;
        }
        
        .alert-success {
            background-color: #e7f9ed;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }
        
        @media (max-width: 576px) {
            .register-container {
                padding: 10px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .form-footer {
                flex-direction: column-reverse;
                gap: 15px;
            }
            
            .btn-register {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo-container">
            <img src="assets/65x45.png" alt="Oral Plus" class="logo">
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>Registro de Usuario</h3>
                <div class="system-name">Sistema de Aprobación de Facturas</div>
            </div>
            
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="name">Nombre Completo</label>
                        <input type="text" id="name" name="name" class="input-field" placeholder="Ingrese su nombre completo" required>
                        <span class="input-icon">
                            <i class="fas fa-user"></i>
                        </span>
                    </div>
                    
                    <div class="input-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" name="email" class="input-field" placeholder="nombre@ejemplo.com" required>
                        <span class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </span>
                    </div>
                    
                    <div class="input-group">
                        <label for="password">Contraseña</label>
                        <input type="password" id="password" name="password" class="input-field" placeholder="Mínimo 6 caracteres" required>
                        <span class="input-icon" onclick="togglePassword('password', 'toggleIcon1')">
                            <i class="fas fa-eye" id="toggleIcon1"></i>
                        </span>
                    </div>
                    
                    <div class="input-group">
                        <label for="confirm_password">Confirmar Contraseña</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="input-field" placeholder="Repita su contraseña" required>
                        <span class="input-icon" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                            <i class="fas fa-eye" id="toggleIcon2"></i>
                        </span>
                    </div>
                    
                    <div class="input-group">
                        <label for="role">Rol</label>
                        <div class="select-wrapper">
                            <select id="role" name="role" class="select-field" required>
                                <option value="" selected disabled>Seleccione un rol</option>
                                <option value="gerente">Gerente</option>
                                <option value="subgerente">Subgerente</option>
                                <option value="contador">Contador</option>
                                <option value="Preparador">Preparador</option>
                                <option value="verificador">Verificador</option>

                            </select>
                        </div>
                    </div>
                    
                    <div class="form-footer">
                        <a href="login.php" class="login-link">¿Ya tiene una cuenta? Inicie sesión</a>
                        <button type="submit" class="btn-register">
                            <i class="fas fa-user-plus"></i> Registrarse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
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