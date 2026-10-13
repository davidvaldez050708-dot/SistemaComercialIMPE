<?php
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

/**
 * Recepción telefónica de Marketing, independiente de Ventas y Vinculación.
 * Muestra entrantes y salientes de la extensión asignada al usuario,
 * desde la fecha en que adquirió esa extensión. Nunca mezcla datos
 * de una extensión antes de que pertenezca a ese usuario.
 */
class TelefoniaRecepcionMarketingService
{
    private $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        (new ZadarmaWebhookEventStoreService())->asegurarEstructura();
    }

    public static function esMarketing(): bool
    {
        return strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
    }

    public static function tienePermisos(): bool
    {
        return self::esMarketing() &&
            tienePermiso('telefonia.usar') &&
            tienePermiso('telefonia.recibir');
    }

    public function asignacion(int $usuarioId): ?array
    {
        if ($usuarioId <= 0 || !self::tienePermisos()) {
            return null;
        }
        $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
        $extension = trim((string)($asignacion['extension'] ?? ''));
        if (
            !$asignacion || empty($asignacion['permite_entrantes']) ||
            !preg_match('/^\d{3,6}$/', $extension)
        ) {
            return null;
        }

        // Sin fallback de extensiones compartidas ni historial de otro dueño.
        $stmt = $this->db->prepare(
            "SELECT GREATEST(created_at, updated_at) AS asignada_desde,
                    permite_salientes FROM telefonia_extensiones
             WHERE usuario_id = ? AND extension = ? AND proveedor = 'ZADARMA'
               AND activo = 1 AND permite_entrantes = 1 LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar la extensión de recepción.');
        }
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila) {
            return null;
        }
        return [
            'extension' => $extension,
            'asignada_desde' => (string)$fila['asignada_desde'],
            'permite_salientes' =>
                !empty($asignacion['permite_salientes']) &&
                !empty($fila['permite_salientes']) &&
                tienePermiso('telefonia.salientes')
        ];
    }

    public function resumen(int $usuarioId): array
    {
        $data = [
            'configurada' => false,
            'extension' => '',
            'periodo' => 'Últimos 30 días',
            'total' => 0,
            'atendidas' => 0,
            'perdidas' => 0,
            'salientes' => 0,
            'transferidas' => 0,
            'en_curso' => 0,
            'llamadas' => []
        ];

        $asignacion = $this->asignacion($usuarioId);
        if (!$asignacion) return $data;

        $ext = $asignacion['extension'];
        $data['configurada'] = true;
        $data['extension'] = $ext;
        $limite = date('Y-m-d H:i:s', strtotime('-30 days'));
        if (strcmp($asignacion['asignada_desde'], $limite) > 0) {
            $limite = $asignacion['asignada_desde'];
        }

        // Inicios PBX firmados de esta extensión dentro del período:
        // NOTIFY_INTERNAL (entrante) o NOTIFY_OUT_START (saliente).
        // Ni números ni ID provienen del navegador; el origen es el webhook.
        $permitirSalientes = !empty($asignacion['permite_salientes']) ? 1 : 0;
        $stmt = $this->db->prepare(
            "SELECT e.evento, e.pbx_call_id, e.internal, e.destination,
                    e.caller_id, e.received_at, e.duration, e.disposition,
                    e.is_recorded, e.call_id_with_rec, e.payload_json
             FROM telefonia_zadarma_eventos e
             WHERE e.received_at >= ?
               AND e.evento IN ('NOTIFY_INTERNAL', 'NOTIFY_ANSWER',
                    'NOTIFY_END', 'NOTIFY_RECORD', 'NOTIFY_OUT_START',
                    'NOTIFY_OUT_END')
               AND e.pbx_call_id IN (
                   SELECT inicial.pbx_call_id
                   FROM telefonia_zadarma_eventos inicial
                   WHERE inicial.internal = ?
                     AND (
                        inicial.evento = 'NOTIFY_INTERNAL'
                        OR (inicial.evento = 'NOTIFY_OUT_START' AND ? = 1)
                     )
                     AND inicial.received_at >= ?
               )
             ORDER BY e.id ASC"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el historial telefónico.');
        }
        $stmt->bind_param('ssis', $limite, $ext, $permitirSalientes, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $eventos = [];
        while ($fila = $resultado->fetch_assoc()) {
            $eventos[] = $fila;
        }
        $stmt->close();

        $llamadas = self::agruparEventos($eventos, $ext, $permitirSalientes === 1);
        foreach ($llamadas as $call) {
            if ($call['tipo'] === 'Saliente') {
                $data['salientes']++;
            } else {
                // Los indicadores existentes conservan su significado:
                // solamente cuentan llamadas de recepción.
                $data['total']++;
                if (in_array($call['estado'], ['Atendida', 'En curso'], true)) {
                    $data['atendidas']++;
                }
                if ($call['estado'] === 'Perdida') $data['perdidas']++;
                if ($call['estado'] === 'Transferida') {
                    $data['transferidas']++;
                    if (!empty($call['fue_contestada'])) $data['atendidas']++;
                }
                if ($call['estado'] === 'Sonando') $data['en_curso']++;
            }
            unset($call['fue_contestada']);
            $data['llamadas'][] = $call;
        }

        usort($data['llamadas'], static function (array $a, array $b): int {
            return strcmp($b['fecha'], $a['fecha']);
        });
        return $data;
    }
    /**
     * Agrupador puro para eventos PBX, verificable sin conexión a Zadarma.
     * La pertenencia se prueba mediante un evento inicial de la propia
     * extensión; los eventos finales globales no crean llamadas ajenas.
     */
    public static function agruparEventos(
        array $eventos,
        string $extension,
        bool $permitirSalientes
    ): array {
        $registros = [];
        // Primero identificar los inicios, incluso si NOTIFY_ANSWER llegó
        // antes a la base por una demora en la entrega del webhook.
        foreach ($eventos as $row) {
            $id = trim((string)($row['pbx_call_id'] ?? ''));
            $tipo = strtoupper(trim((string)($row['evento'] ?? '')));
            $interno = trim((string)($row['internal'] ?? ''));
            if ($id === '' || $interno !== $extension) continue;

            $direction = $tipo === 'NOTIFY_INTERNAL' ? 'Entrante' :
                ($tipo === 'NOTIFY_OUT_START' && $permitirSalientes
                    ? 'Saliente' : '');
            if ($direction === '' || isset($registros[$id])) continue;
            $registros[$id] = [
                'pbx_call_id' => $id,
                'tipo' => $direction,
                'fecha' => (string)($row['received_at'] ?? ''),
                'numero' => trim((string)($direction === 'Entrante'
                    ? ($row['caller_id'] ?? '') : ($row['destination'] ?? ''))),
                'contestada' => false,
                'finalizada' => false,
                'transferida' => false,
                'destino_transferencia' => '',
                'disposition' => '',
                'segundos' => 0,
                'audio_reportado' => false
            ];
        }

        foreach ($eventos as $row) {
            $id = trim((string)($row['pbx_call_id'] ?? ''));
            if (!isset($registros[$id])) continue;
            $call = &$registros[$id];
            $tipo = strtoupper(trim((string)($row['evento'] ?? '')));
            $interno = trim((string)($row['internal'] ?? ''));
            $esSaliente = $call['tipo'] === 'Saliente';

            if ($esSaliente) {
                if ($tipo === 'NOTIFY_ANSWER' &&
                    ($interno === $extension || $interno === '')) {
                    $call['contestada'] = true;
                }
                if ($tipo === 'NOTIFY_OUT_END' &&
                    ($interno === $extension || $interno === '')) {
                    $call['finalizada'] = true;
                    $call['disposition'] = strtolower(trim(
                        (string)($row['disposition'] ?? '')
                    ));
                    $call['segundos'] = max(
                        $call['segundos'], (int)($row['duration'] ?? 0)
                    );
                }
            } else {
                $digits = preg_replace('/\D+/', '', (string)($row['destination'] ?? '')) ?: '';
                $respuestaPropia = $interno === $extension ||
                    ($interno === '' && $digits !== '' &&
                        substr($digits, -strlen($extension)) === $extension);
                if ($tipo === 'NOTIFY_ANSWER' && $respuestaPropia) {
                    $call['contestada'] = true;
                }
                if ($tipo === 'NOTIFY_END' &&
                    ($interno === $extension || $interno === '')) {
                    $call['finalizada'] = true;
                    $call['segundos'] = max(
                        $call['segundos'], (int)($row['duration'] ?? 0)
                    );
                }
                if ($tipo === 'NOTIFY_INTERNAL' && $interno !== $extension) {
                    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                    if (is_array($payload) &&
                        trim((string)($payload['transfer_from'] ?? '')) === $extension) {
                        $call['transferida'] = true;
                        $call['destino_transferencia'] = $interno;
                    }
                }
            }
            if ($tipo === 'NOTIFY_RECORD' ||
                (int)($row['is_recorded'] ?? 0) === 1 ||
                trim((string)($row['call_id_with_rec'] ?? '')) !== '') {
                $call['audio_reportado'] = true;
            }
            unset($call);
        }

        $resultado = [];
        foreach ($registros as $call) {
            if ($call['tipo'] === 'Saliente') {
                $disposition = $call['disposition'];
                if (!$call['finalizada']) {
                    $call['estado'] = $call['contestada'] ? 'En curso' : 'Marcando';
                } elseif ($disposition === 'answered' || $call['contestada']) {
                    $call['estado'] = 'Completada';
                } elseif ($disposition === 'busy') {
                    $call['estado'] = 'Ocupada';
                } elseif (in_array($disposition, ['no answer', 'no-answer', 'no_answer'], true)) {
                    $call['estado'] = 'Sin respuesta';
                } elseif (in_array($disposition, ['canceled', 'cancelled', 'cancel'], true)) {
                    $call['estado'] = 'Cancelada';
                } else {
                    $call['estado'] = 'Fallida';
                }
            } elseif ($call['transferida']) {
                $call['estado'] = 'Transferida';
            } elseif ($call['contestada']) {
                $call['estado'] = $call['finalizada'] ? 'Atendida' : 'En curso';
            } elseif ($call['finalizada']) {
                $call['estado'] = 'Perdida';
            } else {
                $call['estado'] = 'Sonando';
            }

            // Seguridad: el endpoint actual de audio autoriza SOLO
            // entrantes atendidas y no transferidas. No anunciar
            // grabaciones salientes hasta tener su autorización propia.
            $call['tiene_grabacion'] = $call['tipo'] === 'Entrante' &&
                !$call['transferida'] && $call['contestada'] &&
                $call['finalizada'] && $call['audio_reportado'];
            $call['fue_contestada'] = $call['contestada'];
            $call['grabacion_salida_pendiente'] =
                $call['tipo'] === 'Saliente' && $call['audio_reportado'];
            unset($call['audio_reportado'], $call['contestada'],
                $call['finalizada'], $call['transferida'], $call['disposition']);
            $resultado[] = $call;
        }
        return $resultado;
    }

}
