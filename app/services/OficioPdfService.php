<?php

require_once __DIR__ . '/../models/OficioVinculacionModel.php';
require_once __DIR__ . '/OficioPreviewService.php';
require_once __DIR__ . '/OficioDocxPdfService.php';

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
        $this->storagePath = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'oficios';
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

        $servicioVistaPrevia = new OficioPreviewService();
        $resultadoVista = $servicioVistaPrevia->obtenerVistaPrevia(
            $seguimientoId,
            $usuarioId,
            'analista'
        );

        if (!($resultadoVista['ok'] ?? false)) {
            return $resultadoVista;
        }

        $vista = is_array($resultadoVista['vista_previa'] ?? null)
            ? $resultadoVista['vista_previa']
            : [];
        $generadorDocumento = new OficioDocxPdfService();
        $resultadoDocumento = $generadorDocumento->generarPdf($vista);

        if (!($resultadoDocumento['ok'] ?? false)) {
            $detalle = trim((string)($resultadoDocumento['mensaje_tecnico'] ?? ''));

            if ($detalle !== '') {
                error_log('Error DOCX/PDF de oficio: ' . $detalle);
            }

            return $this->error(
                (string)($resultadoDocumento['mensaje'] ??
                    'No fue posible generar el PDF desde la plantilla institucional.'),
                500
            );
        }

        $contenidoPdf = (string)($resultadoDocumento['contenido_pdf'] ?? '');

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
            'mensaje' => 'PDF generado correctamente desde la plantilla institucional.',
            'folio' => $folio,
            'conversor' => (string)($resultadoDocumento['conversor'] ?? ''),
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

    private function resolverRutaPdf($rutaRelativa)
    {
        $rutaRelativa = trim(str_replace(
            ['\\', '/'],
            DIRECTORY_SEPARATOR,
            (string)$rutaRelativa
        ));

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
