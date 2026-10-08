<?php

require_once __DIR__ . '/../services/ReporteConvocatoriaDataService.php';
require_once __DIR__ . '/../services/ReporteConvocatoriaPdfService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ConvocatoriaReporteController
{
    public function index()
    {
        $this->validarAcceso(false);

        $service = new ReporteConvocatoriaDataService();
        $reporteConvocatorias = $service->prepararDatos([
            'tipo' => $_GET['tipo'] ?? '',
            'subtipo' => $_GET['subtipo'] ?? ''
        ]);

        $filtrosReporte = is_array($reporteConvocatorias['filtros'] ?? null)
            ? $reporteConvocatorias['filtros']
            : ['tipo' => '', 'subtipo' => ''];

        $queryExportar = http_build_query(array_filter(
            $filtrosReporte,
            static fn($valor) => trim((string)$valor) !== ''
        ));

        $urlExportarPdf = BASE_URL .
            'index.php?controller=convocatoriaReporte&action=exportarPdf' .
            ($queryExportar !== '' ? '&' . $queryExportar : '');

        $tituloPagina = 'Reporte de Convocatorias';
        $subtituloPagina = 'Resumen ejecutivo y detalle del módulo de convocatorias.';
        $opcionActiva = 'convocatorias_reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/convocatorias.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function exportarPdf()
    {
        $this->validarAcceso(true);

        try {
            $service = new ReporteConvocatoriaDataService();
            $datosReporte = $service->prepararDatos([
                'tipo' => $_GET['tipo'] ?? '',
                'subtipo' => $_GET['subtipo'] ?? ''
            ]);

            $datosReporte['fecha_generacion'] = date('d/m/Y H:i');
            $datosReporte['generado_por'] = trim(
                (string)($_SESSION['nombre'] ?? '') . ' ' .
                (string)($_SESSION['apellidos'] ?? '')
            );
            $datosReporte['generado_por_rol'] =
                (string)($_SESSION['rol'] ?? '');

            $pdfService = new ReporteConvocatoriaPdfService();
            $resultado = $pdfService->generar($datosReporte);

            if (!($resultado['ok'] ?? false)) {
                error_log(
                    '[reporte_convocatorias_pdf] ' .
                    (string)(
                        $resultado['mensaje_tecnico'] ??
                        $resultado['mensaje'] ??
                        'Error sin detalle.'
                    )
                );

                $this->responderError(
                    (string)(
                        $resultado['mensaje'] ??
                        'No fue posible generar el reporte de convocatorias.'
                    )
                );
            }

            $contenidoPdf = (string)($resultado['contenido_pdf'] ?? '');
            $nombreArchivo = (string)(
                $resultado['nombre_archivo'] ??
                'Reporte_Convocatorias_Corte_' . date('Y-m-d') . '.pdf'
            );

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: attachment; filename="' .
                $nombreArchivo .
                '"'
            );
            header('Content-Length: ' . strlen($contenidoPdf));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');

            echo $contenidoPdf;
            exit;
        } catch (Throwable $error) {
            error_log(
                '[reporte_convocatorias_pdf] ' . $error->getMessage()
            );

            $this->responderError(
                'No fue posible generar el reporte de convocatorias.'
            );
        }
    }

    private function validarAcceso($requiereExportar = false)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        $puedeConsultar =
            tienePermiso('reportes.ver') &&
            tienePermiso('reportes.convocatorias');

        $puedeExportar =
            !$requiereExportar ||
            tienePermiso('reportes.exportar');

        if (!$puedeConsultar || !$puedeExportar) {
            http_response_code(403);
            die(
                $requiereExportar
                    ? 'No tienes permiso para exportar este reporte.'
                    : 'No tienes permiso para consultar este reporte.'
            );
        }
    }

    private function responderError($mensaje)
    {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$mensaje;
        exit;
    }
}
