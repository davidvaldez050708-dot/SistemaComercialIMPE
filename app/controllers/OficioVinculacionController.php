<?php

require_once __DIR__ . '/../models/OficioVinculacionModel.php';
require_once __DIR__ . '/../services/OficioPreviewService.php';
require_once __DIR__ . '/../services/OficioPdfService.php';
require_once __DIR__ . '/../services/OficioDocxPdfService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class OficioVinculacionController
{
    private const MAX_DOCX_BYTES = 20 * 1024 * 1024;

    public function estado()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $modelo = new OficioVinculacionModel();
        $modoAcceso = $this->resolverModoAcceso();
        $estado = $modelo->obtenerEstadoSeguimiento(
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este seguimiento.'
            ], 403);
        }

        $esAnalistaResponsable =
            (int)($_SESSION['rol_id'] ?? 0) === 4 &&
            (int)($estado['analista_id'] ?? 0) === $usuarioId;

        $estado['es_analista_responsable'] = $esAnalistaResponsable;
        $estado['solo_consulta'] = !$esAnalistaResponsable;
        $estado['puede_administrar_plantillas'] = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $estado['puede_generar'] =
            $esAnalistaResponsable &&
            tienePermiso('oficios.generar') &&
            !empty($estado['cumple_requisitos_generacion']);

        $this->responderJson([
            'ok' => true,
            'estado' => $estado
        ]);
    }

    public function generarBorrador()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('oficios.generar');

        if ((int)($_SESSION['rol_id'] ?? 0) !== 4) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El oficio solo puede ser generado por el Analista responsable.'
            ], 403);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $modelo = new OficioVinculacionModel();
        $tipoPlantilla = (string)($_POST['tipo_plantilla'] ?? 'predeterminada');
        $plantillaId = 0;
        $plantillaCargada = null;

        if ($tipoPlantilla === 'personalizada') {
            OficioDocxPdfService::registrarTrazaTemporal('CUSTOM_TEMPLATE_SELECTED', ['seguimiento_id' => $seguimientoId]);
            $plantillaGuardadaId = (int)($_POST['plantilla_id'] ?? 0);
            $plantillaCargada = $plantillaGuardadaId > 0
                ? $this->copiarPlantillaGuardada($plantillaGuardadaId, $usuarioId, $modelo)
                : $this->guardarPlantillaParaGeneracion(
                $_FILES['archivo_plantilla'] ?? null,
                $usuarioId,
                $modelo
            );

            if (!($plantillaCargada['ok'] ?? false)) {
                $codigoHttp = (int)($plantillaCargada['codigo_http'] ?? 422);
                unset($plantillaCargada['codigo_http']);
                $this->responderJson($plantillaCargada, $codigoHttp);
            }

            $plantillaId = (int)$plantillaCargada['plantilla_id'];
        }

        $resultado = $modelo->generarBorrador($seguimientoId, $usuarioId, $plantillaId);

        if (!($resultado['ok'] ?? false)) {
            if ($plantillaCargada) {
                $modelo->eliminarPlantillaOficioDocx($plantillaId);
                @unlink((string)$plantillaCargada['ruta_absoluta']);
            }
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        if ($plantillaCargada) {
            $vista = (new OficioPreviewService())->obtenerVistaPrevia(
                $seguimientoId,
                $usuarioId,
                'analista'
            );
            if (!($vista['ok'] ?? false)) {
                $this->responderJson($vista, (int)($vista['codigo_http'] ?? 500));
            }

            $documento = (new OficioDocxPdfService())->generarDocx(
                (array)($vista['vista_previa'] ?? [])
            );
            if (!($documento['ok'] ?? false)) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => (string)($documento['mensaje'] ?? 'No fue posible procesar internamente la plantilla seleccionada.')
                ], 500);
            }

            OficioDocxPdfService::registrarTrazaTemporal('CUSTOM_TEMPLATE_PROCESSED', ['seguimiento_id' => $seguimientoId]);
            $guardado = file_put_contents(
                (string)$plantillaCargada['ruta_absoluta'],
                (string)($documento['contenido_docx'] ?? ''),
                LOCK_EX
            );
            if ($guardado === false || $guardado <= 0) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'No fue posible guardar internamente la plantilla procesada.'
                ], 500);
            }
            OficioDocxPdfService::registrarTrazaTemporal('CUSTOM_TEMPLATE_SAVED', ['seguimiento_id' => $seguimientoId, 'plantilla_id' => $plantillaId]);
            OficioDocxPdfService::registrarTrazaTemporal('CUSTOM_TEMPLATE_PATH=' . (string)$plantillaCargada['ruta_absoluta']);
        }

        $this->responderJson($resultado);
    }

    private function copiarPlantillaGuardada($id, $usuarioId, OficioVinculacionModel $modelo)
    {
        $plantilla = $modelo->obtenerPlantillaGuardada($id);
        $base = realpath(dirname(__DIR__, 2) . '/storage/templates/oficios_personalizados');
        $ruta = $plantilla ? realpath(dirname(__DIR__, 2) . '/' . $plantilla['archivo_docx']) : false;
        if (!$base || !$ruta || !is_file($ruta)
            || strncmp($ruta, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
            || strpos(basename($ruta), 'generacion_') === 0) {
            return ['ok' => false, 'mensaje' => 'La plantilla guardada no está disponible.', 'codigo_http' => 422];
        }
        return $this->guardarPlantillaParaGeneracion([
            'error' => UPLOAD_ERR_OK, 'tmp_name' => $ruta, 'name' => basename($ruta)
        ], $usuarioId, $modelo, null, true);
    }

    private function guardarPlantillaParaGeneracion($archivo, $usuarioId, OficioVinculacionModel $modelo, $nombreBiblioteca = null, $copiar = false)
    {
        if (!$archivo || (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'mensaje' => 'Selecciona una plantilla de oficio.', 'codigo_http' => 422];
        }

        $rutaTemporal = (string)($archivo['tmp_name'] ?? '');
        $nombreOriginal = basename((string)($archivo['name'] ?? 'plantilla.docx'));
        if ($rutaTemporal === '' || !is_file($rutaTemporal)) {
            return ['ok' => false, 'mensaje' => 'El archivo temporal de la plantilla no existe.', 'codigo_http' => 422];
        }
        if (!is_readable($rutaTemporal)) {
            return ['ok' => false, 'mensaje' => 'El archivo temporal de la plantilla no puede leerse.', 'codigo_http' => 422];
        }
        $tamano = (int)filesize($rutaTemporal);

        error_log('[oficio_docx] ' . json_encode([
            'etapa' => 'recepcion',
            'seguimiento_id' => (int)($_POST['seguimiento_id'] ?? 0),
            'archivo' => $nombreOriginal,
            'temporal' => $rutaTemporal,
            'tamano' => $tamano,
            'upload_error' => (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE)
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        if (strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION)) !== 'docx' || $tamano <= 0 || $tamano > self::MAX_DOCX_BYTES) {
            return ['ok' => false, 'mensaje' => 'Solo se permiten plantillas DOCX de hasta 20 MB.', 'codigo_http' => 422];
        }

        $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($rutaTemporal) : '';
        if ($mime !== '' && !in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream'
        ], true)) {
            return ['ok' => false, 'mensaje' => 'El contenido del archivo no corresponde a un DOCX.', 'codigo_http' => 422];
        }

        $validacion = $this->validarPlantillaDocx($rutaTemporal);
        if (!($validacion['ok'] ?? false)) {
            $validacion['codigo_http'] = 422;
            return $validacion;
        }

        $procesable = (new OficioDocxPdfService())->validarPlantillaProcesable($rutaTemporal);
        if (!($procesable['ok'] ?? false)) {
            $procesable['codigo_http'] = 422;
            return $procesable;
        }

        $directorioRelativo = 'storage/templates/oficios_personalizados';
        $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directorioRelativo);
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true)) {
            return ['ok' => false, 'mensaje' => 'No fue posible preparar temporalmente la plantilla.', 'codigo_http' => 500];
        }

        $nombreArchivo = ($nombreBiblioteca === null ? 'generacion_' : 'oficio_') . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.docx';
        $rutaFinal = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;
        if (!($copiar ? copy($rutaTemporal, $rutaFinal) : move_uploaded_file($rutaTemporal, $rutaFinal))) {
            return ['ok' => false, 'mensaje' => 'No fue posible recibir la plantilla seleccionada.', 'codigo_http' => 500];
        }

        $nombreVisible = preg_replace('/[^\pL\pN ._()-]+/u', '', pathinfo($nombreOriginal, PATHINFO_FILENAME));
        $rutaRelativa = $directorioRelativo . '/' . $nombreArchivo;
        $id = $modelo->registrarPlantillaOficioDocx($nombreBiblioteca ?? ($nombreVisible ?: 'Plantilla personalizada'), $rutaRelativa, $usuarioId);
        if ($id <= 0) {
            @unlink($rutaFinal);
            return ['ok' => false, 'mensaje' => 'No fue posible preparar la plantilla seleccionada.', 'codigo_http' => 500];
        }

        return ['ok' => true, 'plantilla_id' => $id, 'ruta_absoluta' => $rutaFinal];
    }

    public function plantillas()
    {
        $this->validarPermisoJson('oficios.ver');
        $modelo = new OficioVinculacionModel();
        $this->responderJson([
            'ok' => true,
            'plantillas' => $modelo->listarPlantillasOficioDocx(),
            'puede_subir' => (int)($_SESSION['rol_id'] ?? 0) === 1 || tienePermiso('oficios.generar')
        ]);
    }

    public function subirPlantilla()
    {
        $this->validarMetodoPostJson();

        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson(['ok' => false, 'mensaje' => 'La sesión no está activa.'], 401);
        }

        if ((int)($_SESSION['rol_id'] ?? 0) !== 1 && !tienePermiso('oficios.generar')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para agregar plantillas de oficio.'
            ], 403);
        }

        $nombre = preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags(trim((string)($_POST['nombre'] ?? ''))));
        $archivo = $_FILES['archivo'] ?? null;

        if ($nombre === '' || mb_strlen($nombre) > 150) {
            $this->responderJson(['ok' => false, 'mensaje' => 'Captura un nombre de hasta 150 caracteres.'], 422);
        }

        $resultado = $this->guardarPlantillaParaGeneracion(
            $archivo, (int)$_SESSION['usuario_id'], new OficioVinculacionModel(), $nombre
        );
        if (!($resultado['ok'] ?? false)) {
            $codigo = (int)($resultado['codigo_http'] ?? 422);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigo);
        }
        $id = (int)$resultado['plantilla_id'];

        $this->responderJson(['ok' => true, 'mensaje' => 'Plantilla cargada correctamente.', 'plantilla_id' => $id]);
    }

    private function validarPlantillaDocx($ruta)
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'mensaje' => 'El servidor no dispone del soporte ZIP necesario para validar DOCX.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            return ['ok' => false, 'mensaje' => 'El archivo DOCX está dañado o no es válido.'];
        }

        $xml = $zip->getFromName('word/document.xml');
        $tipos = $zip->getFromName('[Content_Types].xml');
        $zip->close();

        if (!is_string($xml) || $xml === '' || !is_string($tipos)) {
            return ['ok' => false, 'mensaje' => 'El archivo no contiene una estructura DOCX válida.'];
        }

        preg_match_all('/(?:\{\{[A-Za-z0-9_]+\}\}|\$\{[A-Za-z0-9_]+\})/', $xml, $coincidencias);
        $marcadores = array_unique($coincidencias[0] ?? []);
        $permitidos = [
            '{{numero_oficio}}', '{{lugar_fecha}}', '{{destinatario}}', '{{institucion}}',
            '{{ubicacion}}', '{{municipio}}', '{{estado}}', '{{FOLIO}}', '{{FECHA}}',
            '{{DESTINATARIO_NOMBRE}}', '{{DESTINATARIO_CARGO}}', '{{INSTITUCION}}',
            '{{ESTADO}}', '{{ANALISTA_NOMBRE}}', '{{ANALISTA_CARGO}}', '{{ANALISTA_TELEFONO}}'
        ];
        $permitidos = array_merge($permitidos, [
            '${numero_oficio}', '${lugar_fecha}', '${destinatario}', '${institucion}',
            '${municipio}', '${estado}', '${ubicacion}'
        ]);

        $desconocidos = array_diff($marcadores, $permitidos);
        if (!empty($desconocidos)) {
            return ['ok' => false, 'mensaje' => 'Marcador no reconocido: ' . reset($desconocidos)];
        }

        return ['ok' => true, 'tiene_marcadores' => !empty($marcadores)];
    }

    public function descargarDocx()
    {
        $this->validarPermisoJson('oficios.ver');
        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderTextoPdf('El seguimiento solicitado no es válido.', 422);
        }

        $vista = (new OficioPreviewService())->obtenerVistaPrevia(
            $seguimientoId,
            $usuarioId,
            $this->resolverModoAcceso()
        );
        if (!($vista['ok'] ?? false)) {
            $this->responderTextoPdf((string)($vista['mensaje'] ?? 'No fue posible preparar el oficio.'), (int)($vista['codigo_http'] ?? 500));
        }

        $resultado = (new OficioDocxPdfService())->generarDocx((array)$vista['vista_previa']);
        if (!($resultado['ok'] ?? false)) {
            $this->responderTextoPdf((string)($resultado['mensaje'] ?? 'No fue posible generar el DOCX.'), 422);
        }

        $folio = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($vista['vista_previa']['folio'] ?? 'oficio'));
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $folio . '.docx"');
        header('Content-Length: ' . strlen((string)$resultado['contenido_docx']));
        echo $resultado['contenido_docx'];
        exit;
    }

    public function vistaPrevia()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $modoAcceso = $this->resolverModoAcceso();
        $servicio = new OficioPreviewService();
        $resultado = $servicio->obtenerVistaPrevia(
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $servicioPdf = new OficioPdfService();
        $estadoPdf = $servicioPdf->obtenerEstadoPdf(
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if ($estadoPdf['ok'] ?? false) {
            $resultado['estado_pdf'] = $this->completarPermisosPdf(
                $estadoPdf['estado_pdf'] ?? [],
                $usuarioId
            );
        }

        $this->responderJson($resultado);
    }

    public function estadoPdf()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioPdfService();
        $resultado = $servicio->obtenerEstadoPdf(
            $seguimientoId,
            $usuarioId,
            $this->resolverModoAcceso()
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['estado_pdf'] = $this->completarPermisosPdf(
            $resultado['estado_pdf'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    public function generarPdf()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('oficios.generar');

        if ((int)($_SESSION['rol_id'] ?? 0) !== 4) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El PDF solo puede ser generado por el Analista responsable.'
            ], 403);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioPdfService();
        $resultado = $servicio->generarPdf($seguimientoId, $usuarioId);

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['estado_pdf'] = $this->completarPermisosPdf(
            $resultado['estado_pdf'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    public function verPdf()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderTextoPdf('El seguimiento solicitado no es válido.', 422);
        }

        $servicio = new OficioPdfService();
        $resultado = $servicio->obtenerArchivoPdf(
            $seguimientoId,
            $usuarioId,
            $this->resolverModoAcceso()
        );

        if (!($resultado['ok'] ?? false)) {
            $this->responderTextoPdf(
                (string)($resultado['mensaje'] ?? 'No fue posible consultar el PDF.'),
                (int)($resultado['codigo_http'] ?? 404)
            );
        }

        $ruta = (string)$resultado['ruta_absoluta'];
        $nombre = (string)$resultado['nombre_archivo'];
        $descargar = (int)($_GET['descargar'] ?? 0) === 1;

        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($ruta));
        header(
            'Content-Disposition: ' .
            ($descargar ? 'attachment' : 'inline') .
            '; filename="' . str_replace('"', '', $nombre) . '"'
        );
        readfile($ruta);
        exit;
    }

    private function completarPermisosPdf($estadoPdf, $usuarioId)
    {
        $esAnalistaResponsable =
            (int)($_SESSION['rol_id'] ?? 0) === 4 &&
            (int)($estadoPdf['analista_id'] ?? 0) === (int)$usuarioId;
        $tieneFolio = trim((string)($estadoPdf['folio'] ?? '')) !== '';
        $pdfGenerado = !empty($estadoPdf['pdf_generado']);

        $estadoPdf['es_analista_responsable'] = $esAnalistaResponsable;
        $estadoPdf['solo_consulta'] = !$esAnalistaResponsable;
        $estadoPdf['puede_generar'] =
            $esAnalistaResponsable &&
            tienePermiso('oficios.generar') &&
            $tieneFolio &&
            !$pdfGenerado;

        unset($estadoPdf['analista_id']);

        return $estadoPdf;
    }

    private function resolverModoAcceso()
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return 'administrador';
        }

        if (tienePermiso('seguimientos_vinculacion.supervisar')) {
            return 'supervisor';
        }

        return 'analista';
    }

    private function validarMetodoPostJson()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function validarPermisoJson($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso($codigo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para realizar esta acción.'
            ], 403);
        }
    }

    private function responderTextoPdf($mensaje, $codigoHttp)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: text/plain; charset=utf-8');
        echo $mensaje;
        exit;
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
