<?php
/** Autorización y procedencia exclusiva; los formularios generales no usan este servicio. */
require_once __DIR__ . '/FormularioUbicacionService.php';
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
        $stmt = $this->db->prepare('SELECT * FROM formulario_aliados WHERE token = ? AND ' . $condicion . ($bloquear ? ' FOR UPDATE' : ''));
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
        $aliado['internacional'] = empty($aliado['registro_gestionado']) && (isset($aliado['pais_codigo']) ? strtoupper($aliado['pais_codigo']) !== 'MX' : $aliado['seguimiento_id'] === null);
        $aliado['programas'] = ProgramaEducativo::programas($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        return $aliado;
    }

    public function resolverConvocatoria($token, $referencia, string $tipo, bool $bloquear = false): array
    {
        $aliado=$this->resolver($token,$bloquear,$tipo);
        if (!is_string($referencia)||!preg_match('/^[a-f0-9]{32}$/D',$referencia)) { throw new DomainException('Convocatoria no disponible.'); }
        $hoy=date('Y-m-d');
        $s=$this->db->prepare('SELECT x.convocatoria_id,x.programa_codigo FROM formulario_aliado_convocatorias x INNER JOIN convocatorias c ON c.id=x.convocatoria_id WHERE x.aliado_id=? AND x.referencia=? AND x.formulario=? AND c.estado=1 AND c.fecha_inicio<=? AND c.fecha_termino>=?' . ($bloquear?' FOR UPDATE':''));
        $s->bind_param('issss',$aliado['id'],$referencia,$tipo,$hoy,$hoy);$s->execute();$fila=$s->get_result()->fetch_assoc();
        if (!$fila) { throw new DomainException('Convocatoria no disponible.'); }
        $aliado['programas']=array_values(array_filter($aliado['programas'],static fn($p)=>$p['codigo']===ProgramaEducativo::codigo($fila['programa_codigo'])));
        if (!$aliado['programas']) { throw new DomainException('Programa de convocatoria no autorizado.'); }
        $aliado['convocatoria_id']=(int)$fila['convocatoria_id'];return $aliado;
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
            $aliado = isset($datos['convocatoria_ref']) ? $this->resolverConvocatoria($token,$datos['convocatoria_ref'],$tipo,true) : $this->resolver($token, true, $tipo);
            $datos = FormularioUbicacionService::validar($datos, $aliado['internacional'], $this->db);
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
            $id = (int)$insertar($datos);
            $stmt = $this->db->prepare('INSERT INTO formulario_aliado_procedencias (aliado_id, tipo, registro_id, programa_codigo, aliado_nombre, aliado_ubicacion, clave) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isissss', $aliado['id'], $tipo, $id, $codigo, $aliado['nombre'], $aliado['ubicacion'], $clave);
            if (!$stmt->execute()) { throw new RuntimeException('No se pudo guardar la procedencia.'); }
            if (isset($aliado['convocatoria_id'])) {
                $s=$this->db->prepare('UPDATE formulario_aliado_procedencias SET convocatoria_id=? WHERE tipo=? AND registro_id=?');
                $s->bind_param('isi',$aliado['convocatoria_id'],$tipo,$id);$s->execute();
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
