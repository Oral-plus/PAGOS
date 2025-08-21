<?php
// Obtener la página actual para marcar el ítem activo
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar-container">
    <!-- Botón para mostrar/ocultar sidebar en móviles -->
    <button id="sidebarToggle" class="sidebar-toggle-btn">
        <i class="fas fa-bars"></i>
    </button>

    <nav id="sidebarMenu" class="sidebar">
        <div class="position-sticky pt-3">
            <!-- Logo de la empresa -->
            <div class="text-center mb-4 sidebar-header">
                <?php if (file_exists('assets/65x45.png')): ?>
                    <img src="assets/65x45.png" alt="Logo de la empresa" class="img-fluid sidebar-logo">
                <?php else: ?>
                    <div class="sidebar-logo-placeholder">
                        <i class="fas fa-building"></i>
                    </div>
                <?php endif; ?>
                <h5 class="sidebar-title mt-3">Sistema de Facturas</h5>
            </div>
            
            <!-- Información del usuario -->
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
                    </a>
                </div>
            </div>
            
            <div class="sidebar-divider">
                <span>Menú Principal</span>
            </div>
            
            <ul class="nav flex-column sidebar-nav">
                <?php if (in_array($role, ['admin', 'Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                        <div class="nav-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <span class="nav-text">Facturas a Pagar</span>
                    </a>
                </li>
                <?php endif; ?>
                      <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'escaneados.php' ? 'active' : ''; ?>" href="escaneados.php">
                        <div class="nav-icon">
                            <i class="fas fa-camera"></i>
                        </div>
                        <span class="nav-text">Escaneados</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'add_invoice.php' ? 'active' : ''; ?>" href="add_invoice.php">
                        <div class="nav-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <span class="nav-text">Nueva Factura</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['subgerente','contador','verificador','admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pending_approvals.php' ? 'active' : ''; ?>" href="pending_approvals.php">
                        <div class="nav-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <span class="nav-text">Pendientes de Aprobación</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['gerente','admin','contador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'approved_invoices.php' ? 'active' : ''; ?>" href="approved_invoices.php">
                        <div class="nav-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <span class="nav-text">Facturas Aprobadas</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['contador','admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'rejected_invoices.php' ? 'active' : ''; ?>" href="rejected_invoices.php">
                        <div class="nav-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <span class="nav-text">Facturas Rechazadas</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if (in_array($role, ['contador', 'gerente','admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pago.php' ? 'active' : ''; ?>" href="pago.php">
                        <div class="nav-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span class="nav-text">Pago final</span>
                    </a>
                </li>
                <?php endif; ?>
                     <?php if (in_array($role, ['admin','contador','verificador','subgerente','gerente','Preparador'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'pagos.php' ? 'active' : ''; ?>" href="pagos.php">
                        <div class="nav-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span class="nav-text">Pagos Realizados</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (in_array($role, ['admin',])): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'ver_escaneados.php' ? 'active' : ''; ?>" href="ver_escaneados.php">
                        <div class="nav-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
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
    
    <!-- Overlay para cerrar el sidebar en móviles -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
</div>

<style>
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
    z-index: 1200;
    transition: var(--transition);
}





.main-content.expanded {
    margin-left: 0;
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


/* Animaciones suavizadas */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.sidebar-nav .nav-link {
    animation: fadeIn 0.3s ease forwards;
}
</style>

<script>
document.addEventListener("DOMContentLoaded", () => {
    // Elementos del DOM
    const sidebar = document.getElementById("sidebarMenu");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const sidebarOverlay = document.getElementById("sidebarOverlay");
    const mainContent = document.querySelector(".col-md-9.ms-sm-auto.col-lg-10.px-md-4");

    // Función para verificar si es móvil
    function isMobile() {
        return window.innerWidth < 768;
    }

    // Inicializar el estado del sidebar
    function initSidebar() {
        if (isMobile()) {
            sidebar.classList.remove("active");
            if (mainContent) mainContent.classList.add("expanded");
        } else {
            sidebar.classList.remove("collapsed");
            if (mainContent) mainContent.classList.remove("expanded");
        }
    }

    // Alternar el estado del sidebar
    function toggleSidebar() {
        if (isMobile()) {
            sidebar.classList.toggle("active");
            sidebarOverlay.classList.toggle("active");
            
            if (sidebar.classList.contains("active")) {
                document.body.style.overflow = "hidden"; // Prevenir scroll
            } else {
                document.body.style.overflow = ""; // Restaurar scroll
            }
        } else {
            sidebar.classList.toggle("collapsed");
            if (mainContent) mainContent.classList.toggle("expanded");
        }
    }

    // Event listeners
    if (sidebarToggle) {
        sidebarToggle.addEventListener("click", toggleSidebar);
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener("click", toggleSidebar);
    }

    // Cerrar sidebar al hacer clic en un enlace (en móvil)
    if (sidebar) {
        const navLinks = sidebar.querySelectorAll(".nav-link");
        navLinks.forEach((link) => {
            link.addEventListener("click", () => {
                if (isMobile() && sidebar.classList.contains("active")) {
                    toggleSidebar();
                }
            });
        });
    }

    // Efecto hover en los elementos del menú
    const navItems = document.querySelectorAll('.sidebar-nav .nav-link');
    navItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(5px)';
        });
        
        item.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });

    // Ajustar al cambiar el tamaño de la ventana
    window.addEventListener("resize", initSidebar);

    // Inicializar
    initSidebar();
});
</script>