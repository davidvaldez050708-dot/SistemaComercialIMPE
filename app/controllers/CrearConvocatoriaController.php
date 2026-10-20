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

        // Aliados externos: no son convenios mexicanos formalizados.
        // Se ofrecen sus formularios autorizados sin inventar estado ni seguimiento.
        $aliadosExternos = [];
        try {
            $db = (new Database())->connect();
            $result = $db->query(
                "SELECT a.id, a.nombre, a.ubicacion, a.slug, a.token,
                        GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' / ') AS programas
                 FROM formulario_aliados a
                 JOIN formulario_aliado_programas ap ON ap.aliado_id=a.id
                 JOIN formulario_programas p ON p.codigo=ap.programa_codigo AND p.activo=1
                 WHERE a.activo=1 AND a.seguimiento_id IS NULL
                 GROUP BY a.id, a.nombre, a.ubicacion, a.slug, a.token
                 ORDER BY a.nombre"
            );
            if ($result) { $aliadosExternos = $result->fetch_all(MYSQLI_ASSOC); }
        } catch (Throwable $e) {
            error_log('[crear_convocatoria_aliados_externos] ' . $e->getMessage());
        }

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
