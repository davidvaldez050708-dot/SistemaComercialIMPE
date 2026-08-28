<?php

require_once __DIR__ . '/config/db_connection.php';

$database = new Database();
$conexion = $database->connect();

$sql = "
    SELECT
        usuarios.id,
        usuarios.nombre,
        usuarios.apellidos,
        usuarios.usuario,
        roles.nombre AS rol
    FROM usuarios
    INNER JOIN roles
        ON usuarios.rol_id = roles.id
";

$resultado = $conexion->query($sql);

echo "<h2>Prueba de conexión</h2>";

while ($usuario = $resultado->fetch_assoc()) {

    echo "Usuario: "
        . htmlspecialchars($usuario['usuario'])
        . "<br>";

    echo "Nombre: "
        . htmlspecialchars(
            $usuario['nombre'] . ' ' .
            $usuario['apellidos']
        )
        . "<br>";

    echo "Rol: "
        . htmlspecialchars($usuario['rol'])
        . "<hr>";
}