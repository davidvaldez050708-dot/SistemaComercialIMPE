<?php
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

/**
 * Recepción telefónica de Marketing, independiente de Ventas y Vinculación.
 * Solo muestra entrantes de la extensión actualmente asignada al usuario,
 * desde la fecha en que adquirió esa extensión.
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
            "SELECT GREATEST(created_at, updated_at) AS asignada_desde FROM telefonia_extensiones
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
            'asignada_desde' => (string)$fila['asignada_desde']
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

        // Únicamente llamadas con NOTIFY_INTERNAL para ESTA extensión y
        // después de su asignación. Los eventos de otras extensiones solo
        // se leen para detectar una transferencia de esa misma llamada.
        $stmt = $this->db->prepare(
            "SELECT e.evento, e.pbx_call_id, e.internal, e.destination, e.caller_id,
                    e.received_at, e.duration, e.is_recorded,
                    e.call_id_with_rec, e.payload_json
             FROM telefonia_zadarma_eventos e
             WHERE e.received_at >= ?
               AND e.evento IN ('NOTIFY_INTERNAL', 'NOTIFY_ANSWER',
                    'NOTIFY_END', 'NOTIFY_RECORD')
               AND e.pbx_call_id IN (
                   SELECT inicial.pbx_call_id
                   FROM telefonia_zadarma_eventos inicial
                   WHERE inicial.internal = ?
                     AND inicial.evento = 'NOTIFY_INTERNAL'
                     AND inicial.received_at >= ?
               )
             ORDER BY e.id ASC"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el historial de recepción.');
        }
        $stmt->bind_param('sss', $limite, $ext, $limite);
        $stmt->execute();
        $rs = $stmt->get_result();
        $registros = [];
        while ($row = $rs->fetch_assoc()) {
            $id = (string)$row['pbx_call_id'];
            if ($id === '') continue;
            $tipo = strtoupper(trim((string)$row['evento']));
            $interno = trim((string)$row['internal']);

            if ($tipo === 'NOTIFY_INTERNAL' && $interno === $ext) {
                if (!isset($registros[$id])) {
                    $registros[$id] = [
                        'pbx_call_id' => $id,
                        'fecha' => (string)$row['received_at'],
                        'numero' => trim((string)$row['caller_id']),
                        'contestada' => false,
                        'finalizada' => false,
                        'transferida' => false,
                        'destino_transferencia' => '',
                        'segundos' => 0,
                        'audio_reportado' => false
                    ];
                }
            }
            // La centralita puede emitir notificaciones globales antes
            // del inicio de extensión; ignorarlas hasta conocer su inicio.
            if (!isset($registros[$id])) continue;
            $call = &$registros[$id];
            $destinationDigits = preg_replace('/\D+/', '', (string)($row['destination'] ?? '')) ?: '';
            $respuestaDeEstaExtension = $interno === $ext ||
                ($interno === '' && $destinationDigits !== '' &&
                    substr($destinationDigits, -strlen($ext)) === $ext);
            if ($tipo === 'NOTIFY_ANSWER' && $respuestaDeEstaExtension) {
                $call['contestada'] = true;
            }
            if ($tipo === 'NOTIFY_END' && ($interno === $ext || $interno === '')) {
                $call['finalizada'] = true;
                $call['segundos'] = max($call['segundos'], (int)$row['duration']);
            }
            if ($tipo === 'NOTIFY_RECORD' || (int)$row['is_recorded'] === 1 ||
                trim((string)$row['call_id_with_rec']) !== '') {
                $call['audio_reportado'] = true;
            }
            if ($tipo === 'NOTIFY_INTERNAL' && $interno !== $ext) {
                $payload = json_decode((string)$row['payload_json'], true);
                if (is_array($payload) &&
                    trim((string)($payload['transfer_from'] ?? '')) === $ext) {
                    $call['transferida'] = true;
                    $call['destino_transferencia'] = $interno;
                }
            }
            unset($call);
        }
        $stmt->close();

        foreach ($registros as $call) {
            if ($call['transferida']) {
                $call['estado'] = 'Transferida';
                $data['transferidas']++;
                // Una transferencia puede ser una llamada que Tania atendió.
                // Atendidas y transferidas son indicadores complementarios.
                if ($call['contestada']) $data['atendidas']++;
            } elseif ($call['contestada']) {
                $call['estado'] = $call['finalizada'] ? 'Atendida' : 'En curso';
                $data['atendidas']++;
            } elseif ($call['finalizada']) {
                $call['estado'] = 'Perdida';
                $data['perdidas']++;
            } else {
                $call['estado'] = 'Sonando';
                $data['en_curso']++;
            }
            // Una llamada transferida puede contener conversaciones ajenas
            // en la misma grabación PBX, así que no habilitar su descarga.
            $call['tiene_grabacion'] = !$call['transferida'] &&
                $call['contestada'] && $call['finalizada'] &&
                $call['audio_reportado'];
            unset($call['audio_reportado'], $call['contestada'],
                $call['finalizada'], $call['transferida']);
            $data['llamadas'][] = $call;
        }

        $data['total'] = count($data['llamadas']);
        usort($data['llamadas'], static function (array $a, array $b): int {
            return strcmp($b['fecha'], $a['fecha']);
        });
        return $data;
    }
}
