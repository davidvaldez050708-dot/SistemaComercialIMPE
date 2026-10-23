<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/CorreoMarketingService.php';
require_once __DIR__ . '/../services/CorreoConvocatoriaComposicion.php';

class CorreoMarketingController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $service = new CorreoMarketingService();

        $tituloPagina = 'Correos';
        $subtituloPagina = 'Consulta y organiza la comunicación del área de Marketing.';
        $opcionActiva = 'correos_marketing';

        $resumenCorreos = $service->resumen($usuarioId);
        $correos = $service->listar($usuarioId);

        $convocatoriasMarketing = [];
        // El selector aislado consulta el catálogo una vez al abrir el modal.

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/correos/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function ver()
    {
        $this->validarAccesoMarketing();

        $correoId = (int)($_GET['id'] ?? 0);
        $service = new CorreoMarketingService();
        $correo = $service->obtener(
            (int)($_SESSION['usuario_id'] ?? 0),
            $correoId
        );

        if (!$correo) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible encontrar este correo.'
            ], 404);
        }

        $correo['adjuntos'] = array_map(
            static function ($adjunto) {
                if (
                    !is_array($adjunto) ||
                    empty($adjunto['id']) ||
                    empty($adjunto['disponible'])
                ) {
                    if (is_array($adjunto)) {
                        $adjunto['url_inline'] = '';
                        $adjunto['url_descarga'] = '';
                    }

                    return $adjunto;
                }

                $adjuntoId = (int)$adjunto['id'];
                $adjunto['url_inline'] =
                    BASE_URL .
                    'index.php?controller=correoMarketing&action=archivo&id=' .
                    $adjuntoId .
                    '&modo=inline';
                $adjunto['url_descarga'] =
                    BASE_URL .
                    'index.php?controller=correoMarketing&action=archivo&id=' .
                    $adjuntoId .
                    '&modo=descarga';

                return $adjunto;
            },
            is_array($correo['adjuntos'] ?? null)
                ? $correo['adjuntos']
                : []
        );

        try {
            $composicion = new CorreoConvocatoriaComposicion();
            $correo['convocatoria_seleccion'] = $composicion->publico(
                $composicion->leer((int)$_SESSION['usuario_id'], $correoId), $correoId
            );
        } catch (Throwable $error) {
            error_log('MAIL_CONVOCATORIA lectura_metadata exception=' . get_class($error));
            $correo['convocatoria_seleccion'] = null;
        }
        $this->responder(['ok' => true, 'correo' => $correo]);
    }

    public function convocatorias()
    {
        $this->validarAccesoMarketing();
        try {
            $composicion = new CorreoConvocatoriaComposicion();
            $id = CorreoConvocatoriaComposicion::id($_GET['id'] ?? '');
            $datos = $id ? ['convocatoria' => $composicion->publico($composicion->detalle($id))] : $composicion->catalogo();
            $this->responder(['ok' => true] + $datos);
        } catch (Throwable $error) {
            error_log('MAIL_CONVOCATORIA consulta exception=' . get_class($error));
            $this->responder(['ok' => false, 'mensaje' => 'No fue posible consultar la convocatoria. Puedes redactar sin seleccionarla.'], 422);
        }
    }

    public function imagenConvocatoria()
    {
        $this->validarAccesoMarketing();
        try {
            $composicion = new CorreoConvocatoriaComposicion();
            $id = CorreoConvocatoriaComposicion::id($_GET['id'] ?? '');
            $mail = CorreoConvocatoriaComposicion::id($_GET['correo_id'] ?? '');
            if ($mail) {
                if (!(new CorreoMarketingService())->obtener((int)$_SESSION['usuario_id'], $mail)) { throw new RuntimeException('CONVOCATORIA_ACCESO'); }
                $row = $composicion->leer((int)$_SESSION['usuario_id'], $mail);
                if ((int)($row['id'] ?? 0) !== $id) { throw new RuntimeException('CONVOCATORIA_ACCESO'); }
            } else { $row = $composicion->detalle($id); }
            $image = $composicion->imagen($row);
            if (!$image || !is_string($_GET['v'] ?? null) || !hash_equals($image['sha'], $_GET['v'])) { throw new RuntimeException('CONVOCATORIA_IMAGEN'); }
            header('Content-Type: ' . $image['mime']);
            header('Content-Length: ' . $image['tamano']);
            header('Content-Disposition: inline; filename="convocatoria-' . $id . '.' . pathinfo($image['nombre'], PATHINFO_EXTENSION) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=300');
            @readfile($image['ruta']);
        } catch (Throwable $error) { http_response_code(404); echo 'Imagen no disponible.'; }
        exit;
    }

    public function archivo()
    {
        $this->validarAccesoMarketing();

        $adjuntoId = (int)($_GET['id'] ?? 0);
        $modo = strtolower(trim((string)($_GET['modo'] ?? 'descarga')));
        $service = new CorreoMarketingService();
        $adjunto = $service->obtenerAdjunto(
            (int)($_SESSION['usuario_id'] ?? 0),
            $adjuntoId
        );

        if (!$adjunto) {
            http_response_code(404);
            echo 'Archivo no disponible.';
            exit;
        }

        $ruta = (string)$adjunto['ruta'];
        $nombre = basename((string)$adjunto['nombre']);
        $mime = trim((string)$adjunto['mime']) !== ''
            ? (string)$adjunto['mime']
            : 'application/octet-stream';
        $tamano = is_file($ruta)
            ? (int)filesize($ruta)
            : (int)($adjunto['tamano'] ?? 0);

        $permitirInline =
            strpos(strtolower($mime), 'image/') === 0 ||
            strtolower($mime) === 'application/pdf';

        $disposition = (
            $modo === 'inline' &&
            $permitirInline
        ) ? 'inline' : 'attachment';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $tamano);
        header('X-Content-Type-Options: nosniff');
        header(
            'Content-Disposition: ' .
            $disposition .
            '; filename="' .
            str_replace('"', '', $nombre) .
            '"; filename*=UTF-8\'\'' .
            rawurlencode($nombre)
        );

        readfile($ruta);
        exit;
    }

    public function recuperarAdjunto()
    {
        $this->validarAccesoMarketing();

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $correoId = (int)($_POST['correo_id'] ?? 0);
        $nombreEsperado = trim((string)($_POST['nombre_esperado'] ?? ''));
        $archivo = $_FILES['archivo'] ?? null;

        $service = new CorreoMarketingService();
        $resultado = $service->recuperarAdjuntoLegacy(
            (int)($_SESSION['usuario_id'] ?? 0),
            $correoId,
            $nombreEsperado,
            $archivo
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    public function guardarBorrador()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $service = new CorreoMarketingService();
        [$composicion, $seleccion] = $this->seleccionConvocatoria($service);
        $resultado = $service->guardarBorrador(
            (int)($_SESSION['usuario_id'] ?? 0),
            $_POST,
            $_FILES['adjuntos'] ?? null
        );
        $this->registrarConvocatoria($resultado, $composicion, $seleccion);

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    public function enviar()
    {
        $this->validarAccesoMarketing();

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $service = new CorreoMarketingService();
        [$composicion, $seleccion] = $this->seleccionConvocatoria($service);
        if ($seleccion) { $service = new CorreoMarketingService(null, $composicion->envio($seleccion)); }
        $resultado = $service->enviar(
            (int)($_SESSION['usuario_id'] ?? 0),
            $_POST,
            $_FILES['adjuntos'] ?? null
        );
        $this->registrarConvocatoria($resultado, $composicion, $seleccion);

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function seleccionConvocatoria($service): array
    {
        try {
            $composicion = new CorreoConvocatoriaComposicion();
            $previous = [];
            $mail = CorreoConvocatoriaComposicion::id($_POST['correo_id'] ?? '');
            if ($mail && !empty($_POST['convocatoria_id']) && $service->obtener((int)$_SESSION['usuario_id'], $mail)) {
                $previous = $composicion->leer((int)$_SESSION['usuario_id'], $mail);
            }
            return [$composicion, $composicion->seleccionar($_POST, $previous)];
        } catch (Throwable $error) {
            error_log('MAIL_CONVOCATORIA seleccion exception=' . get_class($error));
            $this->responder(['ok' => false, 'mensaje' => 'La convocatoria o su imagen cambió. Revisa la selección; también puedes limpiarla y enviar sin convocatoria.'], 422);
        }
    }

    private function registrarConvocatoria(array &$resultado, $composicion, array $seleccion): void
    {
        if (empty($resultado['ok']) || empty($resultado['correo_id'])) { return; }
        if (!$seleccion && empty($_POST['correo_id'])) { return; }
        // Un fallo de metadatos nunca transforma un envío aceptado en un error para reintentarlo.
        try { $composicion->registrar((int)$_SESSION['usuario_id'], (int)$resultado['correo_id'], $seleccion, ($resultado['estado_entrega'] ?? '') === 'ACEPTADO'); }
        catch (Throwable $error) {
            error_log('MAIL_CONVOCATORIA guardar_metadata exception=' . get_class($error));
            $resultado['mensaje'] .= ' No se pudo guardar la referencia de la convocatoria.';
        }
    }

    private function validarAccesoMarketing()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        $rol = trim((string)($_SESSION['rol'] ?? ''));

        if (strcasecmp($rol, 'Marketing') !== 0) {
            http_response_code(403);
            die('No tienes permiso para acceder al módulo de Correos.');
        }
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
