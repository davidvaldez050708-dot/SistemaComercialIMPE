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
        // Sólo estos tres roles operativos usan asignaciones territoriales.
        // Los demás roles autorizados consultan el catálogo nacional, aunque
        // no tengan territorios asignados ni permisos de publicación.
        $tiposAsignacion = [3 => 'ASESOR', 4 => 'ANALISTA_DATOS', 6 => 'CUENTA_CLAVE'];
        $tipo = $tiposAsignacion[(int)($_SESSION['rol_id'] ?? 0)] ?? null;
        $estados = $modelo->listarEstados();
        $estadoIds = null;
        if ($tipo !== null) {
            $estados = (new TerritorioModel())->obtenerEstadosAsignadosUsuario(
                (int)($_SESSION['usuario_id'] ?? 0), $tipo
            );
            $estadoIds = array_map('intval', array_column($estados, 'id'));
        }
        $convenios = $estructuraDisponible
            ? $modelo->listarConveniosRecibidos($estadoIds)
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

    private function respuestaV4(callable $action,bool $post=false): void
    {
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
        try {
            if(!isset($_SESSION['usuario_id'])||!puedeCrearConvocatoriaDesdeConvenio())throw new DomainException('No tienes permiso para crear convocatorias desde Convenios.',403);
            if($post){
                if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('Método no permitido.',405);
                $csrf=(string)($_SESSION['csrf_token']??'');
                if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new DomainException('Sesión caducada. Vuelve a iniciar sesión.',419);
            }
            require_once __DIR__.'/../services/ConvocatoriaBorradorService.php';
            $service=new ConvocatoriaBorradorService((new Database())->connect());
            echo json_encode(['ok'=>true,'datos'=>$action($service)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(Throwable $e){
            $status=$e instanceof DomainException&&(int)$e->getCode()>=400&&(int)$e->getCode()<600?(int)$e->getCode():500;
            http_response_code($status);if($status===500)error_log('[borrador_v4] '.$e->getMessage());
            echo json_encode(['ok'=>false,'mensaje'=>$status===500?'No se pudo guardar el borrador. Tus cambios siguen pendientes.':$e->getMessage()]);
        }
        exit;
    }
    public function editorConvenio(): void
    {
        $this->respuestaV4(function($service){
            $aliadoContextoConvocatoria=$service->contexto((string)($_GET['aliado_tipo']??''),(int)($_GET['aliado_id']??0));
            $component=__DIR__.'/../views/convocatorias/partials/aliado_programas_modular.php';
            if(getenv('APP_ENV')==='testing'&&getenv('IMPE_V3_COMPONENT_PATH'))$component=getenv('IMPE_V3_COMPONENT_PATH');
            if(!is_file($component))throw new DomainException('Falta el componente modular de Convocatorias instalado en Hostinger.',503);
            $puedeCrear=true;$texto=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            ob_start();try{require __DIR__.'/../views/convocatorias/partials/editor_convenio_v4.php';$html=ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}
            return ['html'=>$html,'contexto'=>$aliadoContextoConvocatoria];
        });
    }
    public function borrador(): void
    {
        $this->respuestaV4(function($service){
            $id=(int)($_POST['id']??0);$version=(int)($_POST['version']??0);$key=(string)($_POST['operacion_id']??'');
            switch($_POST['operacion']??''){
                case 'abrir':return $service->abrir((string)($_POST['aliado_tipo']??''),(int)($_POST['aliado_id']??0));
                case 'cargar':return $service->cargar($id);
                case 'guardar':
                    if(strlen((string)($_POST['programas']??''))>40000)throw new DomainException('Borrador demasiado grande.',422);
                    try{$data=json_decode((string)($_POST['programas']??''),true,64,JSON_THROW_ON_ERROR);}
                    catch(JsonException $error){throw new DomainException('El borrador contiene datos no válidos.',422);}
                    if(!is_array($data))throw new DomainException('Programas inválidos.',422);
                    return $service->guardar($id,$version,$key,$data);
                case 'imagen':return $service->subir($id,$version,$key,(string)($_POST['programa']??''),$_FILES['imagen']??[]);
                case 'descartar':
                    if(($_POST['confirmar']??'')!=='DESCARTAR')throw new DomainException('Confirma que deseas descartar el borrador.',422);
                    return $service->descartar($id,$version,$key);
                default:throw new DomainException('Operación desconocida.',422);
            }
        },true);
    }
    /**
     * Consulta y guarda el diseño único del QR compartido por aliado.
     * Independiente del borrador y del enlace público de Registro.
     */
    public function qrDiseno(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        try {
            if (empty($_SESSION['usuario_id']) || !puedeCrearConvocatoriaDesdeConvenio()) {
                throw new DomainException('No tienes autorización para gestionar el QR del aliado.',403);
            }
            $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
            if (!in_array($method,['GET','POST'],true)) {
                throw new DomainException('Método no permitido.',405);
            }
            if ($method==='POST') {
                $csrf=(string)($_SESSION['csrf_token']??'');
                if (!$csrf || !hash_equals($csrf,(string)($_POST['csrf_token']??''))) {
                    throw new DomainException('La sesión caducó. Recarga el CRM.',419);
                }
                if (empty($_SERVER['CONTENT_LENGTH'])===false &&
                    (int)$_SERVER['CONTENT_LENGTH']>3000000) {
                    throw new DomainException('La imagen y el diseño superan el tamaño permitido.',413);
                }
            }
            require_once __DIR__.'/../services/ConvocatoriaQrDisenoService.php';
            $service=new ConvocatoriaQrDisenoService((new Database())->connect());
            $input=$method==='POST'?$_POST:$_GET;
            $tipo=(string)($input['aliado_tipo']??'');
            $id=(int)($input['aliado_id']??0);
            $data=$method==='GET'
                ? $service->consultar($tipo,$id)
                : $service->guardar($tipo,$id,(int)($input['version']??-1),
                    (string)($input['config']??''),$_FILES['logo']??[]);
            echo json_encode(['ok'=>true,'datos'=>$data],
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            $status=$error instanceof DomainException
                && (int)$error->getCode()>=400 && (int)$error->getCode()<600
                ? (int)$error->getCode():500;
            if ($status===500) error_log('[qr_diseno_aliado] '.$error->getMessage());
            http_response_code($status);
            echo json_encode(['ok'=>false,'mensaje'=>$status===500
                ? 'No fue posible guardar o recuperar el diseño. Intenta nuevamente.'
                : $error->getMessage()],JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function imagenBorrador(): void
    {
        try{
            require_once __DIR__.'/../services/ConvocatoriaBorradorService.php';
            $image=(new ConvocatoriaBorradorService((new Database())->connect()))->imagen((int)($_GET['id']??0),(string)($_GET['programa']??''),(string)($_GET['ref']??''));
            header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Type: '.$image['mime']);header('Content-Length: '.$image['bytes']);
            readfile($image['ruta']);
        }catch(Throwable $e){http_response_code($e instanceof DomainException?(int)$e->getCode():500);header('Cache-Control: no-store, private');}
        exit;
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
