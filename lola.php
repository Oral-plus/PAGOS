<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to browser
ini_set('log_errors', 1);

// Start output buffering to prevent any accidental output
ob_start();

session_start();

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre_usuario'] ?? 'Usuario';
$es_admin = ($usuario_id == 1); // Admin is user with id = 1

// Limpiar cualquier filtro de vendedores de la sesión para evitar cruces
if (isset($_SESSION['vendedores_seleccionados'])) {
    unset($_SESSION['vendedores_seleccionados']);
}

// ==================== FUNCIONES DE CONEXIÓN Y UTILIDADES ====================

function conectarBaseDatos() {
    try {
        include 'db_connection.php';
        if ($conn) {
            sqlsrv_configure("WarningsReturnAsErrors", 0);
            // Configurar charset para caracteres especiales
            $sql_charset = "SET NAMES 'utf8'";
            sqlsrv_query($conn, $sql_charset);
        }
        return $conn;
    } catch (Exception $e) {
        error_log("Error conectando a base de datos: " . $e->getMessage());
        return false;
    }
}

function conectarBaseDatosSAP() {
    try {
        include 'db_conexion2.php';
        return $connSAP; // Assuming db_conexion2.php sets $connSAP
    } catch (Exception $e) {
        error_log("Error conectando a base de datos SAP: " . $e->getMessage());
        return false;
    }
}

function obtenerNitUsuario($usuario_id, $conn = null) {
    $cerrar_conexion = false;
    if ($conn === null) {
        $conn = conectarBaseDatos();
        $cerrar_conexion = true;
    }
    
    if ($conn === false) {
        error_log("No se pudo conectar a la base de datos en obtenerNitUsuario");
        return null;
    }

    try {
        $sql = "SELECT nit FROM Ruta.dbo.usuarios_ruta WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$usuario_id]);

        if ($stmt === false) {
            error_log("Error al obtener NIT del usuario: " . print_r(sqlsrv_errors(), true));
            if ($cerrar_conexion) sqlsrv_close($conn);
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($cerrar_conexion) sqlsrv_close($conn);
        return $row ? $row['nit'] : null;
    } catch (Exception $e) {
        error_log("Exception en obtenerNitUsuario: " . $e->getMessage());
        if ($cerrar_conexion) sqlsrv_close($conn);
        return null;
    }
}

function obtenerUsuarios() {
    $conn = conectarBaseDatos();
    if ($conn === false) {
        error_log("No se pudo conectar a la base de datos en obtenerUsuarios");
        return [];
    }

    try {
        $sql = "SELECT [id], [nombre], [usuario], [password], [fecha_registro], [es_admin], [vendedor_id], [nit], [Ruta]
                FROM [Ruta].[dbo].[usuarios_ruta]";
        $stmt = sqlsrv_query($conn, $sql);

        if ($stmt === false) {
            error_log("Error al ejecutar consulta de usuarios: " . print_r(sqlsrv_errors(), true));
            sqlsrv_close($conn);
            return [];
        }

        $usuarios = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $usuarios[] = [
                'id' => $row['id'],
                'nombre' => mb_convert_encoding($row['nombre'] ?? '', 'UTF-8', 'auto'),
                'usuario' => mb_convert_encoding($row['usuario'] ?? '', 'UTF-8', 'auto'),
                'fecha_registro' => $row['fecha_registro'] ? $row['fecha_registro']->format('Y-m-d H:i:s') : '',
                'es_admin' => $row['es_admin'],
                'vendedor_id' => $row['vendedor_id'],
                'nit' => mb_convert_encoding($row['nit'] ?? '', 'UTF-8', 'auto'),
                'ruta' => mb_convert_encoding($row['Ruta'] ?? '', 'UTF-8', 'auto')
            ];
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        return $usuarios;
    } catch (Exception $e) {
        error_log("Exception en obtenerUsuarios: " . $e->getMessage());
        sqlsrv_close($conn);
        return [];
    }
}

function obtenerClientesSAP($nit = null, $es_admin = false) {
    $connSAP = conectarBaseDatosSAP();
    $clientes = [];
    if ($connSAP === false) {
        return $clientes;
    }

    try {
        // Siempre obtener TODOS los clientes, sin importar si es admin o no
        $sql = "SELECT DISTINCT T0.[CardCode], T0.[CardFName], T0.[CardName], T1.[SlpCode], T1.[SlpName], T0.[City], T0.[Phone]
            FROM OCRD T0
            INNER JOIN OSLP T1 ON T0.[SlpCode] = T1.[SlpCode]
            WHERE T0.[validFor] = 'Y'
            ORDER BY T0.[CardName]";

        $params = []; // Sin parámetros para obtener todos
        $stmt = sqlsrv_query($connSAP, $sql, $params);

        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $clientes[] = [
                    'codigo' => $row['CardCode'],
                    'nombre_comercial' => $row['CardFName'],
                    'razon_social' => $row['CardName'],
                    'vendedor_codigo' => $row['SlpCode'],
                    'vendedor_nombre' => $row['SlpName'],
                    'ciudad' => $row['City'] ?? '',
                    'telefono' => $row['Phone'] ?? ''
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        sqlsrv_close($connSAP);
    } catch (Exception $e) {
        error_log("Exception en obtenerClientesSAP: " . $e->getMessage());
        if ($connSAP) sqlsrv_close($connSAP);
    }
    return $clientes;
}

function obtenerRutas($fechaInicio, $fechaFin, $municipio_filtro = '', $vendedores_seleccionados = [], $usuario_id = null, $es_admin = false) {
    $conn = conectarBaseDatos();
    if ($conn === false) {
        error_log("No se pudo conectar a la base de datos en obtenerRutas");
        return [];
    }

    $rutas = [];

    try {
        $sql_base = "SELECT r.[id], r.[nombre], r.[estado], r.[usuario_id], r.[fecha_creacion], r.[fecha_actualizacion], r.[fecha_programada], r.[cliente_id], r.[vendedor_id], r.[ciudad], r.[hora_visita],
                    DATENAME(weekday, r.fecha_programada) AS dia_semana,
                    u.nombre as vendedor_nombre
                     FROM [Ruta].[dbo].[rutas] r
                     LEFT JOIN [Ruta].[dbo].[usuarios_ruta] u ON r.vendedor_id = u.nit";

        $sql_base .= " WHERE r.fecha_programada BETWEEN ? AND ?";
        $params = [$fechaInicio, $fechaFin];

        // Aplicar filtros solo si se especifican
        if (!empty($municipio_filtro) && $municipio_filtro !== '') {
            $sql_base .= " AND r.ciudad = ?";
            $params[] = $municipio_filtro;
        }

        // Si no es admin, filtrar por su NIT
        if (!$es_admin && $usuario_id) {
            $nit = obtenerNitUsuario($usuario_id);
            if ($nit) {
                $sql_base .= " AND r.vendedor_id = ?";
                $params[] = $nit;
            }
        } elseif ($es_admin && !empty($vendedores_seleccionados) && is_array($vendedores_seleccionados)) {
            // Si es admin y ha seleccionado vendedores específicos
            $placeholders = str_repeat('?,', count($vendedores_seleccionados) - 1) . '?';
            $sql_base .= " AND r.vendedor_id IN ($placeholders)";
            $params = array_merge($params, $vendedores_seleccionados);
        }

        $sql_base .= " ORDER BY r.fecha_programada, r.hora_visita ASC";

        error_log("SQL Query: $sql_base");
        error_log("Parámetros: " . json_encode($params));

        $stmt = sqlsrv_query($conn, $sql_base, $params);
        
        if ($stmt === false) {
            error_log("Error al ejecutar consulta de rutas: " . print_r(sqlsrv_errors(), true));
            sqlsrv_close($conn);
            return [];
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rutas[] = [
                'id' => $row['id'],
                'nombre' => mb_convert_encoding($row['nombre'] ?? '', 'UTF-8', 'auto'),
                'estado' => mb_convert_encoding($row['estado'] ?? '', 'UTF-8', 'auto'),
                'usuario_id' => $row['usuario_id'],
                'fecha_creacion' => $row['fecha_creacion'] ? $row['fecha_creacion']->format('Y-m-d H:i:s') : '',
                'fecha_actualizacion' => $row['fecha_actualizacion'] ? $row['fecha_actualizacion']->format('Y-m-d H:i:s') : '',
                'fecha_programada' => $row['fecha_programada'] ? $row['fecha_programada']->format('Y-m-d') : '',
                'cliente_id' => mb_convert_encoding($row['cliente_id'] ?? '', 'UTF-8', 'auto'),
                'vendedor_id' => mb_convert_encoding($row['vendedor_id'] ?? '', 'UTF-8', 'auto'),
                'ciudad' => mb_convert_encoding($row['ciudad'] ?? '', 'UTF-8', 'auto'),
                'hora_visita' => $row['hora_visita'] ?? '',
                'dia_semana' => mb_convert_encoding($row['dia_semana'] ?? '', 'UTF-8', 'auto'),
                'vendedor_nombre' => mb_convert_encoding($row['vendedor_nombre'] ?? '', 'UTF-8', 'auto')
            ];
        }

        error_log("Total de rutas obtenidas: " . count($rutas));
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        return $rutas;
        
    } catch (Exception $e) {
        error_log("Exception en obtenerRutas: " . $e->getMessage());
        sqlsrv_close($conn);
        return [];
    }
}

function obtenerTodasRutas() {
    $conn = conectarBaseDatos();
    if ($conn === false) {
        error_log("No se pudo conectar a la base de datos en obtenerTodasRutas");
        return [];
    }

    $rutas = [];

    try {
        $sql = "SELECT r.[id], r.[nombre], r.[estado], r.[usuario_id], r.[fecha_creacion], 
                       r.[fecha_actualizacion], r.[fecha_programada], r.[cliente_id], 
                       r.[vendedor_id], r.[ciudad], r.[hora_visita],
                       DATENAME(weekday, r.fecha_programada) AS dia_semana,
                       u.nombre AS vendedor_nombre
                FROM [Ruta].[dbo].[rutas] r
                LEFT JOIN [Ruta].[dbo].[usuarios_ruta] u ON r.vendedor_id = u.nit
                ORDER BY r.fecha_programada, r.hora_visita ASC";

        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            error_log("Error al ejecutar consulta de rutas: " . print_r(sqlsrv_errors(), true));
            sqlsrv_close($conn);
            return [];
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rutas[] = [
                'id' => $row['id'],
                'nombre' => mb_convert_encoding($row['nombre'] ?? '', 'UTF-8', 'auto'),
                'estado' => mb_convert_encoding($row['estado'] ?? '', 'UTF-8', 'auto'),
                'usuario_id' => $row['usuario_id'],
                'fecha_creacion' => $row['fecha_creacion'] ? $row['fecha_creacion']->format('Y-m-d H:i:s') : '',
                'fecha_actualizacion' => $row['fecha_actualizacion'] ? $row['fecha_actualizacion']->format('Y-m-d H:i:s') : '',
                'fecha_programada' => $row['fecha_programada'] ? $row['fecha_programada']->format('Y-m-d') : '',
                'cliente_id' => mb_convert_encoding($row['cliente_id'] ?? '', 'UTF-8', 'auto'),
                'vendedor_id' => mb_convert_encoding($row['vendedor_id'] ?? '', 'UTF-8', 'auto'),
                'ciudad' => mb_convert_encoding($row['ciudad'] ?? '', 'UTF-8', 'auto'),
                'hora_visita' => $row['hora_visita'] ?? '',
                'dia_semana' => mb_convert_encoding($row['dia_semana'] ?? '', 'UTF-8', 'auto'),
                'vendedor_nombre' => mb_convert_encoding($row['vendedor_nombre'] ?? 'Desconocido (' . $row['vendedor_id'] . ')', 'UTF-8', 'auto')
            ];
        }

        error_log("Total de rutas obtenidas: " . count($rutas));
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        return $rutas;
        
    } catch (Exception $e) {
        error_log("Exception en obtenerTodasRutas: " . $e->getMessage());
        sqlsrv_close($conn);
        return [];
    }
}

// Funciones de formato
function formatearFechaEspanol($fecha) {
    try {
        if (is_object($fecha)) {
            return $fecha->format('d/m/Y');
        }
        // Intentar parsear si es string
        if (is_string($fecha)) {
            $fecha_obj = DateTime::createFromFormat('Y-m-d', $fecha);
            if ($fecha_obj) return $fecha_obj->format('d/m/Y');
            $fecha_obj = DateTime::createFromFormat('Y-m-d H:i:s', $fecha);
            if ($fecha_obj) return $fecha_obj->format('d/m/Y');
        }
        return $fecha; // Devolver original si no se puede formatear
    } catch (Exception $e) {
        error_log("Error formateando fecha: " . $e->getMessage());
        return $fecha;
    }
}

function traducirDiaSemana($dia_ingles) {
    $dias = ['Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves',
             'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'];
    return $dias[$dia_ingles] ?? $dia_ingles;
}

// Función para generar archivo CSV
function generarArchivoCSV($datos, $encabezados, $nombreArchivo, $titulo) {
    try {
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        $output = fopen($tempFile, 'w');
        
        if (!$output) {
            error_log("No se pudo crear archivo temporal");
            return false;
        }
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Título del reporte
        fputcsv($output, [$titulo], ';');
        fputcsv($output, [""], ';');
        
        // Encabezados
        fputcsv($output, $encabezados, ';');
        
        // Datos
        if (empty($datos)) {
            fputcsv($output, ["No se encontraron datos para exportar."], ';');
        } else {
            foreach ($datos as $fila) {
                $fila_utf8 = array_map(function($campo) {
                    return mb_convert_encoding($campo, 'UTF-8', 'auto');
                }, $fila);
                fputcsv($output, $fila_utf8, ';');
            }
        }
        
        fclose($output);
        return $tempFile;
    } catch (Exception $e) {
        error_log("Exception en generarArchivoCSV: " . $e->getMessage());
        return false;
    }
}

// Función para obtener municipios únicos
function obtenerMunicipios($nit = null, $es_admin = false) {
    $conn = conectarBaseDatos();
    if ($conn === false) {
        error_log("No se pudo conectar a la base de datos en obtenerMunicipios");
        return [];
    }

    try {
        if ($es_admin) {
            $sql = "SELECT DISTINCT ciudad 
                    FROM Ruta.dbo.rutas 
                    WHERE ciudad IS NOT NULL AND ciudad != '' 
                    ORDER BY ciudad";
            $params = [];
        } else {
            $sql = "SELECT DISTINCT ciudad 
                    FROM Ruta.dbo.rutas 
                    WHERE ciudad IS NOT NULL AND ciudad != '' AND vendedor_id = ?
                    ORDER BY ciudad";
            $params = [$nit];
        }

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            error_log("Error al obtener municipios: " . print_r(sqlsrv_errors(), true));
            sqlsrv_close($conn);
            return [];
        }

        $municipios = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['ciudad'])) {
                $municipios[] = $row['ciudad'];
            }
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        return $municipios;
    } catch (Exception $e) {
        error_log("Exception en obtenerMunicipios: " . $e->getMessage());
        if ($conn) sqlsrv_close($conn);
        return [];
    }
}

// ==================== CÓDIGO PARA EXPORTAR TODAS LAS RUTAS ====================

if (isset($_GET['exportar']) && $_GET['exportar'] === 'todas_rutas') {
    try {
        ob_clean();
        
        // Obtener todas las rutas sin filtros
        $rutas = obtenerTodasRutas();

        // Preparar CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Todas_Rutas_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        $titulo = "REPORTE COMPLETO DE TODAS LAS RUTAS - " . date('d/m/Y');
        fputcsv($output, [$titulo], ';');
        fputcsv($output, [""], ';');

        if (empty($rutas)) {
            fputcsv($output, ["No se encontraron rutas en la base de datos."], ';');
            error_log("No se encontraron rutas para exportar.");
        } else {
            fputcsv($output, ['ID', 'Nombre', 'Estado', 'Usuario ID', 'Fecha Creación', 'Fecha Actualización', 'Fecha Programada', 'Cliente ID', 'Vendedor ID', 'Ciudad', 'Hora Visita', 'Día', 'Vendedor Nombre'], ';');

            foreach ($rutas as $ruta) {
                $fecha_programada = formatearFechaEspanol($ruta['fecha_programada']);
                $fecha_creacion = isset($ruta['fecha_creacion']) && $ruta['fecha_creacion'] ? 
                    formatearFechaEspanol($ruta['fecha_creacion']) : 'N/A';
                $fecha_actualizacion = isset($ruta['fecha_actualizacion']) && $ruta['fecha_actualizacion'] ? 
                    formatearFechaEspanol($ruta['fecha_actualizacion']) : 'N/A';
                $dia_semana_espanol = traducirDiaSemana($ruta['dia_semana']);
                $hora_visita = $ruta['hora_visita'] ?? 'N/A';
                $vendedor_nombre = $ruta['vendedor_nombre'];
                
                fputcsv($output, [
                    $ruta['id'], $ruta['nombre'], $ruta['estado'], $ruta['usuario_id'], 
                    $fecha_creacion, $fecha_actualizacion, $fecha_programada, 
                    $ruta['cliente_id'], $ruta['vendedor_id'], $ruta['ciudad'], 
                    $hora_visita, $dia_semana_espanol, $vendedor_nombre
                ], ';');
            }
            
            error_log("Exportación completada. Total de rutas exportadas: " . count($rutas));
        }

        fclose($output);
        exit;
        
    } catch (Exception $e) {
        error_log("Exception en exportación de todas las rutas: " . $e->getMessage());
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Error_Exportacion.txt"');
        echo "Error: " . $e->getMessage();
        exit;
    }
}

// ==================== CÓDIGO PARA EXPORTAR MÚLTIPLES ARCHIVOS ====================

if (isset($_GET['exportar']) && $_GET['exportar'] === 'multiple') {
    try {
        ob_clean();
        
        $fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
        $fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
        $tablas_seleccionadas = isset($_GET['tablas']) ? $_GET['tablas'] : [];
        
        // Validar rango de fechas
        if (strtotime($fechaInicio) > strtotime($fechaFin)) {
            $temp = $fechaInicio;
            $fechaInicio = $fechaFin;
            $fechaFin = $temp;
        }

        $fechaInicioEspanol = formatearFechaEspanol($fechaInicio);
        $fechaFinEspanol = formatearFechaEspanol($fechaFin);

        $conn = conectarBaseDatos();
        $nit_usuario = $es_admin ? null : obtenerNitUsuario($usuario_id, $conn);
        if ($conn) sqlsrv_close($conn);

        $vendedores_seleccionados = null;
        if ($es_admin && isset($_GET['opcion_exportacion']) && $_GET['opcion_exportacion'] === 'vendedor' && isset($_GET['vendedor']) && !empty($_GET['vendedor'])) {
            $vendedores_seleccionados = $_GET['vendedor'];
            error_log("Vendedores seleccionados para filtrar: " . implode(',', $vendedores_seleccionados));
        }

        $archivos_generados = [];
        
        foreach ($tablas_seleccionadas as $tabla) {
            switch ($tabla) {
                case 'rutas':
                    $municipio_filtro = isset($_GET['municipio']) ? $_GET['municipio'] : null;
                    // Llamada a la función actualizada obtenerRutas
                    $rutas = obtenerRutas($fechaInicio, $fechaFin, $municipio_filtro, $vendedores_seleccionados, $usuario_id, $es_admin);
                    $encabezados = ['ID', 'Nombre', 'Estado', 'Usuario ID', 'Fecha Creación', 'Fecha Actualización', 'Fecha Programada', 'Cliente ID', 'Vendedor ID', 'Ciudad', 'Hora Visita', 'Día', 'Vendedor Nombre'];
                    
                    $datos_rutas = [];
                    foreach ($rutas as $ruta) {
                        $fecha_programada = formatearFechaEspanol($ruta['fecha_programada']);
                        $fecha_creacion = isset($ruta['fecha_creacion']) && $ruta['fecha_creacion'] ? 
                            formatearFechaEspanol($ruta['fecha_creacion']) : 'N/A';
                        $fecha_actualizacion = isset($ruta['fecha_actualizacion']) && $ruta['fecha_actualizacion'] ? 
                            formatearFechaEspanol($ruta['fecha_actualizacion']) : 'N/A';
                        $dia_semana_espanol = traducirDiaSemana($ruta['dia_semana']);
                        $hora_visita = $ruta['hora_visita'] ?? 'N/A';
                        $vendedor_nombre = $ruta['vendedor_nombre'] ?? 'Desconocido (' . $ruta['vendedor_id'] . ')';
                        
                        $datos_rutas[] = [
                            $ruta['id'], $ruta['nombre'], $ruta['estado'], $ruta['usuario_id'], 
                            $fecha_creacion, $fecha_actualizacion, $fecha_programada, 
                            $ruta['cliente_id'], $ruta['vendedor_id'], $ruta['ciudad'], 
                            $hora_visita, $dia_semana_espanol, $vendedor_nombre
                        ];
                    }
                    
                    $titulo_filtro = $vendedores_seleccionados ? " (FILTRADO POR VENDEDORES SELECCIONADOS)" : " (TODAS LAS RUTAS)";
                    $titulo = "REPORTE DE RUTAS DESDE " . $fechaInicioEspanol . " HASTA " . $fechaFinEspanol . $titulo_filtro;
                    $archivo = generarArchivoCSV($datos_rutas, $encabezados, 'Rutas_' . $fechaInicio . '_' . $fechaFin . '.csv', $titulo);
                    if ($archivo) {
                        $archivos_generados[] = ['archivo' => $archivo, 'nombre' => 'Rutas_' . $fechaInicio . '_' . $fechaFin . '.csv'];
                    }
                    break;
                    
                case 'clientes':
                    // Obtener TODOS los clientes
                    $clientes = obtenerClientesSAP(null, true);
                    $encabezados = ['Código', 'Nombre Comercial', 'Razón Social', 'Vendedor Código', 'Vendedor Nombre', 'Ciudad', 'Teléfono'];
                    
                    $datos_clientes = [];
                    foreach ($clientes as $cliente) {
                        $datos_clientes[] = [
                            $cliente['codigo'], $cliente['nombre_comercial'], $cliente['razon_social'],
                            $cliente['vendedor_codigo'], $cliente['vendedor_nombre'], 
                            $cliente['ciudad'], $cliente['telefono']
                        ];
                    }
                    
                    $titulo = "REPORTE COMPLETO DE TODOS LOS CLIENTES - " . date('d/m/Y');
                    $archivo = generarArchivoCSV($datos_clientes, $encabezados, 'Todos_los_Clientes_' . date('Y-m-d') . '.csv', $titulo);
                    if ($archivo) {
                        $archivos_generados[] = ['archivo' => $archivo, 'nombre' => 'Todos_los_Clientes_' . date('Y-m-d') . '.csv'];
                    }
                    break;
                    
                case 'vendedores':
                    $vendedores = obtenerUsuarios();
                    $encabezados = ['ID', 'Nombre', 'Usuario', 'NIT', 'Fecha Registro', 'Es Admin', 'Vendedor ID', 'Ruta'];
                    
                    $datos_vendedores = [];
                    foreach ($vendedores as $vendedor) {
                        $fecha_registro = '';
                        if (isset($vendedor['fecha_registro']) && $vendedor['fecha_registro']) {
                            $fecha_registro = is_object($vendedor['fecha_registro']) 
                                ? $vendedor['fecha_registro']->format('d/m/Y') 
                                : date('d/m/Y', strtotime($vendedor['fecha_registro']));
                        }
                        
                        $datos_vendedores[] = [
                            $vendedor['id'], $vendedor['nombre'], $vendedor['usuario'],
                            $vendedor['nit'], $fecha_registro, 
                            $vendedor['es_admin'] ? 'Sí' : 'No',
                            $vendedor['vendedor_id'] ?? '', $vendedor['ruta'] ?? ''
                        ];
                    }
                    
                    $titulo = "REPORTE COMPLETO DE VENDEDORES - " . date('d/m/Y');
                    $archivo = generarArchivoCSV($datos_vendedores, $encabezados, 'Todos_los_Vendedores_' . date('Y-m-d') . '.csv', $titulo);
                    if ($archivo) {
                        $archivos_generados[] = ['archivo' => $archivo, 'nombre' => 'Todos_los_Vendedores_' . date('Y-m-d') . '.csv'];
                    }
                    break;
                    
                case 'resumen_visitas':
                    $conn = conectarBaseDatos();
                    $resumen_visitas = [];
                    if ($conn !== false) {
                        try {
                            // Obtener resumen de TODAS las visitas sin filtros de vendedor
                            $sql = "SELECT 
                                    r.cliente_id,
                                    COUNT(*) as total_visitas,
                                    MAX(r.fecha_programada) as ultima_visita,
                                    MIN(r.fecha_programada) as primera_visita,
                                    COALESCE(u.nombre, 'No asignado') as vendedor_nombre,
                                    COUNT(CASE WHEN r.estado = 'Completado' THEN 1 END) as visitas_completadas,
                                    COUNT(CASE WHEN r.estado = 'Pendiente' THEN 1 END) as visitas_pendientes
                                FROM Ruta.dbo.rutas r
                                LEFT JOIN usuarios_ruta u ON r.vendedor_id = u.nit
                                WHERE r.fecha_programada BETWEEN ? AND ?
                                GROUP BY r.cliente_id, u.nombre ORDER BY total_visitas DESC";
                            
                            $params = [$fechaInicio, $fechaFin];
                            
                            $stmt = sqlsrv_query($conn, $sql, $params);
                            
                            if ($stmt) {
                                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                    $primera_visita = '';
                                    $ultima_visita = '';
                                    
                                    if ($row['primera_visita']) {
                                        $primera_visita = is_object($row['primera_visita']) 
                                            ? $row['primera_visita']->format('d/m/Y') 
                                            : date('d/m/Y', strtotime($row['primera_visita']));
                                    }
                                    
                                    if ($row['ultima_visita']) {
                                        $ultima_visita = is_object($row['ultima_visita']) 
                                            ? $row['ultima_visita']->format('d/m/Y') 
                                            : date('d/m/Y', strtotime($row['ultima_visita']));
                                    }
                                    
                                    $resumen_visitas[] = [
                                        $row['cliente_id'], $row['total_visitas'], $row['vendedor_nombre'],
                                        $primera_visita, $ultima_visita, 
                                        $row['visitas_completadas], $row['visitas_pendientes']
                                    ];
                                }
                                sqlsrv_free_stmt($stmt);
                            }
                            sqlsrv_close($conn);
                        } catch (Exception $e) {
                            error_log("Exception en resumen_visitas: " . $e->getMessage());
                            if ($conn) sqlsrv_close($conn);
                        }
                    }
                    
                    $encabezados = ['Cliente ID', 'Total Visitas', 'Vendedor', 'Primera Visita', 'Última Visita', 'Completadas', 'Pendientes'];
                    $titulo = "RESUMEN COMPLETO DE VISITAS POR CLIENTE DESDE " . $fechaInicioEspanol . " HASTA " . $fechaFinEspanol;
                    $archivo = generarArchivoCSV($resumen_visitas, $encabezados, 'Resumen_Completo_Visitas_' . $fechaInicio . '_' . $fechaFin . '.csv', $titulo);
                    if ($archivo) {
                        $archivos_generados[] = ['archivo' => $archivo, 'nombre' => 'Resumen_Completo_Visitas_' . $fechaInicio . '_' . $fechaFin . '.csv'];
                    }
                    break;
            }
        }
        
        if (count($archivos_generados) == 1) {
            $archivo_info = $archivos_generados[0];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $archivo_info['nombre'] . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            readfile($archivo_info['archivo']);
            unlink($archivo_info['archivo']);
            exit;
        }
        
        if (count($archivos_generados) > 1) {
            $zip = new ZipArchive();
            $zipname = 'Exportacion_Multiple_' . date('Y-m-d_H-i-s') . '.zip';
            $zippath = sys_get_temp_dir() . '/' . $zipname;
            
            if ($zip->open($zippath, ZipArchive::CREATE) === TRUE) {
                foreach ($archivos_generados as $archivo_info) {
                    $zip->addFile($archivo_info['archivo'], $archivo_info['nombre']);
                }
                $zip->close();
                
                // Descargar el ZIP
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipname . '"');
                header('Content-Length: ' . filesize($zippath));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                
                readfile($zippath);
                
                // Limpiar archivos temporales
                unlink($zippath);
                foreach ($archivos_generados as $archivo_info) {
                    unlink($archivo_info['archivo']);
                }
                exit;
            }
        }
        
        // Si no hay archivos generados
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Error_Exportacion.txt"');
        echo "Error: No se pudieron generar los archivos de exportación.";
        exit;
        
    } catch (Exception $e) {
        error_log("Exception en exportación múltiple: " . $e->getMessage());
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Error_Exportacion.txt"');
        echo "Error: " . $e->getMessage();
        exit;
    }
}

// ==================== EXPORTACIÓN SIMPLE (CORREGIDA) ====================

if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    try {
        ob_clean();
        
        $fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
        $fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
        
        // Validar rango de fechas
        if (strtotime($fechaInicio) > strtotime($fechaFin)) {
            $temp = $fechaInicio;
            $fechaInicio = $fechaFin;
            $fechaFin = $temp;
        }

        $fechaInicioEspanol = formatearFechaEspanol($fechaInicio);
        $fechaFinEspanol = formatearFechaEspanol($fechaFin);

        $conn = conectarBaseDatos();
        $nit_usuario = $es_admin ? null : obtenerNitUsuario($usuario_id, $conn);
        if ($conn) sqlsrv_close($conn);

        $vendedores_seleccionados = null;
        if ($es_admin && isset($_GET['opcion_exportacion']) && $_GET['opcion_exportacion'] === 'vendedor' && isset($_GET['vendedor']) && !empty($_GET['vendedor'])) {
            $vendedores_seleccionados = $_GET['vendedor'];
        }

        $municipio_filtro = isset($_GET['municipio']) ? $_GET['municipio'] : null;
        // Llamada a la función actualizada obtenerRutas
        $rutas = obtenerRutas($fechaInicio, $fechaFin, $municipio_filtro, $vendedores_seleccionados, $usuario_id, $es_admin);

        // Preparar CSV
        $titulo_filtro = $vendedores_seleccionados ? "_Filtrado_Vendedores" : "_Todas_Rutas";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Rutas_' . $fechaInicio . '_' . $fechaFin . $titulo_filtro . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        $titulo_reporte = $vendedores_seleccionados ? " (FILTRADO POR VENDEDORES SELECCIONADOS)" : " (TODAS LAS RUTAS)";
        fputcsv($output, ["REPORTE DE RUTAS DESDE " . $fechaInicioEspanol . " HASTA " . $fechaFinEspanol . $titulo_reporte], ';');
        fputcsv($output, [""], ';');

        if (empty($rutas)) {
            fputcsv($output, ["No se encontraron rutas para el rango de fechas especificado."], ';');
            error_log("No se encontraron rutas para exportar con los parámetros: fechaInicio=$fechaInicio, fechaFin=$fechaFin");
        } else {
            fputcsv($output, ['ID', 'Nombre', 'Estado', 'Usuario ID', 'Fecha Creación', 'Fecha Actualización', 'Fecha Programada', 'Cliente ID', 'Vendedor ID', 'Ciudad', 'Hora Visita', 'Día', 'Vendedor Nombre'], ';');

            foreach ($rutas as $ruta) {
                $fecha_programada = formatearFechaEspanol($ruta['fecha_programada']);
                $fecha_creacion = isset($ruta['fecha_creacion']) && $ruta['fecha_creacion'] ? 
                    formatearFechaEspanol($ruta['fecha_creacion']) : 'N/A';
                $fecha_actualizacion = isset($ruta['fecha_actualizacion']) && $ruta['fecha_actualizacion'] ? 
                    formatearFechaEspanol($ruta['fecha_actualizacion']) : 'N/A';
                $dia_semana_espanol = traducirDiaSemana($ruta['dia_semana']);
                $hora_visita = $ruta['hora_visita'] ?? 'N/A';
                $vendedor_nombre = $ruta['vendedor_nombre'] ?? 'Desconocido (' . $ruta['vendedor_id'] . ')';
                
                fputcsv($output, [
                    $ruta['id'], $ruta['nombre'], $ruta['estado'], $ruta['usuario_id'], 
                    $fecha_creacion, $fecha_actualizacion, $fecha_programada, 
                    $ruta['cliente_id'], $ruta['vendedor_id'], $ruta['ciudad'], 
                    $hora_visita, $dia_semana_espanol, $vendedor_nombre
                ], ';');
            }
            
            error_log("Exportación completada. Total de rutas exportadas: " . count($rutas));
        }

        fclose($output);
        exit;
        
    } catch (Exception $e) {
        error_log("Exception en exportación simple: " . $e->getMessage());
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="Error_Exportacion.txt"');
        echo "Error: " . $e->getMessage();
        exit;
    }
}

// ==================== FORMULARIO DE EXPORTACIÓN MEJORADO ====================

if (!isset($_GET['exportar'])) {
    include 'header.php';
}

$vendedores = [];
$municipios = [];

try {
    $conn = conectarBaseDatos();
    $nit_usuario_form = $es_admin ? null : obtenerNitUsuario($usuario_id, $conn);
    if ($conn) sqlsrv_close($conn);
    
    $municipios = obtenerMunicipios($nit_usuario_form, $es_admin);
    if ($es_admin) {
        $vendedores = obtenerUsuarios();
        error_log("Vendedores disponibles para el formulario: " . count($vendedores));
    }
} catch (Exception $e) {
    error_log("Exception al cargar datos del formulario: " . $e->getMessage());
}

if (!isset($_GET['exportar'])) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Exportar Datos a Excel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        .export-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 15px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .card-header {
            background: linear-gradient(135deg, #007bff, #6610f2);
            color: white;
            padding: 2rem;
            border-bottom: none;
            text-align: center;
        }
        .card-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }
        .card-body {
            padding: 2.5rem;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-label {
            font-weight: 600;
            color: #34495e;
            margin-bottom: 0.75rem;
        }
        .form-control, .select-vendedor {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 0.875rem;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        .form-control:focus, .select-vendedor:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.25rem rgba(0, 123, 255, 0.15);
            transform: translateY(-2px);
        }
        .select-vendedor {
            height: 140px;
        }
        .btn-export {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            border-radius: 10px;
            padding: 1rem 2rem;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-export:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
            border: none;
            border-radius: 10px;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
        }
        .form-check {
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(0, 123, 255, 0.05);
            border-radius: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .form-check:hover {
            border-color: rgba(0, 123, 255, 0.2);
            background: rgba(0, 123, 255, 0.1);
        }
        .form-check-input {
            width: 1.5rem;
            height: 1.5rem;
            margin-top: 0.125rem;
        }
        .form-check-label {
            font-weight: 500;
            margin-left: 0.75rem;
            font-size: 1.05rem;
        }
        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .option-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .option-card:hover {
            border-color: #007bff;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.1);
        }
        .option-card.selected {
            border-color: #007bff;
            background: rgba(0, 123, 255, 0.05);
        }
        .option-icon {
            font-size: 2.5rem;
            color: #007bff;
            margin-bottom: 1rem;
        }
        .option-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .option-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        @media (max-width: 768px) {
            .export-container {
                margin: 1rem;
            }
            .card-body {
                padding: 1.5rem;
            }
            .card-title {
                font-size: 1.5rem;
            }
            .export-options {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="export-container">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-file-excel"></i> 
                    Sistema de Exportación Avanzado
                </div>
                <p class="mb-0 mt-2" style="opacity: 0.9;">Exporta tus datos de forma organizada y profesional</p>
            </div>
            <div class="card-body">
                <!-- Nueva sección para seleccionar tipo de exportación -->
                <div class="section-title">
                    <i class="fas fa-cogs"></i> Tipo de Exportación
                </div>
                
                <div class="export-options">
                    <div class="option-card" onclick="selectExportType('simple')">
                        <div class="option-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="option-title">Exportación Simple</div>
                        <div class="option-description">Exporta solo las rutas en un archivo CSV</div>
                    </div>
                    <div class="option-card" onclick="selectExportType('multiple')">
                        <div class="option-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="option-title">Exportación Múltiple</div>
                        <div class="option-description">Exporta diferentes tablas en archivos separados</div>
                    </div>
                    <div class="option-card" onclick="selectExportType('todas_rutas')">
                        <div class="option-icon"><i class="fas fa-route"></i></div>
                        <div class="option-title">Exportar Todas las Rutas</div>
                        <div class="option-description">Exporta todas las rutas sin filtros en un archivo CSV</div>
                    </div>
                </div>

                <form action="export-to-excel.php" method="get" class="export-form" id="export-form">
                    <input type="hidden" name="exportar" value="multiple" id="export-type">
                    
                    <!-- Nueva sección para seleccionar tablas a exportar -->
                    <div id="multiple-options">
                        <div class="section-title">
                            <i class="fas fa-database"></i> Seleccionar Datos a Exportar
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" id="tabla-rutas" name="tablas[]" value="rutas" class="form-check-input" checked>
                                    <label for="tabla-rutas" class="form-check-label">
                                        <i class="fas fa-route"></i> Rutas y Visitas
                                        <small class="d-block text-muted">Información detallada de todas las rutas programadas</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" id="tabla-clientes" name="tablas[]" value="clientes" class="form-check-input">
                                    <label for="tabla-clientes" class="form-check-label">
                                        <i class="fas fa-users"></i> Clientes
                                        <small class="d-block text-muted">Base de datos completa de clientes desde SAP</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" id="tabla-vendedores" name="tablas[]" value="vendedores" class="form-check-input">
                                    <label for="tabla-vendedores" class="form-check-label">
                                        <i class="fas fa-user-tie"></i> Vendedores
                                        <small class="d-block text-muted">Información de todos los vendedores registrados</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" id="tabla-resumen" name="tablas[]" value="resumen_visitas" class="form-check-input">
                                    <label for="tabla-resumen" class="form-check-label">
                                        <i class="fas fa-chart-bar"></i> Resumen de Visitas
                                        <small class="d-block text-muted">Estadísticas y resumen por cliente</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($es_admin): ?>
                    <div class="section-title" id="filtros-exportacion">
                        <i class="fas fa-filter"></i> Filtros de Exportación
                    </div>
                    <div class="vendedor-selector">
                        <div class="vendedor-options">
                            <div class="form-check">
                                <input type="radio" id="opcion-todos" name="opcion_exportacion" value="todos" checked 
                                       onchange="toggleVendedorSelect(false);" class="form-check-input">
                                <label for="opcion-todos" class="form-check-label">Exportar todas las rutas</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" id="opcion-vendedor" name="opcion_exportacion" value="vendedor" 
                                       onchange="toggleVendedorSelect(true);" class="form-check-input">
                                <label for="opcion-vendedor" class="form-check-label">Filtrar por vendedor específico</label>
                            </div>
                        </div>
                        <div id="selector-vendedor" style="display: none; margin-top: 1rem;">
                            <label for="vendedor" class="form-label">Seleccionar Vendedores (Ctrl + clic para múltiples):</label>
                            <select id="vendedor" name="vendedor[]" class="form-control select-vendedor" multiple disabled>
                                <option value="">-- Seleccione vendedores --</option>
                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?php echo htmlspecialchars($vendedor['nit']); ?>">
                                        <?php echo htmlspecialchars($vendedor['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="section-title" id="filtro-municipio">
                        <i class="fas fa-map-marker-alt"></i> Filtro por Municipio
                    </div>
                    <div class="row" id="municipio-section">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="municipio" class="form-label">Seleccionar Municipio:</label>
                                <select id="municipio" name="municipio" class="form-control">
                                    <option value="">-- Todos los municipios --</option>
                                    <?php foreach ($municipios as $municipio): ?>
                                        <option value="<?php echo htmlspecialchars($municipio); ?>">
                                            <?php echo htmlspecialchars($municipio); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section-title" id="rango-fechas">
                        <i class="fas fa-calendar-alt"></i> Rango de Fechas
                    </div>
                    <div class="row" id="fechas-section">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="fecha_inicio" class="form-label">Fecha Inicio:</label>
                                <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="fecha_fin" class="form-label">Fecha Fin:</label>
                                <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-success btn-export">
                            <i class="fas fa-download"></i> Generar Exportación
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div style="margin-top: 1.5rem; text-align: center;">
            <a href="tabla.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver al listado de rutas
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectExportType(type) {
            document.querySelectorAll('.option-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            const exportTypeInput = document.getElementById('export-type');
            const multipleOptions = document.getElementById('multiple-options');
            const filtrosExportacion = document.getElementById('filtros-exportacion');
            const selectorVendedor = document.getElementById('selector-vendedor');
            const municipioSection = document.getElementById('municipio-section');
            const fechasSection = document.getElementById('fechas-section');
            const rangoFechas = document.getElementById('rango-fechas');
            const municipioTitle = document.getElementById('filtro-municipio');
            
            if (type === 'simple') {
                exportTypeInput.value = 'excel';
                multipleOptions.style.display = 'none';
                if (filtrosExportacion) filtrosExportacion.style.display = 'block';
                if (selectorVendedor) selectorVendedor.style.display = document.getElementById('opcion-vendedor').checked ? 'block' : 'none';
                municipioSection.style.display = 'block';
                fechasSection.style.display = 'block';
                rangoFechas.style.display = 'block';
                municipioTitle.style.display = 'block';
            } else if (type === 'multiple') {
                exportTypeInput.value = 'multiple';
                multipleOptions.style.display = 'block';
                if (filtrosExportacion) filtrosExportacion.style.display = 'block';
                if (selectorVendedor) selectorVendedor.style.display = document.getElementById('opcion-vendedor').checked ? 'block' : 'none';
                municipioSection.style.display = 'block';
                fechasSection.style.display = 'block';
                rangoFechas.style.display = 'block';
                municipioTitle.style.display = 'block';
            } else if (type === 'todas_rutas') {
                exportTypeInput.value = 'todas_rutas';
                multipleOptions.style.display = 'none';
                if (filtrosExportacion) filtrosExportacion.style.display = 'none';
                if (selectorVendedor) selectorVendedor.style.display = 'none';
                municipioSection.style.display = 'none';
                fechasSection.style.display = 'none';
                rangoFechas.style.display = 'none';
                municipioTitle.style.display = 'none';
            }
        }

        function toggleVendedorSelect(enable) {
            const select = document.getElementById('vendedor');
            const selectorVendedor = document.getElementById('selector-vendedor');
            if (enable) {
                selectorVendedor.style.display = 'block';
                select.disabled = false;
                select.setAttribute('name', 'vendedor[]');
            } else {
                selectorVendedor.style.display = 'none';
                select.disabled = true;
                for (let option of select.options) {
                    option.selected = false;
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('opcion-todos').checked = true;
            toggleVendedorSelect(false);
            // Seleccionar "Exportación Múltiple" por defecto
            document.querySelector('.option-card:nth-child(2)').classList.add('selected');
        });

        document.getElementById('export-form').addEventListener('submit', (event) => {
            const exportType = document.getElementById('export-type').value;
            
            if (exportType === 'multiple') {
                const checkboxes = document.querySelectorAll('input[name="tablas[]"]:checked');
                if (checkboxes.length === 0) {
                    event.preventDefault();
                    alert('Por favor, selecciona al menos una tabla para exportar.');
                    return false;
                }
            }
            
            const nocache = document.createElement('input');
            nocache.type = 'hidden';
            nocache.name = 'nocache';
            nocache.value = new Date().getTime();
            event.target.appendChild(nocache);
        });
    </script>
</body>
</html>
<?php
}
ob_end_flush();
?>