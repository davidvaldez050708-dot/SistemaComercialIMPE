<?php

require_once __DIR__ . '/../../config/db_connection.php';

/** Revalida la sesión en las rutas directas que no pasan por index.php. */
final class TelefoniaSesionService
{
    public static function actualizarPermisos(int $usuarioId, mysqli $conexion): bool
    {
        $stmt = $conexion->prepare(
            'SELECT r.id AS rol_id, r.nombre AS rol, p.codigo
             FROM usuarios u
             INNER JOIN roles r ON r.id = u.rol_id AND r.estado = 1
             LEFT JOIN rol_permisos rp ON rp.rol_id = r.id
             LEFT JOIN permisos p ON p.id = rp.permiso_id AND p.estado = 1
             WHERE u.id = ? AND u.estado = 1
             ORDER BY p.codigo'
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$filas) {
            $_SESSION['permisos'] = [];
            return false;
        }

        $_SESSION['rol_id'] = (int)$filas[0]['rol_id'];
        $_SESSION['rol'] = (string)$filas[0]['rol'];
        $_SESSION['permisos'] = array_values(array_filter(array_column($filas, 'codigo'), 'is_string'));
        return true;
    }
}
