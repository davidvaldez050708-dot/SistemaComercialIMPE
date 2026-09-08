<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/db_connection.php';

const DIEGO_USUARIO_ID = 5;
const DIEGO_CORREO_HOSTINGER = 'd.institucional2@rededucativamexico.org';

$aplicar = in_array('--aplicar', $argv, true);

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$db = new Database();
$conexion = $db->connect();

$stmt = $conexion->prepare(
    "SELECT id, nombre, apellidos, correo, rol_id, estado
     FROM usuarios
     WHERE id = ?
     LIMIT 1"
);
$id = DIEGO_USUARIO_ID;
$stmt->bind_param('i', $id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();

$linea('Actualización de correo institucional - Diego Bahena');
$linea(str_repeat('=', 51));
$linea();

if (!$usuario) {
    $linea('[ERROR] No se encontró el usuario con ID ' . DIEGO_USUARIO_ID . '.');
    exit(1);
}

$nombreCompleto = trim((string)$usuario['nombre'] . ' ' . (string)$usuario['apellidos']);
$linea('Usuario encontrado: ' . $nombreCompleto . ' | ID ' . $usuario['id']);
$linea('Correo actual: ' . (string)$usuario['correo']);
$linea('Correo objetivo: ' . DIEGO_CORREO_HOSTINGER);

$nombreNormalizado = mb_strtolower($nombreCompleto, 'UTF-8');
if (strpos($nombreNormalizado, 'diego') === false || strpos($nombreNormalizado, 'bahena') === false) {
    $linea('[ERROR] El ID ' . DIEGO_USUARIO_ID . ' no parece corresponder a Diego Bahena.');
    $linea('No se realizó ningún cambio.');
    exit(1);
}

$stmtDuplicado = $conexion->prepare(
    "SELECT id, nombre, apellidos
     FROM usuarios
     WHERE LOWER(correo) = LOWER(?)
       AND id <> ?
     LIMIT 1"
);
$correoObjetivo = DIEGO_CORREO_HOSTINGER;
$stmtDuplicado->bind_param('si', $correoObjetivo, $id);
$stmtDuplicado->execute();
$duplicado = $stmtDuplicado->get_result()->fetch_assoc();

if ($duplicado) {
    $linea('[ERROR] El correo institucional ya está utilizado por otro usuario:');
    $linea('  ID ' . $duplicado['id'] . ' | ' . trim($duplicado['nombre'] . ' ' . $duplicado['apellidos']));
    $linea('No se realizó ningún cambio.');
    exit(1);
}

if (strcasecmp(trim((string)$usuario['correo']), DIEGO_CORREO_HOSTINGER) === 0) {
    $linea();
    $linea('[OK] Diego ya tiene configurado el correo institucional correcto.');
    exit(0);
}

if (!$aplicar) {
    $linea();
    $linea('[LISTO PARA APLICAR] No se realizó ningún cambio todavía.');
    $linea('Para actualizar únicamente el correo de Diego:');
    $linea('C:\\xampp\\php\\php.exe tools\\hostinger_mail_actualizar_diego.php --aplicar');
    exit(0);
}

$conexion->begin_transaction();

try {
    $stmtUpdate = $conexion->prepare(
        "UPDATE usuarios
         SET correo = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmtUpdate->bind_param('si', $correoObjetivo, $id);
    $stmtUpdate->execute();

    if ($stmtUpdate->affected_rows !== 1) {
        throw new RuntimeException('La actualización no modificó exactamente un usuario.');
    }

    $conexion->commit();
} catch (Throwable $e) {
    $conexion->rollback();
    $linea('[ERROR] No fue posible actualizar el correo de Diego.');
    $linea('Detalle: ' . $e->getMessage());
    exit(1);
}

$stmtVerificar = $conexion->prepare(
    "SELECT correo FROM usuarios WHERE id = ? LIMIT 1"
);
$stmtVerificar->bind_param('i', $id);
$stmtVerificar->execute();
$verificacion = $stmtVerificar->get_result()->fetch_assoc();

if (!$verificacion || strcasecmp((string)$verificacion['correo'], DIEGO_CORREO_HOSTINGER) !== 0) {
    $linea('[ERROR] La actualización se ejecutó, pero la verificación final no coincide.');
    exit(1);
}

$linea();
$linea('[OK] Correo institucional de Diego actualizado correctamente.');
$linea('[OK] Nuevo correo: ' . DIEGO_CORREO_HOSTINGER);
$linea('No se modificó contraseña, usuario, rol ni asignaciones territoriales.');
