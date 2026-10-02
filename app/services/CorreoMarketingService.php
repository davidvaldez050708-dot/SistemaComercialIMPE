<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';

class CorreoMarketingService
{
    private $connection;
    private $sender;
    private $rootPath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->sender = new CorreoSalidaInstitucionalService();
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function resumen($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return [
                'total' => 0,
                'enviados' => 0,
                'pendientes' => 0,
                'borradores' => 0
            ];
        }

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'ENVIADO' THEN 1 ELSE 0 END) AS enviados,
                    SUM(CASE WHEN estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN estado = 'BORRADOR' THEN 1 ELSE 0 END) AS borradores
                FROM correos_marketing
                WHERE usuario_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: [];

        return [
            'total' => (int)($fila['total'] ?? 0),
            'enviados' => (int)($fila['enviados'] ?? 0),
            'pendientes' => (int)($fila['pendientes'] ?? 0),
            'borradores' => (int)($fila['borradores'] ?? 0)
        ];
    }

    public function listar($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return [];
        }

        $sql = "SELECT
                    id,
                    destinatario,
                    destinatario_nombre,
                    asunto,
                    tipo,
                    estado,
                    proveedor,
                    adjuntos_count,
                    adjuntos_nombres,
                    fecha_envio,
                    created_at
                FROM correos_marketing
                WHERE usuario_id = ?
                ORDER BY COALESCE(fecha_envio, created_at) DESC, id DESC
                LIMIT 250";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        $salida = [];
        $resultado = $stmt->get_result();

        while ($fila = $resultado->fetch_assoc()) {
            $fecha = trim((string)($fila['fecha_envio'] ?? ''));
            if ($fecha === '') {
                $fecha = trim((string)($fila['created_at'] ?? ''));
            }

            $timestamp = $fecha !== '' ? strtotime($fecha) : false;

            $salida[] = [
                'id' => (int)($fila['id'] ?? 0),
                'destinatario' => trim((string)($fila['destinatario_nombre'] ?? '')) !== ''
                    ? (string)$fila['destinatario_nombre']
                    : (string)($fila['destinatario'] ?? ''),
                'correo' => (string)($fila['destinatario'] ?? ''),
                'asunto' => (string)($fila['asunto'] ?? ''),
                'tipo' => $this->etiquetaTipo($fila['tipo'] ?? ''),
                'tipo_codigo' => strtolower((string)($fila['tipo'] ?? 'general')),
                'fecha' => $timestamp !== false ? date('d/m/Y H:i', $timestamp) : '—',
                'estado' => $this->etiquetaEstado($fila['estado'] ?? ''),
                'estado_codigo' => strtolower((string)($fila['estado'] ?? 'pendiente')),
                'adjuntos_count' => (int)($fila['adjuntos_count'] ?? 0)
            ];
        }

        return $salida;
    }

    public function obtener($usuarioId, $correoId)
    {
        $usuarioId = (int)$usuarioId;
        $correoId = (int)$correoId;

        if (
            $usuarioId <= 0 ||
            $correoId <= 0 ||
            !$this->tablaDisponible()
        ) {
            return null;
        }

        $sql = "SELECT
                    id,
                    usuario_id,
                    destinatario,
                    destinatario_nombre,
                    asunto,
                    cuerpo,
                    tipo,
                    estado,
                    proveedor,
                    firma_incluida,
                    adjuntos_count,
                    adjuntos_nombres,
                    error_envio,
                    fecha_envio,
                    created_at
                FROM correos_marketing
                WHERE id = ?
                  AND usuario_id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $correoId, $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila) {
            return null;
        }

        $fechaEnvio = trim((string)($fila['fecha_envio'] ?? ''));
        $fechaRegistro = trim((string)($fila['created_at'] ?? ''));
        $adjuntos = $this->listarAdjuntosCorreo(
            (int)$fila['id'],
            (int)$fila['usuario_id']
        );

        $nombresLegacy = json_decode(
            (string)($fila['adjuntos_nombres'] ?? '[]'),
            true
        );

        if (!is_array($nombresLegacy)) {
            $nombresLegacy = [];
        }

        $nombresPersistidos = [];
        foreach ($adjuntos as $adjuntoPersistido) {
            $clavePersistida = strtolower(
                trim((string)($adjuntoPersistido['nombre'] ?? ''))
            );

            if ($clavePersistida !== '') {
                $nombresPersistidos[$clavePersistida] = true;
            }
        }

        foreach ($nombresLegacy as $nombreLegacy) {
            $nombreLegacy = trim((string)$nombreLegacy);
            $claveLegacy = strtolower($nombreLegacy);

            if (
                $nombreLegacy === '' ||
                isset($nombresPersistidos[$claveLegacy])
            ) {
                continue;
            }

            $extensionLegacy = strtolower(
                (string)pathinfo(
                    $nombreLegacy,
                    PATHINFO_EXTENSION
                )
            );

            $adjuntos[] = [
                'id' => 0,
                'nombre' => $nombreLegacy,
                'mime' => '',
                'tamano' => 0,
                'disponible' => false,
                'es_imagen' => in_array(
                    $extensionLegacy,
                    ['png', 'jpg', 'jpeg'],
                    true
                ),
                'es_pdf' => $extensionLegacy === 'pdf'
            ];
        }

        return [
            'id' => (int)$fila['id'],
            'destinatario' => (string)($fila['destinatario'] ?? ''),
            'destinatario_nombre' =>
                (string)($fila['destinatario_nombre'] ?? ''),
            'asunto' => (string)($fila['asunto'] ?? ''),
            'cuerpo' => (string)($fila['cuerpo'] ?? ''),
            'tipo' => $this->etiquetaTipo($fila['tipo'] ?? ''),
            'estado' => $this->etiquetaEstado($fila['estado'] ?? ''),
            'proveedor' => (string)($fila['proveedor'] ?? ''),
            'firma_incluida' =>
                (bool)((int)($fila['firma_incluida'] ?? 0)),
            'adjuntos' => $adjuntos,
            'adjuntos_count' => (int)($fila['adjuntos_count'] ?? 0),
            'error_envio' => (string)($fila['error_envio'] ?? ''),
            'fecha_envio' => $this->formatearFechaHora(
                $fechaEnvio !== '' ? $fechaEnvio : $fechaRegistro
            )
        ];
    }

    public function obtenerAdjunto($usuarioId, $adjuntoId)
    {
        $usuarioId = (int)$usuarioId;
        $adjuntoId = (int)$adjuntoId;

        if (
            $usuarioId <= 0 ||
            $adjuntoId <= 0 ||
            !$this->tablaAdjuntosDisponible()
        ) {
            return null;
        }

        $sql = "SELECT
                    a.id,
                    a.archivo,
                    a.nombre_original,
                    a.mime,
                    a.tamano
                FROM correos_marketing_adjuntos a
                INNER JOIN correos_marketing c
                    ON c.id = a.correo_id
                WHERE a.id = ?
                  AND a.usuario_id = ?
                  AND c.usuario_id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'iii',
            $adjuntoId,
            $usuarioId,
            $usuarioId
        );
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila) {
            return null;
        }

        $ruta = $this->rutaAdjuntoAbsoluta(
            (string)($fila['archivo'] ?? '')
        );

        if ($ruta === null || !is_file($ruta)) {
            return null;
        }

        return [
            'id' => (int)$fila['id'],
            'ruta' => $ruta,
            'nombre' => (string)($fila['nombre_original'] ?? 'archivo'),
            'mime' => trim((string)($fila['mime'] ?? '')) !== ''
                ? (string)$fila['mime']
                : 'application/octet-stream',
            'tamano' => (int)($fila['tamano'] ?? filesize($ruta))
        ];
    }

    public function recuperarAdjuntoLegacy(
        $usuarioId,
        $correoId,
        $nombreEsperado,
        $archivo
    ) {
        $usuarioId = (int)$usuarioId;
        $correoId = (int)$correoId;
        $nombreEsperado = trim((string)$nombreEsperado);

        if (
            $usuarioId <= 0 ||
            $correoId <= 0 ||
            $nombreEsperado === '' ||
            !$this->tablaDisponible() ||
            !$this->tablaAdjuntosDisponible()
        ) {
            return $this->error(
                'No fue posible preparar la recuperación del archivo.',
                422
            );
        }

        $sql = "SELECT adjuntos_nombres
                FROM correos_marketing
                WHERE id = ?
                  AND usuario_id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $correoId, $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila) {
            return $this->error(
                'No fue posible encontrar el correo.',
                404
            );
        }

        $nombresLegacy = json_decode(
            (string)($fila['adjuntos_nombres'] ?? '[]'),
            true
        );

        if (!is_array($nombresLegacy)) {
            $nombresLegacy = [];
        }

        $coincide = false;
        foreach ($nombresLegacy as $nombreLegacy) {
            if (
                strcasecmp(
                    trim((string)$nombreLegacy),
                    $nombreEsperado
                ) === 0
            ) {
                $coincide = true;
                break;
            }
        }

        if (!$coincide) {
            return $this->error(
                'El archivo no pertenece al historial de este correo.',
                422
            );
        }

        if (!is_array($archivo)) {
            return $this->error(
                'Selecciona el archivo original.',
                422
            );
        }

        $extensionEsperada = strtolower(
            (string)pathinfo(
                $nombreEsperado,
                PATHINFO_EXTENSION
            )
        );
        $extensionRecibida = strtolower(
            (string)pathinfo(
                (string)($archivo['name'] ?? ''),
                PATHINFO_EXTENSION
            )
        );

        if (
            $extensionEsperada !== '' &&
            $extensionRecibida !== $extensionEsperada
        ) {
            return $this->error(
                'El archivo seleccionado no coincide con el tipo del adjunto original.',
                422
            );
        }

        $archivosNormalizados = [
            'name' => [(string)($archivo['name'] ?? '')],
            'type' => [(string)($archivo['type'] ?? '')],
            'tmp_name' => [(string)($archivo['tmp_name'] ?? '')],
            'error' => [(int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE)],
            'size' => [(int)($archivo['size'] ?? 0)]
        ];

        $preparacion = $this->prepararAdjuntos(
            $archivosNormalizados
        );

        if (!($preparacion['ok'] ?? false)) {
            return $preparacion;
        }

        $adjuntos = $preparacion['adjuntos'] ?? [];
        $temporales = $preparacion['rutas_temporales'] ?? [];

        if (count($adjuntos) !== 1) {
            $this->limpiarTemporales($temporales);

            return $this->error(
                'No fue posible preparar el archivo seleccionado.',
                422
            );
        }

        /*
         * Conservamos el nombre histórico del adjunto para que el registro
         * recuperado sustituya exactamente la referencia legacy.
         */
        $adjuntos[0]['nombre'] = $nombreEsperado;

        try {
            $this->guardarAdjuntosPersistentes(
                $correoId,
                $usuarioId,
                $adjuntos
            );
        } catch (Throwable $error) {
            error_log(
                'Recuperación de adjunto de Marketing: ' .
                $error->getMessage()
            );

            return $this->error(
                'No fue posible conservar el archivo recuperado.',
                500
            );
        } finally {
            $this->limpiarTemporales($temporales);
        }

        return [
            'ok' => true,
            'mensaje' => 'Archivo recuperado correctamente.'
        ];
    }

    public function enviar($usuarioId, $datos, $archivos = null)
    {
        $usuarioId = (int)$usuarioId;
        $destinatario = strtolower(trim((string)($datos['destinatario'] ?? '')));
        $destinatarioNombre = trim((string)($datos['destinatario_nombre'] ?? ''));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = trim((string)($datos['cuerpo'] ?? ''));

        if ($usuarioId <= 0) {
            return $this->error('La sesión no está activa.', 401);
        }

        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Ingresa un correo destinatario válido.', 422);
        }

        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }

        if (mb_strlen($asunto) > 255 || mb_strlen($cuerpo) > 20000) {
            return $this->error('El asunto o el mensaje supera el tamaño permitido.', 422);
        }

        $modeloUsuario = new UsuarioModel();
        $usuario = $modeloUsuario->buscarPorId($usuarioId);

        if (!$usuario) {
            return $this->error('No fue posible identificar al remitente.', 404);
        }

        $remitente = strtolower(trim((string)($usuario['correo'] ?? '')));
        if ($remitente === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'Tu usuario no tiene un correo institucional válido para realizar el envío.',
                422
            );
        }

        $preparacion = $this->prepararAdjuntos($archivos);
        if (!($preparacion['ok'] ?? false)) {
            return $preparacion;
        }

        $adjuntos = $preparacion['adjuntos'] ?? [];
        $rutasTemporales = $preparacion['rutas_temporales'] ?? [];

        if (!empty($adjuntos) && !$this->tablaAdjuntosDisponible()) {
            $this->limpiarTemporales($rutasTemporales);

            return $this->error(
                'Falta aplicar la migración de adjuntos de Correos de Marketing.',
                500
            );
        }

        $nombresAdjuntos = array_map(
            static fn($adjunto) => (string)($adjunto['nombre'] ?? ''),
            $adjuntos
        );

        $registroId = 0;

        try {
            if ($this->tablaDisponible()) {
                $registroId = $this->crearRegistroPendiente(
                    $usuarioId,
                    $destinatario,
                    $destinatarioNombre,
                    $asunto,
                    $cuerpo,
                    $nombresAdjuntos
                );
            }

            $envio = $this->sender->enviar([
                'remitente' => $remitente,
                'nombre_remitente' => trim(
                    (string)($usuario['nombre'] ?? '') . ' ' .
                    (string)($usuario['apellidos'] ?? '')
                ),
                'destinatario' => $destinatario,
                'nombre_destinatario' => $destinatarioNombre,
                'asunto' => $asunto,
                'cuerpo' => $cuerpo,
                'adjuntos' => $adjuntos
            ]);

            if (!($envio['ok'] ?? false)) {
                if ($registroId > 0) {
                    $this->actualizarRegistroError(
                        $registroId,
                        (string)($envio['mensaje'] ?? 'No fue posible realizar el envío.')
                    );
                }

                return $envio;
            }

            if ($registroId > 0) {
                if (!empty($adjuntos)) {
                    $this->guardarAdjuntosPersistentes(
                        $registroId,
                        $usuarioId,
                        $adjuntos
                    );
                }

                $this->marcarEnviado(
                    $registroId,
                    (string)($envio['proveedor'] ?? ''),
                    !empty($envio['firma_incluida'])
                );
            }

            return [
                'ok' => true,
                'mensaje' => 'Correo enviado correctamente.',
                'correo_id' => $registroId,
                'proveedor' => (string)($envio['proveedor'] ?? ''),
                'firma_incluida' => (bool)($envio['firma_incluida'] ?? false),
                'adjuntos' => count($adjuntos)
            ];
        } finally {
            foreach ($rutasTemporales as $ruta) {
                if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
                    @unlink($ruta);
                }
            }
        }
    }

    private function prepararAdjuntos($archivos)
    {
        if (
            !is_array($archivos) ||
            !isset($archivos['name']) ||
            !is_array($archivos['name'])
        ) {
            return [
                'ok' => true,
                'adjuntos' => [],
                'rutas_temporales' => []
            ];
        }

        $totalArchivos = count($archivos['name']);
        if ($totalArchivos > 8) {
            return $this->error('Puedes adjuntar como máximo 8 archivos.', 422);
        }

        $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR .
            'marketing' . DIRECTORY_SEPARATOR . 'tmp';

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error('No fue posible preparar los archivos adjuntos.', 500);
        }

        $permitidas = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'ppt', 'pptx', 'txt', 'csv', 'png', 'jpg', 'jpeg'
        ];

        $mimes = [
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

        $adjuntos = [];
        $temporales = [];
        $tamanoTotal = 0;

        for ($i = 0; $i < $totalArchivos; $i++) {
            $error = (int)($archivos['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $this->limpiarTemporales($temporales);
                return $this->error('No fue posible recibir uno de los archivos adjuntos.', 422);
            }

            $nombre = basename((string)($archivos['name'][$i] ?? 'archivo'));
            $tmp = (string)($archivos['tmp_name'][$i] ?? '');
            $tamano = (int)($archivos['size'][$i] ?? 0);
            $extension = strtolower((string)pathinfo($nombre, PATHINFO_EXTENSION));

            if (!in_array($extension, $permitidas, true)) {
                $this->limpiarTemporales($temporales);
                return $this->error('Uno de los archivos adjuntos tiene un formato no permitido.', 422);
            }

            if ($tamano <= 0 || $tamano > 12 * 1024 * 1024) {
                $this->limpiarTemporales($temporales);
                return $this->error('Cada archivo debe pesar como máximo 12 MB.', 422);
            }

            $tamanoTotal += $tamano;
            if ($tamanoTotal > 20 * 1024 * 1024) {
                $this->limpiarTemporales($temporales);
                return $this->error('Los archivos adjuntos no pueden superar 20 MB en total.', 422);
            }

            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $this->limpiarTemporales($temporales);
                return $this->error('Uno de los archivos adjuntos no está disponible.', 422);
            }

            $destino = $directorio . DIRECTORY_SEPARATOR .
                date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' .
                preg_replace('/[^a-zA-Z0-9._-]+/', '_', $nombre);

            if (!move_uploaded_file($tmp, $destino)) {
                $this->limpiarTemporales($temporales);
                return $this->error('No fue posible preparar uno de los archivos adjuntos.', 500);
            }

            $temporales[] = $destino;
            $adjuntos[] = [
                'ruta' => $destino,
                'nombre' => $nombre,
                'mime' => $mimes[$extension] ?? 'application/octet-stream',
                'tamano' => $tamano
            ];
        }

        return [
            'ok' => true,
            'adjuntos' => $adjuntos,
            'rutas_temporales' => $temporales
        ];
    }

    private function listarAdjuntosCorreo($correoId, $usuarioId)
    {
        $correoId = (int)$correoId;
        $usuarioId = (int)$usuarioId;

        if (
            $correoId <= 0 ||
            $usuarioId <= 0 ||
            !$this->tablaAdjuntosDisponible()
        ) {
            return [];
        }

        $sql = "SELECT
                    id,
                    archivo,
                    nombre_original,
                    mime,
                    tamano
                FROM correos_marketing_adjuntos
                WHERE correo_id = ?
                  AND usuario_id = ?
                ORDER BY id ASC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $correoId, $usuarioId);
        $stmt->execute();

        $salida = [];
        $resultado = $stmt->get_result();

        while ($fila = $resultado->fetch_assoc()) {
            $mime = strtolower(trim((string)($fila['mime'] ?? '')));
            $nombre = (string)($fila['nombre_original'] ?? 'archivo');

            $salida[] = [
                'id' => (int)$fila['id'],
                'nombre' => $nombre,
                'mime' => $mime,
                'tamano' => (int)($fila['tamano'] ?? 0),
                'disponible' => $this->rutaAdjuntoAbsoluta(
                    (string)($fila['archivo'] ?? '')
                ) !== null,
                'es_imagen' => strpos($mime, 'image/') === 0,
                'es_pdf' =>
                    $mime === 'application/pdf' ||
                    strtolower(
                        (string)pathinfo($nombre, PATHINFO_EXTENSION)
                    ) === 'pdf'
            ];
        }

        return $salida;
    }

    private function guardarAdjuntosPersistentes(
        $correoId,
        $usuarioId,
        $adjuntos
    ) {
        if (
            (int)$correoId <= 0 ||
            (int)$usuarioId <= 0 ||
            empty($adjuntos) ||
            !$this->tablaAdjuntosDisponible()
        ) {
            return;
        }

        $directorio = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR .
            'mail' . DIRECTORY_SEPARATOR .
            'marketing' . DIRECTORY_SEPARATOR .
            date('Y') . DIRECTORY_SEPARATOR .
            'usuario_' . (int)$usuarioId . DIRECTORY_SEPARATOR .
            'correo_' . (int)$correoId;

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            throw new RuntimeException(
                'No fue posible preparar la carpeta permanente de adjuntos.'
            );
        }

        $sql = "INSERT INTO correos_marketing_adjuntos (
                    correo_id,
                    usuario_id,
                    archivo,
                    nombre_original,
                    mime,
                    tamano
                ) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->connection->prepare($sql);
        $creados = [];

        try {
            foreach ($adjuntos as $adjunto) {
                $origen = (string)($adjunto['ruta'] ?? '');

                if ($origen === '' || !is_file($origen)) {
                    throw new RuntimeException(
                        'Uno de los adjuntos enviados ya no está disponible.'
                    );
                }

                $nombreOriginal = basename(
                    (string)($adjunto['nombre'] ?? 'archivo')
                );
                $extension = strtolower(
                    (string)pathinfo($nombreOriginal, PATHINFO_EXTENSION)
                );
                $base = (string)pathinfo(
                    $nombreOriginal,
                    PATHINFO_FILENAME
                );
                $base = preg_replace(
                    '/[^a-zA-Z0-9._-]+/',
                    '_',
                    $base
                );
                $base = trim((string)$base, '._-');

                if ($base === '') {
                    $base = 'archivo';
                }

                $nombreFisico =
                    date('Ymd_His') . '_' .
                    bin2hex(random_bytes(5)) . '_' .
                    $base .
                    ($extension !== '' ? '.' . $extension : '');
                $destino = $directorio . DIRECTORY_SEPARATOR . $nombreFisico;

                if (!copy($origen, $destino)) {
                    throw new RuntimeException(
                        'No fue posible conservar uno de los archivos enviados.'
                    );
                }

                $creados[] = $destino;
                $relativa = $this->rutaRelativaStorage($destino);
                $mime = trim(
                    (string)($adjunto['mime'] ?? 'application/octet-stream')
                );
                $tamano = (int)(
                    $adjunto['tamano'] ??
                    filesize($destino)
                );

                $stmt->bind_param(
                    'iisssi',
                    $correoId,
                    $usuarioId,
                    $relativa,
                    $nombreOriginal,
                    $mime,
                    $tamano
                );
                $stmt->execute();
            }
        } catch (Throwable $error) {
            foreach ($creados as $creado) {
                if (is_file($creado)) {
                    @unlink($creado);
                }
            }

            throw $error;
        }
    }

    private function rutaRelativaStorage($ruta)
    {
        $root = rtrim(
            str_replace('\\', '/', $this->rootPath),
            '/'
        );
        $rutaNormalizada = str_replace('\\', '/', (string)$ruta);

        if (strpos($rutaNormalizada, $root . '/') !== 0) {
            throw new RuntimeException('Ruta de adjunto fuera del proyecto.');
        }

        return ltrim(substr($rutaNormalizada, strlen($root)), '/');
    }

    private function rutaAdjuntoAbsoluta($rutaRelativa)
    {
        $rutaRelativa = ltrim(
            str_replace('\\', '/', trim((string)$rutaRelativa)),
            '/'
        );

        if (
            $rutaRelativa === '' ||
            strpos($rutaRelativa, '..') !== false ||
            strpos(
                $rutaRelativa,
                'storage/mail/marketing/'
            ) !== 0
        ) {
            return null;
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa);

        return is_file($ruta) ? $ruta : null;
    }

    private function crearRegistroPendiente(
        $usuarioId,
        $destinatario,
        $destinatarioNombre,
        $asunto,
        $cuerpo,
        $nombresAdjuntos
    ) {
        $adjuntosCount = count($nombresAdjuntos);
        $adjuntosNombres = json_encode(
            array_values($nombresAdjuntos),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $tipo = 'GENERAL';
        $estado = 'PENDIENTE';

        $sql = "INSERT INTO correos_marketing (
                    usuario_id,
                    destinatario,
                    destinatario_nombre,
                    asunto,
                    cuerpo,
                    tipo,
                    estado,
                    adjuntos_count,
                    adjuntos_nombres,
                    created_at
                ) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'issssssis',
            $usuarioId,
            $destinatario,
            $destinatarioNombre,
            $asunto,
            $cuerpo,
            $tipo,
            $estado,
            $adjuntosCount,
            $adjuntosNombres
        );
        $stmt->execute();

        return (int)$this->connection->insert_id;
    }

    private function marcarEnviado($registroId, $proveedor, $firmaIncluida)
    {
        $estado = 'ENVIADO';
        $firma = $firmaIncluida ? 1 : 0;

        $sql = "UPDATE correos_marketing
                SET estado = ?,
                    proveedor = NULLIF(?, ''),
                    firma_incluida = ?,
                    error_envio = NULL,
                    fecha_envio = NOW()
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssii', $estado, $proveedor, $firma, $registroId);
        $stmt->execute();
    }

    private function actualizarRegistroError($registroId, $mensaje)
    {
        $estado = 'PENDIENTE';
        $sql = "UPDATE correos_marketing
                SET estado = ?,
                    error_envio = ?
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssi', $estado, $mensaje, $registroId);
        $stmt->execute();
    }

    private function tablaDisponible()
    {
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE 'correos_marketing'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function tablaAdjuntosDisponible()
    {
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE 'correos_marketing_adjuntos'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function limpiarTemporales($rutas)
    {
        foreach ($rutas as $ruta) {
            if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function formatearFechaHora($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return '—';
        }

        $timestamp = strtotime($valor);
        return $timestamp !== false
            ? date('d/m/Y H:i', $timestamp)
            : '—';
    }

    private function etiquetaEstado($estado)
    {
        $mapa = [
            'ENVIADO' => 'Enviado',
            'PENDIENTE' => 'Pendiente',
            'BORRADOR' => 'Borrador'
        ];

        $codigo = strtoupper(trim((string)$estado));
        return $mapa[$codigo] ?? ($codigo !== '' ? ucfirst(strtolower($codigo)) : 'Pendiente');
    }

    private function etiquetaTipo($tipo)
    {
        $mapa = [
            'GENERAL' => 'General',
            'CONVOCATORIA' => 'Convocatoria'
        ];

        $codigo = strtoupper(trim((string)$tipo));
        return $mapa[$codigo] ?? ($codigo !== '' ? ucfirst(strtolower($codigo)) : 'General');
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
