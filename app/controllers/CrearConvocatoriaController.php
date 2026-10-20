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

        require_once __DIR__.'/../services/AliadoInternacionalService.php';
        try {
            $db=(new Database())->connect();
            $tabla=$db->query("SHOW TABLES LIKE 'formulario_aliado_convocatorias'");
            if ($tabla->num_rows && tienePermiso('aliados.ver')) {
                $externos=$db->query("SELECT a.id AS aliado_internacional_id,a.seguimiento_id,0 AS estado_id,a.region AS estado_nombre,a.ciudad AS municipio_nombre,a.nombre AS nombre_entidad,a.contacto_nombre,post.convenio_fecha,post.convenio_referencia,post.convenio_notas,post.convenio_formalizado_at,(SELECT COUNT(*) FROM formulario_aliado_convocatorias x WHERE x.aliado_id=a.id)>0 AS tiene_convocatoria FROM formulario_aliados a INNER JOIN seguimientos_vinculacion_post_envio post ON post.seguimiento_id=a.seguimiento_id WHERE a.pais_codigo<>'MX' AND a.activo=1 AND post.convenio_formalizado_at IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
                $convenios=array_merge($convenios,$externos);
            }
        } catch (Throwable $e) { error_log('[convenios_externos] '.$e->getMessage()); }
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
