<?php
/** Autorización y procedencia exclusiva; los formularios generales no usan este servicio. */
require_once __DIR__ . '/../helpers/ProgramaEducativo.php';
class FormularioAliadoService
{
    private $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    public function resolver($token, bool $bloquear = false, string $tipo = "inscripcion"): array
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new DomainException('El enlace del aliado no es válido o está inactivo.');
        }
        $registroDisponible = (bool)$this->db->query("SHOW TABLES LIKE 'formulario_aliado_registro'")->num_rows;
        // Un perfil nuevo queda inactivo para los lectores antiguos/Inscripción.
        // Sólo Registro puede resolverlo mediante su configuración explícita.
        $condicion = ($tipo === 'registro' && $registroDisponible)
            ? '(activo=1 OR EXISTS(SELECT 1 FROM formulario_aliado_registro perfil WHERE perfil.aliado_id=formulario_aliados.id))' : 'activo=1';
        $stmt = $this->db->prepare('SELECT id, nombre, ubicacion, seguimiento_id FROM formulario_aliados WHERE token = ? AND ' . $condicion . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $aliado = $stmt->get_result()->fetch_assoc();
        if (!$aliado) { throw new DomainException('El enlace del aliado no es válido o está inactivo.'); }
        if ($registroDisponible) {
            $q = $this->db->prepare('SELECT r.*,m.activo manual_activo,s.activo institucional_activo,EXISTS(SELECT 1 FROM aliados_asignaciones asignacion WHERE asignacion.seguimiento_id=r.seguimiento_id AND asignacion.activo=1) institucional_asignado FROM formulario_aliado_registro r LEFT JOIN aliados_manuales m ON m.id=r.manual_id LEFT JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id WHERE r.aliado_id=?');
            $q->bind_param('i', $aliado['id']); $q->execute(); $perfil=$q->get_result()->fetch_assoc();
            $aliado['registro_gestionado'] = (bool)$perfil;
            if ($perfil && ($tipo !== 'registro' || !$perfil['publicado'] || !$perfil['habilitado'] || ($perfil['manual_id'] && !$perfil['manual_activo']) || ($perfil['seguimiento_id'] && (!$perfil['institucional_activo'] || !$perfil['institucional_asignado'])))) {
                throw new DomainException('El enlace de Registro no está disponible.');
            }
        }
        $stmt = $this->db->prepare('SELECT p.codigo, p.nombre FROM formulario_aliado_programas ap INNER JOIN formulario_programas p ON p.codigo = ap.programa_codigo WHERE ap.aliado_id = ? AND p.activo = 1 ORDER BY p.nombre' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i', $aliado['id']);
        $stmt->execute();
        $aliado['programas'] = ProgramaEducativo::programas($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        return $aliado;
    }

    public function autorizar(array $aliado, string $nombre): string
    {
        foreach ($aliado['programas'] as $programa) {
            if (ProgramaEducativo::nombre($programa['nombre']) === ProgramaEducativo::nombre($nombre)) { return ProgramaEducativo::codigo($programa['codigo']); }
        }
        throw new DomainException('El programa seleccionado no está autorizado para este aliado.');
    }

    public function guardar(string $token, array $datos, string $tipo, callable $insertar): int
    {
        if (!in_array($tipo, ['registro', 'inscripcion'], true)) { throw new InvalidArgumentException('Formulario inválido.'); }
        // Una subconsulta del resolver puede abrir un read-view antes de esperar
        // el bloqueo del aliado. Registro debe leer la autorización ya confirmada
        // al obtenerlo. SET TRANSACTION sólo afecta esta transacción, no la sesión.
        if ($tipo === 'registro') { $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        $this->db->begin_transaction();
        try {
            // Serializa reenvíos por aliado; revalida la autorización antes de insertar.
            $aliado = $this->resolver($token, true, $tipo);
            if ($tipo === 'registro') {
                $q = $this->db->prepare('SELECT m.id FROM municipios m JOIN estados e ON e.id=m.estado_id AND e.estado=1 WHERE m.id=? AND m.estado_id=? AND m.estado=1');
                $q->bind_param('ii', $datos['municipio_id'], $datos['estado_id']); $q->execute();
                if (!$q->get_result()->num_rows) throw new DomainException('El municipio no corresponde al estado.');
            }
            $codigo = $this->autorizar($aliado, $datos['perfil_interes']);
            [$clave,$claveAnterior] = ProgramaEducativo::clavesProcedencia(
                [$datos['correo'], $datos['movil'], $datos['fecha_nacimiento'], $codigo],
                isset($aliado['convocatoria_id']) ? (int)$aliado['convocatoria_id'] : null
            );
            $stmt = $this->db->prepare('SELECT registro_id FROM formulario_aliado_procedencias WHERE aliado_id = ? AND tipo = ? AND clave IN (?,?) ORDER BY id LIMIT 1' . ($tipo === 'registro' ? ' FOR UPDATE' : ''));
            $stmt->bind_param('isss', $aliado['id'], $tipo, $clave, $claveAnterior);
            $stmt->execute();
            $existente = $stmt->get_result()->fetch_assoc();
            if ($existente) {
                $this->db->commit();
                return (int)$existente['registro_id'];
            }
            $id = (int)$insertar();
            $stmt = $this->db->prepare('INSERT INTO formulario_aliado_procedencias (aliado_id, tipo, registro_id, programa_codigo, aliado_nombre, aliado_ubicacion, clave) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isissss', $aliado['id'], $tipo, $id, $codigo, $aliado['nombre'], $aliado['ubicacion'], $clave);
            if (!$stmt->execute()) { throw new RuntimeException('No se pudo guardar la procedencia.'); }
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
