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

// Check if user has admin privileges
if ($role !== 'admin') {
    $_SESSION['error_message'] = "No tiene permisos para acceder a esta página.";
    header("Location: index.php");
    exit();
}

// Get all users
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM users ORDER BY name");
$stmt->execute();
$users = $stmt->fetchAll();

// Process user deletion
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    
    // Prevent deleting own account
    if ($delete_id == $user_id) {
        $_SESSION['error_message'] = "No puede eliminar su propia cuenta.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
        $stmt->bindParam(':id', $delete_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Usuario eliminado correctamente.";
        } else {
            $_SESSION['error_message'] = "Error al eliminar el usuario.";
        }
    }
    
    header("Location: users.php");
    exit();
}

// Helper function for role badge class
function getRoleBadgeClass($role) {
    switch ($role) {
        case 'admin':
            return 'bg-danger';
        case 'subgerente':
            return 'bg-primary';
        case 'gerente':
            return 'bg-success';
        case 'contador':
            return 'bg-info';
        default:
            return 'bg-secondary';
    }
}
?>

<?php include 'includes/header.php'; ?>

<!-- Contenido principal -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestión de Usuarios</h1>
    <a href="add_user.php" class="btn btn-primary">
        <i class="fas fa-user-plus me-1"></i> Nuevo Usuario
    </a>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Listado de Usuarios</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Correo Electrónico</th>
                        <th>Rol</th>
                        <th>Fecha de Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo $u['id']; ?></td>
                            <td><?php echo $u['name']; ?></td>
                            <td><?php echo $u['email']; ?></td>
                            <td>
                                <span class="badge <?php echo getRoleBadgeClass($u['role']); ?>">
                                    <?php echo ucfirst($u['role']); ?>
                                </span>
                            </td>
                            <td><?php echo formatDateTime($u['created_at']); ?></td>
                            <td>
                                <div class="btn-group">
                                    <a href="edit_user.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-primary" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($u['id'] != $user_id): ?>
                                    <a href="users.php?delete=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger" title="Eliminar" onclick="return confirm('¿Está seguro de eliminar este usuario?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div><style>/* Estilos generales */
body {
  font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #f8f9fa;
}

/* Estilos para las tarjetas */
.card {
  margin-bottom: 1.5rem;
  border: none;
  border-radius: 0.5rem;
  box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.card-header {
  border-radius: 0.5rem 0.5rem 0 0 !important;
  font-weight: 500;
}

.card-header.bg-primary {
  background-color: #0d6efd !important;
}

/* Estilos para las tablas */
.table {
  margin-bottom: 0;
}

.table th {
  font-weight: 600;
  background-color: #f8f9fa;
}

.table-striped tbody tr:nth-of-type(odd) {
  background-color: rgba(0, 0, 0, 0.02);
}

.table-hover tbody tr:hover {
  background-color: rgba(0, 0, 0, 0.04);
}

/* Estilos para los badges */
.badge {
  padding: 0.5em 0.75em;
  font-weight: 500;
  border-radius: 0.25rem;
}

/* Estilos para los botones */
.btn-primary {
  background-color: #0d6efd;
  border-color: #0d6efd;
}

.btn-primary:hover {
  background-color: #0b5ed7;
  border-color: #0a58ca;
}

.btn-outline-secondary {
  color: #6c757d;
  border-color: #6c757d;
}

.btn-outline-secondary:hover {
  color: #fff;
  background-color: #6c757d;
  border-color: #6c757d;
}

/* Estilos para el footer */
.footer {
  margin-top: auto;
  background-color: #f8f9fa;
  border-top: 1px solid #dee2e6;
  padding: 1rem 0;
  text-align: center;
}

/* Estilos para impresión */
@media print {
  .sidebar,
  .sidebar-toggle-btn,
  .no-print {
    display: none !important;
  }

  .main-content {
    margin-left: 0 !important;
    width: 100% !important;
    padding: 0 !important;
  }

  .card {
    break-inside: avoid;
  }

  body {
    background-color: #fff !important;
  }
}

/* Estilos para alertas */
.alert {
  border-radius: 0.5rem;
  margin-bottom: 1.5rem;
}

.alert-dismissible .btn-close {
  padding: 0.75rem 1rem;
}

/* Estilos para formularios */
.form-control:focus,
.form-select:focus {
  border-color: #86b7fe;
  box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.form-label {
  font-weight: 500;
  margin-bottom: 0.5rem;
}

/* Estilos para paginación */
.pagination {
  margin-bottom: 0;
}

.page-item.active .page-link {
  background-color: #0d6efd;
  border-color: #0d6efd;
}

.page-link {
  color: #0d6efd;
}

.page-link:hover {
  color: #0a58ca;
}

/* Estilos para iconos en botones */
.btn i {
  margin-right: 0.25rem;
}

.btn-sm i {
  font-size: 0.875rem;
}
</style>
<script>
    document.addEventListener("DOMContentLoaded", () => {
  // Toggle sidebar on mobile
  const sidebarCollapseBtn = document.getElementById("sidebarCollapseBtn")
  const sidebar = document.getElementById("sidebarMenu")

  if (sidebarCollapseBtn) {
    sidebarCollapseBtn.addEventListener("click", () => {
      sidebar.classList.toggle("expanded")
    })
  }

  // Close sidebar when clicking on a link (mobile only)
  const sidebarLinks = document.querySelectorAll(".sidebar .nav-link")

  sidebarLinks.forEach((link) => {
    link.addEventListener("click", () => {
      if (window.innerWidth < 768) {
        sidebar.classList.remove("expanded")
      }
    })
  })

  // Close sidebar when clicking outside (mobile only)
  document.addEventListener("click", (event) => {
    const isClickInside = sidebar.contains(event.target)
    const isClickOnToggle = sidebarCollapseBtn && sidebarCollapseBtn.contains(event.target)

    if (!isClickInside && !isClickOnToggle && window.innerWidth < 768 && sidebar.classList.contains("expanded")) {
      sidebar.classList.remove("expanded")
    }
  })

  // Adjust sidebar on window resize
  window.addEventListener("resize", () => {
    if (window.innerWidth >= 768) {
      sidebar.classList.remove("expanded")
    }
  })
})

</script>
</div>

<?php include 'includes/footer.php'; ?>
