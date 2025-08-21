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
    <!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap 5 JS Bundle (incluye Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    
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

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Facturas - Subir Factura</title>
    <style>
 
        
        body {
            background-color: #f5f6fa;
            color: #333;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
        }
        
   

        .title-container {
            flex-grow: 1;
        }
        
        .title {
            font-size: 24px;
            color: #003b71;
            margin-bottom: 5px;
        }
        
        .subtitle {
            font-size: 16px;
            color: #666;
        }
        
        .card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background-color: #003b71;
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
        }
        
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #0067b3;
            box-shadow: 0 0 0 2px rgba(0,103,179,0.2);
        }
        
        select {
            background-color: white;
            cursor: pointer;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 0;
        }
        
        .form-col {
            flex: 1;
        }
        
        .file-input-container {
            position: relative;
            margin-top: 20px;
            border: 2px dashed #ccc;
            padding: 25px;
            text-align: center;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .file-input-container:hover {
            border-color: #0067b3;
            background-color: rgba(0,103,179,0.05);
        }
        
        .file-input-label {
            display: block;
            cursor: pointer;
            font-weight: 500;
            color: #0067b3;
        }
        
        .file-input-icon {
            font-size: 36px;
            color: #0067b3;
            margin-bottom: 10px;
        }
        
        input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .btn {
            background-color: #0067b3;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
            display: inline-block;
        }
        
        .btn:hover {
            background-color: #004f8a;
        }
        
        .btn-container {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-secondary {
            background-color: #6c757d;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-right: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .priority-badge.active {
            color: white;
        }
        
        .priority-badge.high {
            border: 1px solid #dc3545;
            color: #dc3545;
        }
        
        .priority-badge.high.active {
            background-color: #dc3545;
        }
        
        .priority-badge.medium {
            border: 1px solid #fd7e14;
            color: #fd7e14;
        }
        
        .priority-badge.medium.active {
            background-color: #fd7e14;
        }
        
        .priority-badge.low {
            border: 1px solid #28a745;
            color: #28a745;
        }
        
        .priority-badge.low.active {
            background-color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Sky</div>
            <div class="title-container">
                <h1 class="title">Sistema de Facturas</h1>
                <p class="subtitle">Subir nueva factura para procesamiento</p>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                Subir PDF de Factura
            </div>
            <div class="card-body">
                <form action="subir_factura.php" method="POST" enctype="multipart/form-data">
                    <!-- Aquí van los inputs que ya tienes... -->
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="proveedor">Proveedor:</label>
                                <input type="text" id="proveedor" name="proveedor" placeholder="Nombre del proveedor" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="codigo_proveedor">Código de proveedor:</label>
                                <input type="text" id="codigo_proveedor" name="codigo_proveedor" placeholder="Ej: PROV-001" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="fecha">Fecha de emisión:</label>
                                <input type="date" id="fecha" name="fecha" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="fecha_vencimiento">Fecha de vencimiento:</label>
                                <input type="date" id="fecha_vencimiento" name="fecha_vencimiento" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="valor">Valor (€):</label>
                                <input type="number" step="0.01" id="valor" name="valor" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label>Prioridad:</label>
                                <div>
                                    <input type="hidden" id="priority" name="priority" value="Media">
                                    <span class="priority-badge high" onclick="setPriority('Alta', this)">Alta</span>
                                    <span class="priority-badge medium active" onclick="setPriority('Media', this)">Media</span>
                                    <span class="priority-badge low" onclick="setPriority('Baja', this)">Baja</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                   <!-- Contenedor del archivo -->
<div class="form-group">
    <div class="file-input-container">
        <div class="file-input-icon">📄</div>
        <label class="file-input-label">Arrastre el archivo PDF aquí o haga clic para seleccionar</label>
        <p id="file-name" style="margin-top: 10px; color: #666;">Ningún archivo seleccionado</p>
        <input type="file" id="archivo_pdf" name="archivo_pdf" accept="application/pdf" required>
        
        <!-- Contenedor para la vista previa del PDF -->
        <!-- Modal con visor desplazable -->
<div id="pdfModal" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="max-height: 90vh;">
      <div class="modal-header">
        <h5 class="modal-title">Vista previa del PDF</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0" style="height: 80vh; overflow: auto;">
        <iframe id="pdf-viewer"
                style="min-height: 100%; width: 100%; border: none;"
                scrolling="auto"></iframe>
      </div>
    </div>
  </div>
</div>

    </div>
</div>
<script>
  const input = document.getElementById('archivo_pdf');
  const label = document.querySelector('.file-input-label');

  input.addEventListener('change', function () {
    const file = this.files[0];
    if (file && file.type === 'application/pdf') {
      document.getElementById('file-name').textContent = file.name;
      const fileURL = URL.createObjectURL(file);
      document.getElementById('pdf-viewer').src = fileURL;

      const pdfModal = new bootstrap.Modal(document.getElementById('pdfModal'));
      pdfModal.show();
    } else {
      document.getElementById('file-name').textContent = 'Ningún archivo seleccionado';
      document.getElementById('pdf-viewer').src = '';
    }
  });

  label.addEventListener('click', () => input.click());
</script>



                    
                   
            <div class="btn-container">
                <button type="button" class="btn btn-secondary" onclick="window.history.back();">Cancelar</button>
                <button type="submit" class="btn">Subir Factura</button>
            </div>
                </form>
            </div>
        </div>
    </div>
    

    <script>
        // Mostrar nombre del archivo seleccionado
        document.getElementById('archivo_pdf').addEventListener('change', function() {
            var fileName = this.files[0] ? this.files[0].name : 'Ningún archivo seleccionado';
            document.getElementById('file-name').textContent = fileName;
        });
        
        // Función para establecer prioridad
        function setPriority(value, el) {
            document.getElementById('priority').value = value;
            
            // Remover clase active de todos los badges
            var badges = document.querySelectorAll('.priority-badge');
            badges.forEach(function(badge) {
                badge.classList.remove('active');
            });
            
            // Añadir clase active al seleccionado
            el.classList.add('active');
        }
    </script>
</body>

</html>
</html>

