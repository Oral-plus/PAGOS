<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar-container">
    <button id="sidebarToggle" class="sidebar-toggle-btn">
        <i class="fas fa-bars"></i>
    </button>

    <nav id="sidebarMenu" class="sidebar">
        <div class="position-sticky pt-3">
            <!-- Logo -->
            <div class="text-center mb-4 sidebar-header">
                <?php if (file_exists('assets/65x45.png')): ?>
                    <img src="assets/65x45.png" alt="Logo" class="img-fluid sidebar-logo">
                <?php else: ?>
                    <div class="sidebar-logo-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
                <h5 class="sidebar-title mt-3">Sistema de Facturas</h5>
            </div>
            
            <!-- Usuario -->
            <div class="user-info mb-4">
                <div class="user-card">
                    <div class="user-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo $user['name']; ?></div>
                        <div class="user-role"><?php echo ucfirst($role); ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn" title="Cerrar sesión">
                        <i class="fas fa-sign-out-alt"></i>
                    </afecha>
                </div>
            </div>
            
        
            
            <ul class="nav flex-column sidebar-nav">
                <?php if (in_array($role, ['admin', 'Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <div class="nav-icon"><i class="fas fa-tachometer-alt"></i></div>
                        <span class="nav-text">Facturas a Pagar</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'escaneados.php' ? 'active' : ''; ?>" href="escaneados.php">
                        <div class="nav-icon"><i class="fas fa-camera"></i></div>
                        <span class="nav-text">Escaneados</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'add_invoice.php' ? 'active' : ''; ?>" href="add_invoice.php">
                        <div class="nav-icon"><i class="fas fa-plus-circle"></i></div>
                        <span class="nav-text">Nueva Factura</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['subgerente','contador','verificador','admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pending_approvals.php' ? 'active' : ''; ?>" href="pending_approvals.php">
                        <div class="nav-icon"><i class="fas fa-clock"></i></div>
                        <span class="nav-text">Pendientes de Aprobación</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['gerente','admin','contador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'approved_invoices.php' ? 'active' : ''; ?>" href="approved_invoices.php">
                        <div class="nav-icon"><i class="fas fa-check-circle"></i></div>
                        <span class="nav-text">Facturas Aprobadas</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['contador','admin','Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'rejected_invoices.php' ? 'active' : ''; ?>" href="rejected_invoices.php">
                        <div class="nav-icon"><i class="fas fa-times-circle"></i></div>
                        <span class="nav-text">Facturas Rechazadas</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['contador', 'gerente','admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pago.php' ? 'active' : ''; ?>" href="pago.php">
                        <div class="nav-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <span class="nav-text">Pago final</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['subgerente','gerente','admin','contador','Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page == 'Aprovacion.php') ? 'active' : ''; ?>" 
                       href="http://192.168.2.242:8080/Aprovacion_ordenes/Aprovacion.php" target="_blank">
                        <div class="nav-icon"><i class="fas fa-file-invoice"></i></div>
                        <span class="nav-text">Órdenes Aprobadas</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin','contador','verificador','subgerente','gerente','Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pagos.php' ? 'active' : ''; ?>" href="pagos.php">
                        <div class="nav-icon"><i class="fas fa-receipt"></i></div>
                        <span class="nav-text">Pagos Realizados</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'ver_escaneados.php' ? 'active' : ''; ?>" href="ver_escaneados.php">
                        <div class="nav-icon"><i class="fas fa-images"></i></div>
                        <span class="nav-text">Ver Facturas Escaneadas</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            
            <div class="sidebar-footer">
                <p>© <?php echo date('Y'); ?> Sistema de Facturas</p>
            </div>
        </div>
    </nav>
    
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
</div>

<style>
/* === MINIMALISTA + ELEGANTE === */
:root {
    --primary: #003b7a;
    --primary-light: #0056b3;
    --text: #2d3748;
    --text-light: #718096;
    --bg: #f9fafb;
    --white: #ffffff;
    --border: #e2e8f0;
    --sidebar-width: 250px;
    --radius: 12px;
    --transition: all 0.25s ease;
}

.sidebar {
    position: fixed;
    top: 0; left: 0;
    width: var(--sidebar-width);
    height: 100vh;
    background: var(--white);
    border-right: 1px solid var(--border);
    z-index: 1200;
    overflow-y: auto;
    transition: var(--transition);
}

.sidebar-header {
    padding: 1.5rem 1rem 1rem;
    text-align: center;
    border-bottom: 1px solid var(--border);
}
.sidebar-logo { max-height: 48px; }
.sidebar-title { font-weight: 600; color: var(--primary); margin-top: 0.75rem; font-size: 1.1rem; }

.user-card {
    margin: 1.25rem 1rem;
    padding: 0.75rem 1rem;
    background: #f8f9fc;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    border: 1px solid var(--border);
}
.user-avatar { font-size: 2.2rem; color: var(--primary); margin-right: 0.75rem; }
.user-name { font-weight: 600; font-size: 0.95rem; color: var(--text); }
.user-role { font-size: 0.8rem; color: var(--text-light); }
.logout-btn {
    margin-left: auto;
    width: 32px; 
    background: #fee2e2;
    color: #dc2626;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    transition: var(--transition);
}
.logout-btn:hover { background: #fecaca; }

.sidebar-divider {
    margin: 1.5rem 1rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-light);
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border);
}

.sidebar-nav .nav-item { margin: 0.25rem 0.75rem; }
.nav-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    border-radius: var(--radius);
    color: var(--text);
    font-weight: 500;
    transition: var(--transition);
}
.nav-link:hover {
    background: rgba(0, 59, 122, 0.06);
    color: var(--primary);
}
.nav-link.active {
    background: rgba(0, 59, 122, 0.1);
    color: var(--primary);
    font-weight: 600;
}
.nav-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: rgba(0, 59, 122, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.75rem;
    font-size: 1.05rem;
    color: var(--primary);
    transition: var(--transition);
}
.nav-link:hover .nav-icon,
.nav-link.active .nav-icon {
    background: var(--primary);
    color: white;
}

.sidebar-footer {
    margin-top: auto;
    padding: 1rem;
    text-align: center;
    font-size: 0.8rem;
    color: var(--text-light);
    border-top: 1px solid var(--border);
}

/* Botón móvil */
.sidebar-toggle-btn {
    position: fixed;
    top: 1rem; left: 1rem;
    z-index: 1300;
    background: var(--primary);
    color: white;
    width: 44px; height: 44px;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 4px 12px rgba(0, 59, 122, 0.2);
    transition: var(--transition);
}
.sidebar-toggle-btn:hover { background: var(--primary-light); transform: scale(1.05); }

/* Responsive */
@media (max-width: 767.98px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.active { transform: translateX(0); }
    .sidebar-toggle-btn { display: flex; }
}
</style>

<!-- TU SCRIPT ORIGINAL (SIN CAMBIOS) -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const sidebar = document.getElementById("sidebarMenu");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const sidebarOverlay = document.getElementById("sidebarOverlay");
    const mainContent = document.querySelector(".col-md-9.ms-sm-auto.col-lg-10.px-md-4");

    function isMobile() { return window.innerWidth < 768; }

    function initSidebar() {
        if (isMobile()) {
            sidebar.classList.remove("active");
            if (mainContent) mainContent.classList.add("expanded");
        } else {
            sidebar.classList.remove("collapsed");
            if (mainContent) mainContent.classList.remove("expanded");
        }
    }

    function toggleSidebar() {
        if (isMobile()) {
            sidebar.classList.toggle("active");
            sidebarOverlay.classList.toggle("active");
            document.body.style.overflow = sidebar.classList.contains("active") ? "hidden" : "";
        } else {
            sidebar.classList.toggle("collapsed");
            if (mainContent) mainContent.classList.toggle("expanded");
        }
    }

    sidebarToggle?.addEventListener("click", toggleSidebar);
    sidebarOverlay?.addEventListener("click", toggleSidebar);

    sidebar.querySelectorAll(".nav-link").forEach(link => {
        link.addEventListener("click", () => {
            if (isMobile() && sidebar.classList.contains("active")) toggleSidebar();
        });
    });

    window.addEventListener("resize", initSidebar);
    initSidebar();
});
</script>