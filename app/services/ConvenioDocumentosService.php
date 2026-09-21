<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';
require_once __DIR__ . '/OficioDocxPdfService.php';

class ConvenioDocumentosService
{
    private $connection;
    private $rootPath;
    private $templateCarta;
    private $templateConvenio;
    private $storagePath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
        $this->templateCarta = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR .
            'convenios' . DIRECTORY_SEPARATOR . 'carta_propuesta_colaboracion.docx';
        $this->templateConvenio = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR .
            'convenios' . DIRECTORY_SEPARATOR . 'convenio_colaboracion_2026.docx';
        $this->storagePath = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'convenios';
    }

    public function obtenerBorrador($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        if (!$this->estructuraDisponible()) {
            return $this->error(
                'Falta aplicar la migración de documentación de convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);
        $validacion = $this->validarEtapa($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if (trim((string)($seguimiento['convenio_documentacion_enviada_at'] ?? '')) !== '') {
            return $this->error(
                'La documentación de convenio ya fue enviada a esta institución.',
                409
            );
        }

        $institucion = trim((string)($seguimiento['nombre_entidad'] ?? ''));
        $contacto = trim((string)($seguimiento['contacto_nombre'] ?? ''));
        $analista = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );
        $saludo = $contacto !== '' ? 'Buen día, ' . $contacto . ':' : 'Buen día:';

        $lineas = [
            $saludo,
            '',
            'En seguimiento a nuestra reunión, compartimos la documentación para continuar con el proceso de colaboración.',
            '',
            'Adjuntamos la carta propuesta de colaboración en formato PDF y el convenio de colaboración en Word editable, para que puedan completar los datos correspondientes.',
            '',
            'Una vez requisitado el convenio, agradeceremos nos lo compartan para continuar con la formalización.',
            '',
            'Quedamos atentos a cualquier duda o comentario.',
            '',
            'Saludos cordiales,'
        ];

        if ($analista !== '') {
            $lineas[] = $analista;
        }
        $lineas[] = 'Analista de Enlace Institucional';
        $lineas[] = 'Fundación Red Educativa México';

        $slug = $this->nombreArchivoSeguro($institucion);
        $nombreCarta = 'Carta_propuesta_' . $slug . '.pdf';
        $nombreConvenio = 'Convenio_colaboracion_' . $slug . '.docx';

        return [
            'ok' => true,
            'correo' => [
                'para' => (string)($seguimiento['destinatario_correo'] ?? ''),
                'destinatario_nombre' => $contacto,
                'institucion' => $institucion,
                'carta_fecha' => date('Y-m-d'),
                'asunto' => 'Documentación para convenio de colaboración' .
                    ($institucion !== '' ? ' - ' . $institucion : ''),
                'cuerpo' => implode("\n", $lineas),
                'documentos' => [
                    [
                        'tipo' => 'PDF',
                        'nombre' => $nombreCarta,
                        'detalle' => 'Se conserva la carta institucional y únicamente se actualiza la fecha antes de generar el PDF.'
                    ],
                    [
                        'tipo' => 'DOCX',
                        'nombre' => $nombreConvenio,
                        'detalle' => 'Se adjunta en Word editable, sin rellenar ni modificar su contenido, para que el aliado complete sus datos.'
                    ]
                ]
            ]
        ];
    }

    public function enviar($seguimientoId, $usuarioId, $cartaFecha, $asunto, $cuerpo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $cartaFecha = trim((string)$cartaFecha);
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);

        if (!$this->estructuraDisponible()) {
            return $this->error(
                'Falta aplicar la migración de documentación de convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);
        $validacion = $this->validarEtapa($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if (trim((string)($seguimiento['convenio_documentacion_enviada_at'] ?? '')) !== '') {
            return $this->error(
                'La documentación de convenio ya fue enviada. Revisa el expediente antes de intentar nuevamente.',
                409
            );
        }

        if (!$this->fechaValida($cartaFecha)) {
            return $this->error('Indica una fecha válida para la carta propuesta.', 422);
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

        $documentos = $this->prepararDocumentos(
            $seguimientoId,
            (string)($seguimiento['nombre_entidad'] ?? ''),
            $cartaFecha
        );

        if (!($documentos['ok'] ?? false)) {
            return $documentos;
        }

        $correo = new CorreoSalidaInstitucionalService();
        $resultadoEnvio = $correo->enviar([
            'remitente' => (string)($seguimiento['analista_correo'] ?? ''),
            'nombre_remitente' => trim(
                (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                (string)($seguimiento['analista_apellidos'] ?? '')
            ),
            'destinatario' => (string)($seguimiento['destinatario_correo'] ?? ''),
            'nombre_destinatario' => (string)($seguimiento['contacto_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
            'adjuntos' => [
                [
                    'ruta' => $documentos['carta_pdf_absoluta'],
                    'nombre' => $documentos['carta_pdf_nombre'],
                    'mime' => 'application/pdf'
                ],
                [
                    'ruta' => $documentos['convenio_docx_absoluta'],
                    'nombre' => $documentos['convenio_docx_nombre'],
                    'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ]
            ]
        ]);

        if (!($resultadoEnvio['ok'] ?? false)) {
            $this->eliminarGenerados($documentos);
            return $resultadoEnvio;
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $nota = "Documentación de convenio enviada\n" .
            'Para: ' . $destinatario . "\n" .
            'Carta propuesta: ' . $documentos['carta_pdf_nombre'] . "\n" .
            'Convenio editable: ' . $documentos['convenio_docx_nombre'] . "\n" .
            'Fecha de carta: ' . $cartaFecha . "\n" .
            'Asunto: ' . $asunto;

        $this->connection->begin_transaction();

        try {
            $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                        SET convenio_documentacion_enviada_at = NOW(),
                            convenio_documentacion_enviada_por = ?,
                            convenio_carta_fecha = ?,
                            convenio_destinatario = ?,
                            convenio_correo_asunto = ?,
                            convenio_correo_cuerpo = ?,
                            convenio_carta_pdf = ?,
                            convenio_docx = ?
                        WHERE seguimiento_id = ?";
            $stmtPost = $this->connection->prepare($sqlPost);
            $stmtPost->bind_param(
                'issssssi',
                $usuarioId,
                $cartaFecha,
                $destinatario,
                $asunto,
                $cuerpo,
                $documentos['carta_pdf_relativa'],
                $documentos['convenio_docx_relativa'],
                $seguimientoId
            );
            $stmtPost->execute();

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                seguimiento_id,
                                usuario_id,
                                canal,
                                fecha_inicio,
                                resultado,
                                notas
                            ) VALUES (?, ?, 'CORREO', NOW(), 'CORREO_ENVIADO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $nota);
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW(),
                                   proxima_accion_at = NULL
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
                'Documentación de convenio enviada pero no registrada: ' .
                $error->getMessage()
            );

            return $this->error(
                'El correo fue aceptado por el proveedor, pero no fue posible registrar el envío en el expediente. Revisa el correo enviado antes de intentar nuevamente.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => 'Carta propuesta y convenio editable enviados correctamente.',
            'documentos' => [
                'carta' => $documentos['carta_pdf_nombre'],
                'convenio' => $documentos['convenio_docx_nombre']
            ]
        ];
    }

    public function registrarRecibido($seguimientoId, $usuarioId, $fechaRecepcion, $notas, $archivo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $fechaRecepcion = trim((string)$fechaRecepcion);
        $notas = trim((string)$notas);

        if (!$this->estructuraRecepcionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de recepción del convenio requisitado.',
                500
            );
        }

        if (!$this->estructuraRevisionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de revisión y versiones del convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);
        $validacion = $this->validarEtapa($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if (trim((string)($seguimiento['convenio_documentacion_enviada_at'] ?? '')) === '') {
            return $this->error(
                'Primero envía la carta propuesta y el convenio editable a la institución.',
                409
            );
        }

        if (trim((string)($seguimiento['convenio_formalizado_at'] ?? '')) !== '') {
            return $this->error('El convenio ya fue formalizado.', 409);
        }

        $yaRecibido = trim((string)($seguimiento['convenio_recibido_at'] ?? '')) !== '';
        $estadoRevision = strtoupper(
            trim((string)($seguimiento['convenio_revision_estado'] ?? ''))
        );

        if ($yaRecibido && $estadoRevision !== 'CORRECCIONES') {
            return $this->error(
                'Ya existe una versión vigente del convenio. Solo puedes registrar otra después de solicitar correcciones.',
                409
            );
        }

        if (!$this->fechaValida($fechaRecepcion)) {
            return $this->error('Indica una fecha válida de recepción.', 422);
        }

        if ($fechaRecepcion > date('Y-m-d')) {
            return $this->error(
                'La fecha de recepción no puede ser posterior a la fecha actual.',
                422
            );
        }

        if (mb_strlen($notas) > 5000) {
            return $this->error('Las observaciones no pueden superar 5000 caracteres.', 422);
        }

        $validacionArchivo = $this->validarArchivoRecibido($archivo);
        if (!($validacionArchivo['ok'] ?? false)) {
            return $validacionArchivo;
        }

        $version = $this->siguienteVersion($seguimientoId);
        $tipoVersion = $version === 1 ? 'REQUISITADO' : 'CORREGIDO';

        $anio = date('Y');
        $enviadoAt = trim((string)($seguimiento['convenio_documentacion_enviada_at'] ?? ''));
        if ($enviadoAt !== '') {
            try {
                $anio = (new DateTime($enviadoAt))->format('Y');
            } catch (Throwable $error) {
                $anio = date('Y');
            }
        }

        $directorio = $this->storagePath . DIRECTORY_SEPARATOR . $anio .
            DIRECTORY_SEPARATOR . 'seguimiento_' . $seguimientoId .
            DIRECTORY_SEPARATOR . 'recibidos';

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error(
                'No fue posible preparar la carpeta del convenio recibido.',
                500
            );
        }

        $extension = (string)$validacionArchivo['extension'];
        $slug = $this->nombreArchivoSeguro(
            (string)($seguimiento['nombre_entidad'] ?? '')
        );
        $nombreInterno = 'v' . $version . '_Convenio_' .
            ($tipoVersion === 'CORREGIDO' ? 'corregido_' : 'requisitado_') .
            $slug . '_' . date('Ymd_His') . '_' .
            bin2hex(random_bytes(3)) . '.' . $extension;
        $rutaAbsoluta = $directorio . DIRECTORY_SEPARATOR . $nombreInterno;

        if (!move_uploaded_file((string)$archivo['tmp_name'], $rutaAbsoluta)) {
            return $this->error(
                'No fue posible guardar el convenio recibido.',
                500
            );
        }

        $relativa = 'storage/convenios/' . $anio .
            '/seguimiento_' . $seguimientoId . '/recibidos/' . $nombreInterno;
        $nombreOriginal = (string)$validacionArchivo['nombre_original'];
        $mime = (string)$validacionArchivo['mime'];
        $tamano = (int)$validacionArchivo['tamano'];

        $this->connection->begin_transaction();

        try {
            $sqlVersion = "INSERT INTO seguimientos_vinculacion_convenio_versiones (
                                seguimiento_id,
                                version_num,
                                tipo,
                                fecha_recepcion,
                                archivo,
                                nombre_original,
                                mime,
                                tamano,
                                notas,
                                recibido_por,
                                recibido_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmtVersion = $this->connection->prepare($sqlVersion);
            $stmtVersion->bind_param(
                'iisssssisi',
                $seguimientoId,
                $version,
                $tipoVersion,
                $fechaRecepcion,
                $relativa,
                $nombreOriginal,
                $mime,
                $tamano,
                $notas,
                $usuarioId
            );
            $stmtVersion->execute();
            $versionId = (int)$this->connection->insert_id;

            $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                        SET convenio_recibido_fecha = ?,
                            convenio_recibido_at = NOW(),
                            convenio_recibido_por = ?,
                            convenio_recibido_archivo = ?,
                            convenio_recibido_nombre_original = ?,
                            convenio_recibido_mime = ?,
                            convenio_recibido_tamano = ?,
                            convenio_recibido_notas = ?,
                            convenio_revision_estado = 'PENDIENTE',
                            convenio_revision_notas = NULL,
                            convenio_revision_at = NULL,
                            convenio_revision_por = NULL,
                            convenio_version_actual = ?
                        WHERE seguimiento_id = ?";
            $stmtPost = $this->connection->prepare($sqlPost);
            $stmtPost->bind_param(
                'sisssisii',
                $fechaRecepcion,
                $usuarioId,
                $relativa,
                $nombreOriginal,
                $mime,
                $tamano,
                $notas,
                $version,
                $seguimientoId
            );
            $stmtPost->execute();

            if ($stmtPost->affected_rows !== 1) {
                throw new RuntimeException(
                    'No fue posible actualizar la versión vigente del convenio.'
                );
            }

            $notaInteraccion = ($version === 1
                    ? 'Convenio requisitado recibido'
                    : 'Convenio corregido recibido') .
                ' · versión ' . $version . ': ' . $nombreOriginal .
                ' | Fecha de recepción: ' . $fechaRecepcion .
                ($notas !== '' ? ' | ' . $notas : '');

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                seguimiento_id,
                                usuario_id,
                                canal,
                                fecha_inicio,
                                resultado,
                                notas
                            ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param(
                'iis',
                $seguimientoId,
                $usuarioId,
                $notaInteraccion
            );
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW(),
                                   proxima_accion_at = NULL
                               WHERE id = ?
                                 AND analista_id = ?
                                 AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            @unlink($rutaAbsoluta);
            error_log('No fue posible registrar la versión del convenio: ' . $error->getMessage());

            return $this->error(
                'No fue posible registrar el convenio recibido en el expediente.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => $version === 1
                ? 'Convenio requisitado recibido. Quedó pendiente de revisión.'
                : 'Nueva versión del convenio recibida. Quedó pendiente de revisión.',
            'archivo' => [
                'id' => $versionId,
                'version' => $version,
                'nombre' => $nombreOriginal,
                'tipo' => strtoupper($extension),
                'tamano' => $tamano
            ]
        ];
    }

    public function registrarRevision($seguimientoId, $usuarioId, $decision, $notas = '')
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $decision = strtoupper(trim((string)$decision));
        $notas = trim((string)$notas);

        if (!$this->estructuraRevisionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de revisión y versiones del convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);
        $validacion = $this->validarEtapa($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if (trim((string)($seguimiento['convenio_recibido_at'] ?? '')) === '') {
            return $this->error(
                'Primero registra el convenio que devolvió la institución.',
                409
            );
        }

        if (trim((string)($seguimiento['convenio_formalizado_at'] ?? '')) !== '') {
            return $this->error('El convenio ya fue formalizado.', 409);
        }

        if (!in_array($decision, ['APROBAR', 'CORRECCIONES'], true)) {
            return $this->error('La decisión de revisión no es válida.', 422);
        }

        if ($decision === 'CORRECCIONES' && $notas === '') {
            return $this->error(
                'Indica qué debe corregirse antes de solicitar una nueva versión.',
                422
            );
        }

        if (mb_strlen($notas) > 5000) {
            return $this->error('Las observaciones no pueden superar 5000 caracteres.', 422);
        }

        $version = $this->obtenerVersionActual($seguimientoId);
        if (!$version) {
            return $this->error(
                'No se encontró la versión vigente del convenio en el expediente.',
                409
            );
        }

        $estadoActual = strtoupper(
            trim((string)($seguimiento['convenio_revision_estado'] ?? ''))
        );

        if ($estadoActual === 'APROBADO') {
            return $this->error('La versión vigente del convenio ya fue aprobada.', 409);
        }

        if ($estadoActual === 'CORRECCIONES') {
            return $this->error(
                'Ya se solicitaron correcciones. Espera y registra la nueva versión cuando la institución la envíe.',
                409
            );
        }

        $estadoNuevo = $decision === 'APROBAR' ? 'APROBADO' : 'CORRECCIONES';
        $versionNumero = (int)$version['version_num'];

        $this->connection->begin_transaction();

        try {
            $sql = "UPDATE seguimientos_vinculacion_post_envio
                    SET convenio_revision_estado = ?,
                        convenio_revision_notas = ?,
                        convenio_revision_at = NOW(),
                        convenio_revision_por = ?,
                        convenio_version_actual = ?
                    WHERE seguimiento_id = ?";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'ssiii',
                $estadoNuevo,
                $notas,
                $usuarioId,
                $versionNumero,
                $seguimientoId
            );
            $stmt->execute();

            $notaInteraccion = $decision === 'APROBAR'
                ? 'Convenio versión ' . $versionNumero . ' revisado y aprobado.'
                : 'Correcciones solicitadas para convenio versión ' .
                    $versionNumero . ': ' . $notas;

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                seguimiento_id,
                                usuario_id,
                                canal,
                                fecha_inicio,
                                resultado,
                                notas
                            ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param(
                'iis',
                $seguimientoId,
                $usuarioId,
                $notaInteraccion
            );
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW(),
                                   proxima_accion_at = NULL
                               WHERE id = ?
                                 AND analista_id = ?
                                 AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('No fue posible registrar la revisión del convenio: ' . $error->getMessage());

            return $this->error(
                'No fue posible guardar la revisión del convenio.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => $decision === 'APROBAR'
                ? 'Convenio aprobado. Ya puede continuar con la formalización.'
                : 'Correcciones registradas. El seguimiento quedó esperando una nueva versión.',
            'estado' => $estadoNuevo,
            'version' => $versionNumero
        ];
    }

    public function validarConvenioAprobado($seguimientoId, $usuarioId)
    {
        if (!$this->estructuraRevisionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de revisión y versiones del convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId
        );

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (
            strtoupper(trim((string)($seguimiento['convenio_revision_estado'] ?? ''))) !==
            'APROBADO'
        ) {
            return $this->error(
                'Primero revisa y aprueba la versión vigente del convenio.',
                409
            );
        }

        if (!$this->obtenerVersionActual((int)$seguimientoId)) {
            return $this->error(
                'No se encontró la versión aprobada del convenio.',
                409
            );
        }

        return ['ok' => true];
    }

    public function obtenerArchivoActual($seguimientoId)
    {
        $seguimientoId = (int)$seguimientoId;

        if ($seguimientoId <= 0 || !$this->estructuraRecepcionDisponible()) {
            return $this->error('No se encontró el documento solicitado.', 404);
        }

        $sql = "SELECT
                    seguimiento_id,
                    convenio_recibido_fecha AS fecha_recepcion,
                    convenio_recibido_archivo AS archivo,
                    convenio_recibido_nombre_original AS nombre_original,
                    convenio_recibido_mime AS mime,
                    convenio_recibido_tamano AS tamano
                FROM seguimientos_vinculacion_post_envio
                WHERE seguimiento_id = ?
                  AND convenio_recibido_at IS NOT NULL
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $version = $stmt->get_result()->fetch_assoc();

        if (
            !$version ||
            trim((string)($version['archivo'] ?? '')) === ''
        ) {
            return $this->error('No se encontró el documento solicitado.', 404);
        }

        $ruta = $this->rutaVersionAbsoluta((string)$version['archivo']);
        if ($ruta === null || !is_file($ruta)) {
            return $this->error(
                'El archivo del convenio no está disponible en el almacenamiento.',
                404
            );
        }

        $version['id'] = 0;
        $version['version_num'] = 1;
        $version['tipo'] = 'REQUISITADO';
        $version['ruta_absoluta'] = $ruta;

        return [
            'ok' => true,
            'version' => $version
        ];
    }

    public function obtenerArchivoVersion($versionId)
    {
        $versionId = (int)$versionId;

        if ($versionId <= 0 || !$this->estructuraRevisionDisponible()) {
            return $this->error('No se encontró el documento solicitado.', 404);
        }

        $sql = "SELECT
                    id,
                    seguimiento_id,
                    version_num,
                    tipo,
                    fecha_recepcion,
                    archivo,
                    nombre_original,
                    mime,
                    tamano
                FROM seguimientos_vinculacion_convenio_versiones
                WHERE id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $version = $stmt->get_result()->fetch_assoc();

        if (!$version) {
            return $this->error('No se encontró el documento solicitado.', 404);
        }

        $ruta = $this->rutaVersionAbsoluta((string)$version['archivo']);
        if ($ruta === null || !is_file($ruta)) {
            return $this->error(
                'El archivo del convenio no está disponible en el almacenamiento.',
                404
            );
        }

        $version['ruta_absoluta'] = $ruta;

        return [
            'ok' => true,
            'version' => $version
        ];
    }

    public function validarDocumentacionEnviada($seguimientoId, $usuarioId)
    {
        if (!$this->estructuraDisponible()) {
            return $this->error(
                'Falta aplicar la migración de documentación de convenio.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId
        );

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (trim((string)($seguimiento['convenio_documentacion_enviada_at'] ?? '')) === '') {
            return $this->error(
                'Primero envía la carta propuesta y el convenio editable a la institución.',
                409
            );
        }

        return ['ok' => true];
    }

    public function validarConvenioRecibido($seguimientoId, $usuarioId)
    {
        if (!$this->estructuraRecepcionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de recepción del convenio requisitado.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId
        );

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (trim((string)($seguimiento['convenio_recibido_at'] ?? '')) === '') {
            return $this->error(
                'Primero registra el convenio requisitado que devolvió la institución.',
                409
            );
        }

        if (trim((string)($seguimiento['convenio_recibido_archivo'] ?? '')) === '') {
            return $this->error(
                'El expediente no tiene asociado el archivo del convenio recibido.',
                409
            );
        }

        return ['ok' => true];
    }

    public function ajustarFlujo($seguimientoId, $usuarioId, $flujo)
    {
        if (!is_array($flujo) || (int)($flujo['paso_actual'] ?? 0) !== 13) {
            return $flujo;
        }

        if (!$this->estructuraDisponible()) {
            return $flujo;
        }

        $seguimiento = $this->obtenerSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId
        );

        if (!$seguimiento) {
            return $flujo;
        }

        if (trim((string)($seguimiento['convenio_formalizado_at'] ?? '')) !== '') {
            return $flujo;
        }

        if (
            trim((string)($seguimiento['reunion_realizada_at'] ?? '')) === '' ||
            strtoupper(trim((string)($seguimiento['reunion_resultado'] ?? ''))) !== 'AVANZAR_CONVENIO'
        ) {
            return $flujo;
        }

        $enviadoAt = trim(
            (string)($seguimiento['convenio_documentacion_enviada_at'] ?? '')
        );

        $flujo['ventana'] = [
            'anterior' => [
                'numero' => 12,
                'clave' => 'REUNION_REALIZADA',
                'titulo' => 'Reunión y acuerdos'
            ],
            'actual' => [
                'numero' => 13,
                'clave' => 'CONVENIO',
                'titulo' => 'Convenio'
            ],
            'siguiente' => null
        ];
        $flujo['contexto'] = is_array($flujo['contexto'] ?? null)
            ? $flujo['contexto']
            : [];
        $flujo['contexto']['convenio_documentacion_enviada_at'] = $enviadoAt;

        if ($enviadoAt === '') {
            $flujo['titulo'] = 'Enviar documentación de convenio';
            $flujo['descripcion'] =
                'La institución acordó avanzar. Prepara un solo correo con la carta propuesta en PDF y el convenio en Word editable para que el aliado complete sus datos.';
            $flujo['accion_principal'] = [
                'codigo' => 'ENVIAR_DOCUMENTACION_CONVENIO',
                'etiqueta' => 'Preparar y enviar documentos',
                'icono' => 'bi-envelope-paper'
            ];
            $flujo['accion_secundaria'] = null;

            return $flujo;
        }

        $recibidoAt = trim(
            (string)($seguimiento['convenio_recibido_at'] ?? '')
        );
        $flujo['contexto']['convenio_recibido_at'] = $recibidoAt;
        $flujo['contexto']['convenio_recibido_nombre_original'] = trim(
            (string)($seguimiento['convenio_recibido_nombre_original'] ?? '')
        );

        if ($recibidoAt === '') {
            $flujo['titulo'] = 'Esperando convenio requisitado';
            $flujo['descripcion'] =
                'La carta propuesta y el convenio editable ya fueron enviados. Cuando la institución devuelva el convenio con sus datos, registra el archivo recibido en el expediente.';
            $flujo['accion_principal'] = [
                'codigo' => 'REGISTRAR_CONVENIO_RECIBIDO',
                'etiqueta' => 'Registrar convenio recibido',
                'icono' => 'bi-cloud-arrow-up'
            ];
            $flujo['accion_secundaria'] = null;

            return $flujo;
        }

        if (!$this->estructuraRevisionDisponible()) {
            $flujo['titulo'] = 'Convenio recibido · Actualización requerida';
            $flujo['descripcion'] =
                'El archivo ya fue recibido, pero falta aplicar la migración de revisión y versiones del convenio antes de continuar.';
            $flujo['accion_principal'] = null;
            $flujo['accion_secundaria'] = null;

            return $flujo;
        }

        $versionActual = $this->obtenerVersionActual((int)$seguimientoId);
        if ($versionActual) {
            $flujo['contexto']['convenio_version_actual'] = [
                'id' => (int)$versionActual['id'],
                'seguimiento_id' => (int)$seguimientoId,
                'numero' => (int)$versionActual['version_num'],
                'tipo' => (string)$versionActual['tipo'],
                'fecha_recepcion' => (string)$versionActual['fecha_recepcion'],
                'nombre' => (string)$versionActual['nombre_original'],
                'mime' => (string)$versionActual['mime'],
                'tamano' => (int)$versionActual['tamano']
            ];
        } else {
            // Compatibilidad con expedientes recibidos durante la transición:
            // la tarjeta sigue disponible aunque aún no exista una fila histórica.
            $flujo['contexto']['convenio_version_actual'] = [
                'id' => 0,
                'seguimiento_id' => (int)$seguimientoId,
                'numero' => max(
                    1,
                    (int)($seguimiento['convenio_version_actual'] ?? 1)
                ),
                'tipo' => 'REQUISITADO',
                'fecha_recepcion' => (string)($seguimiento['convenio_recibido_fecha'] ?? ''),
                'nombre' => (string)($seguimiento['convenio_recibido_nombre_original'] ?? 'Convenio recibido'),
                'mime' => (string)($seguimiento['convenio_recibido_mime'] ?? ''),
                'tamano' => (int)($seguimiento['convenio_recibido_tamano'] ?? 0)
            ];
        }

        $estadoRevision = strtoupper(
            trim((string)($seguimiento['convenio_revision_estado'] ?? 'PENDIENTE'))
        );
        if ($estadoRevision === '') {
            $estadoRevision = 'PENDIENTE';
        }

        $flujo['contexto']['convenio_revision_estado'] = $estadoRevision;
        $flujo['contexto']['convenio_revision_notas'] = trim(
            (string)($seguimiento['convenio_revision_notas'] ?? '')
        );

        if ($estadoRevision === 'CORRECCIONES') {
            $flujo['titulo'] = 'Esperando convenio corregido';
            $flujo['descripcion'] =
                'Se solicitaron correcciones a la versión vigente. Cuando la institución devuelva el documento actualizado, registra la nueva versión sin reemplazar la anterior.';
            $flujo['accion_principal'] = [
                'codigo' => 'REGISTRAR_CONVENIO_CORREGIDO',
                'etiqueta' => 'Registrar convenio corregido',
                'icono' => 'bi-cloud-arrow-up'
            ];
            $flujo['accion_secundaria'] = null;

            return $flujo;
        }

        if ($estadoRevision === 'APROBADO') {
            $flujo['titulo'] = 'Convenio aprobado';
            $flujo['descripcion'] =
                'La versión vigente fue revisada y aprobada. Registra la fecha y referencia final para concluir la formalización.';
            $flujo['accion_principal'] = [
                'codigo' => 'FORMALIZAR_CONVENIO',
                'etiqueta' => 'Registrar convenio formalizado',
                'icono' => 'bi-file-earmark-check'
            ];
            $flujo['accion_secundaria'] = null;

            return $flujo;
        }

        $flujo['titulo'] = 'Revisar convenio recibido';
        $flujo['descripcion'] =
            'La institución ya devolvió el convenio requisitado. Revisa la versión vigente y decide si está correcta o si requiere ajustes.';
        $flujo['accion_principal'] = [
            'codigo' => 'APROBAR_CONVENIO_RECIBIDO',
            'etiqueta' => 'Convenio correcto',
            'icono' => 'bi-check2-circle'
        ];
        $flujo['accion_secundaria'] = [
            'codigo' => 'SOLICITAR_CORRECCIONES_CONVENIO',
            'etiqueta' => 'Solicitar correcciones',
            'icono' => 'bi-pencil-square'
        ];

        return $flujo;
    }

    private function validarEtapa($seguimiento)
    {
        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if (
            strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) ===
            'DESCARTADO'
        ) {
            return $this->error('Este seguimiento ya fue descartado.', 409);
        }

        if (trim((string)($seguimiento['reunion_realizada_at'] ?? '')) === '') {
            return $this->error('Primero registra la reunión como realizada.', 409);
        }

        if (
            strtoupper(trim((string)($seguimiento['reunion_resultado'] ?? ''))) !==
            'AVANZAR_CONVENIO'
        ) {
            return $this->error(
                'El resultado de la reunión todavía no permite avanzar al convenio.',
                409
            );
        }

        $correo = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'El seguimiento no tiene un correo de contacto válido.',
                422
            );
        }

        $analistaCorreo = trim((string)($seguimiento['analista_correo'] ?? ''));
        if (
            $analistaCorreo === '' ||
            !filter_var($analistaCorreo, FILTER_VALIDATE_EMAIL)
        ) {
            return $this->error(
                'El Analista no tiene un correo institucional válido.',
                422
            );
        }

        return ['ok' => true];
    }

    private function obtenerSeguimiento($seguimientoId, $usuarioId)
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
                    p.reunion_resultado,
                    p.reunion_realizada_at,
                    p.convenio_formalizado_at,
                    p.convenio_documentacion_enviada_at,
                    p.convenio_documentacion_enviada_por,
                    p.convenio_carta_fecha,
                    p.convenio_destinatario,
                    p.convenio_correo_asunto,
                    p.convenio_correo_cuerpo,
                    p.convenio_carta_pdf,
                    p.convenio_docx,
                    " . $this->camposRecepcionSql() . ",
                    u.nombre AS analista_nombre,
                    u.apellidos AS analista_apellidos,
                    u.correo AS analista_correo
                FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                JOIN usuarios u
                    ON u.id = s.analista_id
                WHERE s.id = ?
                    AND s.analista_id = ?
                    AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function validarArchivoRecibido($archivo)
    {
        if (!is_array($archivo)) {
            return $this->error(
                'Selecciona el archivo del convenio requisitado.',
                422
            );
        }

        $errorCarga = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCarga !== UPLOAD_ERR_OK) {
            $mensajes = [
                UPLOAD_ERR_INI_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
                UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el formulario.',
                UPLOAD_ERR_PARTIAL => 'El archivo se recibió de forma incompleta.',
                UPLOAD_ERR_NO_FILE => 'Selecciona el archivo del convenio requisitado.'
            ];

            return $this->error(
                $mensajes[$errorCarga] ?? 'No fue posible recibir el archivo del convenio.',
                422
            );
        }

        $tmp = (string)($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
            return $this->error(
                'El archivo recibido no es una carga válida.',
                422
            );
        }

        $tamano = (int)($archivo['size'] ?? 0);
        $maximo = 15 * 1024 * 1024;
        if ($tamano <= 0) {
            return $this->error('El archivo recibido está vacío.', 422);
        }
        if ($tamano > $maximo) {
            return $this->error(
                'El convenio no puede superar 15 MB.',
                422
            );
        }

        $nombreOriginal = basename(
            str_replace('\\', '/', (string)($archivo['name'] ?? 'convenio'))
        );
        $extension = strtolower((string)pathinfo($nombreOriginal, PATHINFO_EXTENSION));

        if (!in_array($extension, ['docx', 'pdf'], true)) {
            return $this->error(
                'El convenio recibido debe estar en formato DOCX o PDF.',
                422
            );
        }

        if ($extension === 'pdf') {
            $manejador = @fopen($tmp, 'rb');
            $firma = is_resource($manejador) ? fread($manejador, 5) : false;
            if (is_resource($manejador)) {
                fclose($manejador);
            }

            if ($firma !== '%PDF-') {
                return $this->error(
                    'El archivo seleccionado no es un PDF válido.',
                    422
                );
            }
        } else {
            if (!class_exists('ZipArchive')) {
                return $this->error(
                    'PHP necesita la extensión ZIP para validar el convenio DOCX.',
                    500
                );
            }

            $zip = new ZipArchive();
            $abierto = $zip->open($tmp);
            if (
                $abierto !== true ||
                $zip->locateName('[Content_Types].xml') === false ||
                $zip->locateName('word/document.xml') === false
            ) {
                if ($abierto === true) {
                    $zip->close();
                }
                return $this->error(
                    'El archivo seleccionado no es un DOCX válido.',
                    422
                );
            }
            $zip->close();
        }

        $mime = $extension === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        if (class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectado = trim((string)$finfo->file($tmp));
                if ($detectado !== '') {
                    $mime = $detectado;
                }
            } catch (Throwable $error) {
                // La firma/estructura del archivo ya fue validada arriba.
            }
        }

        return [
            'ok' => true,
            'extension' => $extension,
            'nombre_original' => mb_substr($nombreOriginal, 0, 255),
            'mime' => mb_substr($mime, 0, 120),
            'tamano' => $tamano
        ];
    }

    private function camposRecepcionSql()
    {
        $campos = $this->estructuraRecepcionDisponible()
            ? "p.convenio_recibido_fecha,
                    p.convenio_recibido_at,
                    p.convenio_recibido_por,
                    p.convenio_recibido_archivo,
                    p.convenio_recibido_nombre_original,
                    p.convenio_recibido_mime,
                    p.convenio_recibido_tamano,
                    p.convenio_recibido_notas"
            : "NULL AS convenio_recibido_fecha,
                    NULL AS convenio_recibido_at,
                    NULL AS convenio_recibido_por,
                    NULL AS convenio_recibido_archivo,
                    NULL AS convenio_recibido_nombre_original,
                    NULL AS convenio_recibido_mime,
                    NULL AS convenio_recibido_tamano,
                    NULL AS convenio_recibido_notas";

        if ($this->estructuraRevisionDisponible()) {
            $campos .= ",
                    p.convenio_revision_estado,
                    p.convenio_revision_notas,
                    p.convenio_revision_at,
                    p.convenio_revision_por,
                    p.convenio_version_actual";
        } else {
            $campos .= ",
                    NULL AS convenio_revision_estado,
                    NULL AS convenio_revision_notas,
                    NULL AS convenio_revision_at,
                    NULL AS convenio_revision_por,
                    NULL AS convenio_version_actual";
        }

        return $campos;
    }

    private function siguienteVersion($seguimientoId)
    {
        $sql = "SELECT COALESCE(MAX(version_num), 0) + 1 AS siguiente
                FROM seguimientos_vinculacion_convenio_versiones
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return max(1, (int)($fila['siguiente'] ?? 1));
    }

    private function obtenerVersionActual($seguimientoId)
    {
        if (!$this->estructuraRevisionDisponible()) {
            return null;
        }

        $sql = "SELECT
                    id,
                    seguimiento_id,
                    version_num,
                    tipo,
                    fecha_recepcion,
                    archivo,
                    nombre_original,
                    mime,
                    tamano,
                    notas,
                    recibido_por,
                    recibido_at
                FROM seguimientos_vinculacion_convenio_versiones
                WHERE seguimiento_id = ?
                ORDER BY version_num DESC, id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function rutaVersionAbsoluta($rutaRelativa)
    {
        $rutaRelativa = trim(str_replace('\\', '/', (string)$rutaRelativa));
        if ($rutaRelativa === '' || strpos($rutaRelativa, '..') !== false) {
            return null;
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, ltrim($rutaRelativa, '/'));
        $real = realpath($ruta);
        $storage = realpath($this->storagePath);

        if ($real === false || $storage === false) {
            return null;
        }

        $prefijo = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($real, $prefijo) !== 0) {
            return null;
        }

        return $real;
    }

    private function prepararDocumentos($seguimientoId, $institucion, $cartaFecha)
    {
        if (!is_file($this->templateCarta)) {
            return $this->error(
                'No se encontró la plantilla institucional de la carta propuesta.',
                500
            );
        }
        if (!is_file($this->templateConvenio)) {
            return $this->error(
                'No se encontró la plantilla editable del convenio.',
                500
            );
        }

        $anio = date('Y');
        $directorio = $this->storagePath . DIRECTORY_SEPARATOR . $anio .
            DIRECTORY_SEPARATOR . 'seguimiento_' . (int)$seguimientoId;

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error(
                'No fue posible preparar la carpeta de documentos del convenio.',
                500
            );
        }

        $slug = $this->nombreArchivoSeguro($institucion);
        $nombreCarta = 'Carta_propuesta_' . $slug . '.pdf';
        $nombreConvenio = 'Convenio_colaboracion_' . $slug . '.docx';
        $rutaCarta = $directorio . DIRECTORY_SEPARATOR . $nombreCarta;
        $rutaConvenio = $directorio . DIRECTORY_SEPARATOR . $nombreConvenio;
        $rutaCartaDocx = $directorio . DIRECTORY_SEPARATOR .
            '.carta_' . bin2hex(random_bytes(5)) . '.docx';

        if (!copy($this->templateCarta, $rutaCartaDocx)) {
            return $this->error(
                'No fue posible preparar la carta propuesta.',
                500
            );
        }

        try {
            $fechaActualizada = $this->actualizarFechaCarta(
                $rutaCartaDocx,
                $cartaFecha
            );

            if (!($fechaActualizada['ok'] ?? false)) {
                return $fechaActualizada;
            }

            $conversor = new OficioDocxPdfService();
            $resultadoPdf = $conversor->convertirArchivoAPdf($rutaCartaDocx);

            if (!($resultadoPdf['ok'] ?? false)) {
                $detalle = trim((string)($resultadoPdf['mensaje_tecnico'] ?? ''));
                if ($detalle !== '') {
                    error_log('Carta propuesta DOCX/PDF: ' . $detalle);
                }

                return $this->error(
                    (string)($resultadoPdf['mensaje'] ??
                        'No fue posible convertir la carta propuesta a PDF.'),
                    500
                );
            }

            $contenidoPdf = (string)($resultadoPdf['contenido_pdf'] ?? '');
            if (
                $contenidoPdf === '' ||
                file_put_contents($rutaCarta, $contenidoPdf, LOCK_EX) === false
            ) {
                return $this->error(
                    'No fue posible guardar la carta propuesta en PDF.',
                    500
                );
            }

            if (!copy($this->templateConvenio, $rutaConvenio)) {
                @unlink($rutaCarta);
                return $this->error(
                    'No fue posible preparar el convenio editable.',
                    500
                );
            }

            $relativaBase = 'storage/convenios/' . $anio .
                '/seguimiento_' . (int)$seguimientoId . '/';

            return [
                'ok' => true,
                'carta_pdf_absoluta' => $rutaCarta,
                'carta_pdf_relativa' => $relativaBase . $nombreCarta,
                'carta_pdf_nombre' => $nombreCarta,
                'convenio_docx_absoluta' => $rutaConvenio,
                'convenio_docx_relativa' => $relativaBase . $nombreConvenio,
                'convenio_docx_nombre' => $nombreConvenio
            ];
        } finally {
            @unlink($rutaCartaDocx);
        }
    }

    private function actualizarFechaCarta($rutaDocx, $fecha)
    {
        if (!class_exists('ZipArchive')) {
            return $this->error(
                'PHP necesita la extensión ZIP para actualizar la fecha de la carta.',
                500
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($rutaDocx) !== true) {
            return $this->error(
                'No fue posible abrir la carta propuesta para actualizar su fecha.',
                500
            );
        }

        $textoFecha = $this->fechaCarta($fecha);
        $actualizados = 0;

        try {
            for ($indice = 0; $indice < $zip->numFiles; $indice++) {
                $nombre = (string)$zip->getNameIndex($indice);
                if (!preg_match('~^word/header\d+\.xml$~', $nombre)) {
                    continue;
                }

                $xml = $zip->getFromName($nombre);
                if (
                    !is_string($xml) ||
                    strpos($xml, 'Cuernavaca, Mor.') === false
                ) {
                    continue;
                }

                $posicion = strpos($xml, 'Cuernavaca, Mor.');
                $antes = substr($xml, 0, $posicion);
                $inicioParrafo = strrpos($antes, '<w:p');
                $finParrafo = strpos($xml, '</w:p>', $posicion);

                if ($inicioParrafo === false || $finParrafo === false) {
                    continue;
                }

                $finParrafo += strlen('</w:p>');
                $parrafo = substr(
                    $xml,
                    $inicioParrafo,
                    $finParrafo - $inicioParrafo
                );
                $primero = true;
                $fechaXml = htmlspecialchars(
                    $textoFecha,
                    ENT_QUOTES | ENT_XML1,
                    'UTF-8'
                );

                $nuevoParrafo = preg_replace_callback(
                    '~<w:t([^>]*)>.*?</w:t>~s',
                    static function ($coincidencia) use (&$primero, $fechaXml) {
                        $contenido = $primero ? $fechaXml : '';
                        $primero = false;
                        return '<w:t' . $coincidencia[1] . '>' .
                            $contenido . '</w:t>';
                    },
                    $parrafo
                );

                if (!is_string($nuevoParrafo) || $primero) {
                    continue;
                }

                $xml = substr($xml, 0, $inicioParrafo) .
                    $nuevoParrafo .
                    substr($xml, $finParrafo);

                if (!$zip->addFromString($nombre, $xml)) {
                    return $this->error(
                        'No fue posible guardar la fecha actualizada de la carta.',
                        500
                    );
                }

                $actualizados++;
            }
        } finally {
            $zip->close();
        }

        if ($actualizados <= 0) {
            return $this->error(
                'No fue posible localizar la fecha editable de la carta propuesta.',
                500
            );
        }

        return ['ok' => true, 'fecha' => $textoFecha];
    }

    private function fechaCarta($fecha)
    {
        $fecha = DateTime::createFromFormat('Y-m-d', (string)$fecha);
        $meses = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];

        return 'Cuernavaca, Mor. ' . $fecha->format('j') . ' de ' .
            $meses[(int)$fecha->format('n')] . ' del ' .
            $fecha->format('Y');
    }

    private function fechaValida($valor)
    {
        $fecha = DateTime::createFromFormat('Y-m-d', (string)$valor);
        return $fecha instanceof DateTime &&
            $fecha->format('Y-m-d') === (string)$valor;
    }

    private function nombreArchivoSeguro($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return 'institucion';
        }

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if (is_string($ascii) && trim($ascii) !== '') {
                $valor = $ascii;
            }
        }

        $valor = preg_replace('/[^A-Za-z0-9]+/', '_', $valor);
        $valor = trim((string)$valor, '_');
        $valor = substr($valor, 0, 80);

        return $valor !== '' ? $valor : 'institucion';
    }

    private function eliminarGenerados($documentos)
    {
        foreach (['carta_pdf_absoluta', 'convenio_docx_absoluta'] as $clave) {
            $ruta = trim((string)($documentos[$clave] ?? ''));
            if ($ruta !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function estructuraDisponible()
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'convenio_documentacion_enviada_at'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function estructuraRecepcionDisponible()
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'convenio_recibido_at'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function estructuraRevisionDisponible()
    {
        if (
            !$this->tablaExiste('seguimientos_vinculacion_post_envio') ||
            !$this->tablaExiste('seguimientos_vinculacion_convenio_versiones')
        ) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'convenio_revision_estado'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $tabla . "'"
        );

        return $resultado && $resultado->num_rows > 0;
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
