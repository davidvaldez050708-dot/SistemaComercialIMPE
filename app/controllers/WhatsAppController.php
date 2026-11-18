<?php

require_once __DIR__ . '/../models/WhatsAppModel.php';
require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../services/WhatsAppCloudApiService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class WhatsAppController
{
    private ?string $envioId=null;

    private function prepararEnvioIdempotente(string $tipo): void
    {
        $id=$_POST['request_id']??'';
        if($id==='')return; // Clientes anteriores no se convierten en reintentos implícitos.
        if(!is_string($id) || !preg_match('/^[A-Za-z0-9-]{16,80}$/D',$id))$this->responder(['ok'=>false,'mensaje'=>'Identificador de envío inválido.'],422);
        $firma=hash('sha256',$tipo.'|'.(string)($_POST['conversacion_id']??'').'|'.(string)($_POST['mensaje']??''));
        foreach($_SESSION['whatsapp_envios']??[] as $k=>$v)if(($v['at']??0)<time()-900)unset($_SESSION['whatsapp_envios'][$k]);
        $anterior=$_SESSION['whatsapp_envios'][$id]??null;
        if($anterior) {
            if(!hash_equals($anterior['firma'],$firma))$this->responder(['ok'=>false,'mensaje'=>'El intento de envío cambió. Revisa el mensaje.'],409);
            if(isset($anterior['respuesta']))$this->responder($anterior['respuesta'],$anterior['http']);
            $this->responder(['ok'=>false,'mensaje'=>'El envío está pendiente de confirmación. Revisa el historial antes de volver a enviarlo.'],409);
        }
        if(count($_SESSION['whatsapp_envios']??[])>=100)$this->responder(['ok'=>false,'mensaje'=>'Hay demasiados intentos recientes. Espera antes de reintentar.'],429);
        $_SESSION['whatsapp_envios'][$id]=['at'=>time(),'firma'=>$firma];$this->envioId=$id;
    }

    protected function servicioWhatsApp(WhatsAppModel $modelo): WhatsAppCloudApiService
    {
        return new WhatsAppCloudApiService($modelo->conexionParaAutorizacion());
    }

    public function contexto(): void
    {
        $this->validarPermiso('whatsapp.ver');
        $this->validarMetodoPost();
        $tipo=$_POST['tipo']??'';
        $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!is_string($tipo) || !$id)$this->responder(['ok'=>false,'mensaje'=>'Origen de conversación no válido.'],422);
        $modelo=new WhatsAppModel();$this->validarEstructura($modelo);
        try{$conv=WhatsAppContextoService::abrir($modelo,$tipo,(int)$id,(int)$_SESSION['usuario_id']);}
        catch(DomainException $e){$this->responder(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[whatsapp_contexto] '.get_class($e));$this->responder(['ok'=>false,'mensaje'=>'No fue posible abrir la conversación. Intenta de nuevo.'],503);}
        $this->responder(['ok'=>true,'conversacion_id'=>$conv,'url'=>BASE_URL.'index.php?controller=whatsapp&action=panel&conversacion_id='.$conv]);
    }

    public function panel(): void
    {
        $this->validarPermiso('whatsapp.ver');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Frame-Options: SAMEORIGIN');
        $id=filter_var($_GET['conversacion_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $modelo=new WhatsAppModel();$this->validarEstructura($modelo);
        $uid=(int)$_SESSION['usuario_id'];
        $conversacionSeleccionada=$id?$modelo->obtenerConversacion((int)$id,$uid,WhatsAppContextoService::lecturaGlobal()):null;
        if(!$conversacionSeleccionada){http_response_code(403);exit('No tienes acceso a esta conversación.');}
        $servicio=new WhatsAppCloudApiService($modelo->conexionParaAutorizacion());
        $estadoConfiguracionWhatsapp=$servicio->obtenerEstadoConfiguracion();
        $puedeEnviarWhatsapp=$estadoConfiguracionWhatsapp['lista'] && tienePermiso('whatsapp.enviar') &&
            (bool)$modelo->obtenerConversacion((int)$id,$uid,tienePermiso('whatsapp.responder_todas'));
        $cuenta=$modelo->obtenerCuentaPorPhoneNumberId((string)$conversacionSeleccionada['phone_number_id']);
        $puedePlantillaPrueba=$puedeEnviarWhatsapp && ($cuenta['tipo']??'')==='PRUEBA';
        $ventanaServicioAbierta=$modelo->ventanaServicioAbierta($conversacionSeleccionada);
        $mensajesIniciales=$modelo->obtenerMensajes((int)$id,$uid,WhatsAppContextoService::lecturaGlobal());
        require __DIR__.'/../views/whatsapp/panel.php';
    }

    public function index()
    {
        $this->validarPermiso('whatsapp.ver');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $puedeGestionarConversaciones =
            WhatsAppContextoService::lecturaGlobal();
        $puedeGestionarCuentas =
            tienePermiso('whatsapp.gestionar_cuentas');

        $modelo = new WhatsAppModel();
        $servicio = new WhatsAppCloudApiService($modelo->conexionParaAutorizacion());

        $estructuraWhatsappDisponible = $modelo->estructuraDisponible();
        $estadoConfiguracionWhatsapp = $servicio->obtenerEstadoConfiguracion();

        $conversaciones = $estructuraWhatsappDisponible
            ? $modelo->obtenerConversaciones(
                $usuarioId,
                $puedeGestionarConversaciones
            )
            : [];

        $conversacionSeleccionada = null;
        $mensajesIniciales = [];
        $conversacionId = (int)($_GET['conversacion_id'] ?? 0);

        if ($estructuraWhatsappDisponible && $conversacionId > 0) {
            $conversacionSeleccionada = $modelo->obtenerConversacion(
                $conversacionId,
                $usuarioId,
                $puedeGestionarConversaciones
            );

            if ($conversacionSeleccionada) {
                $mensajesIniciales = $modelo->obtenerMensajes(
                    $conversacionId,
                    $usuarioId,
                    $puedeGestionarConversaciones
                );
                if($modelo->obtenerConversacion($conversacionId,$usuarioId,false))$modelo->marcarConversacionLeida($conversacionId);
                $conversacionSeleccionada['no_leidos'] = 0;
            }
        }

        if ($conversacionId>0 && !$conversacionSeleccionada) {
            http_response_code(403);exit('No tienes acceso a esta conversación.');
        }
        if (!$conversacionSeleccionada && !empty($conversaciones)) {
            $conversacionSeleccionada = $conversaciones[0];
            $conversacionId = (int)$conversacionSeleccionada['id'];
            $mensajesIniciales = $modelo->obtenerMensajes(
                $conversacionId,
                $usuarioId,
                $puedeGestionarConversaciones
            );
            if($modelo->obtenerConversacion($conversacionId,$usuarioId,false))$modelo->marcarConversacionLeida($conversacionId);
            $conversacionSeleccionada['no_leidos'] = 0;
        }

        $cuentasDisponibles = $estructuraWhatsappDisponible
            ? $modelo->obtenerCuentas(
                $usuarioId,
                $puedeGestionarCuentas,
                true
            )
            : [];

        $cuentasAdministracion = $puedeGestionarCuentas &&
            $estructuraWhatsappDisponible
                ? $modelo->obtenerCuentas($usuarioId, true, false)
                : [];

        $usuariosWhatsapp = $puedeGestionarCuentas &&
            $estructuraWhatsappDisponible
                ? $modelo->obtenerUsuariosActivos()
                : [];

        $puedeEnviarWhatsapp = $estadoConfiguracionWhatsapp['lista'] && tienePermiso('whatsapp.enviar') && (!$conversacionSeleccionada ||
            (bool)$modelo->obtenerConversacion((int)$conversacionSeleccionada['id'],$usuarioId,tienePermiso('whatsapp.responder_todas')));
        $ventanaServicioAbierta = $conversacionSeleccionada
            ? $modelo->ventanaServicioAbierta($conversacionSeleccionada)
            : false;

        $cuentaChat=$conversacionSeleccionada?$modelo->obtenerCuentaPorPhoneNumberId((string)$conversacionSeleccionada['phone_number_id']):null;
        $puedePlantillaPrueba=$puedeEnviarWhatsapp && ($cuentaChat['tipo']??'')==='PRUEBA';
        $mensajeWhatsapp = $_SESSION['mensaje_whatsapp'] ?? '';
        $errorWhatsapp = $_SESSION['error_whatsapp'] ?? '';
        unset($_SESSION['mensaje_whatsapp'], $_SESSION['error_whatsapp']);

        $tituloPagina = 'Conversaciones';
        $subtituloPagina = 'WhatsApp Business integrado al Sistema Comercial';
        $opcionActiva = 'whatsapp';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/whatsapp/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function conversaciones()
    {
        $this->validarPermiso('whatsapp.ver');

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = WhatsAppContextoService::lecturaGlobal();

        $this->responder([
            'ok' => true,
            'conversaciones' => $modelo->obtenerConversaciones(
                $usuarioId,
                $gestionar
            )
        ]);
    }

    public function mensajes()
    {
        $this->validarPermiso('whatsapp.ver');

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = WhatsAppContextoService::lecturaGlobal();
        $conversacionId = (int)($_GET['conversacion_id'] ?? 0);
        $despuesDeId = (int)($_GET['despues_de_id'] ?? 0);

        $conversacion = $modelo->obtenerConversacion(
            $conversacionId,
            $usuarioId,
            $gestionar
        );

        if (!$conversacion) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a esta conversación.'
            ], 403);
        }

        $mensajes = $modelo->obtenerMensajes(
            $conversacionId,
            $usuarioId,
            $gestionar,
            $despuesDeId
        );
        $estadosSalida = $modelo->obtenerEstadosMensajesSalida(
            $conversacionId,
            $usuarioId,
            $gestionar
        );

        if($modelo->obtenerConversacion($conversacionId,$usuarioId,false))$modelo->marcarConversacionLeida($conversacionId);

        $this->responder([
            'ok' => true,
            'conversacion' => $conversacion,
            'ventana_servicio_abierta' =>
                $modelo->ventanaServicioAbierta($conversacion),
            'mensajes' => $mensajes,
            'estados_salida' => $estadosSalida
        ]);
    }

    public function enviar()
    {
        $this->validarPermiso('whatsapp.ver');
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = tienePermiso('whatsapp.responder_todas');
        $conversacionId = (int)($_POST['conversacion_id'] ?? 0);
        $texto = trim((string)($_POST['mensaje'] ?? ''));

        if ($texto === '' || mb_strlen($texto) > 4096) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Escribe un mensaje de entre 1 y 4096 caracteres.'
            ], 422);
        }

        $conversacion = $modelo->obtenerConversacion(
            $conversacionId,
            $usuarioId,
            $gestionar
        );

        if (!$conversacion) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a esta conversación.'
            ], 403);
        }

        if (!$modelo->ventanaServicioAbierta($conversacion)) {
            $this->responder([
                'ok' => false,
                'requiere_plantilla' => true,
                'mensaje' =>
                    'La ventana de atención está cerrada. Usa una plantilla aprobada para iniciar o reabrir la conversación.'
            ], 409);
        }

        $cuenta = $modelo->obtenerCuentaPorId(
            (int)$conversacion['cuenta_id'],
            $usuarioId,
            true
        );

        if (!$cuenta || (int)$cuenta['activo'] !== 1) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El canal de WhatsApp de esta conversación no está disponible.'
            ], 409);
        }

        try { WhatsAppContextoService::exigirSalida($modelo->conexionParaAutorizacion(),$conversacion); }
        catch(DomainException $e){$this->responder(['ok'=>false,'mensaje'=>$e->getMessage()],409);}

        $this->prepararEnvioIdempotente('enviar');
        $servicio = $this->servicioWhatsApp($modelo);
        $resultado = $servicio->enviarTexto(
            (string)$cuenta['phone_number_id'],
            (string)$conversacion['telefono_contacto'],
            $texto
        );

        try {
            $modelo->registrarMensajeSalida(
                $conversacionId,
                $usuarioId,
                $texto,
                $resultado,
                'TEXT'
            );
        } catch (Throwable $error) {
            error_log('[whatsapp_historial_salida] '.get_class($error));
            if(!empty($resultado['ok']))$this->responder(['ok'=>false,'aceptado_por_meta'=>true,'mensaje'=>'Meta aceptó el mensaje, pero no se pudo guardar el historial. No vuelvas a enviarlo; solicita revisión.','wamid'=>(string)($resultado['wamid']??'')],202);
        }

        if (empty($resultado['ok'])) {
            $this->responder([
                'ok' => false,
                'mensaje' => (string)(
                    $resultado['mensaje'] ??
                    'No fue posible enviar el mensaje.'
                )
            ], 502);
        }

        $this->responder([
            'ok' => true,
            'mensaje' => 'Mensaje enviado a WhatsApp.',
            'wamid' => (string)($resultado['wamid'] ?? '')
        ]);
    }

    public function enviarPlantillaPrueba()
    {
        $this->validarPermiso('whatsapp.ver');
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = tienePermiso('whatsapp.responder_todas');
        $conversacionId = (int)($_POST['conversacion_id'] ?? 0);

        $conversacion = $modelo->obtenerConversacion(
            $conversacionId,
            $usuarioId,
            $gestionar
        );

        if (!$conversacion) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a esta conversación.'
            ], 403);
        }

        $cuenta = $modelo->obtenerCuentaPorId(
            (int)$conversacion['cuenta_id'],
            $usuarioId,
            true
        );

        if (!$cuenta || (int)$cuenta['activo'] !== 1) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El canal de WhatsApp no está disponible.'
            ], 409);
        }

        if(($cuenta['tipo']??'')!=='PRUEBA')$this->responder(['ok'=>false,'mensaje'=>'La plantilla de prueba sólo está disponible en un canal de prueba.'],409);

        try { WhatsAppContextoService::exigirSalida($modelo->conexionParaAutorizacion(),$conversacion); }
        catch(DomainException $e){$this->responder(['ok'=>false,'mensaje'=>$e->getMessage()],409);}

        $this->prepararEnvioIdempotente('enviarPlantillaPrueba');
        $servicio = $this->servicioWhatsApp($modelo);
        $estadoConfig = $servicio->obtenerEstadoConfiguracion();
        $resultado = $servicio->enviarPlantillaPrueba(
            (string)$cuenta['phone_number_id'],
            (string)$conversacion['telefono_contacto']
        );

        $contenido = '[Plantilla] ' .
            (string)($estadoConfig['test_template'] ?? 'hello_world');

        try {
            $modelo->registrarMensajeSalida(
                $conversacionId,
                $usuarioId,
                $contenido,
                $resultado,
                'TEMPLATE'
            );
        } catch (Throwable $error) {
            error_log('[whatsapp_historial_plantilla] '.get_class($error));
            if(!empty($resultado['ok']))$this->responder(['ok'=>false,'aceptado_por_meta'=>true,'mensaje'=>'Meta aceptó la plantilla, pero no se pudo guardar el historial. No vuelvas a enviarla; solicita revisión.'],202);
        }

        if (empty($resultado['ok'])) {
            $this->responder([
                'ok' => false,
                'mensaje' => (string)(
                    $resultado['mensaje'] ??
                    'No fue posible enviar la plantilla.'
                )
            ], 502);
        }

        $this->responder([
            'ok' => true,
            'mensaje' => 'Plantilla de prueba enviada correctamente.'
        ]);
    }

    public function abrirAliado()
    {
        $this->validarPermiso('whatsapp.ver');
        http_response_code(400);
        exit('Abre la conversación desde el botón de WhatsApp de Aliados.');
    }

    public function crearConversacion()
    {
        $this->validarPermiso('whatsapp.ver');
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $puedeGestionarCuentas =
            tienePermiso('whatsapp.gestionar_cuentas');
        $cuentaId = (int)($_POST['cuenta_id'] ?? 0);
        $telefono = preg_replace(
            '/[^0-9]+/',
            '',
            (string)($_POST['telefono'] ?? '')
        );
        $nombreContacto = trim(
            (string)($_POST['nombre_contacto'] ?? '')
        );

        if (
            $telefono === '' ||
            strlen($telefono) < 7 ||
            strlen($telefono) > 15
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Indica un número válido con código de país, sin extensiones.'
            ], 422);
        }

        if (mb_strlen($nombreContacto) > 180) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El nombre del contacto es demasiado largo.'
            ], 422);
        }

        $cuenta = $modelo->obtenerCuentaPorId(
            $cuentaId,
            $usuarioId,
            $puedeGestionarCuentas
        );

        if (!$cuenta || (int)($cuenta['activo'] ?? 0) !== 1) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'No tienes acceso al canal de WhatsApp seleccionado.'
            ], 403);
        }

        $existente=$modelo->obtenerConversacionPorCuentaTelefono((int)$cuenta['id'],$telefono);
        if($existente && !$modelo->obtenerConversacion((int)$existente['id'],$usuarioId,tienePermiso('whatsapp.responder_todas'))) {
            $this->responder(['ok'=>false,'mensaje'=>'El contacto requiere revisión de asignación.'],403);
        }
        try {
            $conversacionId = $modelo->crearORecuperarConversacion(
                (int)$cuenta['id'],
                $telefono,
                $nombreContacto,
                $usuarioId,
                0
            );
        } catch (Throwable $error) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'No fue posible abrir la conversación: ' .
                    $error->getMessage()
            ], 409);
        }

        if ($conversacionId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible crear la conversación.'
            ], 500);
        }

        $this->responder([
            'ok' => true,
            'mensaje' => 'Conversación preparada.',
            'conversacion_id' => $conversacionId,
            'url' => BASE_URL .
                'index.php?controller=whatsapp&action=index&conversacion_id=' .
                $conversacionId
        ]);
    }

    public function probarCanal()
    {
        $this->validarPermiso('whatsapp.gestionar_cuentas');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $cuentaId = (int)($_POST['cuenta_id'] ?? 0);

        if ($cuentaId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona primero un canal guardado.'
            ], 422);
        }

        $cuenta = $modelo->obtenerCuentaPorId(
            $cuentaId,
            $usuarioId,
            true
        );

        if (!$cuenta) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible localizar el canal seleccionado.'
            ], 404);
        }

        $servicio = new WhatsAppCloudApiService($modelo->conexionParaAutorizacion());
        $resultado = $servicio->probarConexion(
            (string)($cuenta['phone_number_id'] ?? '')
        );

        if (empty($resultado['ok'])) {
            $this->responder([
                'ok' => false,
                'mensaje' => (string)(
                    $resultado['mensaje'] ??
                    'No fue posible validar el canal con Meta.'
                ),
                'codigo_meta' => (string)($resultado['codigo_meta'] ?? '')
            ], 502);
        }

        $nombre = trim((string)($resultado['verified_name'] ?? ''));
        $numero = trim((string)($resultado['display_phone_number'] ?? ''));
        $detalle = trim(
            ($nombre !== '' ? $nombre : (string)$cuenta['nombre']) .
            ($numero !== '' ? ' · ' . $numero : '')
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Conexión con Meta verificada' .
                ($detalle !== '' ? ': ' . $detalle : '.'),
            'canal' => [
                'phone_number_id' => (string)(
                    $resultado['phone_number_id'] ?? ''
                ),
                'numero' => $numero,
                'nombre_verificado' => $nombre,
                'graph_version' => (string)(
                    $resultado['graph_version'] ?? ''
                )
            ]
        ]);
    }

    public function guardarCuenta()
    {
        $this->validarPermiso('whatsapp.gestionar_cuentas');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $phoneNumberId = trim((string)($_POST['phone_number_id'] ?? ''));
        $numeroMostrado = trim((string)($_POST['numero_mostrado'] ?? ''));

        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Indica un nombre válido para el canal.'
            ], 422);
        }

        if (
            $phoneNumberId === '' ||
            mb_strlen($phoneNumberId) > 80 ||
            !preg_match('/^[A-Za-z0-9_-]+$/', $phoneNumberId)
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El Phone Number ID no tiene un formato válido.'
            ], 422);
        }

        if (
            $numeroMostrado === '' ||
            strlen(preg_replace('/[^0-9]+/', '', $numeroMostrado)) < 7
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Indica el número visible del canal.'
            ], 422);
        }

        try {
            $cuentaId = $modelo->guardarCuenta(
                [
                    'id' => (int)($_POST['cuenta_id'] ?? 0),
                    'nombre' => $nombre,
                    'phone_number_id' => $phoneNumberId,
                    'numero_mostrado' => $numeroMostrado,
                    'usuario_id' => (int)($_POST['usuario_id'] ?? 0),
                    'tipo' => $_POST['tipo'] ?? 'EMPRESARIAL',
                    'es_predeterminada' =>
                        (int)($_POST['es_predeterminada'] ?? 0) === 1,
                    'activo' => (int)($_POST['activo'] ?? 1) === 1 ? 1 : 0
                ],
                (int)($_SESSION['usuario_id'] ?? 0)
            );
        } catch (Throwable $error) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible guardar el canal: ' .
                    $error->getMessage()
            ], 409);
        }

        $this->responder([
            'ok' => true,
            'mensaje' => 'Canal de WhatsApp guardado correctamente.',
            'cuenta_id' => $cuentaId
        ]);
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            exit('Sesión no válida.');
        }

        if (!tienePermiso($codigo)) {
            http_response_code(403);
            exit('No tienes permiso para realizar esta operación.');
        }
    }

    private function validarMetodoPost()
    {
        $token=$_POST['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if (!is_string($token) || !is_string($_SESSION['csrf_token']??null) ||
            $token==='' || !hash_equals($_SESSION['csrf_token'],$token)) {
            $this->responder(['ok'=>false,'mensaje'=>'La sesión de seguridad cambió. Recarga la página.'],403);
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function validarEstructura(WhatsAppModel $modelo)
    {
        if (!$modelo->estructuraDisponible()) {
            $this->responder([
                'ok' => false,
                'mensaje' =>
                    'Falta aplicar la migración del módulo de WhatsApp.'
            ], 503);
        }
    }

    private function redirigirIndex()
    {
        header(
            'Location: ' . BASE_URL .
            'index.php?controller=whatsapp&action=index'
        );
        exit;
    }

    private function responder($datos, $codigoHttp = 200)
    {
        if($this->envioId!==null) {
            $_SESSION['whatsapp_envios'][$this->envioId]['respuesta']=$datos;
            $_SESSION['whatsapp_envios'][$this->envioId]['http']=(int)$codigoHttp;
        }
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
