<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/CorreoMarketingService.php';
require_once __DIR__ . '/../models/ConvocatoriaModel.php';

class CorreoMarketingController
{
    public function remitentes()
    {
        $this->validarAccesoMarketing();
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { $this->responder(['ok'=>false,'mensaje'=>'Método no permitido.'],405); }
        $from=$_GET['remitente']??'';
        if(!is_string($from) || strlen($from)>255) { $this->responder(['ok'=>false,'mensaje'=>'Dirección de contacto inválida.'],422); }
        $r=(new CorreoSalidaInstitucionalService())->remitentes((int)$_SESSION['usuario_id'],trim($from));
        $code=(int)($r['codigo_http']??200);unset($r['codigo_http']);$this->responder($r,$code);
    }

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
        try {
            $convocatoriaModel = new ConvocatoriaModel();
            $convocatoriasMarketing = $convocatoriaModel->obtenerListado(
                '',
                0,
                '1'
            );
        } catch (Throwable $error) {
            error_log(
                'Correos Marketing - listado de convocatorias: ' .
                $error->getMessage()
            );
        }

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

        $this->responder([
            'ok' => true,
            'correo' => $correo
        ]);
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
        $resultado = $service->guardarBorrador(
            (int)($_SESSION['usuario_id'] ?? 0),
            $_POST,
            $_FILES['adjuntos'] ?? null
        );

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
        $resultado = $service->enviar(
            (int)($_SESSION['usuario_id'] ?? 0),
            $_POST,
            $_FILES['adjuntos'] ?? null
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
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
