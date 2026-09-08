<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/OficioCorreoService.php';
require_once __DIR__ . '/OficioPreviewService.php';
require_once __DIR__ . '/OficioDocxPdfService.php';

class OficioEnvioService
{
    private const LOCK_FOLIO_GLOBAL = 'sistema_comercial_impe_oficio_folio_global';

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

    public function enviarAhora($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $correoService = new OficioCorreoService();
        $seguimiento = $this->obtenerSeguimientoAnalista(
            $seguimientoId,
            $usuarioId
        );

        /*
         * Si falta algún requisito, dejamos que OficioCorreoService devuelva
         * el mensaje de validación habitual sin tocar folio ni PDF.
         */
        if (!$seguimiento || !$this->listoParaAjustarDocumento($seguimiento)) {
            return $correoService->enviarAhora($seguimientoId, $usuarioId);
        }

        if ($this->correoYaEnviado($seguimiento)) {
            return $correoService->enviarAhora($seguimientoId, $usuarioId);
        }

        $ajuste = $this->sincronizarFolioConFechaActual(
            (int)$seguimiento['oficio_id'],
            $seguimientoId
        );

        if (!($ajuste['ok'] ?? false)) {
            return $ajuste;
        }

        $folioFinal = trim((string)($ajuste['folio'] ?? ''));
        $cambioFolio = !empty($ajuste['cambio_folio']);
        $pdfCorresponde = $this->pdfCorrespondeAlFolio(
            (string)($seguimiento['archivo_pdf'] ?? ''),
            $folioFinal
        );
        $pdfRegenerado = false;

        /*
         * El PDF preparado puede conservarse si el envío ocurre el mismo día.
         * Si cambió la fecha incluida en el folio, se genera nuevamente desde
         * el DOCX institucional antes de enviarlo.
         */
        if ($cambioFolio || !$pdfCorresponde) {
            $resultadoPdf = $this->regenerarPdfDefinitivo(
                $seguimientoId,
                $usuarioId,
                (int)$seguimiento['oficio_id'],
                $folioFinal,
                (string)($seguimiento['archivo_pdf'] ?? '')
            );

            if (!($resultadoPdf['ok'] ?? false)) {
                return $resultadoPdf;
            }

            $pdfRegenerado = true;
        }

        $resultado = $correoService->enviarAhora(
            $seguimientoId,
            $usuarioId
        );

        if ($resultado['ok'] ?? false) {
            $resultado['folio_actualizado_al_enviar'] = $cambioFolio;
            $resultado['pdf_regenerado_al_enviar'] = $pdfRegenerado;
            $resultado['folio_envio'] = $folioFinal;
        }

        return $resultado;
    }

    private function listoParaAjustarDocumento($seguimiento)
    {
        $oficioId = (int)($seguimiento['oficio_id'] ?? 0);
        $folio = trim((string)($seguimiento['folio'] ?? ''));
        $asunto = trim((string)($seguimiento['asunto_correo'] ?? ''));
        $cuerpo = trim((string)($seguimiento['cuerpo_correo'] ?? ''));
        $correo = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $archivoPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));
        $rutaPdf = $this->rutaAbsolutaPdf($archivoPdf);

        return $oficioId > 0 &&
            $folio !== '' &&
            $asunto !== '' &&
            $cuerpo !== '' &&
            filter_var($correo, FILTER_VALIDATE_EMAIL) &&
            $rutaPdf !== '' &&
            is_file($rutaPdf);
    }

    private function sincronizarFolioConFechaActual($oficioId, $seguimientoId)
    {
        $oficioId = (int)$oficioId;
        $seguimientoId = (int)$seguimientoId;
        $bloqueo = $this->adquirirBloqueoFolioGlobal();

        if (!$bloqueo) {
            return $this->error(
                'No fue posible validar el folio para el envío. Intenta nuevamente.',
                503
            );
        }

        $this->connection->begin_transaction();

        try {
            $sql = "SELECT id, folio, estado_oficio, fecha_envio
                    FROM oficios_vinculacion
                    WHERE id = ?
                        AND seguimiento_id = ?
                    LIMIT 1
                    FOR UPDATE";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $oficioId, $seguimientoId);
            $stmt->execute();
            $oficio = $stmt->get_result()->fetch_assoc() ?: null;

            if (!$oficio) {
                throw new RuntimeException('No se encontró el oficio que se va a enviar.');
            }

            if (
                strtoupper(trim((string)($oficio['estado_oficio'] ?? ''))) === 'ENVIADO' ||
                trim((string)($oficio['fecha_envio'] ?? '')) !== ''
            ) {
                $this->connection->commit();

                return [
                    'ok' => true,
                    'folio' => (string)($oficio['folio'] ?? ''),
                    'cambio_folio' => false
                ];
            }

            $folioAnterior = trim((string)($oficio['folio'] ?? ''));
            $fechaFolioHoy = date('d-m/y');
            $consecutivo = 0;

            if (
                preg_match(
                    '/^REDMEX\/(\d+)\/(\d{2}-\d{2}\/\d{2})$/',
                    $folioAnterior,
                    $partes
                )
            ) {
                $consecutivo = (int)$partes[1];
            }

            /*
             * Los oficios antiguos con el formato REDMEX-GTO-... se
             * normalizan únicamente si llegan a enviarse con el flujo nuevo.
             */
            if ($consecutivo <= 0) {
                $consecutivo = $this->siguienteConsecutivoFolioGeneral();
            }

            if ($consecutivo <= 0) {
                throw new RuntimeException('No fue posible determinar el consecutivo del oficio.');
            }

            $folioFinal = sprintf(
                'REDMEX/%04d/%s',
                $consecutivo,
                $fechaFolioHoy
            );
            $cambioFolio = $folioFinal !== $folioAnterior;

            if ($cambioFolio) {
                $sqlActualizar = "UPDATE oficios_vinculacion
                        SET folio = ?,
                            error_envio = NULL
                        WHERE id = ?
                            AND seguimiento_id = ?";
                $stmtActualizar = $this->connection->prepare($sqlActualizar);
                $stmtActualizar->bind_param(
                    'sii',
                    $folioFinal,
                    $oficioId,
                    $seguimientoId
                );

                if (!$stmtActualizar->execute()) {
                    throw new RuntimeException('No fue posible actualizar el folio del oficio.');
                }
            }

            $this->connection->commit();

            return [
                'ok' => true,
                'folio' => $folioFinal,
                'folio_anterior' => $folioAnterior,
                'cambio_folio' => $cambioFolio
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Ajuste de folio al enviar: ' . $error->getMessage());

            return $this->error(
                'No fue posible actualizar el folio con la fecha de envío.',
                500
            );
        } finally {
            $this->liberarBloqueoFolioGlobal();
        }
    }

    private function regenerarPdfDefinitivo(
        $seguimientoId,
        $usuarioId,
        $oficioId,
        $folio,
        $archivoAnterior
    ) {
        $servicioVista = new OficioPreviewService();
        $resultadoVista = $servicioVista->obtenerVistaPrevia(
            (int)$seguimientoId,
            (int)$usuarioId,
            'analista'
        );

        if (!($resultadoVista['ok'] ?? false)) {
            return $resultadoVista;
        }

        $vista = is_array($resultadoVista['vista_previa'] ?? null)
            ? $resultadoVista['vista_previa']
            : [];
        $vista['folio'] = $folio;
        $vista['fecha'] = $this->fechaDocumentoDesdeFolio($folio);

        $generadorDocumento = new OficioDocxPdfService();
        $resultadoDocumento = $generadorDocumento->generarPdf($vista);

        if (!($resultadoDocumento['ok'] ?? false)) {
            $detalle = trim((string)($resultadoDocumento['mensaje_tecnico'] ?? ''));

            if ($detalle !== '') {
                error_log('Regeneración DOCX/PDF al enviar: ' . $detalle);
            }

            return $this->error(
                (string)($resultadoDocumento['mensaje'] ??
                    'No fue posible actualizar el PDF con la fecha de envío.'),
                500
            );
        }

        $contenidoPdf = (string)($resultadoDocumento['contenido_pdf'] ?? '');

        if ($contenidoPdf === '') {
            return $this->error('El PDF definitivo del oficio está vacío.', 500);
        }

        $anio = date('Y');
        $directorio = $this->storagePath . DIRECTORY_SEPARATOR . $anio;

        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            return $this->error(
                'No fue posible crear la carpeta para guardar el oficio definitivo.',
                500
            );
        }

        $nombreArchivo = $this->nombreArchivoSeguro($folio) . '.pdf';
        $rutaAbsoluta = $directorio . DIRECTORY_SEPARATOR . $nombreArchivo;
        $rutaRelativa = 'storage/oficios/' . $anio . '/' . $nombreArchivo;

        if (file_put_contents($rutaAbsoluta, $contenidoPdf, LOCK_EX) === false) {
            return $this->error(
                'No fue posible guardar el PDF actualizado del oficio.',
                500
            );
        }

        $sqlActualizar = "UPDATE oficios_vinculacion
                SET archivo_pdf = ?,
                    estado_oficio = 'GENERADO',
                    fecha_generacion = NOW(),
                    error_envio = NULL
                WHERE id = ?
                    AND seguimiento_id = ?";
        $stmtActualizar = $this->connection->prepare($sqlActualizar);
        $oficioId = (int)$oficioId;
        $seguimientoId = (int)$seguimientoId;
        $stmtActualizar->bind_param(
            'sii',
            $rutaRelativa,
            $oficioId,
            $seguimientoId
        );

        if (!$stmtActualizar->execute()) {
            @unlink($rutaAbsoluta);

            return $this->error(
                'El PDF se actualizó, pero no fue posible registrarlo en el expediente.',
                500
            );
        }

        $rutaAnterior = $this->rutaAbsolutaPdf($archivoAnterior);

        if (
            $rutaAnterior !== '' &&
            $rutaAnterior !== $rutaAbsoluta &&
            is_file($rutaAnterior) &&
            $this->rutaDentroDeStorageOficios($rutaAnterior)
        ) {
            @unlink($rutaAnterior);
        }

        return [
            'ok' => true,
            'folio' => $folio,
            'archivo_pdf' => $rutaRelativa,
            'conversor' => (string)($resultadoDocumento['conversor'] ?? '')
        ];
    }

    private function obtenerSeguimientoAnalista($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.analista_id,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    oficio.destinatario_correo,
                    oficio.asunto_correo,
                    oficio.cuerpo_correo,
                    oficio.archivo_pdf,
                    oficio.estado_oficio,
                    oficio.fecha_envio
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT oficio_reciente.id
                        FROM oficios_vinculacion oficio_reciente
                        WHERE oficio_reciente.seguimiento_id = seguimientos.id
                        ORDER BY oficio_reciente.id DESC
                        LIMIT 1
                    )
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function siguienteConsecutivoFolioGeneral()
    {
        $sql = "SELECT COALESCE(MAX(
                    CAST(
                        SUBSTRING_INDEX(
                            SUBSTRING_INDEX(folio, '/', 2),
                            '/',
                            -1
                        ) AS UNSIGNED
                    )
                ), 0) AS ultimo
                FROM oficios_vinculacion
                WHERE folio REGEXP '^REDMEX/[0-9]+/[0-9]{2}-[0-9]{2}/[0-9]{2}$'";
        $resultado = $this->connection->query($sql);
        $fila = $resultado ? ($resultado->fetch_assoc() ?: []) : [];

        return (int)($fila['ultimo'] ?? 0) + 1;
    }

    private function adquirirBloqueoFolioGlobal()
    {
        $nombre = self::LOCK_FOLIO_GLOBAL;
        $sql = "SELECT GET_LOCK(?, 5) AS adquirido";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: [];

        return (int)($fila['adquirido'] ?? 0) === 1;
    }

    private function liberarBloqueoFolioGlobal()
    {
        $nombre = self::LOCK_FOLIO_GLOBAL;
        $sql = "SELECT RELEASE_LOCK(?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
    }

    private function fechaDocumentoDesdeFolio($folio)
    {
        if (
            preg_match(
                '/^REDMEX\/\d+\/(\d{2})-(\d{2})\/(\d{2})$/',
                trim((string)$folio),
                $partes
            )
        ) {
            $dia = (int)$partes[1];
            $mes = (int)$partes[2];
            $anio = 2000 + (int)$partes[3];
            $meses = [
                1 => 'enero', 2 => 'febrero', 3 => 'marzo',
                4 => 'abril', 5 => 'mayo', 6 => 'junio',
                7 => 'julio', 8 => 'agosto', 9 => 'septiembre',
                10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
            ];

            if (checkdate($mes, $dia, $anio)) {
                return sprintf(
                    '%d de %s de %d',
                    $dia,
                    $meses[$mes] ?? '',
                    $anio
                );
            }
        }

        return date('j') . ' de ' . $this->nombreMes((int)date('n')) . ' de ' . date('Y');
    }

    private function nombreMes($mes)
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo',
            4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre',
            10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];

        return $meses[(int)$mes] ?? '';
    }

    private function pdfCorrespondeAlFolio($archivoPdf, $folio)
    {
        $archivoPdf = trim((string)$archivoPdf);
        $folio = trim((string)$folio);

        if ($archivoPdf === '' || $folio === '') {
            return false;
        }

        return basename(str_replace('\\', '/', $archivoPdf)) ===
            $this->nombreArchivoSeguro($folio) . '.pdf';
    }

    private function nombreArchivoSeguro($folio)
    {
        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$folio));
        $nombre = trim((string)$nombre, '_');

        return $nombre !== '' ? $nombre : 'oficio';
    }

    private function rutaAbsolutaPdf($rutaPdf)
    {
        $rutaPdf = trim((string)$rutaPdf);

        if ($rutaPdf === '') {
            return '';
        }

        return $this->rootPath . DIRECTORY_SEPARATOR .
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rutaPdf, '/\\'));
    }

    private function rutaDentroDeStorageOficios($ruta)
    {
        $base = realpath($this->storagePath);
        $real = realpath($ruta);

        if ($base === false || $real === false) {
            return false;
        }

        $prefijo = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($real, $prefijo) === 0;
    }

    private function correoYaEnviado($seguimiento)
    {
        return strtoupper(trim((string)($seguimiento['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            trim((string)($seguimiento['fecha_envio'] ?? '')) !== '';
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
