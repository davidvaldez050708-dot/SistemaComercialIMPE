<?php
require_once __DIR__ . '/AliadoRegistroService.php';

/** Consulta comercial de ambos orígenes. No envía mensajes ni crea expedientes. */
class AliadoHistorialService
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    private function manualDisponible(): bool
    {
        return (bool)$this->db->query("SHOW TABLES LIKE 'aliados_manuales_difusiones'")->num_rows;
    }

    public static function normalizarManual(array $row): array
    {
        $nota = (string)($row['nota'] ?? '');
        $canal = strtoupper((string)($row['canal'] ?? 'OTRO'));
        $item = [
            'id' => (int)$row['id'], 'convocatoria_id' => null,
            'canal' => $canal === 'WHATSAPP' ? 'WHATSAPP_MANUAL' : $canal,
            'convocatoria_titulo' => 'Difusión registrada', 'destinatario' => '',
            'enviado_at' => $row['realizado_at'],
            'enviado_por_nombre' => $row['responsable'] ?? '',
            'estado_envio' => 'REGISTRADO', 'nota' => $nota
        ];
        // Las difusiones antiguas de Compartir conservan esta instantánea textual.
        // No dividir programa/título: ambos pueden contener puntos medios.
        if (in_array($canal, ['CORREO','WHATSAPP'], true) && preg_match('/^Convocatoria #([1-9]\d*) · (.+)$/us', $nota, $match)) {
            $resto = $match[2];
            $separador = strrpos($resto, ' · ');
            if ($separador !== false) {
                $item['convocatoria_id'] = (int)$match[1];
                $item['convocatoria_titulo'] = substr($resto, 0, $separador);
                $item['destinatario'] = substr($resto, $separador + strlen(' · '));
                $item['estado_envio'] = $canal === 'CORREO' ? 'ENVIADO' : 'COMPARTIDO';
                $item['nota'] = '';
            }
        }
        return $item;
    }

    public function consultar(string $tipo, int $id): array
    {
        if (empty($_SESSION['usuario_id'])) throw new DomainException('Inicia sesión nuevamente.', 401);
        if (!tienePermiso('aliados.ver') || !tienePermiso('aliados.ver_historial')) {
            throw new DomainException('No tienes permiso para consultar el historial.', 403);
        }
        if (!in_array($tipo, ['manual', 'institucional'], true) || $id <= 0) {
            throw new DomainException('Origen de aliado inválido.', 422);
        }
        $nacional = puedeConsultarAliadosNacional();
        if ($tipo === 'manual') {
            $aliado = (new AliadoRegistroService($this->db))->origen($tipo, $id, $nacional);
            $historial = [];
            if ($this->manualDisponible()) {
                $stmt = $this->db->prepare('SELECT d.*, CONCAT_WS(" ",u.nombre,u.apellidos) responsable FROM aliados_manuales_difusiones d LEFT JOIN usuarios u ON u.id=d.registrado_por WHERE d.aliado_id=? ORDER BY d.realizado_at DESC,d.id DESC');
                $stmt->bind_param('i', $id); $stmt->execute();
                $historial = array_map([self::class, 'normalizarManual'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            }
        } else {
            require_once __DIR__ . '/../models/AliadoModel.php';
            $model = new AliadoModel();
            $aliado = $model->obtenerAliado($id, (int)$_SESSION['usuario_id'], $nacional);
            if (!$aliado) throw new DomainException('No tienes acceso a este aliado.', 403);
            $historial = $model->obtenerHistorial($id, (int)$_SESSION['usuario_id'], $nacional);
        }
        return ['ok' => true, 'aliado' => $aliado, 'origen_tipo' => $tipo, 'historial' => $historial];
    }

    /** Una consulta para todas las filas manuales; nunca mezcla sus IDs con Vinculación. */
    public function decorarManuales(array $filas): array
    {
        $ids = [];
        foreach ($filas as $fila) {
            if (($fila['origen_tipo'] ?? '') === 'manual') $ids[] = (int)($fila['origen_id'] ?? $fila['id']);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids || !$this->manualDisponible()) return $filas;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT d.*, CONCAT_WS(' ',u.nombre,u.apellidos) responsable FROM aliados_manuales_difusiones d LEFT JOIN usuarios u ON u.id=d.registrado_por WHERE d.aliado_id IN ($marks) AND NOT EXISTS (SELECT 1 FROM aliados_manuales_difusiones reciente WHERE reciente.aliado_id=d.aliado_id AND (reciente.realizado_at>d.realizado_at OR (reciente.realizado_at=d.realizado_at AND reciente.id>d.id)))");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids); $stmt->execute();
        $ultimos = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $ultimos[(int)$row['aliado_id']] = self::normalizarManual($row);
        foreach ($filas as &$fila) {
            if (($fila['origen_tipo'] ?? '') !== 'manual') continue;
            $last = $ultimos[(int)($fila['origen_id'] ?? $fila['id'])] ?? null;
            if (!$last) continue;
            $fila['ultimo_envio_id'] = $last['id'];
            $fila['ultimo_envio_at'] = $last['enviado_at'];
            $fila['ultimo_envio_canal'] = $last['canal'];
            $fila['ultima_convocatoria_titulo'] = $last['convocatoria_titulo'];
            // Resumen derivado del evento: no simula un seguimiento de Vinculación.
            $fila['seguimiento_convocatoria_estado'] = $last['estado_envio'] === 'ENVIADO' ? 'ESPERANDO_RESPUESTA' : ($last['estado_envio'] === 'COMPARTIDO' ? 'COMPARTIDA_MANUALMENTE' : 'REGISTRO_MANUAL');
        }
        unset($fila);
        return $filas;
    }
}
