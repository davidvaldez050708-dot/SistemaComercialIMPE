<?php
/** Autorización y procedencia exclusiva; los formularios generales no usan este servicio. */
class FormularioAliadoService
{
    private $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    public function resolver($token, bool $bloquear = false): array
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new DomainException('El enlace del aliado no es válido o está inactivo.');
        }
        $stmt = $this->db->prepare('SELECT id, nombre, ubicacion, seguimiento_id FROM formulario_aliados WHERE token = ? AND activo = 1' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $aliado = $stmt->get_result()->fetch_assoc();
        if (!$aliado) { throw new DomainException('El enlace del aliado no es válido o está inactivo.'); }
        $stmt = $this->db->prepare('SELECT p.codigo, p.nombre FROM formulario_aliado_programas ap INNER JOIN formulario_programas p ON p.codigo = ap.programa_codigo WHERE ap.aliado_id = ? AND p.activo = 1 ORDER BY p.nombre' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->bind_param('i', $aliado['id']);
        $stmt->execute();
        $aliado['programas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return $aliado;
    }

    public function autorizar(array $aliado, string $nombre): string
    {
        foreach ($aliado['programas'] as $programa) {
            if ($programa['nombre'] === $nombre) { return $programa['codigo']; }
        }
        throw new DomainException('El programa seleccionado no está autorizado para este aliado.');
    }

    public function guardar(string $token, array $datos, string $tipo, callable $insertar): int
    {
        if (!in_array($tipo, ['registro', 'inscripcion'], true)) { throw new InvalidArgumentException('Formulario inválido.'); }
        $this->db->begin_transaction();
        try {
            // Serializa reenvíos por aliado; revalida la autorización antes de insertar.
            $aliado = $this->resolver($token, true);
            $codigo = $this->autorizar($aliado, $datos['perfil_interes']);
            $clave = hash('sha256', json_encode([$datos['correo'], $datos['movil'], $datos['fecha_nacimiento'], $codigo], JSON_UNESCAPED_UNICODE));
            $stmt = $this->db->prepare('SELECT registro_id FROM formulario_aliado_procedencias WHERE aliado_id = ? AND tipo = ? AND clave = ?');
            $stmt->bind_param('iss', $aliado['id'], $tipo, $clave);
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
