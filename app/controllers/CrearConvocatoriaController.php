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

        $estados = [];
        foreach ($convenios as $convenio) {
            $estadoId = (int)($convenio['estado_id'] ?? 0);
            if ($estadoId <= 0) {
                continue;
            }

            $estados[$estadoId] = [
                'id' => $estadoId,
                'nombre' => (string)($convenio['estado_nombre'] ?? '')
            ];
        }

        uasort(
            $estados,
            static function ($a, $b) {
                return strcasecmp(
                    (string)($a['nombre'] ?? ''),
                    (string)($b['nombre'] ?? '')
                );
            }
        );

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
