<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ZadarmaWebhookEventStoreService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->asegurarEstructura();
    }

    public function asegurarEstructura()
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_zadarma_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            evento VARCHAR(32) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            internal VARCHAR(20) NULL,
            destination VARCHAR(80) NULL,
            destination_digits VARCHAR(20) NULL,
            caller_id VARCHAR(80) NULL,
            call_start DATETIME NULL,
            duration INT NOT NULL DEFAULT 0,
            disposition VARCHAR(40) NULL,
            status_code VARCHAR(40) NULL,
            is_recorded TINYINT(1) NOT NULL DEFAULT 0,
            call_id_with_rec VARCHAR(100) NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload_json LONGTEXT NULL,
            event_hash CHAR(64) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_zadarma_event_hash (event_hash),
            KEY idx_zadarma_call_event (pbx_call_id, evento, id),
            KEY idx_zadarma_outgoing_lookup (
                internal,
                destination_digits,
                received_at
            ),
            KEY idx_zadarma_record_lookup (
                pbx_call_id,
                call_id_with_rec
            )
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            throw new RuntimeException(
                'No fue posible preparar el almacenamiento de eventos de telefonía.'
            );
        }

        $indice = $this->connection->query(
            "SHOW INDEX FROM telefonia_zadarma_eventos
             WHERE Key_name = 'idx_zadarma_incoming_lookup'"
        );

        if (!$indice || $indice->num_rows === 0) {
            $this->connection->query(
                "ALTER TABLE telefonia_zadarma_eventos
                 ADD KEY idx_zadarma_incoming_lookup (
                    internal,
                    evento,
                    received_at
                 )"
            );
        }

        return true;
    }

    public function registrar(array $registro)
    {
        $evento = strtoupper(trim((string)($registro['event'] ?? '')));
        $pbxCallId = trim((string)($registro['pbx_call_id'] ?? ''));

        if ($evento === '' || $pbxCallId === '') {
            return false;
        }

        $internal = trim((string)($registro['internal'] ?? ''));
        $destination = trim((string)($registro['destination'] ?? ''));
        $destinationDigits = $this->soloDigitos($destination);
        $callerId = trim((string)($registro['caller_id'] ?? ''));
        $callStart = $this->fechaMysql($registro['call_start'] ?? null);
        $duration = max(0, (int)($registro['duration'] ?? 0));
        $disposition = trim((string)($registro['disposition'] ?? ''));
        $statusCode = trim((string)($registro['status_code'] ?? ''));
        $isRecorded = $this->valorBooleano($registro['is_recorded'] ?? 0) ? 1 : 0;
        $callIdWithRec = trim((string)($registro['call_id_with_rec'] ?? ''));
        $receivedAt = $this->fechaMysql($registro['received_at'] ?? null)
            ?: date('Y-m-d H:i:s');
        $payloadJson = json_encode(
            $registro,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        $hashSource = implode('|', [
            $evento,
            $pbxCallId,
            $internal,
            $destinationDigits,
            (string)$callStart,
            (string)$duration,
            $disposition,
            $statusCode,
            $callIdWithRec,
        ]);
        $eventHash = hash('sha256', $hashSource);

        $sql = "INSERT IGNORE INTO telefonia_zadarma_eventos (
                    evento,
                    pbx_call_id,
                    internal,
                    destination,
                    destination_digits,
                    caller_id,
                    call_start,
                    duration,
                    disposition,
                    status_code,
                    is_recorded,
                    call_id_with_rec,
                    received_at,
                    payload_json,
                    event_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'sssssssississss',
            $evento,
            $pbxCallId,
            $internal,
            $destination,
            $destinationDigits,
            $callerId,
            $callStart,
            $duration,
            $disposition,
            $statusCode,
            $isRecorded,
            $callIdWithRec,
            $receivedAt,
            $payloadJson,
            $eventHash
        );
        $stmt->execute();

        return true;
    }

    /**
     * Marca de agua previa a una nueva marcación WebRTC. Al volver a llamar
     * al mismo destino evita reutilizar un NOTIFY_OUT_END anterior.
     */
    public function ultimoInicioSalienteId($extension)
    {
        $stmt = $this->connection->prepare(
            "SELECT COALESCE(MAX(id), 0) AS ultimo_id
             FROM telefonia_zadarma_eventos
             WHERE evento = 'NOTIFY_OUT_START' AND internal = ?"
        );
        $stmt->bind_param('s', $extension);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($fila['ultimo_id'] ?? 0);
    }

    public function buscarInicioSalienteReciente(
        $extension,
        $destino,
        $desdeUnix,
        $despuesDeId = 0
    ) {
        $extension = trim((string)$extension);
        $destinoDigits = $this->soloDigitos($destino);
        $despuesDeId = max(0, (int)$despuesDeId);
        $desdeUnix = max(time() - 1800, (int)$desdeUnix - 15);

        if (
            $extension === '' ||
            strlen($destinoDigits) < 8
        ) {
            return null;
        }

        $desde = date('Y-m-d H:i:s', $desdeUnix);
        $ultimos10 = strlen($destinoDigits) >= 10
            ? substr($destinoDigits, -10)
            : $destinoDigits;

        $sql = "SELECT *
                FROM telefonia_zadarma_eventos
                WHERE evento = 'NOTIFY_OUT_START'
                  AND internal = ?
                  AND received_at >= ?
                  AND id > ?
                  AND (
                    destination_digits = ?
                    OR RIGHT(destination_digits, 10) = ?
                  )
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'ssiss',
            $extension,
            $desde,
            $despuesDeId,
            $destinoDigits,
            $ultimos10
        );
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return $fila ? $this->normalizarFila($fila) : null;
    }

    public function obtenerPorPbxCallId($pbxCallId)
    {
        $pbxCallId = trim((string)$pbxCallId);
        if ($pbxCallId === '') {
            return [];
        }

        $sql = "SELECT *
                FROM telefonia_zadarma_eventos
                WHERE pbx_call_id = ?
                ORDER BY id ASC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $pbxCallId);
        $stmt->execute();
        $resultado = $stmt->get_result();

        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $this->normalizarFila($fila);
        }

        return $filas;
    }

    public function buscarEntranteRecientePorExtension(
        $extension,
        $desdeUnix = 0
    ) {
        $extension = trim((string)$extension);
        $desdeUnix = (int)$desdeUnix;

        if ($extension === '') {
            return null;
        }

        if ($desdeUnix <= 0) {
            $desdeUnix = time() - 600;
        }

        $desdeUnix = max(time() - 3600, $desdeUnix);
        $desde = date('Y-m-d H:i:s', $desdeUnix);

        $sql = "SELECT *
                FROM telefonia_zadarma_eventos
                WHERE evento = 'NOTIFY_INTERNAL'
                  AND internal = ?
                  AND received_at >= ?
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ss', $extension, $desde);
        $stmt->execute();
        $inicio = $stmt->get_result()->fetch_assoc();

        if (!$inicio) {
            return null;
        }

        return $this->construirEstadoEntrante(
            $extension,
            $this->normalizarFila($inicio)
        );
    }

    public function obtenerEstadoEntrantePorPbxCallId(
        $extension,
        $pbxCallId
    ) {
        $extension = trim((string)$extension);
        $pbxCallId = trim((string)$pbxCallId);

        if ($extension === '' || $pbxCallId === '') {
            return null;
        }

        $eventos = $this->obtenerPorPbxCallId($pbxCallId);
        $inicio = null;

        foreach ($eventos as $evento) {
            if (
                strtoupper(
                    trim((string)($evento['event'] ?? ''))
                ) === 'NOTIFY_INTERNAL' &&
                trim((string)($evento['internal'] ?? '')) ===
                    $extension
            ) {
                $inicio = $evento;
                break;
            }
        }

        if (!$inicio) {
            return null;
        }

        return $this->construirEstadoEntrante(
            $extension,
            $inicio,
            $eventos
        );
    }

    private function construirEstadoEntrante(
        $extension,
        array $inicio,
        array $eventos = []
    ) {
        $extension = trim((string)$extension);
        $pbxCallId = trim(
            (string)($inicio['pbx_call_id'] ?? '')
        );

        if ($extension === '' || $pbxCallId === '') {
            return null;
        }

        if (empty($eventos)) {
            $eventos =
                $this->obtenerPorPbxCallId(
                    $pbxCallId
                );
        }

        $respuesta = null;
        $fin = null;
        $transferida = null;

        foreach ($eventos as $evento) {
            $tipo = strtoupper(
                trim((string)($evento['event'] ?? ''))
            );
            $internal = trim(
                (string)($evento['internal'] ?? '')
            );

            $destination = preg_replace(
                '/\\D+/',
                '',
                (string)($evento['destination'] ?? '')
            ) ?: '';
            $lastInternal = trim(
                (string)($evento['last_internal'] ?? '')
            );
            $respuestaPerteneceExtension =
                $internal === $extension ||
                (
                    $internal === '' &&
                    $destination !== '' &&
                    substr(
                        $destination,
                        -strlen($extension)
                    ) === $extension
                );

            if (
                $tipo === 'NOTIFY_ANSWER' &&
                $respuestaPerteneceExtension
            ) {
                $respuesta = $evento;
            }

            if (
                $tipo === 'NOTIFY_END' &&
                (
                    $internal === '' ||
                    $internal === $extension ||
                    $lastInternal === $extension
                )
            ) {
                $fin = $evento;
            }

            if (
                $tipo === 'NOTIFY_INTERNAL' &&
                $internal !== $extension &&
                trim(
                    (string)(
                        $evento['transfer_from'] ?? ''
                    )
                ) === $extension
            ) {
                $transferida = $evento;
            }
        }

        $estado = 'ringing';

        if ($transferida) {
            $estado = 'transferred';
        } elseif ($fin) {
            $estado = 'ended';
        } elseif ($respuesta) {
            $estado = 'answered';
        }

        return [
            'pbx_call_id' => $pbxCallId,
            'estado' => $estado,
            'caller_id' =>
                (string)($inicio['caller_id'] ?? ''),
            'called_did' =>
                (string)($inicio['called_did'] ?? ''),
            'internal' => $extension,
            'call_start' =>
                (string)($inicio['call_start'] ?? ''),
            'answer_at' =>
                (string)($respuesta['received_at'] ?? ''),
            'end_at' =>
                (string)($fin['received_at'] ?? ''),
            'duration' =>
                max(0, (int)($fin['duration'] ?? 0)),
            'disposition' =>
                (string)($fin['disposition'] ?? ''),
            'transfer_to' =>
                (string)($transferida['internal'] ?? ''),
            'transfer_type' =>
                (string)($transferida['transfer_type'] ?? ''),
        ];
    }

    public function buscarCallIdGrabacion($pbxCallId)
    {
        $pbxCallId = trim((string)$pbxCallId);
        if ($pbxCallId === '') {
            return '';
        }

        $sql = "SELECT call_id_with_rec
                FROM telefonia_zadarma_eventos
                WHERE pbx_call_id = ?
                  AND TRIM(COALESCE(call_id_with_rec, '')) <> ''
                ORDER BY
                    CASE WHEN evento = 'NOTIFY_RECORD' THEN 0 ELSE 1 END,
                    id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $pbxCallId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return trim((string)($fila['call_id_with_rec'] ?? ''));
    }

    private function normalizarFila(array $fila)
    {
        $payload = json_decode(
            (string)($fila['payload_json'] ?? ''),
            true
        );
        $registro = is_array($payload) ? $payload : [];

        $registro['event'] =
            (string)($fila['evento'] ?? ($registro['event'] ?? ''));
        $registro['pbx_call_id'] =
            (string)($fila['pbx_call_id'] ?? ($registro['pbx_call_id'] ?? ''));
        $registro['internal'] =
            (string)($fila['internal'] ?? ($registro['internal'] ?? ''));
        $registro['destination'] =
            (string)($fila['destination'] ?? ($registro['destination'] ?? ''));
        $registro['called_did'] =
            (string)($registro['called_did'] ?? '');
        $registro['transfer_from'] =
            (string)($registro['transfer_from'] ?? '');
        $registro['transfer_type'] =
            (string)($registro['transfer_type'] ?? '');
        $registro['last_internal'] =
            (string)($registro['last_internal'] ?? '');
        $registro['caller_id'] =
            (string)($fila['caller_id'] ?? ($registro['caller_id'] ?? ''));
        $registro['call_start'] =
            (string)($fila['call_start'] ?? ($registro['call_start'] ?? ''));
        $registro['duration'] =
            (string)max(0, (int)($fila['duration'] ?? 0));
        $registro['disposition'] =
            (string)($fila['disposition'] ?? ($registro['disposition'] ?? ''));
        $registro['status_code'] =
            (string)($fila['status_code'] ?? ($registro['status_code'] ?? ''));
        $registro['is_recorded'] =
            (string)((int)($fila['is_recorded'] ?? 0));
        $registro['call_id_with_rec'] =
            (string)($fila['call_id_with_rec'] ?? ($registro['call_id_with_rec'] ?? ''));
        $registro['received_at'] =
            (string)($fila['received_at'] ?? ($registro['received_at'] ?? ''));

        return $registro;
    }

    private function soloDigitos($valor)
    {
        return preg_replace('/\D+/', '', (string)$valor) ?: '';
    }

    private function fechaMysql($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        try {
            $fecha = new DateTime($valor);
            $fecha->setTimezone(new DateTimeZone('America/Mexico_City'));
            return $fecha->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function valorBooleano($valor)
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return in_array(
            strtolower(trim((string)$valor)),
            ['1', 'true', 'yes', 'si', 'sí'],
            true
        );
    }
}
