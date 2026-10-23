<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/CrearConvocatoriaModel.php';
require_once __DIR__ . '/../models/TerritorioModel.php';

class CrearConvocatoriaController
{
    public function index()
    {
        $this->validarAccesoConvenios();

        $modelo = new CrearConvocatoriaModel();
        $estructuraDisponible = $modelo->estructuraDisponible();
        // Marketing/Admin: cobertura nacional; Cuenta Clave: sólo asignaciones
        // vigentes. Un permiso de lectura no amplía el alcance territorial.
        $esMarketing = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $estados = $modelo->listarEstados();
        $estadoIds = null;
        if (!$esMarketing && !$esAdministrador) {
            $tipo = (int)($_SESSION['rol_id'] ?? 0) === 6 ? 'CUENTA_CLAVE'
                : ((int)($_SESSION['rol_id'] ?? 0) === 4 ? 'ANALISTA_DATOS'
                : ((int)($_SESSION['rol_id'] ?? 0) === 3 ? 'ASESOR' : null));
            $estados = $tipo === null ? [] : (new TerritorioModel())->obtenerEstadosAsignadosUsuario(
                (int)($_SESSION['usuario_id'] ?? 0), $tipo
            );
            $estadoIds = array_map('intval', array_column($estados, 'id'));
        }
        $convenios = $estructuraDisponible
            ? $modelo->listarConveniosRecibidos($estadoIds)
            : [];
        $puedeCrearDesdeConvenio = puedeCrearConvocatoriaDesdeConvenio();

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

    private function validarAccesoConvenios()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (!puedeConsultarDirectorioConvenios()) {
            http_response_code(403);
            die('No tienes permiso para acceder a Convenios.');
        }
    }
}
