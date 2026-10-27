<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/SupervisionRolePolicy.php';

/** La cartera se trabaja por territorio vigente; la autoría no se reescribe. */
final class SeguimientoTerritorialScope
{
    private static function alias(string $alias): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Alias territorial inválido.');
        }
        return $alias;
    }

    public static function analista(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM asignaciones_territorio cartera_asignacion
            JOIN usuarios cartera_usuario ON cartera_usuario.id=cartera_asignacion.usuario_id
                AND cartera_usuario.estado=1 AND cartera_usuario.rol_id=4
            JOIN roles cartera_rol ON cartera_rol.id=cartera_usuario.rol_id AND cartera_rol.estado=1
            JOIN estados cartera_estado ON cartera_estado.id=cartera_asignacion.estado_id AND cartera_estado.estado=1
            WHERE cartera_asignacion.estado_id=$alias.estado_id AND cartera_asignacion.usuario_id=?
              AND cartera_asignacion.tipo_asignacion='ANALISTA_DATOS' AND cartera_asignacion.activo=1
              AND (cartera_asignacion.fecha_inicio IS NULL OR cartera_asignacion.fecha_inicio<=CURDATE())
              AND (cartera_asignacion.fecha_fin IS NULL OR cartera_asignacion.fecha_fin>=CURDATE()))";
    }

    public static function supervisor(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM asignaciones_territorio cartera_cuenta
            JOIN usuarios cartera_kam ON cartera_kam.id=cartera_cuenta.usuario_id AND cartera_kam.estado=1 AND cartera_kam.rol_id=6
            JOIN roles cartera_rol ON cartera_rol.id=cartera_kam.rol_id AND cartera_rol.estado=1
            JOIN estados cartera_estado ON cartera_estado.id=cartera_cuenta.estado_id AND cartera_estado.estado=1
            JOIN asignaciones_territorio cartera_analista ON cartera_analista.cuenta_clave_asignacion_id=cartera_cuenta.id
                AND cartera_analista.estado_id=cartera_cuenta.estado_id AND cartera_analista.tipo_asignacion='ANALISTA_DATOS'
                AND cartera_analista.activo=1
            JOIN usuarios cartera_usuario ON cartera_usuario.id=cartera_analista.usuario_id AND cartera_usuario.estado=1 AND cartera_usuario.rol_id=4
            JOIN roles cartera_rol_analista ON cartera_rol_analista.id=cartera_usuario.rol_id AND cartera_rol_analista.estado=1
            WHERE cartera_cuenta.usuario_id=? AND cartera_cuenta.estado_id=$alias.estado_id
              AND cartera_cuenta.tipo_asignacion='CUENTA_CLAVE' AND cartera_cuenta.activo=1
              AND (cartera_cuenta.fecha_inicio IS NULL OR cartera_cuenta.fecha_inicio<=CURDATE())
              AND (cartera_cuenta.fecha_fin IS NULL OR cartera_cuenta.fecha_fin>=CURDATE())
              AND (cartera_analista.fecha_inicio IS NULL OR cartera_analista.fecha_inicio<=CURDATE())
              AND (cartera_analista.fecha_fin IS NULL OR cartera_analista.fecha_fin>=CURDATE()))";
    }

    /** Sólo para consultas de ruta; las escrituras siempre usan analista(). */
    public static function lectura(string $alias): string
    {
        $scope = self::analista($alias);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0 || !function_exists('tienePermiso')
            || !tienePermiso('seguimientos_vinculacion.ver')) {
            return $scope;
        }
        if (function_exists('esRolSupervisionNacional') && esRolSupervisionNacional()) {
            return "($scope OR 1=1)";
        }
        $vigente = str_replace('?', (string)$usuarioId, self::analista($alias));
        $equipo = str_replace('?', (string)$usuarioId, self::supervisor($alias));
        return "($scope OR $vigente OR $equipo)";
    }

    public static function reunionLectura(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM seguimientos_vinculacion cartera_expediente
            WHERE cartera_expediente.id=$alias.seguimiento_id AND cartera_expediente.activo=1 AND "
            . self::lectura('cartera_expediente') . ')';
    }

    public static function observacionAnalista(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM seguimientos_vinculacion cartera_expediente
            WHERE cartera_expediente.id=$alias.seguimiento_id AND cartera_expediente.activo=1 AND "
            . self::analista('cartera_expediente') . ')';
    }

    public static function reunionAnalista(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM seguimientos_vinculacion cartera_expediente
            WHERE cartera_expediente.id=$alias.seguimiento_id AND cartera_expediente.activo=1 AND "
            . self::analista('cartera_expediente') . ')';
    }

    public static function reunionSupervisor(string $alias): string
    {
        $alias = self::alias($alias);
        return "EXISTS (SELECT 1 FROM seguimientos_vinculacion cartera_expediente
            WHERE cartera_expediente.id=$alias.seguimiento_id AND cartera_expediente.activo=1 AND "
            . self::supervisor('cartera_expediente') . ')';
    }

    public static function estadosAnalista(int $usuarioId, ?mysqli $db = null): array
    {
        $db = $db ?? (new Database())->connect();
        $sql = 'SELECT cartera_territorio.estado_id FROM (SELECT id AS estado_id FROM estados) cartera_territorio WHERE '
            . self::analista('cartera_territorio');
        $stmt = $db->prepare($sql);
        try {
            $stmt->bind_param('i', $usuarioId);
            $stmt->execute();
            return array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'estado_id'));
        } finally {
            $stmt->close();
        }
    }

    public static function analistasVigentes(?mysqli $db = null, int $estadoId = 0): array
    {
        $db = $db ?? (new Database())->connect();
        $sql = "SELECT DISTINCT u.id, TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) AS nombre
                FROM usuarios u JOIN asignaciones_territorio a ON a.usuario_id=u.id
                WHERE " . str_replace('?', 'u.id', self::analista('a')) . ($estadoId > 0 ? ' AND a.estado_id=' . $estadoId : '') . ' ORDER BY nombre,u.id';
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public static function puedeTrabajar(array $seguimiento, int $uid, ?mysqli $db = null): bool
    {
        return self::permitido($seguimiento, $uid, false, $db);
    }

    public static function puedeSupervisar(array $seguimiento, int $uid, ?mysqli $db = null): bool
    {
        return self::permitido($seguimiento, $uid, true, $db);
    }

    private static function permitido(array $seguimiento, int $uid, bool $supervisor, ?mysqli $db): bool
    {
        $id = (int)($seguimiento['id'] ?? $seguimiento['seguimiento_id'] ?? 0);
        if ($id <= 0 || $uid <= 0) return false;
        $db = $db ?? (new Database())->connect();
        $scope = $supervisor ? self::supervisor('cartera_seguimiento') : self::analista('cartera_seguimiento');
        $stmt = $db->prepare('SELECT cartera_seguimiento.id FROM seguimientos_vinculacion cartera_seguimiento
            WHERE cartera_seguimiento.id=? AND cartera_seguimiento.activo=1 AND '.$scope);
        try {
            $stmt->bind_param('ii', $id, $uid);
            $stmt->execute();
            return (bool)$stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
    }
}
