<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/CrearConvocatoriaModel.php';

class CrearConvocatoriaController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $modelo = new CrearConvocatoriaModel();
        $estructuraDisponible = $modelo->estructuraDisponible();
        $convenios = $estructuraDisponible
            ? $modelo->listarConveniosRecibidos()
            : [];

        $estados = $modelo->listarEstados();

        $tituloPagina = 'Crear convocatoria';
        $subtituloPagina =
            'Convierte los convenios recibidos en nuevas convocatorias por territorio.';
        $opcionActiva = 'crear_convocatoria';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/convocatorias/crear_desde_convenio.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function marcarAtendido()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                [
                    'ok' => false,
                    'mensaje' => 'Método no permitido.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);

        if ($seguimientoId <= 0) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                [
                    'ok' => false,
                    'mensaje' => 'El convenio seleccionado no es válido.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        $modelo = new CrearConvocatoriaModel();
        $guardado = $modelo->marcarConvenioAtendido(
            $seguimientoId,
            (int)$_SESSION['usuario_id']
        );

        header('Content-Type: application/json; charset=utf-8');

        if (!$guardado) {
            http_response_code(500);
            echo json_encode(
                [
                    'ok' => false,
                    'mensaje' => 'No fue posible actualizar el estado del convenio.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        echo json_encode(
            [
                'ok' => true,
                'estado' => 'con_convocatoria',
                'etiqueta' => 'Con convocatoria'
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
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

        if (
            strcasecmp($rol, 'Marketing') !== 0 ||
            !tienePermiso('convocatorias.ver')
        ) {
            http_response_code(403);
            die('No tienes permiso para acceder a Crear convocatoria.');
        }
    }
}
