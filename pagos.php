<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'] ?? 'user'; // ← Arreglado: evitar undefined

// Inicializar filtros con valores por defecto
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Exportar Facturas Pagadas - Sistema de Facturación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* ===========================
           VARIABLES PREMIUM
           =========================== */
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-light: #2563eb;
            --secondary: #2563eb;
            --success: #22c55e;
            --danger: #dc3545;
            --info: #0ea5e9;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            
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

        /* ===========================
           BODY Y CONTENEDORES SIN SCROLL (FONDO ESTÁTICO)
           =========================== */
        body {
            overflow: hidden !important;
            height: 100vh;
            position: fixed;
            width: 100%;
        }
        
        .container-fluid {
            overflow: hidden !important;
            height: 100vh;
            position: fixed;
            width: 100%;
            top: 0;
            left: 0;
        }
        
        .row {
            height: 100%;
            overflow: hidden !important;
            margin: 0;
        }

        /* ===========================
           CONTENEDOR PRINCIPAL CENTRADO (FONDO ESTÁTICO)
           =========================== */
        main {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            width: 100%;
            padding: 2rem 1rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden !important;
            z-index: 1;
        }

        /* === FONDO OSCURO FIJO PREMIUM === */
        .modal-backdrop-custom {
            position: fixed;
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.6), rgba(30, 64, 175, 0.2));
            z-index: 999;
            backdrop-filter: var(--backdrop-blur);
            animation: backdropFadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes backdropFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* === TARJETA CENTRADA PREMIUM === */
        .export-modal-card {
            position: relative;
            width: 100%;
            max-width: 700px;
            height: 90vh;
            max-height: 90vh;
            margin: 0 auto;
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-2xl);
            overflow: hidden; /* Oculta el overflow del contenedor */
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: modalFadeIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalFadeIn {
            from { 
                opacity: 0; 
                transform: translateY(30px) scale(0.95);
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1);
            }
        }

        /* === SCROLLBAR PERSONALIZADA PREMIUM (SOLO EN EL BODY) === */
        .export-body::-webkit-scrollbar { 
            width: 10px; 
        }
        .export-body::-webkit-scrollbar-track { 
            background: var(--gray-100); 
            border-radius: var(--radius-full); 
        }
        .export-body::-webkit-scrollbar-thumb { 
            background: linear-gradient(135deg, var(--primary), var(--primary-light)); 
            border-radius: var(--radius-full); 
            border: 2px solid var(--gray-100);
            transition: var(--transition);
        }
        .export-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }

        /* ===========================
           HEADER PREMIUM
           =========================== */
        .export-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, var(--secondary) 100%);
            color: #fff;
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .export-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: shimmer 8s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: rotate(0deg) scale(1); opacity: 0.5; }
            50% { transform: rotate(180deg) scale(1.1); opacity: 0.8; }
        }
        
        .export-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light), var(--primary));
            background-size: 200% 100%;
            animation: shimmerLine 3s linear infinite;
        }
        
        @keyframes shimmerLine {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .export-header i {
            font-size: 4rem;
            margin-bottom: 1.25rem;
            display: block;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
            animation: iconFloat 3s ease-in-out infinite;
        }
        
        @keyframes iconFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }
        
        .export-header h1 { 
            font-size: 2rem; 
            font-weight: 800; 
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
            letter-spacing: -0.5px;
        }
        
        .export-header p { 
            font-size: 1rem; 
            opacity: 0.95; 
            margin: 0;
            position: relative;
            z-index: 1;
            font-weight: 400;
            letter-spacing: 0.025em;
        }
        
        .export-body { 
            padding: 2.5rem 2rem;
            background: linear-gradient(to bottom, #ffffff, var(--gray-50));
            overflow-y: auto !important;
            overflow-x: hidden !important;
            flex: 1 1 auto;
            min-height: 0; /* Importante para que flex funcione correctamente */
            height: 0; /* Necesario para que flex funcione correctamente */
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
            position: relative;
        }

        /* ===========================
           FILTROS SECTION PREMIUM
           =========================== */
        .filters-section {
            background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .filters-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            opacity: 0.8;
        }
        
        .filters-section:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-3px);
            border-left-width: 5px;
        }
        
        .filters-section h5 { 
            color: var(--primary); 
            margin-bottom: 1.25rem; 
            font-weight: 700;
            font-size: 1.125rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            letter-spacing: 0.025em;
        }
        
        .filters-section h5 i {
            font-size: 1.25rem;
        }

        /* ===========================
           FORM CONTROLS PREMIUM
           =========================== */
        .form-label {
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.625rem;
            font-size: 0.9375rem;
            letter-spacing: 0.025em;
        }
        
        .form-control {
            border: 2px solid var(--gray-300);
            border-radius: var(--radius-lg);
            padding: 0.875rem 1rem;
            font-size: 0.9375rem;
            transition: var(--transition);
            background: #ffffff;
            font-weight: 500;
            color: var(--gray-800);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1), var(--shadow-md);
            background: #ffffff;
            transform: translateY(-2px);
        }

        /* ===========================
           SEARCH CONTAINER PREMIUM
           =========================== */
        .search-container { 
            position: relative; 
        }
        
        .search-input {
            width: 100%;
            padding: 0.875rem 3rem 0.875rem 1rem;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius-lg);
            font-size: 0.9375rem;
            transition: var(--transition);
            background: #ffffff;
            font-weight: 500;
            color: var(--gray-800);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1), var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .search-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            font-size: 1.125rem;
            pointer-events: none;
            transition: var(--transition);
        }
        
        .search-input:focus + .search-icon {
            color: var(--primary);
        }
        
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 2px solid var(--primary);
            border-top: none;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            max-height: 300px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
            box-shadow: var(--shadow-xl);
            backdrop-filter: var(--backdrop-blur);
        }
        
        .search-results.show { 
            display: block; 
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
        
        .search-result-item {
            padding: 0.875rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
            color: var(--gray-700);
            font-weight: 500;
        }
        
        .search-result-item:hover {
            background: linear-gradient(90deg, rgba(30, 64, 175, 0.1), rgba(30, 64, 175, 0.05));
            padding-left: 1.5rem;
            color: var(--primary);
            font-weight: 700;
            transform: translateX(4px);
            border-left: 3px solid var(--primary);
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }

        /* ===========================
           BADGES PREMIUM
           =========================== */
        .selected-supplier-badge {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-full);
            margin-top: 1rem;
            font-size: 0.9375rem;
            gap: 0.625rem;
            box-shadow: var(--shadow-lg);
            animation: badgeSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 700;
            letter-spacing: 0.025em;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        @keyframes badgeSlideIn {
            from {
                opacity: 0;
                transform: translateX(-15px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }
        
        .selected-supplier-badge i.fa-times { 
            cursor: pointer;
            padding: 0.375rem;
            border-radius: var(--radius-full);
            transition: var(--transition);
            font-size: 0.875rem;
        }
        
        .selected-supplier-badge i.fa-times:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg) scale(1.1);
        }

        .badge {
            padding: 0.625rem 1rem;
            border-radius: var(--radius-full);
            font-weight: 700;
            font-size: 0.875rem;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            letter-spacing: 0.025em;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .badge:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: var(--shadow-lg);
        }

        /* ===========================
           BOTONES PREMIUM
           =========================== */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border: none;
            border-radius: var(--radius-lg);
            padding: 0.875rem 2rem;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            letter-spacing: 0.025em;
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: var(--shadow-xl);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }

        .export-button-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            padding: 2rem 0;
        }
        
        .btn-export-main {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border: none;
            color: #fff;
            font-size: 1.0625rem;
            font-weight: 700;
            padding: 1.125rem 2.5rem;
            border-radius: var(--radius-full);
            box-shadow: var(--shadow-xl);
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-export-main::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: var(--radius-full);
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn-export-main:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-export-main:hover {
            transform: translateY(-4px) scale(1.03);
            box-shadow: var(--shadow-2xl), 0 0 40px rgba(30, 64, 175, 0.3);
        }
        
        .btn-export-main:active {
            transform: translateY(-2px) scale(1.01);
        }
        
        .btn-export-main:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: var(--shadow-sm) !important;
        }
        
        .btn-export-main:disabled:hover {
            transform: none !important;
            box-shadow: var(--shadow-sm) !important;
        }
        
        .btn-export-main i {
            margin-right: 0.625rem;
            position: relative;
            z-index: 1;
            font-size: 1.125rem;
        }
        
        .btn-export-main span {
            position: relative;
            z-index: 1;
        }
        
        .btn-export-pdf { 
            background: linear-gradient(135deg, var(--danger), #c82333); 
            box-shadow: var(--shadow-xl);
        }
        
        .btn-export-pdf:hover {
            box-shadow: var(--shadow-2xl), 0 0 40px rgba(220, 53, 69, 0.3);
            background: linear-gradient(135deg, #c82333, #bd2130);
        }

        /* ===========================
           INFO BOX PREMIUM
           =========================== */
        .info-box {
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.05) 0%, rgba(30, 64, 175, 0.02) 100%);
            border-left: 4px solid var(--primary);
            padding: 1.5rem;
            border-radius: var(--radius-xl);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .info-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(30, 64, 175, 0.05) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .info-box:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-left-width: 5px;
        }
        
        .info-box h4 { 
            color: var(--primary); 
            margin-bottom: 0.75rem; 
            font-weight: 700;
            font-size: 1.125rem;
            position: relative;
            z-index: 1;
            letter-spacing: 0.025em;
        }
        
        .info-box p { 
            color: var(--gray-700); 
            margin: 0; 
            line-height: 1.7;
            position: relative;
            z-index: 1;
            font-size: 0.9375rem;
            font-weight: 500;
        }

        /* ===========================
           TEXT MUTED PREMIUM
           =========================== */
        .text-muted {
            color: var(--gray-600) !important;
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.025em;
        }
        
        /* ===========================
           ALERTA DE VALIDACIÓN
           =========================== */
        .validation-alert {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.05));
            border-left: 4px solid #ffc107;
            padding: 1rem 1.25rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-md);
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .validation-alert i {
            color: #ffc107;
            font-size: 1.25rem;
        }
        
        .validation-alert p {
            margin: 0;
            color: #856404;
            font-weight: 600;
            font-size: 0.9375rem;
        }

        /* ===========================
           ACCESIBILIDAD
           =========================== */
        *:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 3px;
            border-radius: var(--radius-md);
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.2);
        }

        .btn-export-main:focus-visible {
            outline-color: white;
            outline-width: 4px;
        }

        /* ===========================
           RESPONSIVE PREMIUM
           =========================== */
        @media(max-width: 768px){
            main {
                padding: 1.5rem 1rem;
                min-height: calc(100vh - 150px);
            }
            
            .export-modal-card { 
                width: 100%; 
                max-width: 100%;
                border-radius: var(--radius-xl);
            }
            
            .export-header { 
                padding: 2rem 1.5rem;
            }
            
            .export-header h1 { 
                font-size: 1.625rem; 
            }
            
            .export-header i {
                font-size: 3.5rem;
            }
            
            .export-body {
                padding: 2rem 1.5rem;
            }
            
            .filters-section {
                padding: 1.5rem;
            }
            
            .btn-export-main { 
                font-size: 0.9375rem; 
                padding: 1rem 2rem; 
            }
            
            .export-button-container { 
                flex-direction: column; 
                align-items: stretch;
                gap: 0.875rem;
            }
            
            .btn-export-main {
                width: 100%;
            }
        }

        @media(max-width: 480px){
            main {
                padding: 1rem 0.75rem;
            }
            
            .export-header {
                padding: 1.75rem 1.25rem;
            }
            
            .export-header h1 {
                font-size: 1.375rem;
            }
            
            .export-header i {
                font-size: 3rem;
            }
            
            .export-body {
                padding: 1.5rem 1.25rem;
            }
            
            .filters-section {
                padding: 1.25rem;
            }
        }
    </style>
</head>
<body>

    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

                <!-- FONDO OSCURO -->
                <div class="modal-backdrop-custom"></div>

                <!-- TARJETA FIJA CENTRADA -->
                <div class="export-modal-card">
                    <div class="export-header">
                        <i class="fas fa-file-export"></i>
                        <h1>Exportar Facturas Pagadas</h1>
                        <p>Descarga un reporte completo en Excel o PDF</p>
                    </div>
                    
                    <div class="export-body">
                        <div class="info-box">
                            <h4><i class="fas fa-info-circle me-2"></i>¿Cómo funciona?</h4>
                            <p>
                                Configura los filtros que desees aplicar y presiona el botón de exportación. 
                                El sistema generará automáticamente un archivo Excel o PDF con todas las facturas pagadas 
                                que coincidan con tus criterios de búsqueda.
                            </p>
                        </div>
                        
                        <?php if (!empty($filter_supplier) || !empty($filter_status) || !empty($filter_date_from) || !empty($filter_date_to)): ?>
                        <div class="filters-section">
                            <h5><i class="fas fa-filter me-2"></i>Filtros Aplicados</h5>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (!empty($filter_supplier)): ?>
                                    <span class="badge bg-primary"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($filter_supplier); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_status)): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($filter_status); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_date_from)): ?>
                                    <span class="badge bg-info"><i class="fas fa-calendar me-1"></i>Desde: <?php echo $filter_date_from; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($filter_date_to)): ?>
                                    <span class="badge bg-info"><i class="fas fa-calendar me-1"></i>Hasta: <?php echo $filter_date_to; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="filters-section">
                            <h5><i class="fas fa-sliders-h me-2"></i>Configurar Filtros</h5>
                            <form method="GET" action="" class="row g-3" id="filterForm">
                                <div class="col-md-6">
                                    <label for="supplier_search" class="form-label">Buscar Proveedor</label>
                                    <div class="search-container">
                                        <input type="text" class="form-control search-input" id="supplier_search" placeholder="Escribe para buscar proveedores..." autocomplete="off">
                                        <i class="fas fa-search search-icon"></i>
                                        <div class="search-results" id="searchResults"></div>
                                    </div>
                                    <input type="hidden" name="filter_supplier" id="filter_supplier" value="<?php echo htmlspecialchars($filter_supplier); ?>">
                                    <?php if (!empty($filter_supplier)): ?>
                                    <div id="selectedSupplierBadge" class="selected-supplier-badge">
                                        <i class="fas fa-building"></i>
                                        <span id="selectedSupplierName"><?php echo htmlspecialchars($filter_supplier); ?></span>
                                        <i class="fas fa-times" onclick="clearSupplier()"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="filter_date_from" class="form-label">Fecha Desde</label>
                                    <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="filter_date_to" class="form-label">Fecha Hasta</label>
                                    <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                </div>
                                
                                <div class="col-12 text-center">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sync-alt me-2"></i>Aplicar Filtros
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Alerta de validación -->
                        <div id="validationAlert" class="validation-alert" style="display: none;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Debe aplicar al menos un filtro antes de exportar</p>
                        </div>
                        
                        <div class="export-button-container">
                            <button type="button" class="btn-export-main" id="exportExcelBtn" disabled>
                                <i class="fas fa-file-excel"></i>
                                <span>Descargar Excel</span>
                            </button>
                            <button type="button" class="btn-export-main btn-export-pdf" id="exportPdfBtn" disabled>
                                <i class="fas fa-file-pdf"></i>
                                <span>Descargar PDF</span>
                            </button>
                        </div>
                        <p class="text-muted text-center mt-4 mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <span id="exportMessage">Aplique filtros para habilitar la exportación</span>
                        </p>
                    </div>
                </div>

            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        // ===========================
        // VALIDACIÓN DE FILTROS
        // ===========================
        function checkFilters() {
            const urlParams = new URLSearchParams(window.location.search);
            const filterSupplier = urlParams.get('filter_supplier') || '';
            const filterStatus = urlParams.get('filter_status') || '';
            const filterDateFrom = urlParams.get('filter_date_from') || '';
            const filterDateTo = urlParams.get('filter_date_to') || '';
            
            const hasFilters = !!(filterSupplier || filterStatus || filterDateFrom || filterDateTo);
            
            const exportExcelBtn = document.getElementById('exportExcelBtn');
            const exportPdfBtn = document.getElementById('exportPdfBtn');
            const validationAlert = document.getElementById('validationAlert');
            const exportMessage = document.getElementById('exportMessage');
            
            if (hasFilters) {
                exportExcelBtn.disabled = false;
                exportPdfBtn.disabled = false;
                validationAlert.style.display = 'none';
                exportMessage.textContent = 'Los archivos se generarán con los filtros aplicados';
            } else {
                exportExcelBtn.disabled = true;
                exportPdfBtn.disabled = true;
                exportMessage.textContent = 'Aplique filtros para habilitar la exportación';
            }
            
            return hasFilters;
        }
        
        // Verificar filtros al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            checkFilters();
        });
        
        // ===========================
        // FUNCIONES ORIGINALES
        // ===========================
        function formatCurrencyCOP(value) {
            const num = parseFloat(value);
            if (isNaN(num)) return '$ 0,00';
            return new Intl.NumberFormat('es-CO', {
                style: 'currency',
                currency: 'COP',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(num);
        }

        const searchInput = document.getElementById('supplier_search');
        const searchResults = document.getElementById('searchResults');
        const filterSupplierInput = document.getElementById('filter_supplier');
        let allSuppliers = [];
        let searchTimeout;
        
        fetch('search-suppliers.php')
            .then(response => response.json())
            .then(data => {
                allSuppliers = data.suppliers || [];
            })
            .catch(error => console.error('Error cargando proveedores:', error));
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim().toLowerCase();
            if (query.length === 0) {
                searchResults.classList.remove('show');
                return;
            }
            searchTimeout = setTimeout(() => {
                const filtered = allSuppliers.filter(supplier => 
                    supplier.toLowerCase().includes(query)
                );
                displaySearchResults(filtered);
            }, 300);
        });
        
        function displaySearchResults(suppliers) {
            if (suppliers.length === 0) {
                searchResults.innerHTML = '<div class="no-results p-3 text-muted">No se encontraron proveedores</div>';
                searchResults.classList.add('show');
                return;
            }
            const html = suppliers.map(supplier => 
                `<div class="search-result-item" onclick="selectSupplier('${supplier.replace(/'/g, "\\'")}')">${supplier}</div>`
            ).join('');
            searchResults.innerHTML = html;
            searchResults.classList.add('show');
        }
        
        function selectSupplier(supplier) {
            filterSupplierInput.value = supplier;
            searchInput.value = '';
            searchResults.classList.remove('show');
            const badgeHtml = `
                <div id="selectedSupplierBadge" class="selected-supplier-badge">
                    <i class="fas fa-building"></i>
                    <span id="selectedSupplierName">${supplier}</span>
                    <i class="fas fa-times" onclick="clearSupplier()"></i>
                </div>
            `;
            const existing = document.getElementById('selectedSupplierBadge');
            if (existing) existing.remove();
            searchInput.parentElement.insertAdjacentHTML('afterend', badgeHtml);
        }
        
        function clearSupplier() {
            filterSupplierInput.value = '';
            const badge = document.getElementById('selectedSupplierBadge');
            if (badge) badge.remove();
        }
        
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('show');
            }
        });
        
        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            if (!checkFilters()) {
                const validationAlert = document.getElementById('validationAlert');
                validationAlert.style.display = 'flex';
                setTimeout(() => {
                    validationAlert.style.display = 'none';
                }, 5000);
                return;
            }
            exportData('excel', this);
        });
        
        document.getElementById('exportPdfBtn').addEventListener('click', function() {
            if (!checkFilters()) {
                const validationAlert = document.getElementById('validationAlert');
                validationAlert.style.display = 'flex';
                setTimeout(() => {
                    validationAlert.style.display = 'none';
                }, 5000);
                return;
            }
            exportData('pdf', this);
        });
        
        function exportData(format, btn) {
            // Validar filtros antes de exportar
            if (!checkFilters()) {
                const validationAlert = document.getElementById('validationAlert');
                validationAlert.style.display = 'flex';
                setTimeout(() => {
                    validationAlert.style.display = 'none';
                }, 5000);
                return;
            }
            
            // Guardar el HTML original del botón
            const originalHTML = btn.innerHTML;
            const originalDisabled = btn.disabled;
            
            // Deshabilitar y mostrar loading
            btn.disabled = true;
            btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i><span>Generando ${format.toUpperCase()}...</span>`;
            
            // Función para restaurar el botón
            const restoreButton = () => {
                btn.disabled = originalDisabled;
                btn.innerHTML = originalHTML;
            };
            
            const urlParams = new URLSearchParams(window.location.search);
            const filters = {
                filter_supplier: urlParams.get('filter_supplier') || '',
                filter_status: urlParams.get('filter_status') || '',
                filter_date_from: urlParams.get('filter_date_from') || '',
                filter_date_to: urlParams.get('filter_date_to') || ''
            };
            
            fetch('export-data.php?' + new URLSearchParams(filters))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en la respuesta del servidor');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        restoreButton();
                        return;
                    }
                    if (!data.invoices || data.invoices.length === 0) {
                        alert('No hay datos para exportar con los filtros aplicados.');
                        restoreButton();
                        return;
                    }
                    
                    // Generar el archivo
                    (async () => {
                        try {
                            if (format === 'excel') {
                                generateExcel(data, filters);
                                // Mostrar éxito
                                btn.innerHTML = `<i class="fas fa-check"></i><span>¡Descargado!</span>`;
                                setTimeout(() => {
                                    restoreButton();
                                }, 2000);
                            } else {
                                await generatePDF(data, filters);
                                // Mostrar éxito
                                btn.innerHTML = `<i class="fas fa-check"></i><span>¡Descargado!</span>`;
                                setTimeout(() => {
                                    restoreButton();
                                }, 2000);
                            }
                        } catch (error) {
                            console.error('Error generando archivo:', error);
                            alert('Error al generar el archivo. Por favor, intente nuevamente.');
                            restoreButton();
                        }
                    })();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al conectar con el servidor. Por favor, intente nuevamente.');
                    restoreButton();
                });
        }

        // === GENERAR EXCEL ===
        function generateExcel(data, filters) {
            try {
                if (typeof XLSX === 'undefined') {
                    throw new Error('La librería XLSX no está cargada');
                }

                const hojaData = [];
                hojaData.push(["REPORTE DE FACTURAS PAGADAS-EGRESOS"]);
                hojaData.push([]);
                hojaData.push(["Fecha de Exportación:", new Date().toLocaleString("es-CO")]);
                hojaData.push(["Total de Proveedores:", data.total_suppliers || 0]);
                hojaData.push(["Total de Facturas:", data.total_invoices || 0]);
                hojaData.push(["Monto Total Pagado:", formatCurrencyCOP(data.total_paid || 0)]);
                hojaData.push([]);

                if (filters.filter_supplier || filters.filter_status || filters.filter_date_from || filters.filter_date_to) {
                    hojaData.push(["FILTROS APLICADOS:"]);
                    if (filters.filter_supplier) hojaData.push(["Proveedor:", filters.filter_supplier]);
                    if (filters.filter_status) hojaData.push(["Estado:", filters.filter_status]);
                    if (filters.filter_date_from) hojaData.push(["Fecha Desde:", filters.filter_date_from]);
                    if (filters.filter_date_to) hojaData.push(["Fecha Hasta:", filters.filter_date_to]);
                    hojaData.push([]);
                }

                hojaData.push([
                    "Proveedor", "N° SAP", "N° Factura", "Fecha Vencimiento",
                    "Fecha de Pago", "Valor Total", "Valor Pagado", "Estado"
                ]);

                if (data.invoices && Array.isArray(data.invoices)) {
                    data.invoices.forEach(invoice => {
                        hojaData.push([
                            invoice.proveedor || '',
                            invoice.n_sap || '',
                            invoice.n_factura || '',
                            invoice.fecha_vencimiento || '',
                            invoice.fecha_pago || '',
                            formatCurrencyCOP(parseFloat(invoice.valor_total) || 0),
                            formatCurrencyCOP(parseFloat(invoice.valor_pagado) || 0),
                            invoice.estado || ''
                        ]);
                    });
                }

                const worksheet = XLSX.utils.aoa_to_sheet(hojaData);
                const headerStyle = { fill: { fgColor: { rgb: "FF4472C4" } }, font: { bold: true, color: { rgb: "FFFFFFFF" }, size: 12 }, alignment: { horizontal: "center" } };
                const headerRow = hojaData.length - (data.invoices ? data.invoices.length : 0) - 1;
                for (let col = 0; col < 8; col++) {
                    const cell = XLSX.utils.encode_cell({ r: headerRow, c: col });
                    if (worksheet[cell]) worksheet[cell].s = headerStyle;
                }

                for (const key in worksheet) {
                    if (key[0] === "!") continue;
                    if (!worksheet[key].s) worksheet[key].s = {};
                    worksheet[key].s.alignment = { horizontal: "center", vertical: "center", wrapText: true };
                    worksheet[key].s.font = { size: 10, color: { rgb: "FF1e40af" } };
                    worksheet[key].s.border = { top: { style: "thin" }, bottom: { style: "thin" }, left: { style: "thin" }, right: { style: "thin" } };
                }

                worksheet["!cols"] = [{ wch: 30 }, { wch: 15 }, { wch: 15 }, { wch: 18 }, { wch: 18 }, { wch: 18 }, { wch: 18 }, { wch: 12 }];
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, "Facturas Pagadas");
                const fecha = new Date().toISOString().split("T")[0];
                XLSX.writeFile(workbook, `Facturas_Pagadas_${fecha}.xlsx`);
            } catch (error) {
                console.error('Error generando Excel:', error);
                throw error;
            }
        }

        // === GENERAR PDF ===
        async function generatePDF(data, filters) {
            try {
                if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
                    throw new Error('La librería jsPDF no está cargada');
                }

                const { jsPDF } = window.jspdf;
                const doc = new jsPDF('l', 'mm', 'a4');
                
                // Colores del tema
                const primaryColor = [30, 64, 175]; // #1e40af
                const secondaryColor = [37, 99, 235]; // #2563eb
                const lightBlue = [219, 234, 254]; // #dbeafe
                const darkBlue = [15, 23, 42]; // #0f172a
                const successColor = [34, 197, 94]; // #22c55e
                
                // === HEADER ELEGANTE CON FONDO ===
                // Fondo azul degradado en el header
                doc.setFillColor(...primaryColor);
                doc.rect(0, 0, 297, 35, 'F');
                
                // Logo centrado y más grande con mejor calidad
                try {
                    // Cargar la imagen con fetch para mejor calidad
                    const response = await fetch('assets/65x45.png');
                    const blob = await response.blob();
                    const imgUrl = URL.createObjectURL(blob);
                    
                    // Crear un canvas temporal para mejorar la calidad del logo
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    
                    await new Promise((resolve, reject) => {
                        img.onload = function() {
                            // Crear un canvas con mayor resolución para mejor calidad
                            const canvas = document.createElement('canvas');
                            const ctx = canvas.getContext('2d');
                            
                            // Aumentar la resolución del canvas (3x para mejor calidad)
                            const scale = 3;
                            canvas.width = img.width * scale;
                            canvas.height = img.height * scale;
                            
                            // Configurar el contexto para mejor calidad
                            ctx.imageSmoothingEnabled = true;
                            ctx.imageSmoothingQuality = 'high';
                            
                            // Dibujar la imagen escalada con mejor calidad
                            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                            
                            // Convertir el canvas a data URL con máxima calidad
                            const imgData = canvas.toDataURL('image/png', 1.0);
                            
                            // Agregar el logo con mejor calidad y tamaño aumentado
                            doc.addImage(imgData, 'PNG', 14, 6, 45, 28, undefined, 'FAST');
                            
                            // Limpiar el objeto URL
                            URL.revokeObjectURL(imgUrl);
                            
                            resolve();
                        };
                        
                        img.onerror = function() {
                            // Si falla la carga, intentar con el método original
                            try {
                                doc.addImage('assets/65x45.png', 'PNG', 14, 8, 45, 28, undefined, 'FAST');
                            } catch (fallbackError) {
                                console.warn('No se pudo cargar la imagen del logo:', fallbackError);
                            }
                            URL.revokeObjectURL(imgUrl);
                            resolve();
                        };
                        
                        img.src = imgUrl;
                    });
                } catch (imgError) {
                    console.warn('No se pudo cargar la imagen del logo:', imgError);
                    // Intentar con el método original como fallback
                    try {
                        doc.addImage('assets/65x45.png', 'PNG', 14, 8, 45, 28, undefined, 'FAST');
                    } catch (fallbackError) {
                        console.warn('No se pudo cargar la imagen del logo con fallback:', fallbackError);
                    }
                }
                
                // Título principal elegante
                doc.setFontSize(22);
                doc.setTextColor(255, 255, 255);
                doc.setFont(undefined, 'bold');
                doc.text('REPORTE DE FACTURAS PAGADAS', 148, 18, { align: 'center' });
                
                doc.setFontSize(12);
                doc.setTextColor(255, 255, 255);
                doc.setFont(undefined, 'normal');
                doc.text('SISTEMA DE EGRESOS', 148, 26, { align: 'center' });
                
                // Línea decorativa debajo del header
                doc.setDrawColor(...secondaryColor);
                doc.setLineWidth(0.5);
                doc.line(14, 38, 283, 38);
                
                // === INFORMACIÓN DEL REPORTE ===
                let yPos = 45;
                
                // Caja de información con fondo
                doc.setFillColor(...lightBlue);
                doc.roundedRect(14, yPos, 269, 30, 3, 3, 'F');
                
                doc.setFontSize(10);
                doc.setTextColor(...darkBlue);
                doc.setFont(undefined, 'bold');
                doc.text('INFORMACIÓN DEL REPORTE', 20, yPos + 7);
                
                doc.setFontSize(9);
                doc.setFont(undefined, 'normal');
                doc.setTextColor(55, 65, 81);
                
                yPos += 10;
                doc.text(`Fecha de Exportación: ${new Date().toLocaleString('es-CO')}`, 20, yPos);
                yPos += 5;
                doc.text(`Total de Proveedores: ${data.total_suppliers || 0}`, 20, yPos);
                yPos += 5;
                doc.text(`Total de Facturas: ${data.total_invoices || 0}`, 20, yPos);
                yPos += 5;
                doc.setFont(undefined, 'bold');
                doc.setTextColor(...primaryColor);
                doc.text(`Monto Total Pagado: ${formatCurrencyCOP(data.total_paid || 0)}`, 20, yPos);
                
                yPos += 12;
                
                // === FILTROS APLICADOS (si existen) ===
                if (filters.filter_supplier || filters.filter_status || filters.filter_date_from || filters.filter_date_to) {
                    doc.setFillColor(255, 255, 255);
                    doc.setDrawColor(...primaryColor);
                    doc.setLineWidth(1);
                    doc.roundedRect(14, yPos, 269, 20, 3, 3, 'FD');
                    
                    doc.setFontSize(10);
                    doc.setTextColor(...primaryColor);
                    doc.setFont(undefined, 'bold');
                    doc.text('FILTROS APLICADOS', 20, yPos + 7);
                    
                    doc.setFontSize(8);
                    doc.setFont(undefined, 'normal');
                    doc.setTextColor(55, 65, 81);
                    
                    let filterY = yPos + 12;
                    if (filters.filter_supplier) { 
                        doc.text(`Proveedor: ${filters.filter_supplier}`, 20, filterY); 
                        filterY += 4; 
                    }
                    if (filters.filter_status) { 
                        doc.text(`Estado: ${filters.filter_status}`, 150, filterY - 4); 
                    }
                    if (filters.filter_date_from) { 
                        doc.text(`Fecha Desde: ${filters.filter_date_from}`, 20, filterY); 
                        filterY += 4; 
                    }
                    if (filters.filter_date_to) { 
                        doc.text(`Fecha Hasta: ${filters.filter_date_to}`, 150, filterY - 4); 
                    }
                    
                    yPos += 25;
                }

                // === TABLA ELEGANTE ===
                const tableData = [];
                if (data.invoices && Array.isArray(data.invoices)) {
                    data.invoices.forEach(invoice => {
                        tableData.push([
                            invoice.proveedor || '',
                            invoice.n_sap || '',
                            invoice.n_factura || '',
                            invoice.fecha_vencimiento || '',
                            invoice.fecha_pago || '',
                            formatCurrencyCOP(parseFloat(invoice.valor_total) || 0),
                            formatCurrencyCOP(parseFloat(invoice.valor_pagado) || 0),
                            invoice.estado || ''
                        ]);
                    });
                }

                if (typeof doc.autoTable === 'function') {
                    // Ancho total disponible: 269mm (297mm - 14mm izquierdo - 14mm derecho)
                    // Distribución optimizada para usar todo el espacio
                    doc.autoTable({
                        startY: yPos,
                        head: [['Proveedor', 'N° SAP', 'N° Factura', 'F. Vencimiento', 'F. Pago', 'Valor Total', 'Valor Pagado', 'Estado']],
                        body: tableData,
                        theme: 'striped',
                        headStyles: { 
                            fillColor: primaryColor, 
                            textColor: [255, 255, 255], 
                            fontStyle: 'bold', 
                            fontSize: 8, 
                            halign: 'center',
                            cellPadding: 2
                        },
                        bodyStyles: { 
                            fontSize: 7, 
                            halign: 'center', 
                            textColor: darkBlue,
                            cellPadding: 1.5
                        },
                        alternateRowStyles: {
                            fillColor: [249, 250, 251]
                        },
                        columnStyles: { 
                            0: { cellWidth: 60, fontStyle: 'bold', halign: 'left' },  // Proveedor - más ancho
                            1: { cellWidth: 25, halign: 'center' },  // N° SAP
                            2: { cellWidth: 28, halign: 'center' },  // N° Factura
                            3: { cellWidth: 30, halign: 'center' },  // F. Vencimiento
                            4: { cellWidth: 30, halign: 'center' },  // F. Pago
                            5: { cellWidth: 35, halign: 'right' },  // Valor Total
                            6: { cellWidth: 35, halign: 'right', fontStyle: 'bold', textColor: successColor },  // Valor Pagado
                            7: { cellWidth: 26, halign: 'center' }  // Estado
                        },
                        margin: { left: 14, right: 14, top: 5 },
                        styles: {
                            lineColor: [229, 231, 235],
                            lineWidth: 0.3
                        },
                        tableLineColor: primaryColor,
                        tableLineWidth: 0.5,
                        tableWidth: 'auto'  // Usar todo el ancho disponible
                    });
                } else {
                    throw new Error('La función autoTable no está disponible');
                }

                // === FOOTER ELEGANTE ===
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    
                    // Línea decorativa en el footer
                    doc.setDrawColor(...primaryColor);
                    doc.setLineWidth(0.5);
                    doc.line(14, 200, 283, 200);
                    
                    // Texto del footer
                    doc.setFontSize(8);
                    doc.setTextColor(107, 114, 128);
                    doc.setFont(undefined, 'normal');
                    doc.text(`Página ${i} de ${pageCount}`, 148, 205, { align: 'center' });
                    doc.text(`Generado el ${new Date().toLocaleString('es-CO')}`, 148, 210, { align: 'center' });
                }

                const fecha = new Date().toISOString().split('T')[0];
                doc.save(`Facturas_Pagadas_${fecha}.pdf`);
            } catch (error) {
                console.error('Error generando PDF:', error);
                throw error;
            }
        }
    </script>
</body>
</html>