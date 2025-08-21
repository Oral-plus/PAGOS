<?php
require_once 'config/database.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proveedor = $_POST['proveedor'];
    $fecha = $_POST['fecha'];
    $valor = $_POST['valor'];
    $codigo_proveedor = $_POST['codigo_proveedor'];
    $priority = $_POST['priority'];
    $fecha_vencimiento = $_POST['fecha_vencimiento'];

    if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] === 0) {
        $archivoTmp = $_FILES['archivo_pdf']['tmp_name'];
        $nombreOriginal = $_FILES['archivo_pdf']['name'];
        $rutaDestino = 'uploads/' . uniqid() . '_' . basename($nombreOriginal);

        if (!is_dir('uploads')) {
            mkdir('uploads', 0777, true);
        }

        if (move_uploaded_file($archivoTmp, $rutaDestino)) {
            try {
                $conn = getDbConnection();

                $sql = "INSERT INTO escaneados (proveedor, fecha, valor, codigo_proveedor, priority, fecha_vencimiento, pdf_path)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $params = [$proveedor, $fecha, $valor, $codigo_proveedor, $priority, $fecha_vencimiento, $rutaDestino];
                
                if ($conn instanceof PDO) {
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $stmt = sqlsrv_prepare($conn, $sql, $params);
                    if (!$stmt || !sqlsrv_execute($stmt)) {
                        throw new Exception(print_r(sqlsrv_errors(), true));
                    }
                }

                $_SESSION['mensaje'] = ['tipo' => 'success', 'texto' => 'Factura subida e insertada correctamente.'];
            } catch (Exception $e) {
                $_SESSION['mensaje'] = ['tipo' => 'error', 'texto' => 'Error al insertar en la base de datos: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['mensaje'] = ['tipo' => 'error', 'texto' => 'Error al mover el archivo.'];
        }
    } else {
        $_SESSION['mensaje'] = ['tipo' => 'error', 'texto' => 'Archivo PDF no válido.'];
    }

    header("Location: escaneados.php"); // o donde esté el formulario
    exit;
} else {
    $_SESSION['mensaje'] = ['tipo' => 'error', 'texto' => 'Acceso no permitido.'];
    header("Location: index.php");
    exit;
}
?>
