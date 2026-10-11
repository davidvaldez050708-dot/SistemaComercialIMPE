<?php
/**
 * Opcional, CLI exclusivamente. Concede telefonia.salientes al ROL Marketing.
 * Por defecto sólo inspecciona; para confirmar:
 *   php tools/habilitar_devoluciones_marketing.php --apply
 *
 * Cambia el permiso del ROL (todas las cuentas Marketing), pero NO activa
 * extensiones ni llamadas salientes por usuario: eso se hace en Telefonía.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo CLI.');
}

$aplicar = in_array('--apply', $argv, true);
if (count(array_diff(array_slice($argv, 1), ['--apply'])) > 0) {
    fwrite(STDERR, "Uso: php tools/habilitar_devoluciones_marketing.php [--apply]\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/db_connection.php';

try {
    $db = (new Database())->connect();

    $fila = $db->query(
        "SELECT r.id AS rol_id,
                (SELECT COUNT(*) FROM usuarios u
                 WHERE u.rol_id = r.id AND u.estado = 1) AS usuarios_activos
         FROM roles r WHERE r.nombre = 'Marketing' LIMIT 1"
    )?->fetch_assoc();

    $permiso = $db->query(
        "SELECT id AS permiso_id FROM permisos
         WHERE codigo = 'telefonia.salientes' AND estado = 1 LIMIT 1"
    )?->fetch_assoc();

    if (!$fila || !$permiso) {
        throw new RuntimeException(
            'No se encontró el rol Marketing o el permiso telefonia.salientes activo.'
        );
    }
    $rolId = (int)$fila['rol_id'];
    $permisoId = (int)$permiso['permiso_id'];

    $st = $db->prepare(
        'SELECT COUNT(*) AS n FROM rol_permisos WHERE rol_id = ? AND permiso_id = ?'
    );
    $st->bind_param('ii', $rolId, $permisoId);
    $st->execute();
    $yaConcedido = (int)$st->get_result()->fetch_assoc()['n'] > 0;
    $st->close();

    echo "Rol: Marketing\n";
    echo "Usuarios activos alcanzados: " . (int)$fila['usuarios_activos'] . "\n";
    echo "Permiso telefonia.salientes: " .
        ($yaConcedido ? 'YA CONCEDIDO' : 'PENDIENTE') . "\n";

    if (!$aplicar) {
        echo "MODO CONSULTA: no se cambió MySQL.\n";
        echo "Si deseas permitir devoluciones a TODO el rol Marketing: --apply\n";
        exit(0);
    }
    if (!$yaConcedido) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)'
            );
            $stmt->bind_param('ii', $rolId, $permisoId);
            $stmt->execute();
            $stmt->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    echo "Permiso habilitado para Marketing. Activa salientes por usuario" .
        " en Administración > Telefonía > Extensiones y vuelve a iniciar sesión.\n";
} catch (Throwable $e) {
    error_log('[marketing-devoluciones] ' . $e->getMessage());
    fwrite(STDERR, "No se completó la operación. Revisa conexión y permisos de BD.\n");
    exit(1);
}
