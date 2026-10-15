<?php
/**
 * Directorio de transferencias por territorio, propietario de expediente y
 * jerarquía real. Nunca autoriza extensiones arbitrarias del navegador.
 */
require_once __DIR__ . '/TelefoniaExtensionService.php';

final class TelefoniaEnrutamientoService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?: (new Database())->connect();
    }

    public static function categoriaRol(string $rol): string
    {
        $rol = mb_strtoupper(trim($rol), 'UTF-8');
        if ($rol === 'MARKETING') return 'RECEPCION';
        if ($rol === 'ANALISTA DE DATOS') return 'ANALISTA';
        if (in_array($rol, ['ASESOR', 'ASESOR DE VENTAS'], true)) return 'VENTAS';
        return 'OTRO';
    }

    private static function asignacionVigente(string $alias): string
    {
        return "$alias.activo = 1
            AND ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())";
    }

    /**
     * Devuelve solamente destinos con extensión activa, capacidad de recibir
     * y territorio vigente. La búsqueda se efectúa sobre ese subconjunto.
     * Cuenta Clave de un Asesor exige vínculo explícito (nunca deducido).
     */
    public function destinos(int $actorId, string $rol, int $estadoId = 0, string $tipo = '', string $busqueda = ''): array
    {
        $categoria = self::categoriaRol($rol);
        if ($actorId <= 0 || $categoria === 'OTRO') return [];
        if (!in_array($tipo, ['', 'INSTITUCION', 'PROSPECTO'], true)) {
            throw new InvalidArgumentException('Tipo de atención inválido.');
        }
        if ($estadoId < 0) {
            throw new InvalidArgumentException('Estado inválido.');
        }
        $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($actorId);
        if (!$asignacion || empty($asignacion['permite_transferir']) ||
            empty($asignacion['permite_entrantes'])) {
            return [];
        }
        // La recepción es una extensión real de Marketing, no un permiso
        // genérico para que todo usuario Marketing enrute instituciones.
        if ($categoria === 'RECEPCION' &&
            (string)($asignacion['origen'] ?? '') === 'LEGACY_CONFIG') {
            return [];
        }

        $tipoFiltro = "a.tipo_asignacion IN ('ANALISTA_DATOS', 'ASESOR')";
        if ($categoria === 'RECEPCION') {
            if ($tipo === 'INSTITUCION') $tipoFiltro = "a.tipo_asignacion = 'ANALISTA_DATOS'";
            if ($tipo === 'PROSPECTO') $tipoFiltro = "a.tipo_asignacion = 'ASESOR'";
        } else {
            $tipoFiltro = "a.tipo_asignacion = 'CUENTA_CLAVE'";
        }
        $restriccion = '';
        if ($categoria !== 'RECEPCION') {
            $clase = $categoria === 'ANALISTA' ? 'ANALISTA_DATOS' : 'ASESOR';
            $restriccion = " AND EXISTS (
                SELECT 1 FROM asignaciones_territorio origen
                WHERE origen.usuario_id = ? AND origen.tipo_asignacion = '$clase'
                  AND origen.cuenta_clave_asignacion_id = a.id
                  AND origen.estado_id = a.estado_id
                  AND " . self::asignacionVigente('origen') . "
            )";
        }
        // Un actor puede pertenecer a varios estados, pero sólo sus
        // Cuentas Clave explícitas serán destinatarias.
        $sql = "SELECT DISTINCT te.usuario_id, te.extension,
                   TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos,''))) AS nombre,
                   u.foto_perfil, r.nombre AS rol, a.estado_id,
                   e.nombre AS estado_nombre, a.tipo_asignacion
                FROM asignaciones_territorio a
                JOIN telefonia_extensiones te ON te.usuario_id = a.usuario_id
                    AND te.proveedor = 'ZADARMA' AND te.activo = 1
                    AND te.permite_entrantes = 1
                JOIN usuarios u ON u.id = a.usuario_id AND u.estado = 1
                JOIN roles r ON r.id = u.rol_id
                JOIN estados e ON e.id = a.estado_id AND e.estado = 1
                WHERE " . self::asignacionVigente('a') . "
                  AND $tipoFiltro AND a.usuario_id <> ?
                  AND EXISTS (
                    SELECT 1 FROM rol_permisos rp
                    JOIN permisos p ON p.id = rp.permiso_id
                    WHERE rp.rol_id = u.rol_id AND p.codigo = 'telefonia.usar'
                      AND p.estado = 1
                  )
                  AND EXISTS (
                    SELECT 1 FROM rol_permisos rp
                    JOIN permisos p ON p.id = rp.permiso_id
                    WHERE rp.rol_id = u.rol_id AND p.codigo = 'telefonia.recibir'
                      AND p.estado = 1
                  )
                  $restriccion
                ORDER BY a.estado_id, nombre, te.extension";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No se pudo consultar el directorio telefónico.');
        if ($categoria === 'RECEPCION') {
            $stmt->bind_param('i', $actorId);
        } else {
            $stmt->bind_param('ii', $actorId, $actorId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $res = [];
        $filtro = mb_strtolower(trim($busqueda), 'UTF-8');
        foreach ($rows as $row) {
            if ($estadoId > 0 && (int)$row['estado_id'] !== $estadoId) continue;
            $nombre = trim((string)$row['nombre']);
            if ($filtro !== '' && mb_strpos(mb_strtolower($nombre, 'UTF-8'), $filtro) === false &&
                strpos((string)$row['extension'], $filtro) === false) continue;
            $ext = (string)$row['extension'];
            if (!preg_match('/^[0-9]{3,6}$/', $ext)) continue;
            $res[] = [
                'usuario_id' => (int)$row['usuario_id'],
                'extension' => $ext,
                'nombre' => $nombre,
                'rol' => (string)$row['rol'],
                'estado_id' => (int)$row['estado_id'],
                'estado_nombre' => (string)$row['estado_nombre'],
                'tipo' => (string)$row['tipo_asignacion']
            ];
        }
        return $res;
    }

    public function estados(): array
    {
        $r = $this->db->query(
            "SELECT id, nombre FROM estados WHERE estado = 1 ORDER BY nombre"
        );
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Nunca regresar institución ajena al filtro / responsable válido. */
    public function instituciones(int $actorId, string $rol, int $estadoId, string $busqueda): array
    {
        if (self::categoriaRol($rol) !== 'RECEPCION' || $estadoId <= 0 ||
            mb_strlen(trim($busqueda), 'UTF-8') < 2) return [];
        $destinos = $this->destinos($actorId, $rol, $estadoId, 'INSTITUCION');
        $permitidos = [];
        foreach ($destinos as $d) $permitidos[(int)$d['usuario_id']] = $d;
        if (!$permitidos) return [];
        $q = '%' . mb_substr(trim($busqueda), 0, 80, 'UTF-8') . '%';
        $sql = "SELECT s.id, s.nombre_entidad, s.analista_id,
                       s.estado_id, s.telefono_fuente
                FROM seguimientos_vinculacion s
                WHERE s.estado_id = ? AND s.activo = 1
                  AND s.estado_seguimiento <> 'DESCARTADO'
                  AND s.nombre_entidad LIKE ?
                ORDER BY s.id DESC LIMIT 25";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('is', $estadoId, $q);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $matches = [];
        foreach ($rows as $row) {
            $owner = (int)$row['analista_id'];
            if (!isset($permitidos[$owner])) continue;
            $matches[] = [
                'seguimiento_id' => (int)$row['id'],
                'nombre' => (string)$row['nombre_entidad'],
                'responsable_id' => $owner,
                'responsable' => $permitidos[$owner]['nombre'],
                'extension' => $permitidos[$owner]['extension']
            ];
        }
        return $matches;
    }

    public function validarDestino(
        int $actorId, string $rol, string $extension,
        int $estadoId = 0, string $tipo = '', int $responsableId = 0
    ): array {
        if (!preg_match('/^[0-9]{3,6}$/', $extension)) {
            throw new DomainException('Selecciona una extensión válida del directorio.');
        }
        foreach ($this->destinos($actorId, $rol, $estadoId, $tipo) as $destino) {
            if ($destino['extension'] === $extension &&
                ($responsableId === 0 || $destino['usuario_id'] === $responsableId)) {
                return $destino;
            }
        }
        throw new DomainException('No estás autorizado para transferir a esa extensión.');
    }
}
