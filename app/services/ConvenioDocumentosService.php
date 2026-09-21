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
                        'nombre' => 'Carta propuesta de colaboración',
                        'detalle' => 'El sistema actualizará únicamente la fecha antes de convertirla a PDF.'
                    ],
                    [
                        'tipo' => 'DOCX',
                        'nombre' => 'Convenio de colaboración',
                        'detalle' => 'Se enviará en Word editable para que el aliado complete sus datos.'
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

        $flujo['titulo'] = 'Esperando convenio requisitado';
        $flujo['descripcion'] =
            'La carta propuesta y el convenio editable ya fueron enviados. Cuando la institución devuelva el convenio con sus datos y esté listo para formalizarse, registra la referencia final.';
        $flujo['accion_principal'] = [
            'codigo' => 'FORMALIZAR_CONVENIO',
            'etiqueta' => 'Registrar convenio formalizado',
            'icono' => 'bi-file-earmark-check'
        ];
        $flujo['accion_secundaria'] = null;

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
