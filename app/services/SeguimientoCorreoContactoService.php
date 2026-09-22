<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';

class SeguimientoCorreoContactoService
{
    private $connection;
    private $rootPath;
    private $correoService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
        $this->correoService = new CorreoSalidaInstitucionalService();
    }

    public function obtenerBorrador($seguimientoId, $usuarioId)
    {
        $seguimiento = $this->obtenerSeguimientoAnalista(
            (int)$seguimientoId,
            (int)$usuarioId
        );
        $validacion = $this->validarSeguimiento($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        $institucion = trim((string)($seguimiento['nombre_entidad'] ?? ''));
        $contacto = trim((string)($seguimiento['contacto_nombre'] ?? ''));
        $analista = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );
        $saludo = $contacto !== ''
            ? 'Buen día, ' . $contacto . ':'
            : 'Buen día:';

        $lineas = [
            $saludo,
            '',
            'Me comunico para dar seguimiento a nuestra comunicación institucional.',
            '',
            'Quedo atento a sus comentarios.',
            '',
            'Saludos cordiales,'
        ];

        if ($analista !== '') {
            $lineas[] = $analista;
        }

        $lineas[] = 'Analista de Enlace Institucional';
        $lineas[] = 'Fundación Red Educativa México';

        return [
            'ok' => true,
            'correo' => [
                'para' => (string)$seguimiento['destinatario_correo'],
                'destinatario_nombre' => $contacto,
                'institucion' => $institucion,
                'asunto' => 'Seguimiento institucional' .
                    ($institucion !== '' ? ' - ' . $institucion : ''),
                'cuerpo' => implode("\n", $lineas)
            ]
        ];
    }

    public function enviar($seguimientoId, $usuarioId, $asunto, $cuerpo, $archivos = null)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);
        $seguimiento = $this->obtenerSeguimientoAnalista($seguimientoId, $usuarioId);
        $validacion = $this->validarSeguimiento($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }
        if (mb_strlen($asunto) > 255) {
            return $this->error('El asunto no puede superar 255 caracteres.', 422);
        }
        if (mb_strlen($cuerpo) > 20000) {
            return $this->error('El mensaje es demasiado largo.', 422);
        }

        $preparados = $this->prepararAdjuntos($archivos, $seguimientoId);
        if (!($preparados['ok'] ?? false)) {
            return $preparados;
        }

        $adjuntos = $preparados['adjuntos'];
        $nombresAdjuntos = $preparados['nombres'];
        $directorioTemporal = (string)($preparados['directorio'] ?? '');

        try {
            $resultadoEnvio = $this->correoService->enviar([
                'remitente' => (string)($seguimiento['analista_correo'] ?? ''),
                'nombre_remitente' => trim(
                    (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                    (string)($seguimiento['analista_apellidos'] ?? '')
                ),
                'destinatario' => (string)$seguimiento['destinatario_correo'],
                'nombre_destinatario' => (string)($seguimiento['contacto_nombre'] ?? ''),
                'asunto' => $asunto,
                'cuerpo' => $cuerpo,
                'adjuntos' => $adjuntos
            ]);

            if (!($resultadoEnvio['ok'] ?? false)) {
                return $resultadoEnvio;
            }

            $destinatario = trim((string)$seguimiento['destinatario_correo']);
            $notas = "Correo institucional enviado\n" .
                'Para: ' . $destinatario . "\n" .
                'Asunto: ' . $asunto;

            if (!empty($nombresAdjuntos)) {
                $notas .= "\nAdjuntos: " . implode(', ', $nombresAdjuntos);
            }

            $notas .= "\n\nMensaje:\n" . $cuerpo;

            $this->connection->begin_transaction();

            try {
                $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                    seguimiento_id,
                                    usuario_id,
                                    canal,
                                    fecha_inicio,
                                    resultado,
                                    notas
                                ) VALUES (?, ?, 'CORREO', NOW(), 'CORREO_ENVIADO', ?)";
                $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
                $stmtInteraccion->bind_param(
                    'iis',
                    $seguimientoId,
                    $usuarioId,
                    $notas
                );
                $stmtInteraccion->execute();

                $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                                   SET ultima_interaccion_at = NOW()
                                   WHERE id = ?
                                     AND analista_id = ?
                                     AND activo = 1";
                $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
                $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
                $stmtSeguimiento->execute();

                $this->connection->commit();
            } catch (Throwable $error) {
                $this->connection->rollback();
                error_log(
                    'Correo institucional enviado pero no registrado: ' .
                    $error->getMessage()
                );

                return $this->error(
                    'El correo fue enviado, pero no fue posible registrarlo en el historial. Revisa el correo enviado antes de intentar nuevamente.',
                    500
                );
            }

            return [
                'ok' => true,
                'mensaje' => 'Correo enviado y registrado en el historial.',
                'adjuntos' => $nombresAdjuntos
            ];
        } finally {
            $this->limpiarDirectorioTemporal($directorioTemporal);
        }
    }

    private function obtenerSeguimientoAnalista($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    s.id,
                    s.analista_id,
                    s.nombre_entidad,
                    s.contacto_nombre,
                    s.contacto_cargo,
                    s.estado_seguimiento,
                    COALESCE(
                        NULLIF(TRIM(s.correo_verificado), ''),
                        NULLIF(TRIM(s.correo_fuente), '')
                    ) AS destinatario_correo,
                    u.nombre AS analista_nombre,
                    u.apellidos AS analista_apellidos,
                    u.correo AS analista_correo
                FROM seguimientos_vinculacion s
                JOIN usuarios u ON u.id = s.analista_id
                WHERE s.id = ?
                  AND s.analista_id = ?
                  AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function validarSeguimiento($seguimiento)
    {
        if (!$seguimiento) {
            return $this->error(
                'No tienes acceso a este seguimiento.',
                403
            );
        }

        if (
            strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) ===
            'DESCARTADO'
        ) {
            return $this->error(
                'El seguimiento está descartado y solo puede consultarse.',
                409
            );
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        if (
            $destinatario === '' ||
            !filter_var($destinatario, FILTER_VALIDATE_EMAIL)
        ) {
            return $this->error(
                'El contacto no tiene un correo válido.',
                422
            );
        }

        $remitente = trim((string)($seguimiento['analista_correo'] ?? ''));
        if (
            $remitente === '' ||
            !filter_var($remitente, FILTER_VALIDATE_EMAIL)
        ) {
            return $this->error(
                'Tu cuenta no tiene un correo institucional válido.',
                422
            );
        }

        return ['ok' => true];
    }

    private function prepararAdjuntos($archivos, $seguimientoId)
    {
        if (
            !is_array($archivos) ||
            !isset($archivos['name']) ||
            $archivos['name'] === '' ||
            $archivos['name'] === []
        ) {
            return [
                'ok' => true,
                'adjuntos' => [],
                'nombres' => [],
                'directorio' => ''
            ];
        }

        $nombres = is_array($archivos['name'])
            ? $archivos['name']
            : [$archivos['name']];
        $temporales = is_array($archivos['tmp_name'])
            ? $archivos['tmp_name']
            : [$archivos['tmp_name']];
        $errores = is_array($archivos['error'])
            ? $archivos['error']
            : [$archivos['error']];
        $tamanos = is_array($archivos['size'])
            ? $archivos['size']
            : [$archivos['size']];

        if (count($nombres) > 8) {
            return $this->error(
                'El correo no puede incluir más de 8 archivos adjuntos.',
                422
            );
        }

        $permitidos = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg'
        ];

        $total = 0;
        $directorio = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR .
            'temporales' . DIRECTORY_SEPARATOR . 'seguimiento_' .
            (int)$seguimientoId . '_' . bin2hex(random_bytes(4));

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error(
                'No fue posible preparar los archivos adjuntos.',
                500
            );
        }

        $adjuntos = [];
        $nombresLimpios = [];

        foreach ($nombres as $indice => $nombreOriginal) {
            $errorCarga = (int)($errores[$indice] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCarga === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errorCarga !== UPLOAD_ERR_OK) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'No fue posible recibir uno de los archivos adjuntos.',
                    422
                );
            }

            $tmp = (string)($temporales[$indice] ?? '');
            $tamano = (int)($tamanos[$indice] ?? 0);
            $nombre = basename(
                str_replace('\\', '/', trim((string)$nombreOriginal))
            );
            $extension = strtolower(
                (string)pathinfo($nombre, PATHINFO_EXTENSION)
            );

            if (
                $tmp === '' ||
                !is_uploaded_file($tmp) ||
                $tamano <= 0
            ) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'Uno de los archivos adjuntos no es válido.',
                    422
                );
            }

            if (!isset($permitidos[$extension])) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'Solo se permiten PDF, Office, TXT, CSV, PNG y JPG como adjuntos.',
                    422
                );
            }

            if ($tamano > 12 * 1024 * 1024) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'Cada archivo adjunto debe pesar como máximo 12 MB.',
                    422
                );
            }

            $total += $tamano;
            if ($total > 20 * 1024 * 1024) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'Los adjuntos no pueden superar 20 MB en total.',
                    422
                );
            }

            $nombreSeguro = preg_replace(
                '/[^a-zA-Z0-9._-]+/',
                '_',
                pathinfo($nombre, PATHINFO_FILENAME)
            );
            $nombreSeguro = trim((string)$nombreSeguro, '._-');
            if ($nombreSeguro === '') {
                $nombreSeguro = 'archivo';
            }

            $destino = $directorio . DIRECTORY_SEPARATOR .
                $nombreSeguro . '_' . bin2hex(random_bytes(3)) . '.' . $extension;

            if (!move_uploaded_file($tmp, $destino)) {
                $this->limpiarDirectorioTemporal($directorio);
                return $this->error(
                    'No fue posible preparar uno de los archivos adjuntos.',
                    500
                );
            }

            $adjuntos[] = [
                'ruta' => $destino,
                'nombre' => mb_substr($nombre, 0, 255),
                'mime' => $permitidos[$extension]
            ];
            $nombresLimpios[] = mb_substr($nombre, 0, 255);
        }

        return [
            'ok' => true,
            'adjuntos' => $adjuntos,
            'nombres' => $nombresLimpios,
            'directorio' => $directorio
        ];
    }

    private function limpiarDirectorioTemporal($directorio)
    {
        $directorio = trim((string)$directorio);

        if (
            $directorio === '' ||
            !is_dir($directorio)
        ) {
            return;
        }

        $archivos = glob(
            rtrim($directorio, DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR . '*'
        );

        foreach (is_array($archivos) ? $archivos : [] as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        @rmdir($directorio);
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
