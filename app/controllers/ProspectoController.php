<?php
require_once __DIR__ . '/../models/ProspectoModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/ProspectoCentralRepository.php';
require_once __DIR__ . '/../services/ProspectoMetaImportador.php';

class ProspectoController
{
    public function index()
    {
        $this->validarAcceso();
        $seccionProspectos=$_GET['seccion']??null;
        if ($seccionProspectos===null) {
            $legacy=array_intersect(['vista','estado_id','buscar_estado','aliado_id','buscar','programa','pagina'],array_keys($_GET));
            $seccionProspectos=$legacy?'aliados':'todos';
        }
        if (!is_string($seccionProspectos)||!in_array($seccionProspectos,['todos','aliados','meta','asignaciones'],true)) {http_response_code(400);exit('Sección de prospectos no válida.');}
        if ($seccionProspectos==='aliados') {$this->aliados();return;}
        $tituloPagina='Prospectos';$subtituloPagina='Almacén central y procedencia de los registros.';$opcionActiva='prospectos';
        $mensajeError='';$centralDisponible=false;$indicadores=$this->indicadores();
        $metaPuedeImportar=$seccionProspectos==='meta' && ProspectoMetaImportador::puedeImportar();
        $metaPuedeConfigurar=$seccionProspectos==='meta'&&ProspectoMetaFormularioService::autorizado();
        $metaCsrf=$metaPuedeImportar||$metaPuedeConfigurar?ProspectoMetaImportador::csrf():'';
        $formulariosConfiguracion=[];$formulariosHistorial=[];$formulariosDisponible=false;
        $formularioAviso=(string)($_SESSION['prospectos_formulario_aviso']??'');unset($_SESSION['prospectos_formulario_aviso']);
        $metaResumen=$metaPuedeImportar?ProspectoMetaImportador::resumenCargar():null;
        $metaResultado=$metaPuedeImportar?ProspectoMetaImportador::resultado():null;
        $metaAviso=$metaPuedeImportar?(string)($_SESSION['prospectos_meta_error']??''):'';
        unset($_SESSION['prospectos_meta_error']);
        $metaProgramas=[];
        $listado=['registros'=>[],'total'=>0,'pagina'=>1,'paginas'=>1,'limite'=>25,'programas'=>[]];
        try {
            $db=(new Database())->connect(false);$repo=new ProspectoCentralRepository($db);
            $centralDisponible=$repo->estructura()['disponible'];
            $resumen=(new ProspectoModel($db))->resumen();$indicadores=$this->indicadores($resumen,$repo);
            if ($centralDisponible) {
                if ($seccionProspectos==='asignaciones') $listado=$repo->asignaciones((int)($_GET['pagina']??1));
                else {$f=$_GET;if ($seccionProspectos==='meta') $f['origen']='META_ADS';$listado=$repo->listar($f);}
                if ($metaPuedeImportar||$metaPuedeConfigurar) $metaProgramas=ProspectoMetaImportador::programas($db);
                if ($metaPuedeImportar) $metaResumen=ProspectoMetaImportador::resumenCargar(null,$db);
                if ($metaPuedeConfigurar) {
                    $svc=new ProspectoMetaFormularioService($db);$formulariosDisponible=$svc->disponible();
                    $formulariosConfiguracion=$svc->detectados($metaResumen['formularios']??[]);$formulariosHistorial=$svc->historial();
                }
            }
        } catch (DomainException $e) {http_response_code(400);$mensajeError='Los filtros seleccionados no son válidos.';}
        catch (Throwable $e) {error_log('[prospectos_almacen] '.get_class($e).' codigo='.(int)$e->getCode());$mensajeError='No fue posible consultar los prospectos. Intenta nuevamente.';}
        require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';require __DIR__.'/../views/layout/topbar.php';
        require __DIR__.'/../views/prospectos/central.php';require __DIR__.'/../views/layout/dashboard_footer.php';
    }


    /** Leer exclusivamente los formularios de un archivo, sin importar sus leads. */
    public function metaFormularioDetectar()
    {
        $this->validarAcceso();
        if (!ProspectoMetaFormularioService::autorizado()){http_response_code(403);exit('Sin permiso para configurar formularios.');}
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('Método no permitido.');}
        try {
            ProspectoMetaImportador::verificarCsrf($_POST['csrf_meta']??null);
            ProspectoMetaFormularioService::detectar($_FILES['archivo_formularios']??[]);
            $_SESSION['prospectos_formulario_aviso']='Formularios detectados. Selecciona y guarda explícitamente sus programas; no se importaron prospectos.';
        }catch(DomainException $e){$_SESSION['prospectos_formulario_aviso']=$e->getMessage();}
        catch(Throwable $e){error_log('[prospectos_formulario_detectar] '.get_class($e).' codigo='.(int)$e->getCode());$_SESSION['prospectos_formulario_aviso']='No se pudo revisar el archivo.';}
        header('Location: '.BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta&configurar_formularios=1',true,303);exit;
    }

    /** Guardar una nueva versión de la asociación explícitamente autorizada. */
    public function metaFormularioGuardar()
    {
        $this->validarAcceso();
        if (!ProspectoMetaFormularioService::autorizado()){http_response_code(403);exit('Sin permiso para configurar formularios.');}
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit('Método no permitido.');}
        try {
            ProspectoMetaImportador::verificarCsrf($_POST['csrf_meta']??null);
            $id=$_POST['formulario_id']??null;$code=$_POST['programa_codigo']??null;
            $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if (!is_string($id)||!is_string($code)||$version===false||$version===null) throw new DomainException('Datos de configuración no válidos.');
            $db=(new Database())->connect(false);$preview=ProspectoMetaImportador::carga();
            (new ProspectoMetaFormularioService($db))->guardar($id,$code,$version,$preview['formularios']??[]);
            $_SESSION['prospectos_formulario_aviso']='Asociación guardada. Se utilizará en las próximas importaciones; los prospectos existentes conservan su programa.';
        }catch(DomainException $e){$_SESSION['prospectos_formulario_aviso']=$e->getMessage();}
        catch(Throwable $e){error_log('[prospectos_formulario] '.get_class($e).' codigo='.(int)$e->getCode());$_SESSION['prospectos_formulario_aviso']='No se pudo guardar la asociación. Intenta nuevamente.';}
        header('Location: '.BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta&configurar_formularios=1',true,303);exit;
    }

    /** Recibir archivo Meta; guardar vista previa privada, sin insertar prospectos. */
    public function metaPrevisualizar()
    {
        $this->validarAcceso();
        if (!ProspectoMetaImportador::puedeImportar()) {http_response_code(403);exit('Sin permiso para importar.');}
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit('Método no permitido.');}
        try {
            ProspectoMetaImportador::verificarCsrf($_POST['csrf_meta']??null);
            $db=(new Database())->connect(false);
            ProspectoMetaImportador::preparar($_FILES['archivo_meta']??[],$db);
        } catch (DomainException $e) {
            $_SESSION['prospectos_meta_error']=$e->getMessage();
        } catch (Throwable $e) {
            error_log('[prospectos_meta_previa] '.get_class($e).' codigo='.(int)$e->getCode());
            $_SESSION['prospectos_meta_error']='No fue posible revisar el archivo. Verifica el formato e inténtalo nuevamente.';
        }
        header('Cache-Control: no-store');
        header('Location: '.BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta',true,303);
        exit;
    }

    /** Cada POST confirma como máximo 75 registros, atómicamente y con resumen recuperable. */
    public function metaConfirmar()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            http_response_code(405);echo json_encode(['ok'=>false,'mensaje'=>'Método no permitido.']);return;
        }
        $this->validarAcceso();
        if (!ProspectoMetaImportador::puedeImportar()) {
            http_response_code(403);echo json_encode(['ok'=>false,'mensaje'=>'No tienes permiso para importar.']);return;
        }
        try {
            ProspectoMetaImportador::verificarCsrf($_POST['csrf_meta']??null);
            $programas=$_POST['programa']??[];
            $formularios=$_POST['programa_formulario']??null;
            $excepciones=$_POST['excepcion_campania']??[];
            if (!is_array($programas) || ($formularios!==null&&!is_array($formularios)) || !is_array($excepciones))
                throw new DomainException('Selección de programas no válida.');
            $db=(new Database())->connect(false);
            $out=ProspectoMetaImportador::procesar($db,$programas,$formularios,$excepciones);
            echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[prospectos_meta_confirmar] '.get_class($e).' codigo='.(int)$e->getCode());
            http_response_code(503);
            echo json_encode(['ok'=>false,'mensaje'=>'No se pudo confirmar este lote. Se conservaron los lotes anteriores; puedes reintentarlo.']);
        }
    }

    /** Cancelar una vista previa sin procesamiento; nunca borrar datos importados. */
    public function metaCancelar()
    {
        $this->validarAcceso();
        if (!ProspectoMetaImportador::puedeImportar()) {http_response_code(403);exit('Sin permiso.');}
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit('Método no permitido.');}
        try {
            ProspectoMetaImportador::verificarCsrf($_POST['csrf_meta']??null);
            ProspectoMetaImportador::cancelar();
        } catch (DomainException $e) {
            $_SESSION['prospectos_meta_error']=$e->getMessage();
        }
        header('Location: '.BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta',true,303);
        exit;
    }

    public function detalle()
    {
        $this->validarAcceso();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $ficha=null;$mensajeError='';
        try {
            if ($id===false||$id===null) {http_response_code(400);throw new DomainException('Identificador no válido.');}
            $repo=new ProspectoCentralRepository((new Database())->connect(false));
            if (!$repo->estructura()['disponible']) throw new DomainException('El almacén central aún no está disponible.');
            $ficha=$repo->detalle($id,max(1,(int)($_GET['pagina_origenes']??1)),max(1,(int)($_GET['pagina_intereses']??1)),isset($_GET['registro_id'])?(int)$_GET['registro_id']:null);
            if (!$ficha) {http_response_code(404);throw new DomainException('Prospecto no encontrado.');}
        } catch (DomainException $e) {$mensajeError=$e->getMessage();}
        catch (Throwable $e) {http_response_code(503);error_log('[prospecto_ficha] '.get_class($e));$mensajeError='No fue posible consultar este prospecto.';}
        $this->presentarDetalle($ficha,$mensajeError);
    }

    public function detalleRegistro()
    {
        $this->validarAcceso();$id=filter_var($_GET['registro_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $ficha=null;$mensajeError='';
        try {
            if ($id===false||$id===null) {http_response_code(400);throw new DomainException('Identificador no válido.');}
            $repo=new ProspectoCentralRepository((new Database())->connect(false));
            $r=$repo->registroOriginal($id);if (!$r) {http_response_code(404);throw new DomainException('Registro original no encontrado.');}
            if ($repo->estructura()['disponible']) {
                $origen=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$id);
                if ($origen) {
                    $destino=['controller'=>'prospecto','action'=>'detalle','id'=>$origen['prospecto_id']];
                    if (($_GET['panel']??'')==='1' && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest') $destino['panel']='1';
                    header('Location: '.BASE_URL.'index.php?'.http_build_query($destino));return;
                }
            }
            // Consulta original disponible incluso antes de conciliar. Ningún INSERT en GET.
            $ap=$repo->consulta("SELECT * FROM formulario_aliado_procedencias WHERE tipo='registro' AND registro_id=? ORDER BY id",[$id])->fetch_all(MYSQLI_ASSOC);
            $ficha=ProspectoCentralDatos::persona($r)+['id'=>null,'created_at'=>$r['created_at'],'estado_nombre'=>$r['estado_nombre'],
                'municipio_nombre'=>$r['municipio_nombre'],'verificacion_estado'=>'SIN_VERIFICAR','original'=>$r,'coincidencias'=>0,
                'origenes'=>[],'intereses'=>[],'asignaciones'=>[],'integraciones'=>[],'origenes_total'=>1,'origenes_pagina'=>1,'origenes_paginas'=>1,
                'intereses_total'=>0,'intereses_pagina'=>1,'intereses_paginas'=>1];
            $ficha['origenes'][]=['tipo_origen'=>$ap?'ALIADO':'FORMULARIO_GENERAL','aliado_nombre'=>null,'formulario_nombre'=>'Formulario de Registro',
                'datos_originales_ref'=>'formulario_registros:'.$id,'fecha_registro_original'=>$r['created_at'],'zona_horaria_origen'=>'UTC',
                'valor_original'=>$r['horario_contacto']??null,'origen_padre_id'=>null,'identificador_origen'=>(string)$id];
            foreach ($ap as $a) $ficha['origenes'][]=['tipo_origen'=>'ALIADO','aliado_nombre'=>$a['aliado_nombre'],'formulario_nombre'=>'Formulario de Registro','datos_originales_ref'=>'formulario_aliado_procedencias:'.$a['id'],'fecha_registro_original'=>$r['created_at'],'zona_horaria_origen'=>'UTC'];
            if (($r['perfil_interes']??'')!=='') {$ficha['intereses'][]=['nombre_original'=>$r['perfil_interes'],'clasificacion_estado'=>'Dato del formulario'];$ficha['intereses_total']=1;}
        } catch (DomainException $e) {$mensajeError=$e->getMessage();}
        catch (Throwable $e) {http_response_code(503);error_log('[prospecto_ficha_original] '.get_class($e));$mensajeError='No fue posible consultar el registro original.';}
        $this->presentarDetalle($ficha,$mensajeError);
    }

    private function presentarDetalle(?array $ficha,string $mensajeError): void
    {
        $vistaPanel=(($_GET['panel']??'')==='1'
            && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest');
        if ($vistaPanel) {
            // Fragmento seguro: los permisos ya se comprobaron antes de llegar aquí.
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store');
            require __DIR__.'/../views/prospectos/detalle.php';
            return;
        }
        // Enlaces directos, favoritos y navegación sin JS mantienen una vista accesible.
        $tituloPagina='Ficha del prospecto';$subtituloPagina='Identidad comercial, procedencia y datos originales.';$opcionActiva='prospectos';$seccionProspectos='todos';
        require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';require __DIR__.'/../views/layout/topbar.php';
        require __DIR__.'/../views/prospectos/detalle.php';require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    private function indicadores(array $fuentes=[],?ProspectoCentralRepository $repo=null): array
    {
        $c=['personas'=>null,'meta'=>null,'por_incorporar'=>null];
        try {if ($repo) $c=$repo->resumen();}catch(Throwable $e) {error_log('[prospectos_indicadores] '.get_class($e));}
        return $c+['aliados'=>$fuentes['aliados']??null,'generales'=>$fuentes['generales']??null];
    }

    private function aliados()
    {
        $this->validarAcceso();

        $tituloPagina = 'Prospectos';
        $subtituloPagina = 'Almacén central y procedencia de los registros.';
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
        if (!isset($_GET['vista']) && !isset($_GET['estado_id']) && (isset($_GET['buscar']) || isset($_GET['aliado_id']) || isset($_GET['programa']) || isset($_GET['pagina']))) $vistaProspectos='todos';
        $buscarProspectos = is_string($_GET['buscar']??null) ? mb_substr(trim($_GET['buscar']),0,180) : '';
        $aliadoFiltroProspectos = is_string($_GET['aliado_id']??null) && (($_GET['aliado_id']==='general') || ctype_digit($_GET['aliado_id'])) ? $_GET['aliado_id'] : '';
        $programaFiltroProspectos = is_string($_GET['programa']??null) ? $_GET['programa'] : '';
        $errorProgramasProspectos = '';
        $errorAliadosProspectos = '';
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
                error_log('[prospectos_resumen] '.get_class($error).' codigo='.(int)$error->getCode());
                $errorProcedencia='No fue posible consultar los indicadores de Prospectos.';
            }
            try {
                $territoriosProspectos=$modelo->territorios();
                $estadosConRegistros=count(array_filter($territoriosProspectos,static fn($estado)=>(int)$estado['total']>0));
            } catch (Throwable $error) {
                error_log('[prospectos_territorios] '.get_class($error).' codigo='.(int)$error->getCode());
                $errorTerritorial='No fue posible cargar la información territorial. Intenta nuevamente.';
            }
            if ($estadoSolicitado) {
                foreach ($territoriosProspectos as $territorio) if ((int)$territorio['id']===$estadoId) $estadoProspectos=$territorio;
                if ($errorTerritorial!=='') throw new DomainException('No se pudo verificar el estado seleccionado.');
                if ($estadoId===false || !$estadoProspectos) { http_response_code($estadoId===false?400:404); throw new DomainException('Estado no encontrado. Selecciona un estado activo del catálogo.'); }
            }
            if ($mostrarTablaProspectos) {
                foreach (['buscar','aliado_id','programa'] as $campo) if (isset($_GET[$campo]) && !is_string($_GET[$campo])) throw new DomainException('Filtro no válido.');
                if (isset($_GET['aliado_id']) && $_GET['aliado_id']!=='' && $aliadoFiltroProspectos==='') throw new DomainException('Aliado no válido.');
                $filtros=['buscar'=>$buscarProspectos,'aliado_id'=>$aliadoFiltroProspectos,'programa'=>$programaFiltroProspectos];
                if ($estadoProspectos) $filtros['estado_id']=(int)$estadoProspectos['id'];
                $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
                $listado=$modelo->listar($pagina,25,$filtros);
                $programaFiltroProspectos=$listado['programa_aplicado'];
                $errorProgramasProspectos=$listado['error_programas'];
                $errorAliadosProspectos=$listado['error_aliados'];
            }
        } catch (DomainException $error) {
            $mensajeError=$error->getMessage();
        } catch (Throwable $error) {
            error_log('[prospectos_listado] ' . get_class($error).' codigo='.(int)$error->getCode());
            $mensajeError = 'No fue posible consultar los registros. Intenta nuevamente.';
        }

        $seccionProspectos='aliados';
        try {$repoCentral=new ProspectoCentralRepository((new Database())->connect(false));$indicadores=$this->indicadores($resumenProspectos,$repoCentral);}
        catch(Throwable $e) {$indicadores=$this->indicadores($resumenProspectos);}
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
