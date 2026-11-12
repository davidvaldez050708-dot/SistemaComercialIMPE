<?php
require_once __DIR__.'/../helpers/EmbudoComercialAcceso.php';
require_once __DIR__.'/../services/EmbudoComercialRepository.php';
require_once __DIR__.'/../services/EmbudoComercialV3AccesoControlado.php';
require_once __DIR__.'/../../config/db_connection.php';

/**
 * Vista Kanban de consulta: funciones de movimiento, pago y contacto
 * se incorporarán con permisos operativos propios y evidencia verificable.
 */
final class EmbudoComercialController
{
    private function autorizar(string $vista): array
    {
        $id=(int)($_SESSION['usuario_id']??0);
        if ($id<=0) {
            http_response_code(401);
            exit('Sesión no disponible.');
        }
        if (!isset(EmbudoComercialAcceso::VISTAS[$vista])) {
            http_response_code(404);
            exit('Vista comercial no disponible.');
        }
        if (!EmbudoComercialAcceso::puede($vista)) {
            http_response_code(403);
            exit('No tienes permiso para consultar esta vista comercial.');
        }
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
            http_response_code(405);
            exit('Método no permitido.');
        }
        header('Cache-Control: private, no-store, max-age=0');
        return [$id,EmbudoComercialAcceso::esSupervisor()];
    }

    private function presentar(string $archivo,string $vista,array $datos): void
    {
        extract($datos,EXTR_SKIP);
        $titulos=[
            'embudo'=>'Gestión comercial',
            'recuperacion'=>'Recuperación comercial',
            'descartados'=>'Prospectos descartados'
        ];
        $tituloPagina=$titulos[$vista];
        $subtituloPagina='Control de etapas, atención y seguimiento de prospectos.';
        $opcionActiva='embudo_comercial';
        require __DIR__.'/../views/layout/dashboard_head.php';
        require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';
        require __DIR__.'/../views/embudo_comercial/'.$archivo.'.php';
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    public function index(): void
    {
        $vista=$_GET['vista']??'embudo';
        if (!is_string($vista)) {
            http_response_code(400);
            exit('Vista no válida.');
        }
        [$usuarioId,$supervisor]=$this->autorizar($vista);
        // Una vista de SOLO LECTURA reservada a supervisores.
        // No reconfigurar el Kanban normal ni exponerla a asesores.
        $previsualizacionV3=$vista==='embudo' && $supervisor &&
            ($_GET['previsualizacion_v3']??null)==='1';
        // V3 es la entrada principal de Gestión Comercial del asesor autorizado.
        // ?tablero_anterior=1 mantiene un rescate explícito temporal (sin botón).
        // Este cambio no enciende movimientos automáticos ni pilotos PBX.
        $v3Asesor=!$supervisor &&
            EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuarioId) &&
            ($_GET['tablero_anterior']??null)!=='1';
        $operativoV3=$vista==='embudo' && $v3Asesor;
        $bandejasV3=$v3Asesor &&
            in_array($vista,['recuperacion','descartados'],true);
        $error='';
        $disponible=false;
        $opciones=['programas'=>[],'asesores'=>[]];
        $tablero=['columnas'=>[],'conteos'=>[],'total'=>0];
        $paginasV3=[];
        $filtros=[
            'buscar'=>'','origen'=>'','programa'=>'','vencidos'=>false,
            'asesor'=>0,'paginas'=>[]
        ];
        try {
            $filtros=EmbudoComercialRepository::filtros($_GET,$supervisor);
            if($operativoV3){
                $paginasV3=EmbudoComercialRepository::paginasV3Operativo($_GET);
            }
            $repo=new EmbudoComercialRepository((new Database())->connect(false));
            $disponible=$repo->disponible();
            if ($disponible) {
                $opciones=$repo->opciones($usuarioId,$supervisor);
                $tablero=$previsualizacionV3
                    ?$repo->listadoV3Preparacion($usuarioId,$supervisor,$filtros)
                    :($operativoV3
                        ?$repo->listadoV3Operativo($usuarioId,$filtros,$paginasV3)
                        :$repo->listado($usuarioId,$supervisor,$filtros,$vista,$bandejasV3));
            }
        } catch (DomainException $e) {
            http_response_code(400);
            $error=$e->getMessage();
        } catch (Throwable $e) {
            http_response_code(503);
            error_log('[embudo_comercial_consulta] '.get_class($e).' codigo='.(int)$e->getCode());
            $error='No fue posible consultar el embudo comercial en este momento.';
        }
        $archivo=$previsualizacionV3?'previsualizacion_v3'
            :($operativoV3?'operativo_v3':($bandejasV3?'bandejas_v3':'index'));
        $this->presentar($archivo,$vista,compact(
            'vista','usuarioId','supervisor','error','disponible','opciones',
            'tablero','filtros','previsualizacionV3','operativoV3','bandejasV3','paginasV3'
        ));
    }

    public function detalle(): void
    {
        $vista=$_GET['vista']??'embudo';
        if (!is_string($vista)) {
            http_response_code(400);
            exit('Vista no válida.');
        }
        [$usuarioId,$supervisor]=$this->autorizar($vista);
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $prospecto=null;
        $operativoV3=$vista==='embudo' && !$supervisor &&
            EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuarioId) &&
            ($_GET['tablero_anterior']??null)!=='1';
        $error='';
        if (!$id) {
            http_response_code(404);
            $error='El prospecto no está disponible en esta vista.';
        } else {
            try {
                $repo=new EmbudoComercialRepository((new Database())->connect(false));
                if (!$repo->disponible()) throw new RuntimeException('Estructura no instalada.');
                $prospecto=$repo->detalle(
                    $usuarioId,$supervisor,(int)$id,$vista,$operativoV3
                );
                if (!$prospecto) {
                    http_response_code(404);
                    $error='El prospecto no está disponible en esta vista.';
                }
            } catch (Throwable $e) {
                http_response_code(503);
                error_log('[embudo_comercial_detalle] '.get_class($e).' codigo='.(int)$e->getCode());
                $error='No fue posible consultar la ficha comercial.';
            }
        }
        $this->presentar('detalle',$vista,compact(
            'vista','usuarioId','supervisor','prospecto','error','operativoV3'
        ));
    }
    /**
     * POST exclusivo de la V3 operativa para asesores.
     * Reutiliza el movimiento V1 verificado y su transacción/auditoría,
     * con lista blanca ADICIONAL que impide mover sin evidencia a Potencial,
     * Recuperación o Proceso Inscripción mientras esa política no se active.
     *
     * No activa el piloto PBX, ni altera la telefonía ni otros endpoints.
     */
    /**
     * POST: gestión transversal de un Potencial, sin cambiar su etapa.
     * INTERESADO histórico queda INTERESADO hasta conciliación institucional.
     */
    public function gestionarPotencialV3(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $reply=static function(bool $ok,string $message,int $status,array $extra=[]): void {
            http_response_code($status);
            echo json_encode(array_merge(['ok'=>$ok,'mensaje'=>$message],$extra),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            $reply(false,'Método no permitido.',405);
            return;
        }
        $uid=(int)($_SESSION['usuario_id']??0);
        if ($uid<1 || !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($uid) ||
            !tienePermiso('ventas.embudo.mover')) {
            $reply(false,'Acceso comercial no autorizado.',403);
            return;
        }
        $token=(string)($_SESSION['csrf_token']??'');
        $received=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if ($token==='' || $received==='' || !hash_equals($token,$received)) {
            $reply(false,'Sesión de seguridad vencida. Recarga la página.',403);
            return;
        }
        foreach (['asignacion_id','etapa_actual','subetapa_anterior',
                  'subetapa_nueva','motivo','proxima_accion'] as $key) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                $reply(false,'Datos de gestión inválidos.',422);
                return;
            }
        }
        $asig=filter_var($_POST['asignacion_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $motivo=trim((string)($_POST['motivo']??''));
        if (!$asig || mb_strlen($motivo)<8 || mb_strlen($motivo)>190) {
            $reply(false,'Identifica el prospecto e indica un motivo válido.',422);
            return;
        }
        try {
            require_once __DIR__.'/../services/EmbudoComercialV3GestionPotencialService.php';
            $db=(new Database())->connect(false);
            $resultado=(new EmbudoComercialV3GestionPotencialService($db))->registrar(
                $uid,(int)$asig,(string)($_POST['etapa_actual']??''),
                (string)($_POST['subetapa_anterior']??''),
                (string)($_POST['subetapa_nueva']??''),
                $motivo,(string)($_POST['proxima_accion']??'')
            );
            $reply(true,'Gestión registrada sin modificar la etapa comercial.',200,
                ['gestion'=>$resultado]);
        } catch (DomainException $e) {
            $reply(false,$e->getMessage(),409);
        } catch (Throwable $e) {
            error_log('[ventas_v3_potencial_gestion] '.get_class($e).
                ' codigo='.(int)$e->getCode());
            $reply(false,'No pudo confirmarse la gestión. No se guardó.',503);
        }
    }

    public function seguimientoOperativoV3(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        $reply=static function(int $status,array $datos): void {
            http_response_code($status);
            echo json_encode(['ok'=>$status===200]+$datos,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        $metodo=$_SERVER['REQUEST_METHOD']??'';
        if (!in_array($metodo,['GET','POST'],true)) {
            $reply(405,['mensaje'=>'Método no permitido.']);return;
        }
        $uid=(int)($_SESSION['usuario_id']??0);
        if ($uid<1 || !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($uid) ||
            !tienePermiso('ventas.embudo.mover')) {
            $reply(403,['mensaje'=>'Acceso comercial no autorizado.']);return;
        }
        $token=(string)($_SESSION['csrf_token']??'');
        if ($metodo==='POST' && ($token==='' || !hash_equals($token,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))) {
            $reply(403,['mensaje'=>'Sesión de seguridad vencida. Recarga la página.']);return;
        }
        $entrada=$metodo==='POST'?$_POST:$_GET;
        foreach (['asignacion_id','antes','etapa_actual','version','operacion','motivo','fecha','subetapa'] as $campo) {
            if (isset($entrada[$campo]) && !is_string($entrada[$campo])) {
                $reply(422,['mensaje'=>'Datos de actividad inválidos.']);return;
            }
        }
        $id=filter_var($entrada['asignacion_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $version=filter_var($entrada['version']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $antes=filter_var($entrada['antes']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if (!$id || $version===false || $antes===false || ($metodo==='POST' && !isset($entrada['version']))) {
            $reply(422,['mensaje'=>'La asignación o versión no es válida.']);return;
        }
        try {
            require_once __DIR__.'/../services/EmbudoComercialV3SeguimientoService.php';
            $db=(new Database())->connect(false);
            if (!EmbudoComercialV3SeguimientoService::instalada($db)) {
                $reply(503,['mensaje'=>'El seguimiento V3 aún no está disponible.']);return;
            }
            $servicio=new EmbudoComercialV3SeguimientoService($db);
            if ($metodo==='GET') {
                $reply(200,['historial'=>$servicio->historial($uid,$id,$antes)]);return;
            }
            $evento=$servicio->registrar($uid,$id,$entrada['etapa_actual']??'',$version,
                $entrada['operacion']??'',$entrada['motivo']??'',$entrada['fecha']??'',$entrada['subetapa']??'');
            $reply(200,['evento_id'=>$evento]);
        } catch (DomainException $error) {
            $reply(409,['mensaje'=>$error->getMessage()]);
        } catch (Throwable $error) {
            error_log('[ventas_v3_seguimiento] '.get_class($error).' codigo='.(int)$error->getCode());
            $reply(503,['mensaje'=>'No se pudo confirmar la actividad.']);
        }
    }

    /**
     * Solicitud institucional: verificar identidad y confirmar con formulario
     * real. La búsqueda no crea etapa, pago, inscripción ni registros.
     */
    public function inscripcionOperativaV3(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $reply=static function(int $status,string $mensaje,array $extra=[]): void {
            http_response_code($status);
            echo json_encode(array_merge(['ok'=>$status===200,'mensaje'=>$mensaje],$extra),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            $reply(405,'Método no permitido.');return;
        }
        $usuario=(int)($_SESSION['usuario_id']??0);
        if ($usuario<1 ||
            !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuario) ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            $reply(403,'No tienes acceso al proceso de inscripción V3.');return;
        }
        $token=(string)($_SESSION['csrf_token']??'');
        if ($token==='' || !hash_equals($token,
            (string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) {
            $reply(403,'Sesión de seguridad vencida. Recarga la página.');return;
        }
        foreach (['operacion','asignacion_id','formulario_id','motivo','confirmacion'] as $campo) {
            if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
                $reply(422,'Datos de trámite inválidos.');return;
            }
        }
        $asignacion=filter_var($_POST['asignacion_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $operacion=(string)($_POST['operacion']??'');
        if (!$asignacion || !in_array($operacion,['BUSCAR','VINCULAR'],true)) {
            $reply(422,'La solicitud no es válida.');return;
        }
        try {
            require_once __DIR__.'/../services/EmbudoComercialV3InscripcionService.php';
            $db=(new Database())->connect(false);
            $svc=new EmbudoComercialV3InscripcionService($db);
            if ($operacion==='BUSCAR') {
                $candidato=$svc->candidato($usuario,(int)$asignacion);
                $reply(200,'Consulta institucional completada.',
                    ['consulta'=>$candidato]);return;
            }
            $formId=filter_var($_POST['formulario_id']??null,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]);
            if (!$formId) {
                $reply(422,'Primero verifica el formulario de inscripción.');return;
            }
            $res=$svc->vincular($usuario,(int)$asignacion,(int)$formId,
                (string)($_POST['motivo']??''),
                ($_POST['confirmacion']??'')==='1');
            $reply(200,'Solicitud de inscripción vinculada y auditada.',
                ['movimiento'=>$res]);
        } catch (DomainException $e) {
            $reply(409,$e->getMessage());
        } catch (Throwable $e) {
            error_log('[ventas_v3_inscripcion] '.get_class($e).
                ' codigo='.(int)$e->getCode());
            $reply(503,'No se pudo verificar o vincular la solicitud. No se guardó.');
        }
    }

    public function moverEtapaV3(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $reply=static function(bool $ok,string $message,int $status,array $extra=[]): void {
            http_response_code($status);
            echo json_encode(array_merge(['ok'=>$ok,'mensaje'=>$message],$extra),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
            $reply(false,'Método no permitido.',405);
            return;
        }
        $uid=(int)($_SESSION['usuario_id']??0);
        if($uid<1 || !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($uid) ||
            !tienePermiso('ventas.embudo.mover')) {
            $reply(false,'Esta operación no está autorizada.',403);
            return;
        }
        // El dashboard_head ya entrega el token de sesión a los formularios
        // del CRM. Se comprueba aquí de forma efectiva (no solo en JS).
        $token=(string)($_SESSION['csrf_token']??'');
        $received=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if($token==='' || $received==='' ||
            !hash_equals($token,$received)){
            $reply(false,'Sesión de seguridad vencida. Recarga la página.',403);
            return;
        }
        foreach(['asignacion_id','etapa_anterior','etapa_nueva',
                 'motivo','proxima_accion','calificacion_confirmada'] as $key) {
            if(isset($_POST[$key]) && !is_string($_POST[$key])) {
                $reply(false,'Datos de movimiento inválidos.',422);
                return;
            }
        }
        $asig=filter_var($_POST['asignacion_id']??null,
            FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$asig){
            $reply(false,'La asignación no es válida.',422);
            return;
        }
        $anterior=(string)($_POST['etapa_anterior']??'');
        $nueva=(string)($_POST['etapa_nueva']??'');
        $motivo=trim((string)($_POST['motivo']??''));
        $fecha=(string)($_POST['proxima_accion']??'');
        if(mb_strlen($motivo)>190 || mb_strlen($motivo)<8) {
            $reply(false,'Describe brevemente la gestión (8 a 190 caracteres).',422);
            return;
        }
        try{
            require_once __DIR__.'/../services/EmbudoComercialV3MovimientosPermitidos.php';
            EmbudoComercialV3MovimientosPermitidos::validar($anterior,$nueva);
            $db=(new Database())->connect(false);
            if ($nueva==='POTENCIAL') {
                if ($fecha!=='') {
                    throw new DomainException('La calificación conserva la próxima acción. Puedes cambiarla en Potenciales.');
                }
                require_once __DIR__.'/../services/EmbudoComercialV3CalificacionService.php';
                $confirmada=($_POST['calificacion_confirmada']??'')==='1';
                $mov=(new EmbudoComercialV3CalificacionService($db))->registrar($uid,(int)$asig,$motivo,$confirmada);
                $reply(true,'Calificación registrada y auditada.',200,['movimiento'=>$mov]);
                return;
            }
            require_once __DIR__.'/../services/EmbudoComercialV3SeguimientoService.php';
            if (EmbudoComercialV3SeguimientoService::instalada($db)) {
                $mov=(new EmbudoComercialV3SeguimientoService($db))->mover($uid,(int)$asig,$anterior,$nueva,$motivo,$fecha);
                $reply(true,'Movimiento registrado y auditado.',200,['movimiento'=>$mov]);
                return;
            }
            if(EmbudoComercialV3MovimientosPermitidos::requiereConversacion(
                $anterior,$nueva
            ) && !EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
                $db,$uid,(int)$asig
            )) {
                throw new DomainException(
                    'El primer contacto requiere conversación humana '.
                    'acreditada en el resultado PBX de esta asignación.'
                );
            }
            require_once __DIR__.'/../services/EmbudoComercialMovimientoService.php';
            $mov=(new EmbudoComercialMovimientoService($db))->moverManual(
                $uid,(int)$asig,$anterior,$nueva,$motivo,$fecha
            );
            $reply(true,'Movimiento registrado y auditado.',200,['movimiento'=>$mov]);
        }catch(DomainException $e){
            $reply(false,$e->getMessage(),409);
        }catch(Throwable $e){
            error_log('[ventas_v3_movimiento] '.get_class($e).
                ' codigo='.(int)$e->getCode());
            $reply(false,'No se pudo confirmar el movimiento. No se guardó.',503);
        }
    }

    /**
     * POST independiente: Recuperación, descartes acreditados y reactivación.
     * No usa el endpoint V1 ni recibe contadores PBX desde el navegador.
     */
    public function bandejasOperativasV3(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $reply=static function(int $status,string $mensaje,array $extra=[]): void {
            http_response_code($status);
            echo json_encode(array_merge(['ok'=>$status===200,'mensaje'=>$mensaje],$extra),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            $reply(405,'Método no permitido.');return;
        }
        $uid=(int)($_SESSION['usuario_id']??0);
        if ($uid<1 ||
            !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($uid) ||
            !tienePermiso('ventas.embudo.mover')) {
            $reply(403,'Esta operación no está autorizada para tu cuenta.');return;
        }
        $csrf=(string)($_SESSION['csrf_token']??'');
        if ($csrf==='' || !hash_equals($csrf,(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) {
            $reply(403,'Sesión de seguridad vencida. Recarga la página.');return;
        }
        foreach (['asignacion_id','etapa_actual','operacion','subetapa',
                  'motivo','dia','confirmacion'] as $campo) {
            if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
                $reply(422,'Datos de gestión inválidos.');return;
            }
        }
        $asig=filter_var($_POST['asignacion_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if (!$asig) {$reply(422,'La asignación no es válida.');return;}
        $etapa=(string)($_POST['etapa_actual']??'');
        $accion=(string)($_POST['operacion']??'');
        $motivo=(string)($_POST['motivo']??'');
        $confirmada=($_POST['confirmacion']??'')==='1';
        if (!in_array($accion,['RECUPERACION','DESCARTAR','REACTIVAR'],true)) {
            $reply(422,'Operación no disponible.');return;
        }
        try {
            require_once __DIR__.'/../services/EmbudoComercialV3BandejasService.php';
            $db=(new Database())->connect(false);
            $service=new EmbudoComercialV3BandejasService($db);
            if ($accion==='RECUPERACION') {
                $mov=$service->ingresarRecuperacion($uid,(int)$asig,$etapa,
                    (string)($_POST['dia']??''),$motivo,$confirmada);
            } elseif ($accion==='DESCARTAR') {
                $mov=$service->descartar($uid,(int)$asig,$etapa,
                    (string)($_POST['subetapa']??''),$motivo,$confirmada);
            } else {
                $mov=$service->reactivar($uid,(int)$asig,$etapa,
                    $motivo,$confirmada);
            }
            $reply(200,'Decisión comercial registrada y auditada.',['movimiento'=>$mov]);
        } catch (DomainException $e) {
            $reply(409,$e->getMessage());
        } catch (Throwable $e) {
            error_log('[ventas_v3_bandejas] '.get_class($e).
                ' codigo='.(int)$e->getCode());
            $reply(503,'No se pudo confirmar la decisión. No se guardó.');
        }
    }

    /** POST exclusivo de Asesor: cambio de etapa con control de concurrencia. */
    public function moverEtapa(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $respond=static function(bool $ok,string $mensaje,int $status,array $extra=[]): void {
            http_response_code($status);
            echo json_encode(array_merge(['ok'=>$ok,'mensaje'=>$mensaje],$extra),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        };
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
            $respond(false,'Método no permitido.',405);
            return;
        }
        $usuario=(int)($_SESSION['usuario_id']??0);
        if ($usuario<1 || (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            $respond(false,'Esta gestión no está autorizada para tu cuenta.',403);
            return;
        }
        foreach (['asignacion_id','etapa_anterior','etapa_nueva','motivo','proxima_accion'] as $clave) {
            if (isset($_POST[$clave]) && !is_string($_POST[$clave])) {
                $respond(false,'Los datos del movimiento no son válidos.',422);
                return;
            }
        }
        $id=filter_var($_POST['asignacion_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if (!$id) {
            $respond(false,'La tarjeta no es válida.',422);
            return;
        }
        // No permitir que V1 eluda requisitos PBX o de descarte a asesores V3.
        if (EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuario) &&
            (in_array((string)($_POST['etapa_anterior']??''),['RECUPERACION','EN_CONTACTO','DESCARTADO'],true) ||
             in_array((string)($_POST['etapa_nueva']??''),['RECUPERACION','DESCARTADO'],true))) {
            $respond(false,'Utiliza las acciones verificadas de las bandejas V3.',403);
            return;
        }
        try {
            require_once __DIR__.'/../services/EmbudoComercialMovimientoService.php';
            $db=(new Database())->connect(false);
            $svc=new EmbudoComercialMovimientoService($db);
            $mov=$svc->moverManual(
                $usuario,(int)$id,
                (string)($_POST['etapa_anterior']??''),
                (string)($_POST['etapa_nueva']??''),
                (string)($_POST['motivo']??''),
                (string)($_POST['proxima_accion']??'')
            );
            $respond(true,'Etapa actualizada y movimiento registrado.',200,
                ['movimiento'=>$mov]);
        } catch (DomainException $e) {
            $respond(false,$e->getMessage(),409);
        } catch (Throwable $e) {
            error_log('[embudo_mover] '.get_class($e).' codigo='.(int)$e->getCode());
            $respond(false,'No fue posible actualizar la etapa. No se confirmó el movimiento.',503);
        }
    }

}
