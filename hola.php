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

// Procesar la actualización del campo "ok" si se envió el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_ok'])) {
    $invoice_id = $_POST['invoice_id'];
    
    // Verificar que el usuario haya visto los detalles de la factura
    if (hasUserViewedInvoice($invoice_id, $user_id)) {
        $result = markInvoiceAsOk($invoice_id);
        
        if ($result) {
            $_SESSION['success_message'] = "Factura #$invoice_id marcada como OK correctamente";
        } else {
            $_SESSION['error_message'] = "Error al marcar la factura #$invoice_id como OK";
        }
    } else {
        $_SESSION['error_message'] = "Debe ver los detalles de la factura antes de marcarla como OK";
    }
    
    // Redireccionar para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
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
        // Usar funciones nativas de sqlsrv
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $affected = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        return ($affected > 0);
    }
}

// Para la carga inicial de la página
$date_filter = isset($_GET['date']) ? trim($_GET['date']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$invoice_id_filter = isset($_GET['invoice_id']) ? trim($_GET['invoice_id']) : '';
$overdue_days_filter = isset($_GET['overdue_days']) ? trim($_GET['overdue_days']) : '';

// Obtener facturas pendientes (sin OK)
$pending_invoices = getFilteredInvoices($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, false);

// Obtener facturas marcadas como OK
$ok_invoices = getFilteredInvoices($date_filter, $status_filter, $supplier_filter, $invoice_id_filter, $overdue_days_filter, true);

// Solución para el error de variable 'role' indefinida
if (!isset($role)) {
    $role = 'user'; // Valor predeterminado para role
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Bienvenido al Sistema de Pagos</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <!-- Animate.css para animaciones -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <!-- Font Awesome para iconos -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
  <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido al Sistema de Pagos</title>
        <?php 
        try {
            include 'includes/sidebar.php';
        } catch (Exception $e) {
            echo '<div class="sidebar-error">Error cargando sidebar</div>';
        }
        ?>
<style>
    :root {
        --primary-color: #3a86ff;
        --secondary-color:rgb(29, 190, 240);
        --accent-color:rgb(0, 57, 214);
    
        --dark-bg: #1a1a2e;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color:#fff;
        overflow-x: hidden;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        margin: 0;
        perspective: 1000px;
    }
    
    .welcome-header {
        width: 500px;
        height: 500px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color), var(--accent-color));
        background-size: 300% 300%;
        animation: gradientBackground 8s ease infinite;
        color: white;
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3),
                    0 0 80px rgba(131, 56, 236, 0.2);
        position: relative;
        overflow: hidden;
        display: flex;
        justify-content: center;
        align-items: center;
        transform-style: preserve-3d;
        transition: all 0.5s ease;
    }
    
    .welcome-header:hover {
        transform: translateY(-10px) rotateX(5deg) rotateY(5deg);
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4),
                    0 0 100px rgba(131, 56, 236, 0.3);
    }
    
    @keyframes gradientBackground {
        0% {
            background-position: 0% 50%;
        }
        50% {
            background-position: 100% 50%;
        }
        100% {
            background-position: 0% 50%;
        }
    }
    
    .floating-shapes {
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        overflow: hidden;
        z-index: 0;
    }
    
    .shape {
        position: absolute;
        background: rgba(255, 255, 255, 0.15);
        animation: float 15s infinite linear;
        backdrop-filter: blur(2px);
        -webkit-backdrop-filter: blur(2px);
    }
    
    .shape-circle {
        border-radius: 50%;
    }
    
    .shape-triangle {
        clip-path: polygon(50% 0%, 100% 100%, 0% 100%);
    }
    
    .shape-square {
        border-radius: 10px;
        transform: rotate(45deg);
    }
    
    .shape:nth-child(1) {
        width: 80px;
        height: 80px;
        top: -40px;
        right: 10%;
        animation-duration: 25s;
    }
    
    .shape:nth-child(2) {
        width: 120px;
        height: 120px;
        bottom: -60px;
        right: 20%;
        animation-duration: 35s;
        animation-delay: 2s;
    }
    
    .shape:nth-child(3) {
        width: 60px;
        height: 60px;
        top: 40%;
        left: 5%;
        animation-duration: 20s;
        animation-delay: 1s;
    }
    
    .shape:nth-child(4) {
        width: 100px;
        height: 100px;
        top: 10%;
        left: 15%;
        animation-duration: 30s;
        animation-delay: 3s;
    }
    
    .shape:nth-child(5) {
        width: 70px;
        height: 70px;
        bottom: 15%;
        left: 20%;
        animation-duration: 22s;
        animation-delay: 1.5s;
    }
    
    @keyframes float {
        0% {
            transform: translateY(0) rotate(0deg) scale(1);
            opacity: 1;
        }
        33% {
            transform: translateY(-50px) rotate(120deg) scale(1.1);
            opacity: 0.8;
        }
        66% {
            transform: translateY(20px) rotate(240deg) scale(0.9);
            opacity: 0.6;
        }
        100% {
            transform: translateY(0) rotate(360deg) scale(1);
            opacity: 1;
        }
    }
    
    .welcome-message {
        position: relative;
        z-index: 2;
        text-align: center;
        padding: 30px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 15px;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transform: translateZ(30px);
        transition: all 0.5s ease;
    }
    
    .welcome-message:hover {
        transform: translateZ(50px);
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
    }
    
    .welcome-message h1 {
        font-weight: 700;
        margin-bottom: 1rem;
        font-size: 2.5rem;
        text-align: center;
        letter-spacing: 2px;
        background: linear-gradient(to right, #ffffff, #ffffffaa);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        animation: shine 3s infinite linear, fadeInDown 1s;
    }
    
    @keyframes shine {
        0% {
            background-position: 0% 50%;
        }
        100% {
            background-position: 200% 50%;
        }
    }
    
    .welcome-message p {
        font-size: 1.1rem;
        margin-bottom: 1.5rem;
        line-height: 1.7;
        animation: fadeInUp 1s;
        animation-delay: 0.5s;
        animation-fill-mode: both;
        text-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }
    
    .divider {
        height: 4px;
        width: 80%;
        background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.5), transparent);
        border-radius: 4px;
        margin: 20px auto;
        position: relative;
        overflow: hidden;
    }
    
    .divider::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.8), transparent);
        animation: shine-divider 2s infinite;
    }
    
    @keyframes shine-divider {
        100% {
            left: 100%;
        }
    }
    
    .cta-button {
        display: inline-block;
        padding: 12px 30px;
        background: linear-gradient(to right, var(--accent-color), var(--secondary-color));
        color: white;
        border: none;
        border-radius: 50px;
        font-size: 1.1rem;
        cursor: pointer;
        margin-top: 20px;
        transition: all 0.3s ease;
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        position: relative;
        overflow: hidden;
        animation: fadeIn 1s;
        animation-delay: 1s;
        animation-fill-mode: both;
        font-weight: 600;
        letter-spacing: 1px;
    }
    
    .cta-button:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 25px rgba(0, 0, 0, 0.3);
    }
    
    .cta-button::after {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: rgba(255, 255, 255, 0.2);
        transform: rotate(45deg);
        transition: all 0.5s ease;
        opacity: 0;
    }
    
    .cta-button:hover::after {
        opacity: 1;
        animation: shine-button 1.5s infinite;
    }
    
    @keyframes shine-button {
        0% {
            left: -50%;
        }
        100% {
            left: 100%;
        }
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @media (max-width: 600px) {
        .welcome-header {
            width: 90%;
            height: auto;
            min-height: 400px;
        }
        
        .welcome-message h1 {
            font-size: 2rem;
        }
        
        .welcome-message p {
            font-size: 1rem;
        }
    }
    
    /* Partículas de fondo */
    .particle {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.5);
        animation: particleFade 3s infinite ease-out;
        pointer-events: none;
    }
    
    @keyframes particleFade {
        0% {
            transform: scale(0);
            opacity: 1;
        }
        100% {
            transform: scale(1);
            opacity: 0;
        }
    }
    </style>
</head>
<body>
    <div class="welcome-header">
        <div class="floating-shapes">
            <div class="shape shape-circle"></div>
            <div class="shape shape-triangle"></div>
            <div class="shape shape-square"></div>
            <div class="shape shape-circle"></div>
            <div class="shape shape-triangle"></div>
        </div>
        <div class="welcome-message">
            <h1>¡Bienvenido!</h1>
            <div class="divider"></div>
            <p>Nos complace contar con su participación en esta plataforma.
A partir de ahora, podrá gestionar sus transacciones y aprobaciones de pago de forma segura, ágil y eficiente.
Si requiere asistencia, nuestro equipo está disponible para brindarle soporte en todo momento.</p>
            
        </div>
    </div>

    <script>
        // Crear efecto de partículas al pasar el mouse
        document.addEventListener('mousemove', function(e) {
            if (Math.random() > 0.92) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                
                const size = Math.random() * 8 + 4;
                particle.style.width = size + 'px';
                particle.style.height = size + 'px';
                
                particle.style.left = e.clientX + 'px';
                particle.style.top = e.clientY + 'px';
                
                document.body.appendChild(particle);
                
                setTimeout(() => {
                    particle.remove();
                }, 3000);
            }
        });
        
        // Efecto 3D al mover el mouse sobre el welcome-header
        const card = document.querySelector('.welcome-header');
        const message = document.querySelector('.welcome-message');
        
        document.addEventListener('mousemove', function(e) {
            const xAxis = (window.innerWidth / 2 - e.pageX) / 25;
            const yAxis = (window.innerHeight / 2 - e.pageY) / 25;
            
            card.style.transform = `rotateY(${xAxis}deg) rotateX(${yAxis}deg)`;
        });
        
        // Reset de la rotación cuando el mouse sale
        document.addEventListener('mouseleave', function() {
            card.style.transform = 'rotateY(0deg) rotateX(0deg)';
        });
    </script>
</body>
</html>
</body>
</html>