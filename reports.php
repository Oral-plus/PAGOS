<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user role
$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$role = $user['role'];

// Get report type
$report_type = isset($_GET['type']) ? $_GET['type'] : 'monthly';

// Get report data
$conn = getDbConnection();
$report_data = [];
$chart_data = [];

if ($report_type == 'monthly') {
    // Monthly report - invoices by month
    $current_year = date('Y');
    
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("
            SELECT 
                MONTH(fecha_vencimiento) as month, 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'completado' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rechazado' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status NOT IN ('completado', 'rechazado') THEN 1 ELSE 0 END) as pending,
                SUM(monto_linea) as total_amount
            FROM invoices 
            WHERE YEAR(fecha_vencimiento) = :year
            GROUP BY MONTH(fecha_vencimiento)
            ORDER BY MONTH(fecha_vencimiento)
        ");
        $stmt->bindParam(':year', $current_year);
        $stmt->execute();
        $report_data = $stmt->fetchAll();
    } else {
        // SQL Server version
        $sql = "
            SELECT 
                MONTH(fecha_vencimiento) as month, 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'completado' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rechazado' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status NOT IN ('completado', 'rechazado') THEN 1 ELSE 0 END) as pending,
                SUM(monto_linea) as total_amount
            FROM invoices 
            WHERE YEAR(fecha_vencimiento) = ?
            GROUP BY MONTH(fecha_vencimiento)
            ORDER BY MONTH(fecha_vencimiento)
        ";
        $params = array($current_year);
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $report_data = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $report_data[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // Prepare chart data
    $months = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    
    $chart_labels = [];
    $chart_values_approved = [];
    $chart_values_rejected = [];
    $chart_values_pending = [];
    
    foreach ($report_data as $row) {
        $chart_labels[] = $months[$row['month']];
        $chart_values_approved[] = $row['approved'];
        $chart_values_rejected[] = $row['rejected'];
        $chart_values_pending[] = $row['pending'];
    }
    
    $chart_data = [
        'labels' => json_encode($chart_labels),
        'approved' => json_encode($chart_values_approved),
        'rejected' => json_encode($chart_values_rejected),
        'pending' => json_encode($chart_values_pending)
    ];
} elseif ($report_type == 'supplier') {
    // Supplier report - invoices by supplier
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("
            SELECT 
                nombre as supplier_name,
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'completado' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rechazado' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status NOT IN ('completado', 'rechazado') THEN 1 ELSE 0 END) as pending,
                SUM(monto_linea) as total_amount
            FROM invoices 
            GROUP BY nombre
            ORDER BY COUNT(*) DESC
            LIMIT 10
        ");
        $stmt->execute();
        $report_data = $stmt->fetchAll();
    } else {
        // SQL Server version
        $sql = "
            SELECT TOP 10
                nombre as supplier_name,
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'completado' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rechazado' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status NOT IN ('completado', 'rechazado') THEN 1 ELSE 0 END) as pending,
                SUM(monto_linea) as total_amount
            FROM invoices 
            GROUP BY nombre
            ORDER BY COUNT(*) DESC
        ";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            throw new Exception("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
        
        $report_data = array();
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $report_data[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // Prepare chart data
    $chart_labels = [];
    $chart_values = [];
    
    foreach ($report_data as $row) {
        $chart_labels[] = $row['supplier_name'];
        $chart_values[] = $row['total_amount'];
    }
    
    $chart_data = [
        'labels' => json_encode($chart_labels),
        'values' => json_encode($chart_values)
    ];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="assets/65x45.png" type="image/x-icon">
    <title>Reportes - Sistema de Aprobación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar Container -->
            <div class="sidebar-container">
                <!-- Botón para mostrar/ocultar sidebar en móviles -->
                <button id="sidebarToggle" class="sidebar-toggle-btn">
                    <i class="fas fa-bars"></i>
                </button>

                <nav id="sidebarMenu" class="sidebar">
                    <div class="position-sticky pt-3">
                        <!-- Logo de la empresa -->
                        <div class="text-center mb-3">
                            <?php if (file_exists('assets/65x45.png')): ?>
                                <img src="assets/65x45.png" alt="Logo de la empresa" class="img-fluid sidebar-logo">
                            <?php else: ?>
                                <div class="sidebar-logo-placeholder">
                                    <i class="fas fa-building"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Información del usuario -->
                        <div class="user-info mb-3">
                            <div class="d-flex align-items-center p-3 bg-white rounded shadow-sm">
                                <div class="user-avatar">
                                    <i class="fas fa-user-circle"></i>
                                </div>
                                <div class="ms-2 flex-grow-1">
                                    <div class="fw-bold"><?php echo $user['name']; ?></div>
                                    <small class="text-muted"><?php echo ucfirst($role); ?></small>
                                </div>
                                <a href="logout.php" class="btn btn-sm btn-outline-danger" title="Cerrar sesión">
                                    <i class="fas fa-sign-out-alt"></i>
                                </a>
                            </div>
                        </div>
                        
                        <ul class="nav flex-column">
                        <?php if (in_array($role, ['admin', 'Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="nav-text">Facturas a Pagar</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'add_invoice.php' ? 'active' : ''; ?>" href="add_invoice.php">
                        <i class="fas fa-plus-circle"></i>
                        <span class="nav-text">Nueva Factura</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['subgerente','contador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pending_approvals.php' ? 'active' : ''; ?>" href="pending_approvals.php">
                        <i class="fas fa-clock"></i>
                        <span class="nav-text">Pendientes de Aprobación</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['gerente'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'approved_invoices.php' ? 'active' : ''; ?>" href="approved_invoices.php">
                        <i class="fas fa-check-circle"></i>
                        <span class="nav-text">Facturas Aprobadas</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['contador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'rejected_invoices.php' ? 'active' : ''; ?>" href="rejected_invoices.php">
                        <i class="fas fa-times-circle"></i>
                        <span class="nav-text">Facturas Rechazadas</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['contador', 'gerente'])): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $current_page == 'pago.php' ? 'active' : ''; ?>" href="pago.php">
            <i class="fas"></i>
            <span class="nav-text">Pago final</span>
        </a>
    </li>
                            <?php endif; ?>
                        </ul>
                        
                        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                            <span>Reportes</span>
                        </h6>
                        <ul class="nav flex-column mb-2">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'monthly' ? 'active' : ''; ?>" href="reports.php?type=monthly">
                                    <i class="fas fa-chart-bar"></i>
                                    <span class="nav-text">Reporte Mensual</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'supplier' ? 'active' : ''; ?>" href="reports.php?type=supplier">
                                    <i class="fas fa-building"></i>
                                    <span class="nav-text">Por Proveedor</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </nav>
                
                <!-- Overlay para cerrar el sidebar en móviles -->
                <div id="sidebarOverlay" class="sidebar-overlay"></div>
            </div>
            
            <!-- Main content -->
            <main class="main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Reportes</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="reports.php?type=monthly" class="btn btn-sm <?php echo $report_type == 'monthly' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <i class="fas fa-calendar-alt me-1"></i> Mensual
                            </a>
                            <a href="reports.php?type=supplier" class="btn btn-sm <?php echo $report_type == 'supplier' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <i class="fas fa-building me-1"></i> Por Proveedor
                            </a>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Imprimir
                        </button>
                    </div>
                </div>
                
                <?php if ($report_type == 'monthly'): ?>
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Facturas por Mes (<?php echo date('Y'); ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <canvas id="monthlyChart" height="300"></canvas>
                                </div>
                                <div class="col-md-4">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr class="table-light">
                                                    <th>Mes</th>
                                                    <th>Facturas</th>
                                                    <th>Monto Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_invoices = 0;
                                                $total_amount = 0;
                                                
                                                foreach ($report_data as $row): 
                                                    $total_invoices += $row['total_invoices'];
                                                    $total_amount += $row['total_amount'];
                                                ?>
                                                    <tr>
                                                        <td><?php echo $months[$row['month']]; ?></td>
                                                        <td><?php echo $row['total_invoices']; ?></td>
                                                        <td>$<?php echo number_format($row['total_amount'], 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-light">
                                                    <th>Total</th>
                                                    <th><?php echo $total_invoices; ?></th>
                                                    <th>$<?php echo number_format($total_amount, 2, ',', '.'); ?></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Detalles por Mes</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Mes</th>
                                            <th>Total Facturas</th>
                                            <th>Aprobadas</th>
                                            <th>Rechazadas</th>
                                            <th>Pendientes</th>
                                            <th>Monto Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <td><?php echo $months[$row['month']]; ?></td>
                                                <td><?php echo $row['total_invoices']; ?></td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo $row['approved']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger"><?php echo $row['rejected']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo $row['pending']; ?></span>
                                                </td>
                                                <td>$<?php echo number_format($row['total_amount'], 2, ',', '.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                        // Monthly chart
                        var ctx = document.getElementById('monthlyChart').getContext('2d');
                        var monthlyChart = new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: <?php echo $chart_data['labels']; ?>,
                                datasets: [
                                    {
                                        label: 'Aprobadas',
                                        data: <?php echo $chart_data['approved']; ?>,
                                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                        borderColor: 'rgba(40, 167, 69, 1)',
                                        borderWidth: 1
                                    },
                                    {
                                        label: 'Rechazadas',
                                        data: <?php echo $chart_data['rejected']; ?>,
                                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                        borderColor: 'rgba(220, 53, 69, 1)',
                                        borderWidth: 1
                                    },
                                    {
                                        label: 'Pendientes',
                                        data: <?php echo $chart_data['pending']; ?>,
                                        backgroundColor: 'rgba(255, 193, 7, 0.7)',
                                        borderColor: 'rgba(255, 193, 7, 1)',
                                        borderWidth: 1
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            precision: 0
                                        }
                                    }
                                }
                            }
                        });
                    </script>
                <?php elseif ($report_type == 'supplier'): ?>
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Top 10 Proveedores por Monto</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <canvas id="supplierChart" height="300"></canvas>
                                </div>
                                <div class="col-md-4">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr class="table-light">
                                                    <th>Proveedor</th>
                                                    <th>Monto Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_amount = 0;
                                                
                                                foreach ($report_data as $row): 
                                                    $total_amount += $row['total_amount'];
                                                ?>
                                                    <tr>
                                                        <td><?php echo $row['supplier_name']; ?></td>
                                                        <td>$<?php echo number_format($row['total_amount'], 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-light">
                                                    <th>Total</th>
                                                    <th>$<?php echo number_format($total_amount, 2, ',', '.'); ?></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Detalles por Proveedor</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Total Facturas</th>
                                            <th>Aprobadas</th>
                                            <th>Rechazadas</th>
                                            <th>Pendientes</th>
                                            <th>Monto Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <td><?php echo $row['supplier_name']; ?></td>
                                                <td><?php echo $row['total_invoices']; ?></td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo $row['approved']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger"><?php echo $row['rejected']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo $row['pending']; ?></span>
                                                </td>
                                                <td>$<?php echo number_format($row['total_amount'], 2, ',', '.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                        // Supplier chart
                        var ctx = document.getElementById('supplierChart').getContext('2d');
                        var supplierChart = new Chart(ctx, {
                            type: 'pie',
                            data: {
                                labels: <?php echo $chart_data['labels']; ?>,
                                datasets: [{
                                    data: <?php echo $chart_data['values']; ?>,
                                    backgroundColor: [
                                        'rgba(54, 162, 235, 0.7)',
                                        'rgba(255, 99, 132, 0.7)',
                                        'rgba(255, 206, 86, 0.7)',
                                        'rgba(75, 192, 192, 0.7)',
                                        'rgba(153, 102, 255, 0.7)',
                                        'rgba(255, 159, 64, 0.7)',
                                        'rgba(199, 199, 199, 0.7)',
                                        'rgba(83, 102, 255, 0.7)',
                                        'rgba(40, 159, 64, 0.7)',
                                        'rgba(210, 199, 199, 0.7)'
                                    ],
                                    borderColor: [
                                        'rgba(54, 162, 235, 1)',
                                        'rgba(255, 99, 132, 1)',
                                        'rgba(255, 206, 86, 1)',
                                        'rgba(75, 192, 192, 1)',
                                        'rgba(153, 102, 255, 1)',
                                        'rgba(255, 159, 64, 1)',
                                        'rgba(199, 199, 199, 1)',
                                        'rgba(83, 102, 255, 1)',
                                        'rgba(40, 159, 64, 1)',
                                        'rgba(210, 199, 199, 1)'
                                    ],
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'right',
                                    }
                                }
                            }
                        });
                    </script>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <style>
        .table-responsive {
    overflow-x: auto;
}
.table td, .table th {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
}

     /* Variables de colores */
:root {
    --primary-color: #003b7a;
    --primary-light: #0056b3;
    --primary-dark: #002a5a;
    --accent-color: #0077cc;
    --text-color: #333333;
    --text-light: #6c757d;
    --bg-light: #f8f9fa;
    --bg-white: #ffffff;
    --border-color: #e4e6ef;
    --active-item: rgba(0, 59, 122, 0.1);
    --hover-item: rgba(0, 59, 122, 0.05);
    --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
    --transition: all 0.3s ease;
    --sidebar-width: 250px; /* Reducido de 280px */
}

/* Estilos generales */
body {
    font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
    font-size: .875rem;
    margin: 0;
    padding: 0;
    background-color: #f5f7fa;
}

/* Contenedor principal */
.wrapper {
    display: flex;
    min-height: 100vh;
}

/* Sidebar principal */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    height: 100%;
    width: var(--sidebar-width);
    background-color: var(--bg-white);
    box-shadow: var(--shadow-md);
    z-index: 1000;
    transition: var(--transition);
}

.sidebar-content {
    height: 100%;
    overflow-y: auto;
    padding: 0 0 20px 0;
    scrollbar-width: thin;
    scrollbar-color: #cfd8dc var(--bg-light);
}

/* Scrollbar personalizado */
.sidebar-content::-webkit-scrollbar {
    width: 4px; /* Más delgado */
}

.sidebar-content::-webkit-scrollbar-track {
    background: var(--bg-light);
}

.sidebar-content::-webkit-scrollbar-thumb {
    background-color: #cfd8dc;
    border-radius: 20px;
}

/* Sidebar colapsado */
.sidebar.collapsed {
    transform: translateX(-100%);
}

/* Contenido principal */
.main-content {
    margin-left: var(--sidebar-width);
    flex-grow: 1;
    transition: var(--transition);
}

.main-content.expanded {
    margin-left: 0;
}

/* Encabezado del sidebar */
.sidebar-header {
    padding: 15px 15px 10px; /* Reducido */
    background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
    color: white;
    position: relative;
}

.sidebar-header:after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(to right, var(--primary-color), var(--accent-color));
}

.sidebar-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* Logo */
.sidebar-logo {
    max-width: 80px; /* Reducido */
    max-height: 60px; /* Reducido */
    margin: 0 auto;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

/* Información del usuario */
.user-info {
    padding: 0 15px; /* Reducido */
    margin-top: 10px;
}

.user-card {
    background-color: var(--bg-light);
    border-radius: 8px;
    padding: 10px;
    display: flex;
    align-items: center;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.user-avatar {
    font-size: 1.8rem;
    color: var(--primary-color);
    margin-right: 10px;
}

.user-details {
    flex-grow: 1;
}

.user-name {
    font-weight: 600;
    color: var(--text-color);
    font-size: 0.9rem;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}

.user-role {
    color: var(--text-light);
    font-size: 0.75rem;
    text-transform: capitalize;
}

.logout-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background-color: #fff1f0;
    color: #ff4d4f;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: var(--transition);
    border: none;
    cursor: pointer;
}

/* Divisor de secciones */
.sidebar-divider {
    padding: 0 15px;
    margin: 12px 0 8px;
    display: flex;
    align-items: center;
    color: var(--text-light);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.sidebar-divider:after {
    content: '';
    flex-grow: 1;
    height: 1px;
    background-color: var(--border-color);
    margin-left: 8px;
}

/* Navegación */
.sidebar-nav {
    padding: 0 8px;
}

.sidebar-nav .nav-item {
    margin-bottom: 4px;
}

.sidebar-nav .nav-link {
    padding: 8px 12px;
    color: var(--text-color);
    display: flex;
    align-items: center;
    border-radius: 8px;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.sidebar-nav .nav-link:hover {
    color: var(--primary-color);
    background-color: var(--hover-item);
}

.sidebar-nav .nav-link.active {
    background-color: var(--active-item);
    color: var(--primary-color);
    font-weight: 600;
}

.nav-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background-color: rgba(0, 59, 122, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    transition: var(--transition);
}

.sidebar-nav .nav-link:hover .nav-icon {
    background-color: var(--primary-color);
    color: white;
}

.sidebar-nav .nav-link.active .nav-icon {
    background-color: var(--primary-color);
    color: white;
}

.nav-icon i {
    font-size: 0.9rem;
}

.nav-text {
    font-size: 0.9rem;
}

/* Footer del sidebar */
.sidebar-footer {
    padding: 10px 15px;
    text-align: center;
    color: var(--text-light);
    font-size: 0.7rem;
    border-top: 1px solid var(--border-color);
    margin-top: 15px;
}

/* Toggle botón */
.sidebar-toggle-btn {
    position: fixed;
    top: 10px;
    left: 10px;
    z-index: 1050;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
    color: white;
    border: none;
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
}

/* Overlay */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 999;
    display: none;
    transition: var(--transition);
}

.sidebar-overlay.active {
    display: block;
}

/* Responsive */
@media (max-width: 767.98px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
        width: 100%;
    }
    
    .sidebar-toggle-btn {
        display: flex;
    }
}

@media (min-width: 768px) {
    .sidebar-toggle-btn {
        display: none;
    }
}

/* Animaciones suavizadas */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.sidebar-nav .nav-link {
    animation: fadeIn 0.3s ease forwards;
}
    </style>
    
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container text-center">
            <span class="text-muted">Sistema de Aprobación de Facturas © <?php echo date('Y'); ?></span>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Script para el sidebar colapsable
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebarMenu');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                mainContent.classList.toggle('expanded');
            });
            
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                mainContent.classList.remove('expanded');
            });
        });
    </script>
</body>
</html>