<?php

require_once __DIR__ . '/../services/ReporteAdministradorDataService.php';
require_once __DIR__ . '/../services/ReporteAdministradorPdfService.php';

class ReporteAdministradorController
{
    public function exportarPdf()
    {
        $this->validarAdministrador();

        try {
            $datosService = new ReporteAdministradorDataService();

            try {
                $rolesSeleccionados = $datosService->resolverRolesSeleccionados($_GET);
            } catch (InvalidArgumentException $error) {
                $this->responderSolicitudInvalida($error->getMessage());
            }

            $datosReporte = $datosService->prepararDatos($rolesSeleccionados);
            $datosReporte['fecha_generacion'] = date('d/m/Y H:i');
            $datosReporte['generado_por'] = trim(
                (string)($_SESSION['nombre'] ?? '') . ' ' .
                (string)($_SESSION['apellidos'] ?? '')
            );
            $datosReporte['generado_por_rol'] = (string)($_SESSION['rol'] ?? '');

            $servicio = new ReporteAdministradorPdfService();
            $resultado = $servicio->generar($datosReporte);

            if (!($resultado['ok'] ?? false)) {
                error_log(
                    '[reporte_administrador_pdf] ' .
                    (string)($resultado['mensaje_tecnico'] ?? $resultado['mensaje'] ?? 'Error sin detalle.')
                );
                $this->responderError(
                    (string)($resultado['mensaje'] ?? 'No fue posible generar el reporte administrativo.')
                );
            }

            $contenidoPdf = (string)($resultado['contenido_pdf'] ?? '');
            $nombreArchivo = (string)($resultado['nombre_archivo'] ?? 'Reporte_Administrativo_Usuarios.pdf');

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            header('Content-Length: ' . strlen($contenidoPdf));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo $contenidoPdf;
            exit;
        } catch (Throwable $error) {
            error_log('[reporte_administrador_pdf] ' . $error->getMessage());
            $this->responderError('No fue posible generar el reporte administrativo.');
        }
    }

    private function responderSolicitudInvalida($mensaje)
    {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$mensaje;
        exit;
    }

    private function validarAdministrador()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarLogin');
            exit;
        }

        if ((int)($_SESSION['rol_id'] ?? 0) !== 1) {
            http_response_code(403);
            die('No tienes permiso para generar este reporte.');
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
