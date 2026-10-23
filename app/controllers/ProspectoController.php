<?php
require_once __DIR__ . '/../models/ProspectoModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ProspectoController
{
    public function index()
    {
        $this->validarAcceso();

        $tituloPagina = 'Prospectos';
        $subtituloPagina = 'Consulta los registros recibidos desde el formulario de registro.';
        $opcionActiva = 'prospectos';
        $mensajeError = '';
        $listado = ['registros' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];

        try {
            $modelo = new ProspectoModel();
            $listado = $modelo->listar((int)($_GET['pagina'] ?? 1),25,['buscar'=>substr((string)($_GET['buscar']??''),0,180),'aliado_id'=>(string)($_GET['aliado_id']??'')]);
        } catch (Throwable $error) {
            error_log('[prospectos_listado] ' . $error->getMessage());
            $mensajeError = 'No fue posible consultar los registros. Verifica que la tabla formulario_registros esté disponible.';
        }

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/prospectos/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function validarAcceso()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarLogin');
            exit;
        }
        if (!tienePermiso('prospectos.ver_todos')) {
            http_response_code(403);
            exit('No tienes permiso para consultar prospectos.');
        }
    }
}
