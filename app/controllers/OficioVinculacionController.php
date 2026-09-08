<?php

require_once __DIR__ . '/../models/OficioVinculacionModel.php';
require_once __DIR__ . '/../services/OficioPreviewService.php';
require_once __DIR__ . '/../services/OficioPdfService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class OficioVinculacionController
{
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
        $resultado = $modelo->generarBorrador($seguimientoId, $usuarioId);

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $this->responderJson($resultado);
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
