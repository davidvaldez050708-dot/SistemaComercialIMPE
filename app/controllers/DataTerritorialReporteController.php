<?php

require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../services/ReporteTerritorialService.php';
require_once __DIR__ . '/../services/ReporteTerritorialPdfService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class DataTerritorialReporteController
{
    public function index()
    {
        $this->validarPermiso();

        $modelo = new DataTerritorialModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $territorios = $modelo->obtenerTerritoriosUsuario($usuarioId, $rolId);
        $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
        $generarReporte = (string)($_GET['generar'] ?? '') === '1' && $estadoId > 0;
        $reporte = null;
        $errorReporte = '';

        if ($estadoId > 0) {
            $this->validarAccesoEstado($modelo, $usuarioId, $rolId, $estadoId);

            $resultado = (new ReporteTerritorialService())->construir($estadoId);

            if (($resultado['ok'] ?? false) === true) {
                $reporte = $resultado;
            } else {
                $errorReporte = (string)($resultado['mensaje'] ?? 'No fue posible preparar el reporte territorial.');
            }
        }

        $errorExportacionPdf = (string)($_SESSION['error_reporte_territorial'] ?? '');
        unset($_SESSION['error_reporte_territorial']);

        $urlExportarPdf = '';
        if ($reporte && $generarReporte) {
            $urlExportarPdf = BASE_URL . 'index.php?' . http_build_query([
                'controller' => 'dataTerritorialReporte',
                'action' => 'exportarPdf',
                'estado_id' => $estadoId
            ], '', '&', PHP_QUERY_RFC3986);
        }

        $tituloPagina = 'Reportes';
        $subtituloPagina = 'Información territorial';
        $opcionActiva = 'reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/data_territorial.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function exportarPdf()
    {
        $this->validarPermiso();

        $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $modelo = new DataTerritorialModel();

        if ($estadoId <= 0) {
            http_response_code(422);
            die('El territorio seleccionado no es válido.');
        }

        $this->validarAccesoEstado($modelo, $usuarioId, $rolId, $estadoId);

        $reporte = (new ReporteTerritorialService())->construir($estadoId);
        if (($reporte['ok'] ?? false) !== true) {
            http_response_code(500);
            die((string)($reporte['mensaje'] ?? 'No fue posible preparar el reporte territorial.'));
        }

        $resultadoPdf = (new ReporteTerritorialPdfService())->generar($reporte);
        if (($resultadoPdf['ok'] ?? false) !== true) {
            error_log('[reporte_territorial_pdf] ' . (string)($resultadoPdf['mensaje_tecnico'] ?? $resultadoPdf['mensaje'] ?? 'Error sin detalle.'));
            $_SESSION['error_reporte_territorial'] = (string)($resultadoPdf['mensaje'] ?? 'No fue posible generar el PDF.');
            header(
                'Location: ' . BASE_URL . 'index.php?' . http_build_query([
                    'controller' => 'dataTerritorialReporte',
                    'action' => 'index',
                    'estado_id' => $estadoId,
                    'generar' => 1
                ], '', '&', PHP_QUERY_RFC3986)
            );
            exit;
        }

        $contenido = (string)($resultadoPdf['contenido_pdf'] ?? '');
        $nombreArchivo = (string)($resultadoPdf['nombre_archivo'] ?? 'Reporte_Informacion_Territorial.pdf');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($contenido));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $contenido;
        exit;
    }

    private function validarPermiso(): void
    {
        if (!tienePermiso('data_territorial.ver')) {
            http_response_code(403);
            die('No tienes permiso para consultar reportes de información territorial.');
        }
    }

    private function validarAccesoEstado(
        DataTerritorialModel $modelo,
        int $usuarioId,
        int $rolId,
        int $estadoId
    ): void {
        if ($estadoId <= 0 || !$modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
            http_response_code(403);
            die('No tienes acceso a este territorio.');
        }
    }
}
