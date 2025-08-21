

<?php
// Conexión a SQL Server
$serverName = "HERCULES"; // o localhost, o IP
$connectionOptions = [
    "Database" => "invoice_approval_system",
    "Uid" => "sa",       // reemplaza con tu usuario SQL
    "PWD" => "Sky2022*!",   // reemplaza con tu contraseña SQL
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// Datos del usuario
$email = 'SUBGERENTE@oral-plus.com';       // Correo del usuario al que vas a cambiarle la contraseña
$nuevaPassword = 'SKY2025STEVEN';            // Nueva contraseña que quieres asignar

// Encriptar la nueva contraseña
$hash = password_hash($nuevaPassword, PASSWORD_DEFAULT);

// Query SQL Server
$sql = "UPDATE users SET password = ? WHERE email = ?";
$params = [$hash, $email];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

echo "✅ Contraseña actualizada correctamente.";
sqlsrv_close($conn);
?>
