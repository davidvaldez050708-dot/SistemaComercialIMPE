<?php

require_once __DIR__ . '/../models/OficioVinculacionModel.php';
require_once __DIR__ . '/OficioPreviewService.php';

class OficioPdfService
{
    private $connection;
    private $rootPath;
    private $storagePath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
        $this->storagePath = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oficios';
    }

    public function obtenerEstadoPdf($seguimientoId, $usuarioId, $modoAcceso)
    {
        $modelo = new OficioVinculacionModel();
        $estado = $modelo->obtenerEstadoSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId,
            $modoAcceso
        );

        if (!$estado) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        $oficioId = (int)($estado['oficio_id'] ?? 0);
        $folio = trim((string)($estado['folio'] ?? ''));

        if ($oficioId <= 0 || $folio === '') {
            return [
                'ok' => true,
                'estado_pdf' => [
                    'seguimiento_id' => (int)$seguimientoId,
                    'analista_id' => (int)($estado['analista_id'] ?? 0),
                    'oficio_id' => 0,
                    'folio' => '',
                    'estado_oficio' => '',
                    'pdf_generado' => false,
                    'fecha_generacion' => '',
                    'fecha_generacion_label' => ''
                ]
            ];
        }

        $oficio = $this->obtenerDatosPdfPorOficio($oficioId);

        if (!$oficio) {
            return $this->error('No fue posible consultar el oficio.', 404);
        }

        $rutaReal = $this->resolverRutaPdf((string)($oficio['archivo_pdf'] ?? ''));

        return [
            'ok' => true,
            'estado_pdf' => [
                'seguimiento_id' => (int)$seguimientoId,
                'analista_id' => (int)($estado['analista_id'] ?? 0),
                'oficio_id' => $oficioId,
                'folio' => $folio,
                'estado_oficio' => (string)($oficio['estado_oficio'] ?? ''),
                'pdf_generado' => $rutaReal !== null,
                'fecha_generacion' => (string)($oficio['fecha_generacion'] ?? ''),
                'fecha_generacion_label' => $this->formatearFechaHora(
                    (string)($oficio['fecha_generacion'] ?? '')
                )
            ]
        ];
    }

    public function generarPdf($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        $modelo = new OficioVinculacionModel();
        $estado = $modelo->obtenerEstadoSeguimiento(
            $seguimientoId,
            $usuarioId,
            'analista'
        );

        if (!$estado) {
            return $this->error(
                'El PDF solo puede ser generado por el Analista responsable.',
                403
            );
        }

        $oficioId = (int)($estado['oficio_id'] ?? 0);
        $folio = trim((string)($estado['folio'] ?? ''));

        if ($oficioId <= 0 || $folio === '') {
            return $this->error(
                'Primero genera el oficio y su folio antes de crear el PDF.',
                422
            );
        }

        $oficioActual = $this->obtenerDatosPdfPorOficio($oficioId);
        $rutaExistente = $this->resolverRutaPdf(
            (string)($oficioActual['archivo_pdf'] ?? '')
        );

        if ($rutaExistente !== null) {
            return [
                'ok' => true,
                'existente' => true,
                'mensaje' => 'El PDF de este oficio ya fue generado.',
                'folio' => $folio,
                'estado_pdf' => $this->estadoPdfGenerado(
                    $seguimientoId,
                    $usuarioId,
                    $oficioId,
                    $folio
                )
            ];
        }

        $autoload = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (!is_file($autoload)) {
            return $this->error(
                'No se encontraron las dependencias de Composer. Ejecuta composer install.',
                500
            );
        }

        require_once $autoload;

        if (!class_exists('Dompdf\\Dompdf')) {
            return $this->error(
                'Dompdf no está disponible. Ejecuta composer install.',
                500
            );
        }

        $servicioVistaPrevia = new OficioPreviewService();
        $resultadoVista = $servicioVistaPrevia->obtenerVistaPrevia(
            $seguimientoId,
            $usuarioId,
            'analista'
        );

        if (!($resultadoVista['ok'] ?? false)) {
            return $resultadoVista;
        }

        $vista = $resultadoVista['vista_previa'] ?? [];
        $html = $this->construirHtmlPdf($vista);

        try {
            $opciones = new \Dompdf\Options();
            $opciones->set('defaultFont', 'DejaVu Sans');
            $opciones->set('isRemoteEnabled', false);
            $opciones->set('isHtml5ParserEnabled', true);

            $dompdf = new \Dompdf\Dompdf($opciones);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $contenidoPdf = $dompdf->output();
        } catch (Throwable $error) {
            return $this->error('No fue posible construir el PDF del oficio.', 500);
        }

        if ($contenidoPdf === '') {
            return $this->error('El PDF generado está vacío.', 500);
        }

        $anio = date('Y');
        $directorio = $this->storagePath . DIRECTORY_SEPARATOR . $anio;

        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            return $this->error(
                'No fue posible crear la carpeta para guardar el oficio.',
                500
            );
        }

        $nombreArchivo = $this->nombreArchivoSeguro($folio) . '.pdf';
        $rutaAbsoluta = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;
        $rutaRelativa = 'storage/oficios/' . $anio . '/' . $nombreArchivo;

        if (file_put_contents($rutaAbsoluta, $contenidoPdf, LOCK_EX) === false) {
            return $this->error('No fue posible guardar el PDF del oficio.', 500);
        }

        $sqlActualizar = "UPDATE oficios_vinculacion
                SET archivo_pdf = ?,
                    estado_oficio = 'GENERADO',
                    fecha_generacion = NOW(),
                    error_envio = NULL
                WHERE id = ?";
        $stmtActualizar = $this->connection->prepare($sqlActualizar);
        $stmtActualizar->bind_param('si', $rutaRelativa, $oficioId);

        if (!$stmtActualizar->execute()) {
            @unlink($rutaAbsoluta);
            return $this->error(
                'El PDF se creó, pero no fue posible registrar su información.',
                500
            );
        }

        return [
            'ok' => true,
            'existente' => false,
            'mensaje' => 'PDF generado correctamente.',
            'folio' => $folio,
            'estado_pdf' => $this->estadoPdfGenerado(
                $seguimientoId,
                $usuarioId,
                $oficioId,
                $folio
            )
        ];
    }

    public function obtenerArchivoPdf($seguimientoId, $usuarioId, $modoAcceso)
    {
        $modelo = new OficioVinculacionModel();
        $estado = $modelo->obtenerEstadoSeguimiento(
            (int)$seguimientoId,
            (int)$usuarioId,
            $modoAcceso
        );

        if (!$estado) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        $oficioId = (int)($estado['oficio_id'] ?? 0);

        if ($oficioId <= 0) {
            return $this->error('Este seguimiento aún no tiene un oficio.', 404);
        }

        $oficio = $this->obtenerDatosPdfPorOficio($oficioId);
        $rutaReal = $this->resolverRutaPdf((string)($oficio['archivo_pdf'] ?? ''));

        if ($rutaReal === null) {
            return $this->error('El PDF del oficio aún no ha sido generado.', 404);
        }

        $folio = trim((string)($estado['folio'] ?? 'oficio'));

        return [
            'ok' => true,
            'ruta_absoluta' => $rutaReal,
            'nombre_archivo' => $this->nombreArchivoSeguro($folio) . '.pdf'
        ];
    }

    private function estadoPdfGenerado($seguimientoId, $usuarioId, $oficioId, $folio)
    {
        $oficio = $this->obtenerDatosPdfPorOficio($oficioId) ?: [];

        return [
            'seguimiento_id' => (int)$seguimientoId,
            'analista_id' => (int)$usuarioId,
            'oficio_id' => (int)$oficioId,
            'folio' => (string)$folio,
            'estado_oficio' => (string)($oficio['estado_oficio'] ?? 'GENERADO'),
            'pdf_generado' => $this->resolverRutaPdf(
                (string)($oficio['archivo_pdf'] ?? '')
            ) !== null,
            'fecha_generacion' => (string)($oficio['fecha_generacion'] ?? ''),
            'fecha_generacion_label' => $this->formatearFechaHora(
                (string)($oficio['fecha_generacion'] ?? '')
            )
        ];
    }

    private function obtenerDatosPdfPorOficio($oficioId)
    {
        $sql = "SELECT id, archivo_pdf, estado_oficio, fecha_generacion
                FROM oficios_vinculacion
                WHERE id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $oficioId = (int)$oficioId;
        $stmt->bind_param('i', $oficioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function construirHtmlPdf($vista)
    {
        $escapar = static function ($valor) {
            return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
        };

        $folio = $escapar($vista['folio'] ?? '');
        $fecha = $escapar($vista['fecha'] ?? '');
        $asunto = $escapar($vista['asunto'] ?? 'Programa de Profesionalización');
        $contenidoHtml = $this->formatearContenidoInstitucional(
            (string)($vista['contenido'] ?? '')
        );

        $header = $this->imagenDataUri(
            $this->rootPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR .
                'img' . DIRECTORY_SEPARATOR . 'oficios' . DIRECTORY_SEPARATOR .
                'redmex_encabezado.png'
        );
        $sello = $this->imagenDataUri(
            $this->rootPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR .
                'img' . DIRECTORY_SEPARATOR . 'oficios' . DIRECTORY_SEPARATOR .
                'redmex_sello.png'
        );
        $pie = $this->imagenDataUri(
            $this->rootPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR .
                'img' . DIRECTORY_SEPARATOR . 'oficios' . DIRECTORY_SEPARATOR .
                'redmex_pie.png'
        );

        $encabezadoHtml = $header !== ''
            ? '<img class="encabezado-img" src="' . $header . '" alt="Red Educativa México">'
            : '<div class="encabezado-fallback">RED EDUCATIVA<br>MÉXICO</div><div class="linea-marca"></div>';
        $selloHtml = $sello !== ''
            ? '<img class="sello" src="' . $sello . '" alt="Sello REDMEX">'
            : '';
        $pieHtml = $pie !== ''
            ? '<img class="pie-img" src="' . $pie . '" alt="rededucativamexico.org">'
            : '<div class="pie-fallback">www.rededucativamexico.org &nbsp; | &nbsp; Tel. 800.0440.189</div>';

        return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 1.15cm 1.65cm 1.75cm; }
    * { box-sizing: border-box; }
    body {
        font-family: "DejaVu Sans", Arial, sans-serif;
        font-size: 9.15pt;
        line-height: 1.23;
        color: #111111;
        margin: 0;
    }
    .encabezado-wrap {
        height: 2.35cm;
        margin: -0.25cm -0.35cm 0.12cm;
        overflow: hidden;
    }
    .encabezado-img {
        display: block;
        width: 100%;
        height: auto;
    }
    .encabezado-fallback {
        color: #2b2a66;
        font-size: 19pt;
        line-height: .92;
        font-weight: 800;
        padding-top: .15cm;
    }
    .linea-marca {
        height: 4px;
        margin-top: .25cm;
        background: #29294f;
        border-right: 7cm solid #12a69a;
    }
    .meta {
        width: 100%;
        text-align: right;
        margin: 0 0 .38cm 0;
        font-size: 8.7pt;
        line-height: 1.18;
    }
    .meta strong { font-weight: 700; }
    .contenido { margin: 0; }
    .destinatario {
        font-weight: 700;
        text-transform: uppercase;
        line-height: 1.32;
        margin-bottom: .42cm;
    }
    .parrafo {
        text-align: justify;
        margin: 0 0 .16cm 0;
    }
    .atentamente {
        margin-top: .28cm;
        font-weight: 700;
    }
    .firma-texto {
        margin-top: .10cm;
        line-height: 1.25;
    }
    .sello {
        position: fixed;
        width: 2.55cm;
        right: 3.15cm;
        bottom: 2.35cm;
    }
    .pie-wrap {
        position: fixed;
        left: -1.65cm;
        right: -1.65cm;
        bottom: -1.75cm;
        height: 1.03cm;
        overflow: hidden;
    }
    .pie-img {
        width: 100%;
        height: 1.03cm;
        display: block;
    }
    .pie-fallback {
        height: 1.03cm;
        padding-top: .30cm;
        background: #29294f;
        color: #ffffff;
        text-align: center;
        font-size: 8pt;
    }
</style>
</head>
<body>
    <div class="encabezado-wrap">' . $encabezadoHtml . '</div>

    <div class="meta">
        <div><strong>Asunto:</strong> ' . $asunto . '</div>
        <div><strong>No. de oficio:</strong> ' . $folio . '</div>
        <div>Cuernavaca, Morelos, a ' . $fecha . '</div>
    </div>

    <div class="contenido">' . $contenidoHtml . '</div>
    ' . $selloHtml . '
    <div class="pie-wrap">' . $pieHtml . '</div>
</body>
</html>';
    }

    private function formatearContenidoInstitucional($contenido)
    {
        $contenido = trim(str_replace(["\r\n", "\r"], "\n", (string)$contenido));

        if ($contenido === '') {
            return '';
        }

        $bloques = preg_split('/\n\s*\n/u', $contenido) ?: [];
        $html = '';

        foreach ($bloques as $indice => $bloque) {
            $bloque = trim((string)$bloque);

            if ($bloque === '') {
                continue;
            }

            $seguro = htmlspecialchars($bloque, ENT_QUOTES, 'UTF-8');
            $seguro = nl2br($seguro, false);

            if ($indice === 0) {
                $html .= '<div class="destinatario">' . $seguro . '</div>';
                continue;
            }

            if (preg_match('/^Atentamente\.?$/iu', $bloque)) {
                $html .= '<div class="atentamente">' . $seguro . '</div>';
                continue;
            }

            if (
                strpos($bloque, 'Enlace Institucional') !== false ||
                strpos($bloque, 'Móvil/Atención WhatsApp') !== false
            ) {
                $html .= '<div class="firma-texto">' . $seguro . '</div>';
                continue;
            }

            $html .= '<p class="parrafo">' . $seguro . '</p>';
        }

        return $html;
    }

    private function imagenDataUri($ruta)
    {
        if (!is_file($ruta)) {
            return '';
        }

        $contenido = file_get_contents($ruta);

        if ($contenido === false || $contenido === '') {
            return '';
        }

        $mime = 'image/png';

        if (function_exists('mime_content_type')) {
            $detectado = mime_content_type($ruta);

            if (is_string($detectado) && strpos($detectado, 'image/') === 0) {
                $mime = $detectado;
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contenido);
    }

    private function resolverRutaPdf($rutaRelativa)
    {
        $rutaRelativa = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$rutaRelativa));

        if ($rutaRelativa === '') {
            return null;
        }

        $rutaAbsoluta = $this->rootPath . DIRECTORY_SEPARATOR . ltrim(
            $rutaRelativa,
            DIRECTORY_SEPARATOR
        );
        $rutaReal = realpath($rutaAbsoluta);
        $baseReal = realpath($this->storagePath);

        if ($rutaReal === false || $baseReal === false || !is_file($rutaReal)) {
            return null;
        }

        $prefijo = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($rutaReal, $prefijo) === 0 ? $rutaReal : null;
    }

    private function nombreArchivoSeguro($folio)
    {
        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$folio));
        $nombre = trim((string)$nombre, '_');

        return $nombre !== '' ? $nombre : 'oficio';
    }

    private function formatearFechaHora($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return '';
        }

        try {
            return (new DateTime($fecha))->format('d/m/Y H:i');
        } catch (Exception $error) {
            return '';
        }
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
