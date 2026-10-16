<?php
/**
 * Asociación de sólo lectura entre interacción WebRTC y transferencia PBX.
 *
 * La recepción autenticada registró el PBX y el expediente destinatario.
 * Nunca se enlaza por teléfono u hora solamente: se comprueban transferencia,
 * participante, respuesta, finalización y grabación firmada de la PBX.
 * Ante cualquier ambigüedad se devuelve null sin alterar la interacción.
 */
declare(strict_types=1);

class TelefoniaGrabacionExpedienteTransferidaService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public static function numero10(string $numero): string
    {
        $digits = preg_replace('/\\D+/', '', $numero) ?: '';
        return strlen($digits) >= 10 ? substr($digits, -10) : '';
    }

    /**
     * WebRTC almacena fecha_inicio en UTC. Los webhooks pueden recibirse con
     * zona de México o UTC, según la configuración real del hosting.
     * La fecha candidata siempre se interpreta en ambas zonas explícitas.
     */
    public static function distanciaSegundos(string $inicioUtc, string $evento): ?int
    {
        $utc = new DateTimeZone('UTC');
        $mexico = new DateTimeZone('America/Mexico_City');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $inicioUtc, $utc);
        if (!$start || $start->format('Y-m-d H:i:s') !== $inicioUtc) {
            return null;
        }
        $best = null;
        foreach ([$utc, $mexico] as $zone) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $evento, $zone);
            if (!$time || $time->format('Y-m-d H:i:s') !== $evento) continue;
            $delta = abs($time->getTimestamp() - $start->getTimestamp());
            $best = $best === null ? $delta : min($best, $delta);
        }
        return $best;
    }

    public function buscar(int $usuarioId, int $seguimientoId, int $interaccionId): ?string
    {
        if ($usuarioId <= 0 || $seguimientoId <= 0 || $interaccionId <= 0) {
            return null;
        }
        // El usuario debe ser el mismo analista que atendió y registró esta
        // sesión WebRTC; un supervisor que consulte el expediente no adquiere
        // automáticamente acceso al audio de otra extensión.
        $stmt = $this->db->prepare(
            "SELECT session_id, extension, numero_origen, fecha_inicio
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND seguimiento_id = ? AND interaccion_id = ?
                AND clasificacion = 'VINCULADA' AND estado = 'ended'
                AND fecha_atendida IS NOT NULL AND fecha_fin IS NOT NULL
              LIMIT 2"
        );
        $stmt->bind_param('iii', $usuarioId, $seguimientoId, $interaccionId);
        $stmt->execute();
        $sesiones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($sesiones) !== 1) return null;

        $sesion = $sesiones[0];
        $extension = (string)$sesion['extension'];
        $numero10 = self::numero10((string)$sesion['numero_origen']);
        $inicioUtc = (string)$sesion['fecha_inicio'];
        if ($numero10 === '' || !preg_match('/^[0-9]{3,6}$/D', $extension)) {
            return null;
        }

        // Vincular únicamente transferencias asociadas por Recepción al MISMO
        // expediente y a la MISMA extensión del analista, nunca estadísticas
        // buscadas por un teléfono aislado.
        $tablaContextos = $this->db->query(
            "SHOW TABLES LIKE 'telefonia_transferencia_contextos'"
        );
        $tieneTablaContextos = $tablaContextos && $tablaContextos->num_rows > 0;
        $transferencias = [];
        if ($tieneTablaContextos) {
            $stmt = $this->db->prepare(
                "SELECT pbx_call_id, created_at
                   FROM telefonia_transferencia_contextos
                  WHERE destino_usuario_id = ? AND destino_extension = ?
                    AND seguimiento_id = ? AND telefono10 = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                  ORDER BY created_at DESC LIMIT 120"
            );
            $stmt->bind_param('isis', $usuarioId, $extension, $seguimientoId, $numero10);
            $stmt->execute();
            $transferencias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // En una transferencia hecha desde el teléfono de Recepción (100),
        // Zadarma puede confirmar ambos tramos sin que el contexto opcional
        // del modal llegue a persistirse. Sólo en ese caso buscar grupos PBX
        // firmados en un intervalo acotado; nunca inferir audio por duración.
        if (count($transferencias) === 0) {
            $stmt = $this->db->prepare(
                "SELECT pbx_call_id, caller_id, received_at
                   FROM telefonia_zadarma_eventos
                  WHERE evento = 'NOTIFY_INTERNAL' AND internal = '100'
                    AND received_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                  ORDER BY id DESC LIMIT 150"
            );
            $stmt->execute();
            $iniciosRecepcion = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            // Evitar declarar unicidad si la ventana se truncó por LIMIT.
            if (count($iniciosRecepcion) >= 150) return null;
            $vistos = [];
            foreach ($iniciosRecepcion as $inicioRecepcion) {
                $pbx = trim((string)($inicioRecepcion['pbx_call_id'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbx) ||
                    isset($vistos[$pbx]) ||
                    self::numero10((string)($inicioRecepcion['caller_id'] ?? '')) !== $numero10) {
                    continue;
                }
                $vistos[$pbx] = true;
                // El inicio con Tania precede a la sesión observada por Diego.
                $delta = self::distanciaSegundos(
                    $inicioUtc, (string)($inicioRecepcion['received_at'] ?? '')
                );
                if ($delta === null || $delta > 180) continue;

                // Si ya hay un contexto institucional para el PBX, debe
                // corresponder exactamente a este expediente/analista.
                // Nunca utilizar la búsqueda alternativa para eludirlo.
                if ($tieneTablaContextos) {
                    $ctx = $this->db->prepare(
                        "SELECT destino_usuario_id, destino_extension, seguimiento_id
                           FROM telefonia_transferencia_contextos
                          WHERE pbx_call_id = ? LIMIT 2"
                    );
                    $ctx->bind_param('s', $pbx);
                    $ctx->execute();
                    $contextosPbx = $ctx->get_result()->fetch_all(MYSQLI_ASSOC);
                    $ctx->close();
                    if (count($contextosPbx) > 1) return null;
                } else {
                    $contextosPbx = [];
                }
                if ($contextosPbx) {
                    $registrado = $contextosPbx[0];
                    if ((int)$registrado['destino_usuario_id'] !== $usuarioId ||
                        (string)$registrado['destino_extension'] !== $extension ||
                        (int)$registrado['seguimiento_id'] !== $seguimientoId) {
                        continue;
                    }
                }
                $transferencias[] = ['pbx_call_id' => $pbx];
            }
        }

        $candidatas = [];
        foreach ($transferencias as $contexto) {
            $pbx = trim((string)$contexto['pbx_call_id']);
            if (!preg_match('/^[A-Za-z0-9_-]{6,100}$/D', $pbx)) continue;
            $stmt = $this->db->prepare(
                "SELECT
                    MIN(CASE WHEN evento = 'NOTIFY_INTERNAL' AND internal = ?
                        THEN received_at END) AS llegada,
                    SUM(CASE WHEN evento = 'NOTIFY_ANSWER' AND internal = ?
                        THEN 1 ELSE 0 END) AS respondida,
                    SUM(CASE WHEN evento = 'NOTIFY_END'
                        THEN 1 ELSE 0 END) AS finalizada,
                    SUM(CASE WHEN evento = 'NOTIFY_RECORD'
                         AND TRIM(COALESCE(call_id_with_rec,'')) <> ''
                        THEN 1 ELSE 0 END) AS con_audio
                   FROM telefonia_zadarma_eventos
                  WHERE pbx_call_id = ?"
            );
            $stmt->bind_param('sss', $extension, $extension, $pbx);
            $stmt->execute();
            $e = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            if (empty($e['llegada']) || (int)($e['respondida'] ?? 0) < 1 ||
                (int)($e['finalizada'] ?? 0) < 1 ||
                (int)($e['con_audio'] ?? 0) < 1) {
                continue;
            }
            // La llegada PBX de esta extensión coincide con el inicio
            // observado por el navegador con un margen máximo de 90 segundos.
            $delta = self::distanciaSegundos($inicioUtc, (string)$e['llegada']);
            if ($delta === null || $delta > 90) continue;
            $candidatas[$pbx] = true;
            if (count($candidatas) > 1) return null;
        }
        if (count($candidatas) !== 1) return null;

        // Dos sesiones cercanas del MISMO número podrían pertenecer a
        // distintas transferencias. En tal caso no se selecciona ninguna.
        $stmt = $this->db->prepare(
            "SELECT session_id, numero_origen, fecha_inicio
               FROM telefonia_analista_webrtc_sesiones
              WHERE usuario_id = ? AND extension = ? AND estado = 'ended'
                AND fecha_inicio >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
              ORDER BY fecha_inicio DESC LIMIT 300"
        );
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $otras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($otras as $otra) {
            if ((string)$otra['session_id'] === (string)$sesion['session_id'] ||
                self::numero10((string)$otra['numero_origen']) !== $numero10) {
                continue;
            }
            $a = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                $inicioUtc, new DateTimeZone('UTC'));
            $b = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
                (string)$otra['fecha_inicio'], new DateTimeZone('UTC'));
            if ($a && $b && abs($a->getTimestamp() - $b->getTimestamp()) <= 180) {
                return null;
            }
        }
        return array_key_first($candidatas);
    }
}
