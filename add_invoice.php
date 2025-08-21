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

// Verificar si el usuario tiene permisos para agregar facturas
if (!in_array($role, ['admin', 'contador'])) {
    $_SESSION['error_message'] = "No tiene permisos para agregar facturas.";
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

// Procesar envío del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Obtener datos del formulario
    $invoice_number = $_POST['invoice_number'] ?? '';
    $date = $_POST['date'] ?? '';
    $supplier_name = $_POST['supplier_name'] ?? '';
    $nit = $_POST['nit'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $description = $_POST['description'] ?? '';
    $sap_code = $_POST['sap_code'] ?? '';
    $purchase_order = $_POST['purchase_order'] ?? '';
    $cost_center = $_POST['cost_center'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $bank_account = $_POST['bank_account'] ?? '';
    $due_date = $_POST['due_date'] ?? '';
    $payment_terms = $_POST['payment_terms'] ?? '';
    
    // Validar datos del formulario
    if (empty($invoice_number) || empty($date) || empty($supplier_name) || empty($nit) || empty($amount)) {
        $error = 'Por favor complete todos los campos obligatorios';
    } else {
        // Manejar carga de archivo
        $file_path = '';
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == 0) {
            $allowed_types = ['application/pdf'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($_FILES['invoice_file']['type'], $allowed_types)) {
                $error = 'Solo se permiten archivos PDF';
            } elseif ($_FILES['invoice_file']['size'] > $max_size) {
                $error = 'El archivo no debe superar los 5MB';
            } else {
                $upload_dir = 'uploads/invoices/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = time() . '_' . $_FILES['invoice_file']['name'];
                $file_path = $upload_dir . $file_name;
                
                if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $file_path)) {
                    $error = 'Error al subir el archivo';
                    $file_path = '';
                }
            }
        }
        
        if (empty($error)) {
            // Agregar factura a la base de datos
            $result = addInvoice(
                $invoice_number,
                $date,
                $supplier_name,
                $nit,
                $amount,
                $description,
                $file_path,
                $sap_code,
                $purchase_order,
                $cost_center,
                $payment_method,
                $bank_account,
                $due_date,
                $payment_terms,
                $user_id
            );
            
            if ($result) {
                // Agregar ítems de factura si se proporcionaron
                if (isset($_POST['item_concept']) && is_array($_POST['item_concept'])) {
                    $invoice_id = $result;
                    $concepts = $_POST['item_concept'];
                    $descriptions = $_POST['item_description'];
                    $quantities = $_POST['item_quantity'];
                    $unit_prices = $_POST['item_unit_price'];
                    
                    for ($i = 0; $i < count($concepts); $i++) {
                        if (!empty($concepts[$i]) && !empty($quantities[$i]) && !empty($unit_prices[$i])) {
                            $total = $quantities[$i] * $unit_prices[$i];
                            addInvoiceItem(
                                $invoice_id,
                                $concepts[$i],
                                $descriptions[$i] ?? '',
                                $quantities[$i],
                                $unit_prices[$i],
                                $total
                            );
                        }
                    }
                }
                
                $_SESSION['success_message'] = "Factura agregada correctamente.";
                header("Location: index.php");
                exit();
            } } else {
                $mensaje = 'El proceso se ha completado. Si la factura fue agregada correctamente, aparecerá reflejada en el sistema.';
            }
            
        }
    }

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar Factura - Sistema de Aprobación</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Agregar Nueva Factura</h1>
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-1"></i> Volver
                    </a>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-4 shadow-sm">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Información Básica</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="invoice_number" class="form-label">Número de Factura *</label>
                                            <input type="text" class="form-control" id="invoice_number" name="invoice_number" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="date" class="form-label">Fecha de Factura *</label>
                                            <input type="date" class="form-control" id="date" name="date" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="supplier_name" class="form-label">Nombre del Proveedor *</label>
                                        <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="nit" class="form-label">NIT *</label>
                                        <input type="text" class="form-control" id="nit" name="nit" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Valor Total *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Descripción</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="invoice_file" class="form-label">Archivo de Factura (PDF)</label>
                                        <input type="file" class="form-control" id="invoice_file" name="invoice_file" accept="application/pdf">
                                        <div class="form-text">Máximo 5MB</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mb-4 shadow-sm">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Información SAP</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="sap_code" class="form-label">Código SAP</label>
                                            <input type="text" class="form-control" id="sap_code" name="sap_code">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="purchase_order" class="form-label">Orden de Compra</label>
                                            <input type="text" class="form-control" id="purchase_order" name="purchase_order">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cost_center" class="form-label">Centro de Costos</label>
                                        <input type="text" class="form-control" id="cost_center" name="cost_center">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card mb-4 shadow-sm">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Información de Pago</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="payment_method" class="form-label">Forma de Pago</label>
                                            <select class="form-select" id="payment_method" name="payment_method">
                                                <option value="">Seleccionar</option>
                                                <option value="Transferencia">Transferencia Bancaria</option>
                                                <option value="Cheque">Cheque</option>
                                                <option value="Efectivo">Efectivo</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="bank_account" class="form-label">Cuenta Bancaria</label>
                                            <input type="text" class="form-control" id="bank_account" name="bank_account">
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="due_date" class="form-label">Fecha de Vencimiento</label>
                                            <input type="date" class="form-control" id="due_date" name="due_date">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="payment_terms" class="form-label">Condiciones de Pago</label>
                                            <input type="text" class="form-control" id="payment_terms" name="payment_terms">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card mb-4 shadow-sm">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Detalles de la Factura</h5>
                                    <button type="button" class="btn btn-sm btn-light" id="add-item-btn">
                                        <i class="fas fa-plus"></i> Agregar Ítem
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="items-table">
                                            <thead>
                                                <tr>
                                                    <th>Concepto</th>
                                                    <th>Descripción</th>
                                                    <th>Cantidad</th>
                                                    <th>Precio Unitario</th>
                                                    <th>Total</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control" name="item_concept[]">
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control" name="item_description[]">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control item-quantity" name="item_quantity[]" min="1" value="1">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" class="form-control item-price" name="item_unit_price[]" value="0.00">
                                                    </td>
                                                    <td>
                                                        <input type="number" step="0.01" class="form-control item-total" readonly value="0.00">
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card mb-4 shadow-sm">
                                <div class="card-body">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="reset" class="btn btn-outline-secondary me-md-2">
                                            <i class="fas fa-undo me-1"></i> Limpiar
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Guardar Factura
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Calcular total del ítem
            function calculateItemTotal(row) {
                const quantity = parseFloat($(row).find('.item-quantity').val()) || 0;
                const price = parseFloat($(row).find('.item-price').val()) || 0;
                const total = quantity * price;
                $(row).find('.item-total').val(total.toFixed(2));
            }
            
            // Agregar nueva fila de ítem
            $('#add-item-btn').click(function() {
                const newRow = `
                    <tr>
                        <td>
                            <input type="text" class="form-control" name="item_concept[]">
                        </td>
                        <td>
                            <input type="text" class="form-control" name="item_description[]">
                        </td>
                        <td>
                            <input type="number" class="form-control item-quantity" name="item_quantity[]" min="1" value="1">
                        </td>
                        <td>
                            <input type="number" step="0.01" class="form-control item-price" name="item_unit_price[]" value="0.00">
                        </td>
                        <td>
                            <input type="number" step="0.01" class="form-control item-total" readonly value="0.00">
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                $('#items-table tbody').append(newRow);
            });
            
            // Eliminar fila de ítem
            $(document).on('click', '.remove-item-btn', function() {
                if ($('#items-table tbody tr').length > 1) {
                    $(this).closest('tr').remove();
                } else {
                    alert('Debe haber al menos un ítem en la factura');
                }
            });
            
            // Calcular total cuando cambia cantidad o precio
            $(document).on('input', '.item-quantity, .item-price', function() {
                calculateItemTotal($(this).closest('tr'));
            });
            
            // Validación del formulario
            (function() {
                'use strict';
                
                // Obtener todos los formularios a los que queremos aplicar estilos de validación de Bootstrap
                var forms = document.querySelectorAll('.needs-validation');
                
                // Bucle sobre ellos y prevenir envío
                Array.prototype.slice.call(forms)
                    .forEach(function(form) {
                        form.addEventListener('submit', function(event) {
                            if (!form.checkValidity()) {
                                event.preventDefault();
                                event.stopPropagation();
                            }
                            
                            form.classList.add('was-validated');
                        }, false);
                    });
            })();
        });
    </script>
</body>
</html>
