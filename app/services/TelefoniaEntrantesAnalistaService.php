<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';
require_once __DIR__ . '/TelefoniaAnalistaWebrtcService.php';
require_once __DIR__ . '/TelefoniaInternaGrabacionService.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';

/**
 * Bandeja de entrantes del Analista: fuente de verdad = webhooks firmados de
 * Zadarma; clasificación y expediente en una sola transacción de MySQL.
 * Nunca acepta extensión, duración, teléfono o proveedor desde el navegador.
 */
class TelefoniaEntrantesAnalistaService
{
    private $db;
    private $eventos;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->eventos = new ZadarmaWebhookEventStoreService();
        $this->asegurarEstructura();
    }

    private function asegurarEstructura(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_analista_entrantes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            extension VARCHAR(12) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            estado VARCHAR(24) NOT NULL DEFAULT 'PENDIENTE',
            seguimiento_id INT NULL,
            interaccion_id INT NULL,
            observacion VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_analista_llamada (usuario_id, pbx_call_id),
            KEY idx_analista_entrantes_estado (usuario_id, estado, updated_at),
            KEY idx_analista_entrantes_expediente (seguimiento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No fue posible preparar las llamadas recibidas.');
        }
    }

    private function extensionActual(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT te.extension, GREATEST(te.created_at, te.updated_at) AS desde
             FROM telefonia_extensiones te
             INNER JOIN usuarios u ON u.id = te.usuario_id AND u.estado = 1
             WHERE te.usuario_id = ? AND te.proveedor = 'ZADARMA'
               AND te.activo = 1 AND te.permite_entrantes = 1 LIMIT 1"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila || !preg_match('/^[0-9]{3,6}$/', (string)$fila['extension'])) {
            throw new DomainException('Asigna y activa una extensión de llamadas entrantes al Analista.');
        }
        return $fila;
    }

    private function inicioVerificado(int $usuarioId, string $pbxCallId): array
    {
        if ($usuarioId <= 0 || !preg_match('/^[a-zA-Z0-9_-]{3,100}$/', $pbxCallId)) {
            throw new InvalidArgumentException('Identificador de llamada no válido.');
        }
        $asignacion = $this->extensionActual($usuarioId);
        $ext = (string)$asignacion['extension'];
        $stmt = $this->db->prepare(
            "SELECT received_at FROM telefonia_zadarma_eventos
             WHERE pbx_call_id = ? AND internal = ?
               AND evento = 'NOTIFY_INTERNAL' AND received_at >= ?
             ORDER BY id ASC LIMIT 1"
        );
        $desde = max(
            strtotime('-30 days'),
            strtotime((string)$asignacion['desde']) ?: 0
        );
        $desdeMysql = date('Y-m-d H:i:s', $desde);
        $stmt->bind_param('sss', $pbxCallId, $ext, $desdeMysql);
        $stmt->execute();
        $inicio = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$inicio) {
            throw new DomainException('La llamada no pertenece a tu extensión actual.');
        }

        $llamada = $this->eventos->obtenerEstadoEntrantePorPbxCallId($ext, $pbxCallId);
        if (!$llamada) {
            throw new DomainException('No se encontró un evento entrante verificable.');
        }
        $llamada['extension'] = $ext;
        $llamada['fecha_evento'] = (string)$inicio['received_at'];
        return $llamada;
    }

    private static function segundosAtendidos(array $call): int
    {
        $inicio = strtotime((string)($call['answer_at'] ?? ''));
        $fin = strtotime((string)($call['end_at'] ?? ''));
        if ($inicio !== false && $fin !== false && $fin > $inicio) {
            return max(0, $fin - $inicio);
        }
        return max(0, (int)($call['duration'] ?? 0));
    }

    public function listar(int $usuarioId): array
    {
        $asignacion = $this->extensionActual($usuarioId);
        $extension = (string)$asignacion['extension'];
        $desde = date('Y-m-d H:i:s', max(
            strtotime('-30 days'),
            strtotime((string)$asignacion['desde']) ?: 0
        ));

        $stmt = $this->db->prepare(
            "SELECT pbx_call_id, MIN(received_at) AS fecha
             FROM telefonia_zadarma_eventos
             WHERE internal = ? AND evento = 'NOTIFY_INTERNAL'
               AND received_at >= ?
             GROUP BY pbx_call_id
             ORDER BY fecha DESC LIMIT 75"
        );
        $stmt->bind_param('ss', $extension, $desde);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        $fechas = [];
        while ($row = $result->fetch_assoc()) {
            $id = (string)$row['pbx_call_id'];
            if ($id === '') continue;
            $ids[] = $id;
            $fechas[$id] = (string)$row['fecha'];
        }
        $stmt->close();

        $clasificaciones = [];
        $stmt = $this->db->prepare(
            "SELECT pbx_call_id, estado, seguimiento_id, interaccion_id
             FROM telefonia_analista_entrantes
             WHERE usuario_id = ?
             ORDER BY id DESC LIMIT 250"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $clasificaciones[(string)$row['pbx_call_id']] = $row;
        }
        $stmt->close();

        $llamadas = [];
        $clasificadorInterno = new TelefoniaAnalistaWebrtcService($this->db);
        foreach ($ids as $id) {
            $call = $this->eventos->obtenerEstadoEntrantePorPbxCallId($extension, $id);
            if (!$call) continue;
            $registro = $clasificaciones[$id] ?? null;
            $llamadas[] = [
                'pbx_call_id' => $id,
                'numero' => (string)($call['caller_id'] ?? ''),
                'fuente' => 'PBX',
                'tipo_llamada' => $clasificadorInterno->esNumeroInterno(
                    (string)($call['caller_id'] ?? ''), $extension
                ) ? 'INTERNA' : 'ENTRANTE',
                'fecha' => $fechas[$id],
                'duracion' => self::segundosAtendidos($call),
                'estado_pbx' => (string)($call['estado'] ?? 'ringing'),
                'contestada' => !empty($call['answer_at']),
                'clasificacion' => (string)($registro['estado'] ?? 'PENDIENTE'),
                'seguimiento_id' => (int)($registro['seguimiento_id'] ?? 0),
                'interaccion_id' => (int)($registro['interaccion_id'] ?? 0),
                'grabacion_pbx_reportada' => (string)($call['estado'] ?? '') === 'ended' &&
                    $this->eventos->buscarCallIdGrabacion($id) !== '',
            ];
        }
        // Dos fuentes distintas: PBX firmada y observación de WebRTC local.
        // La segunda siempre se identifica como NO verificada por PBX.
        $sesiones = $clasificadorInterno->listar($usuarioId);
        // El audio se ofrece sólo desde el historial técnico de la extensión.
        // Una sesión interna aún asociada erróneamente a un expediente no
        // dispone del reproductor hasta completar su desvinculación segura.
        try {
            $grabacionesInternas = new TelefoniaInternaGrabacionService($this->db);
            $grabacionesInternas->asegurarEstructura();
            $conAudio = $grabacionesInternas->porUsuario($usuarioId);
        } catch (Throwable $e) {
            error_log('[telefonia_grabaciones_internas] ' . $e->getMessage());
            $conAudio = [];
        }
        foreach ($sesiones as &$filaSdk) {
            $filaSdk['grabacion_disponible'] =
                ($filaSdk['tipo_llamada'] ?? '') === 'INTERNA' &&
                ($filaSdk['clasificacion'] ?? '') === 'INTERNA' &&
                empty($filaSdk['seguimiento_id']) && empty($filaSdk['interaccion_id']) &&
                isset($conAudio[(string)$filaSdk['pbx_call_id']]);
        }
        unset($filaSdk);
        // Conciliar una observación WebRTC con su llamada PBX firmada SOLO
        // cuando la correspondencia es única en ambas direcciones. Nunca
        // se altera la base de datos ni se asigna audio por semejanza sola.
        $normalizarNumero = static function ($valor): string {
            $digitos = preg_replace('/\D+/', '', (string)$valor) ?: '';
            return strlen($digitos) >= 10 ? substr($digitos, -10) : '';
        };
        $timestamp = static function ($valor): int {
            $time = strtotime((string)$valor);
            return $time === false ? 0 : $time;
        };
        $candidatos = [];
        $inversos = [];
        foreach ($sesiones as $si => $sdk) {
            if (($sdk['tipo_llamada'] ?? '') !== 'ENTRANTE') continue;
            $numeroSdk = $normalizarNumero($sdk['numero'] ?? '');
            $tiempoSdk = $timestamp($sdk['fecha'] ?? '');
            if ($numeroSdk === '' || !$tiempoSdk) continue;
            foreach ($llamadas as $pi => $pbx) {
                if (($pbx['tipo_llamada'] ?? '') !== 'ENTRANTE') continue;
                $numeroPbx = $normalizarNumero($pbx['numero'] ?? '');
                $tiempoPbx = $timestamp($pbx['fecha'] ?? '');
                if ($numeroPbx !== $numeroSdk || !$tiempoPbx ||
                    abs($tiempoSdk - $tiempoPbx) > 60) continue;
                $candidatos[$si][] = $pi;
                $inversos[$pi][] = $si;
            }
        }
        $ocultarSdk = [];
        foreach ($candidatos as $si => $indicesPbx) {
            if (count($indicesPbx) !== 1) continue;
            $pi = $indicesPbx[0];
            if (count($inversos[$pi] ?? []) !== 1) continue;
            $sdk = $sesiones[$si];
            $pbx = &$llamadas[$pi];
            // Conservar el ID WebRTC para el formulario sin cambiar el ID PBX del audio.
            $pbx['sdk_session_id'] = (string)($sdk['pbx_call_id'] ?? '');
            // El evento PBX conserva identidad, duración y acceso a grabación.
            // La clasificación del registro SDK se aprovecha si PBX sigue pendiente.
            if (($pbx['clasificacion'] ?? 'PENDIENTE') === 'PENDIENTE' &&
                ($sdk['clasificacion'] ?? 'PENDIENTE') !== 'PENDIENTE') {
                $pbx['clasificacion'] = $sdk['clasificacion'];
            }
            foreach (['seguimiento_id', 'interaccion_id'] as $campo) {
                if ((int)($pbx[$campo] ?? 0) <= 0 && (int)($sdk[$campo] ?? 0) > 0) {
                    $pbx[$campo] = (int)$sdk[$campo];
                }
            }
            $ocultarSdk[$si] = true;
            unset($pbx);
        }
        foreach ($sesiones as $si => $sdk) {
            if (!isset($ocultarSdk[$si])) $llamadas[] = $sdk;
        }
        usort($llamadas, static function ($a, $b) {
            return strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? ''));
        });
        return ['extension' => $extension, 'llamadas' => array_slice($llamadas, 0, 100)];
    }

    public function estados(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT e.id, e.nombre
             FROM asignaciones_territorio a
             INNER JOIN estados e ON e.id = a.estado_id AND e.estado = 1
             WHERE a.usuario_id = ? AND a.tipo_asignacion = 'ANALISTA_DATOS'
               AND a.activo = 1
               AND (a.fecha_inicio IS NULL OR a.fecha_inicio <= CURDATE())
               AND (a.fecha_fin IS NULL OR a.fecha_fin >= CURDATE())
             ORDER BY e.nombre"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $estados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $estados;
    }

    public function buscarSeguimientos(int $usuarioId, string $texto): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto, 'UTF-8') < 2) return [];
        $like = '%' . $texto . '%';
        $stmt = $this->db->prepare(
            "SELECT s.id, s.nombre_entidad, s.estado_id, e.nombre AS estado_nombre
             FROM seguimientos_vinculacion s
             INNER JOIN estados e ON e.id = s.estado_id
             WHERE s.analista_id = ? AND s.activo = 1
               AND (s.nombre_entidad LIKE ? OR s.telefono_fuente LIKE ?
                    OR s.telefono_verificado LIKE ?)
             ORDER BY s.nombre_entidad LIMIT 20"
        );
        $stmt->bind_param('isss', $usuarioId, $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Una llamada real produce a lo sumo una clasificación por Analista.
     * Tanto el seguimiento nuevo como su interacción se guardan en la misma
     * transacción que el registro de clasificación. No genera "verificaciones
     * efectivas": siguen exigiendo evidencia del flujo institucional.
     */
    public function registrar(int $usuarioId, array $datos): array
    {
        $idSdk = trim((string)($datos['sdk_session_id'] ?? ''));
        $sdk = $idSdk !== '';
        $id = $sdk ? $idSdk : trim((string)($datos['pbx_call_id'] ?? ''));
        $decision = strtolower(trim((string)($datos['decision'] ?? '')));
        if (!in_array($decision, ['existente', 'nueva', 'sin_identificar'], true)) {
            throw new InvalidArgumentException('Selecciona cómo clasificar la llamada.');
        }
        if ($sdk) {
            $rowSdk = (new TelefoniaAnalistaWebrtcService($this->db))
                ->obtenerFinalizada($usuarioId, $id);
            if ((new TelefoniaAnalistaWebrtcService($this->db))->esNumeroInterno(
                (string)$rowSdk['numero_origen'], (string)$rowSdk['extension']
            )) {
                throw new DomainException(
                    'Esta comunicación es interna entre extensiones. Se conserva en el historial y no debe vincularse a una institución.'
                );
            }
            $call = [
                'estado' => 'ended',
                'extension' => (string)$rowSdk['extension'],
                'caller_id' => (string)$rowSdk['numero_origen'],
                'answer_at' => (string)($rowSdk['fecha_atendida'] ?? ''),
                'call_start' => (string)$rowSdk['fecha_inicio'],
                'end_at' => (string)$rowSdk['fecha_fin'],
                'duration' => TelefoniaAnalistaWebrtcService::duracion($rowSdk),
                'fecha_evento' => (string)$rowSdk['fecha_inicio']
            ];
            // Un evento del navegador NO demuestra recepción PBX ni datos
            // verificados: sólo admite asociación comercial confirmada
            // expresamente por el usuario después de conversar.
            if ($decision !== 'sin_identificar' &&
                (int)($datos['confirmacion_manual_sdk'] ?? 0) !== 1) {
                throw new DomainException('Confirma que hablaste realmente con la persona antes de vincular esta llamada WebRTC.');
            }
        } else {
            $call = $this->inicioVerificado($usuarioId, $id);
        }
        if (!$sdk && (new TelefoniaAnalistaWebrtcService($this->db))
            ->esNumeroInterno((string)($call['caller_id'] ?? ''), (string)$call['extension'])) {
            throw new DomainException('Las llamadas internas entre extensiones no se vinculan a expedientes institucionales.');
        }
        if (!in_array((string)$call['estado'], ['ended', 'transferred'], true)) {
            throw new DomainException('La llamada debe finalizar antes de registrar el resultado.');
        }
        // Una comunicación sin respuesta no debe figurar como contacto efectivo
        // ni generar un expediente: sólo admite clasificación sin identificar.
        if (empty($call['answer_at']) && $decision !== 'sin_identificar') {
            throw new DomainException('La centralita no confirmó una conversación. Clasifica sin identificar o devuelve la llamada.');
        }
        $observacion = trim((string)($datos['observacion'] ?? ''));
        if (mb_strlen($observacion, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Las observaciones son demasiado extensas.');
        }

        $model = new SeguimientoVinculacionModel($this->db);
        $extension = (string)$call['extension'];
        $this->db->begin_transaction();
        try {
            if (!$sdk) {
                $stmt = $this->db->prepare(
                    "INSERT IGNORE INTO telefonia_analista_entrantes
                      (usuario_id, extension, pbx_call_id)
                     VALUES (?, ?, ?)"
                );
                $stmt->bind_param('iss', $usuarioId, $extension, $id);
                $stmt->execute();
                $stmt->close();
            }

            $tabla = $sdk
                ? 'telefonia_analista_webrtc_sesiones' : 'telefonia_analista_entrantes';
            $campoId = $sdk ? 'session_id' : 'pbx_call_id';
            $campoEstado = $sdk ? 'clasificacion' : 'estado';
            $stmt = $this->db->prepare(
                "SELECT id, {$campoEstado} AS estado, seguimiento_id, interaccion_id
                 FROM {$tabla}
                 WHERE usuario_id = ? AND {$campoId} = ? FOR UPDATE"
            );
            $stmt->bind_param('is', $usuarioId, $id);
            $stmt->execute();
            $registro = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // Una llamada sin identificar puede vincularse más tarde si el
            // analista reconoce a la institución. Una ya vinculada jamás
            // genera una segunda interacción con el mismo PBX call ID.
            if (!$registro || !in_array((string)$registro['estado'],
                ['PENDIENTE', 'SIN_IDENTIFICAR'], true)) {
                throw new DomainException('La llamada ya está vinculada. Actualiza el historial.');
            }
            if ($registro['estado'] === 'SIN_IDENTIFICAR' && $decision === 'sin_identificar') {
                $this->db->commit();
                return ['estado' => 'SIN_IDENTIFICAR', 'seguimiento_id' => 0];
            }

            if ($decision === 'sin_identificar') {
                $estadoRegistro = 'SIN_IDENTIFICAR';
                $notaBreve = mb_substr($observacion, 0, 500, 'UTF-8');
                $stmt = $this->db->prepare(
                    "UPDATE {$tabla}
                     SET {$campoEstado} = ?, observacion = ? WHERE id = ?"
                );
                $registroId = (int)$registro['id'];
                $stmt->bind_param('ssi', $estadoRegistro, $notaBreve, $registroId);
                $stmt->execute();
                $stmt->close();
                $this->db->commit();
                return ['estado' => $estadoRegistro, 'seguimiento_id' => 0];
            }

            $resultadoFormulario = strtoupper(trim((string)($datos['resultado'] ?? '')));
            $mapa = [
                'CONTACTO_CORRECTO' => 'CONTACTADO',
                'SOLICITO_INFORMACION' => 'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES' => 'SOLICITO_LLAMAR_DESPUES',
                'NO_INTERESADO' => 'NO_INTERESADO',
                'OTRO' => 'OTRO'
            ];
            if (!isset($mapa[$resultadoFormulario])) {
                throw new InvalidArgumentException('Selecciona un resultado válido.');
            }
            $personaAtendio = trim((string)($datos['persona_atendio'] ?? ''));
            if (mb_strlen($personaAtendio, 'UTF-8') > 140) {
                throw new InvalidArgumentException('El nombre de contacto es demasiado largo.');
            }
            $proximaAccion = trim((string)($datos['proxima_accion'] ?? ''));
            if (!in_array($proximaAccion, ['', 'Volver a llamar', 'Enviar WhatsApp', 'Revisar correo', 'Preparar oficio'], true)) {
                throw new InvalidArgumentException('La próxima acción no es válida.');
            }
            $proximaAccionAt = trim((string)($datos['proxima_accion_at'] ?? ''));
            if ($resultadoFormulario === 'SOLICITO_LLAMAR_DESPUES' && $proximaAccionAt === '') {
                throw new InvalidArgumentException('Indica la fecha de la próxima llamada.');
            }
            $fechaProxima = null;
            if ($proximaAccionAt !== '') {
                $ts = strtotime($proximaAccionAt);
                if (!$ts || $ts <= time()) {
                    throw new InvalidArgumentException('La próxima acción debe ser futura.');
                }
                $fechaProxima = date('Y-m-d H:i:s', $ts);
            }

            if ($decision === 'existente') {
                $seguimientoId = (int)($datos['seguimiento_id'] ?? 0);
                $stmt = $this->db->prepare(
                    "SELECT id, telefono_fuente, telefono_verificado, correo_fuente,
                            correo_verificado, contacto_nombre, datos_verificados, estado_seguimiento
                     FROM seguimientos_vinculacion
                     WHERE id = ? AND analista_id = ? AND activo = 1 FOR UPDATE"
                );
                $stmt->bind_param('ii', $seguimientoId, $usuarioId);
                $stmt->execute();
                $seguimiento = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$seguimiento || $seguimiento['estado_seguimiento'] === 'DESCARTADO') {
                    throw new DomainException('El expediente no pertenece al Analista o no está activo.');
                }
            } else {
                $estadoId = (int)($datos['estado_id'] ?? 0);
                $nombre = trim((string)($datos['nombre_entidad'] ?? ''));
                $tipo = strtoupper(trim((string)($datos['tipo_entidad'] ?? 'INSTITUCION')));
                if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 180 ||
                    !in_array($tipo, ['INSTITUCION', 'SECRETARIA', 'MUNICIPIO', 'EMPRESA', 'ORGANIZACION', 'OTRO'], true)) {
                    throw new InvalidArgumentException('Indica nombre y tipo de institución válidos.');
                }
                $permitidos = array_column($this->estados($usuarioId), 'id');
                if (!in_array($estadoId, array_map('intval', $permitidos), true)) {
                    throw new DomainException('El estado no está asignado actualmente al Analista.');
                }
                $stmt = $this->db->prepare(
                    "SELECT id FROM seguimientos_vinculacion
                     WHERE analista_id = ? AND estado_id = ?
                       AND LOWER(TRIM(nombre_entidad)) = LOWER(?)
                       AND activo = 1 LIMIT 1"
                );
                $stmt->bind_param('iis', $usuarioId, $estadoId, $nombre);
                $stmt->execute();
                $duplicado = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($duplicado) {
                    throw new DomainException('Esta institución ya tiene expediente. Selecciona Seguimiento existente.');
                }
                $telefonoCapturado = trim((string)($datos['telefono_capturado'] ?? ''));
                $correoCapturado = trim((string)($datos['correo_capturado'] ?? ''));
                if (mb_strlen($telefonoCapturado, 'UTF-8') > 40 ||
                    ($telefonoCapturado !== '' &&
                      !preg_match('/^[+0-9()\- .]{7,40}$/', $telefonoCapturado))) {
                    throw new InvalidArgumentException('El teléfono capturado no es válido.');
                }
                if (mb_strlen($correoCapturado, 'UTF-8') > 160 ||
                    ($correoCapturado !== '' &&
                     !filter_var($correoCapturado, FILTER_VALIDATE_EMAIL))) {
                    throw new InvalidArgumentException('El correo capturado no es válido.');
                }
                $telefonoInstitucional = $telefonoCapturado !== ''
                    ? $telefonoCapturado : (string)($call['caller_id'] ?? '');
                $seguimientoId = $model->crearSeguimientoManual([
                    'estado_id' => $estadoId,
                    'municipio_id' => 0,
                    'analista_id' => $usuarioId,
                    'clave_origen' => 'MANUAL:' . bin2hex(random_bytes(16)),
                    'tipo_entidad' => $tipo,
                    'nombre' => $nombre,
                    'telefono' => $telefonoInstitucional,
                    'whatsapp' => '',
                    'correo' => $correoCapturado,
                    'contacto_nombre' => $personaAtendio,
                    'contacto_cargo' => '',
                    'datos_verificados' => 0,
                    'estado_seguimiento' => 'NUEVO',
                    'motivo_descarte' => '',
                    'observaciones' => '',
                    'proxima_accion_at' => null
                ], true);
                $seguimiento = [
                    'telefono_fuente' => $telefonoInstitucional,
                    'telefono_verificado' => '',
                    'correo_fuente' => $correoCapturado,
                    'correo_verificado' => '',
                    'contacto_nombre' => $personaAtendio,
                    'datos_verificados' => 0
                ];
            }

            // El tiempo del expediente corresponde a la conversación del
            // Analista, no al tramo previo atendido por Recepción (Tania).
            $fechaInicio = trim((string)($call['answer_at'] ?? ''));
            if (!$fechaInicio || strtotime($fechaInicio) === false) {
                $fechaInicio = trim((string)($call['call_start'] ?? ''));
            }
            if (!$fechaInicio || strtotime($fechaInicio) === false) {
                $fechaInicio = (string)$call['fecha_evento'];
            }
            $fechaFin = trim((string)($call['end_at'] ?? ''));
            if (!$fechaFin || strtotime($fechaFin) === false) {
                $fechaFin = date('Y-m-d H:i:s');
            }
            $segundosConversacion = self::segundosAtendidos($call);
            $admiteVerificacion = in_array($resultadoFormulario, [
                'CONTACTO_CORRECTO', 'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'
            ], true);
            $confirmaciones = [
                'verificacion_telefono_confirmado' => 'Teléfono confirmado',
                'verificacion_correo_confirmado' => 'Correo confirmado',
                'verificacion_contacto_confirmado' => 'Contacto institucional confirmado'
            ];
            $evidencias = [];
            foreach ($confirmaciones as $campo => $etiqueta) {
                if ((int)($datos[$campo] ?? 0) !== 1) continue;
                if ($sdk) {
                    throw new DomainException('Las llamadas WebRTC sin evento PBX no acreditan verificaciones efectivas.');
                }
                if (!$admiteVerificacion || $segundosConversacion <= 0) {
                    throw new DomainException('La verificación necesita una conversación confirmada y un resultado compatible.');
                }
                if ($campo === 'verificacion_telefono_confirmado' &&
                    trim((string)($seguimiento['telefono_verificado'] ?: ($seguimiento['telefono_fuente'] ?? ''))) === '') {
                    throw new DomainException('No hay teléfono registrado para verificar.');
                }
                if ($campo === 'verificacion_correo_confirmado' &&
                    trim((string)($seguimiento['correo_verificado'] ?: ($seguimiento['correo_fuente'] ?? ''))) === '') {
                    throw new DomainException('No hay correo registrado para verificar.');
                }
                if ($campo === 'verificacion_contacto_confirmado' &&
                    trim((string)($seguimiento['contacto_nombre'] ?? '')) === '') {
                    throw new DomainException('No hay contacto institucional registrado para verificar.');
                }
                $evidencias[] = $etiqueta;
            }
            if ($evidencias && $personaAtendio === '') {
                throw new InvalidArgumentException('Indica con quién hablaste para registrar una verificación efectiva.');
            }
            $descartar = (int)($datos['descartar'] ?? 0) === 1;
            $motivoDescarte = trim((string)($datos['motivo_descarte'] ?? ''));
            if ($descartar && ($resultadoFormulario !== 'NO_INTERESADO' || $motivoDescarte === '' ||
                mb_strlen($motivoDescarte, 'UTF-8') > 500)) {
                throw new InvalidArgumentException('Para descartar indica No interesado y un motivo válido.');
            }
            $notas = trim(implode("\n", array_filter([
                'Llamada entrante recibida en la extensión ' . $extension . '.',
                $sdk ? '[WEBRTC OBSERVADO; SIN EVENTO PBX FIRMADO; RESULTADO DECLARADO POR ANALISTA]' : '',
                $personaAtendio !== '' ? 'Persona atendida: ' . $personaAtendio : '',
                $evidencias ? '[VERIFICACION_EFECTIVA]' : '',
                $evidencias ? 'Verificación obtenida: ' . implode(' · ', $evidencias) : '',
                $resultadoFormulario === 'NO_INTERESADO' ? 'Resultado registrado: No interesado' : '',
                $observacion,
                $proximaAccion !== '' ? 'Próxima acción: ' . $proximaAccion : ''
            ])));

            $interaccionId = $model->registrarInteraccionManual(
                $seguimientoId, $usuarioId, [
                    'canal' => 'LLAMADA_IP',
                    'resultado' => $mapa[$resultadoFormulario],
                    'fecha_inicio' => $fechaInicio,
                    'notas' => $notas,
                    'telefono_destino' => (string)($call['caller_id'] ?? ''),
                    'correo_destino' => (string)($seguimiento['correo_verificado'] ?: ($seguimiento['correo_fuente'] ?? '')),
                    'proxima_accion_at' => $fechaProxima,
                    'datos_verificados' => $evidencias ? 1 : (int)($seguimiento['datos_verificados'] ?? 0),
                    'descartar' => $descartar ? 1 : 0,
                    'motivo_descarte' => $descartar ? $motivoDescarte : '',
                    'proveedor_externo' => $sdk ? 'ZADARMA_WEBRTC' : 'ZADARMA',
                    'id_externo' => $sdk ? 'WEBRTC-' . $id : $id,
                    'duracion_segundos' => $segundosConversacion,
                    'fecha_fin' => $fechaFin
                ], true
            );
            if (!is_int($interaccionId) || $interaccionId <= 0) {
                throw new RuntimeException('No se pudo guardar la interacción telefónica.');
            }

            $estadoRegistro = 'VINCULADA';
            $registroId = (int)$registro['id'];
            $stmt = $this->db->prepare(
                "UPDATE {$tabla}
                 SET {$campoEstado} = ?, seguimiento_id = ?, interaccion_id = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('siii', $estadoRegistro, $seguimientoId, $interaccionId, $registroId);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();

            return [
                'estado' => $estadoRegistro,
                'seguimiento_id' => $seguimientoId,
                'interaccion_id' => $interaccionId
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
