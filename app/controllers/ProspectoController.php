<?php
require_once __DIR__ . '/../models/ProspectoModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ProspectoController
{
    public function index()
    {
        $this->validarAcceso();

        $tituloPagina = 'Prospectos';
        $subtituloPagina = 'Consulta los prospectos por aliado de origen o en el listado general.';
        $opcionActiva = 'prospectos';
        $mensajeError = '';
        $listado = ['registros' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
        $resumenProspectos = ['total'=>null,'aliados'=>null,'generales'=>null];
        $territoriosProspectos = [];
        $estadoProspectos = null;
        $errorTerritorial = '';
        $errorProcedencia = '';
        $estadosConRegistros = null;
        $vistaProspectos = ($_GET['vista']??'') === 'todos' ? 'todos' : 'estados';
        // Los enlaces previos de búsqueda/paginación siguen abriendo el listado.
        if (!isset($_GET['vista']) && !isset($_GET['estado_id']) && (isset($_GET['buscar']) || isset($_GET['aliado_id']) || isset($_GET['pagina']))) $vistaProspectos='todos';
        $buscarProspectos = is_string($_GET['buscar']??null) ? mb_substr(trim($_GET['buscar']),0,180) : '';
        $aliadoFiltroProspectos = is_string($_GET['aliado_id']??null) && (($_GET['aliado_id']==='general') || ctype_digit($_GET['aliado_id'])) ? $_GET['aliado_id'] : '';
        $buscarEstadoProspectos = is_string($_GET['buscar_estado']??null) ? mb_substr(trim($_GET['buscar_estado']),0,80) : '';
        $estadoSolicitado = isset($_GET['estado_id']);
        $estadoId = $estadoSolicitado ? filter_var($_GET['estado_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
        if ($estadoSolicitado) $vistaProspectos='estados';
        $mostrarTablaProspectos = $vistaProspectos==='todos' || $estadoSolicitado;

        try {
            $modelo = new ProspectoModel();
            try {
                $resumenProspectos=$modelo->resumen();
                if (!$resumenProspectos['procedencias_disponibles']) $errorProcedencia='No está disponible la información de procedencia. Los indicadores de aliados y formularios generales no pueden verificarse.';
            } catch (Throwable $error) {
                error_log('[prospectos_resumen] '.$error->getMessage());
                $errorProcedencia='No fue posible consultar los indicadores de Prospectos.';
            }
            try {
                $territoriosProspectos=$modelo->territorios();
                $estadosConRegistros=count(array_filter($territoriosProspectos,static fn($estado)=>(int)$estado['total']>0));
            } catch (Throwable $error) {
                error_log('[prospectos_territorios] '.$error->getMessage());
                $errorTerritorial='No fue posible cargar la información territorial. Intenta nuevamente.';
            }
            if ($estadoSolicitado) {
                foreach ($territoriosProspectos as $territorio) if ((int)$territorio['id']===$estadoId) $estadoProspectos=$territorio;
                if ($errorTerritorial!=='') throw new DomainException('No se pudo verificar el estado seleccionado.');
                if ($estadoId===false || !$estadoProspectos) { http_response_code($estadoId===false?400:404); throw new DomainException('Estado no encontrado. Selecciona un estado activo del catálogo.'); }
            }
            if ($mostrarTablaProspectos) {
                $filtros=['buscar'=>$buscarProspectos,'aliado_id'=>$aliadoFiltroProspectos];
                if ($estadoProspectos) $filtros['estado_id']=(int)$estadoProspectos['id'];
                $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
                $listado=$modelo->listar($pagina,25,$filtros);
            }
        } catch (DomainException $error) {
            $mensajeError=$error->getMessage();
        } catch (Throwable $error) {
            error_log('[prospectos_listado] ' . $error->getMessage());
            $mensajeError = 'No fue posible consultar los registros. Intenta nuevamente.';
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
            require __DIR__.'/../views/prospectos/acceso_denegado.php';
            exit;
        }
    }
}
