<?php

require_once __DIR__ . '/../services/TelefoniaExtensionService.php';
require_once __DIR__ . '/../services/TelefoniaActividadService.php';
require_once __DIR__ . '/../services/TelefoniaControlService.php';
require_once __DIR__ . '/../services/TelefoniaMarcadorPanelService.php';
require_once __DIR__ . '/../services/TelefoniaContactosService.php';
require_once __DIR__ . '/../services/TelefoniaResultadoVentasService.php';
require_once __DIR__ . '/../services/TelefoniaMarcacionesVentasService.php';
require_once __DIR__ . '/../models/RolModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class TelefoniaController
{
    private $service;

    public function __construct()
    {
        $modeloRol = new RolModel();
        $modeloRol->inicializarPermisosSistema();

        if (isset($_SESSION['rol_id'])) {
            $_SESSION['permisos'] =
                $modeloRol->obtenerCodigosPermisosPorRol(
                    (int)$_SESSION['rol_id']
                );
        }

        $this->service = new TelefoniaExtensionService();
    }

    /**
     * Centro de Control Telefónico: reportes y tendencias.
     * No consulta ni mezcla los formularios de extensiones.
     */
    public function index()
    {
        $this->validarAccesoControlLlamadas();

        $puedeAdministrarExtensiones = (int)($_SESSION['rol_id'] ?? 0) === 1
            && tienePermiso('telefonia.configurar');
        $actividadTelefonicaError = '';
        $controlTelefonia = [];
        $filtros = [
            'desde' => (string)($_GET['desde'] ?? date('Y-m-d', strtotime('-29 days'))),
            'hasta' => (string)($_GET['hasta'] ?? date('Y-m-d')),
            'rol' => (string)($_GET['rol'] ?? ''),
            'usuario_id' => (int)($_GET['usuario_id'] ?? 0),
            'direccion' => (string)($_GET['direccion'] ?? ''),
            'orden' => (string)($_GET['orden'] ?? '')
        ];

        try {
            $controlTelefonia = (new TelefoniaControlService())->consultar($filtros);
        } catch (InvalidArgumentException $e) {
            $actividadTelefonicaError = $e->getMessage();
            $controlTelefonia = (new TelefoniaControlService())->consultar();
        } catch (Throwable $e) {
            error_log('[telefonia_control_admin] ' . $e->getMessage());
            $actividadTelefonicaError =
                'No fue posible recuperar las estadísticas telefónicas. Verifica la conexión de Zadarma y los webhooks.';
            $controlTelefonia = [
                'stats' => ['llamadas_unicas'=>0, 'atenciones'=>0, 'conectadas'=>0,
                    'efectivas'=>0, 'segundos'=>0, 'sin_atribucion'=>0,
                    'por_rol'=>[], 'por_extension'=>[], 'por_usuario'=>[],
                    'historial'=>[], 'historial_total'=>0, 'tendencia'=>[]],
                'filtros' => ['desde'=>date('Y-m-d', strtotime('-29 days')),
                    'hasta'=>date('Y-m-d'), 'rol'=>'', 'usuario_id'=>0,
                    'direccion'=>'', 'orden'=>'atenciones'],
                'roles'=>[], 'usuarios'=>[]
            ];
        }

        $tituloPagina = 'Telefonía · Control de llamadas';
        $subtituloPagina = 'Supervisa llamadas, duración, resultados y desempeño del equipo';
        $opcionActiva = 'telefonia';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/telefonia/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    /**
     * Mis llamadas: punto de entrada personal a consulta, recepción,
     * registro de entrantes y devoluciones. Conserva los servicios y
     * formularios originales para no bifurcar la lógica de telefonía.
     */
    public function misLlamadas()
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        if ($uid <= 0 || !tienePermiso('telefonia.usar')) {
            http_response_code(403);
            exit('No tienes acceso al historial telefónico.');
        }

        if ((int)($_SESSION['rol_id']??0)===3) {
            $this->misLlamadasVentas($uid);return;
        }
        $rol = trim((string)($_SESSION['rol'] ?? ''));
        $puedeGestionAnalista = strcasecmp($rol, 'Analista de Datos') === 0
            && tienePermiso('seguimientos_vinculacion.operar_propios')
            && tienePermiso('telefonia.recibir');
        $puedeRecepcionMarketing = strcasecmp($rol, 'Marketing') === 0
            && tienePermiso('telefonia.recibir');
        if ($puedeRecepcionMarketing) {
            require_once __DIR__.'/../helpers/TelefoniaMarketingAccessHelper.php';
            $puedeRecepcionMarketing = marketingTieneRecepcionAsignada();
        }
        // Recepción es la operación principal del perfil Marketing.
        $vistaActual = (string)($_GET['vista']
            ?? ($puedeRecepcionMarketing ? 'recepcion' : 'historial'));
        $permitidas = ['historial'];
        if ($puedeGestionAnalista) {
            $permitidas[] = 'recibidas';
            $permitidas[] = 'devoluciones';
        }
        if ($puedeRecepcionMarketing) $permitidas[] = 'recepcion';
        if (!in_array($vistaActual, $permitidas, true)) {
            http_response_code(403);
            exit('No tienes permiso para consultar esta sección.');
        }

        $errorMisLlamadas = '';
        $desdeMisLlamadas = true;
        if ($vistaActual === 'historial') {
            require_once __DIR__ . '/../services/TelefoniaMisLlamadasService.php';
            try {
                $misLlamadas = (new TelefoniaMisLlamadasService())->consultar($uid, [
                    'desde' => $_GET['desde'] ?? date('Y-m-d', strtotime('-29 days')),
                    'hasta' => $_GET['hasta'] ?? date('Y-m-d'),
                    'direccion' => $_GET['direccion'] ?? '',
                    'pagina' => $_GET['pagina'] ?? 1
                ]);
            } catch (Throwable $error) {
                error_log('[telefonia_mis_llamadas] '.get_class($error).': '.$error->getMessage());
                $errorMisLlamadas = 'No fue posible consultar tus llamadas. Revisa el intervalo seleccionado.';
                $misLlamadas = [
                    'filas'=>[], 'total'=>0, 'conectadas'=>0, 'entrantes'=>0, 'salientes'=>0,
                    'pagina'=>1, 'paginas'=>1, 'por_pagina'=>20,
                    'filtros'=>['desde'=>date('Y-m-d', strtotime('-29 days')),
                        'hasta'=>date('Y-m-d'), 'direccion'=>''],
                    'limitacion'=>$errorMisLlamadas
                ];
            }
        } elseif ($vistaActual === 'recibidas') {
            require_once __DIR__ . '/../services/TelefoniaEntrantesAnalistaService.php';
            $bandejaEntrantes = ['extension' => '', 'llamadas' => []];
            $estadosEntrantes = [];
            $errorEntrantes = '';
            try {
                $servicio = new TelefoniaEntrantesAnalistaService();
                $bandejaEntrantes = $servicio->listar($uid);
                $estadosEntrantes = $servicio->estados($uid);
            } catch (Throwable $error) {
                error_log('[mis_llamadas_recibidas] '.$error->getMessage());
                $errorEntrantes = 'No fue posible consultar las llamadas recibidas. Comprueba tu extensión.';
            }
        } elseif ($vistaActual === 'devoluciones') {
            require_once __DIR__ . '/../services/TelefoniaDevolucionService.php';
            $devoluciones = [];
            $estadosEntrantes = [];
            try {
                $devoluciones = (new TelefoniaDevolucionService())->listar($uid);
                $estadosEntrantes = (new TelefoniaEntrantesAnalistaService())->estados($uid);
            } catch (Throwable $error) {
                error_log('[mis_llamadas_devoluciones] '.$error->getMessage());
                $errorMisLlamadas = 'No se pudieron consultar las devoluciones. Inténtalo nuevamente.';
            }
        }

        $tituloPagina = 'Mis llamadas';
        $subtituloPagina = 'Historial y gestiones telefónicas personales';
        $opcionActiva = 'mis_llamadas';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        require __DIR__ . '/../views/telefonia/mis_llamadas_navegacion.php';
        if ($errorMisLlamadas !== '') {
            echo '<div class="linkage-incoming-feedback" role="alert">'
                .htmlspecialchars($errorMisLlamadas, ENT_QUOTES, 'UTF-8').'</div>';
        }
        if ($vistaActual === 'recibidas') {
            require __DIR__ . '/../views/seguimiento_vinculacion/llamadas_entrantes.php';
        } elseif ($vistaActual === 'devoluciones') {
            require __DIR__ . '/../views/telefonia/devoluciones.php';
        } elseif ($vistaActual === 'recepcion') {
            require __DIR__ . '/../views/telefonia/recepcion_marketing.php';
        } else {
            require __DIR__ . '/../views/telefonia/mis_llamadas.php';
        }
        require __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function misLlamadasVentas(int $uid): void
    {
        require_once __DIR__.'/../services/TelefoniaVentasLlamadasService.php';
        $ventasBandeja=[];$contactosVentas=[];$devolucionesVentas=[];$errorMisLlamadas='';
        $esDevolucionVenta=($_GET['vista']??'')==='devoluciones';$esAgendaVenta=($_GET['vista']??'')==='agenda';$programacionesVentas=[];
        try{
            if($esAgendaVenta){require_once __DIR__.'/../services/TelefoniaProgramacionVentasService.php';$programacionesVentas=(new TelefoniaProgramacionVentasService())->listar($uid);}
            elseif($esDevolucionVenta){require_once __DIR__.'/../services/TelefoniaDevolucionVentasService.php';$cb=new TelefoniaDevolucionVentasService();$cb->reconciliar($uid);$devolucionesVentas=$cb->listar($uid);}
            else $ventasBandeja=(new TelefoniaVentasLlamadasService())->bandeja($uid,$_GET);
            $contactosVentas=(new TelefoniaContactosService())->listar($uid);
        }catch(Throwable $e){error_log('[ventas_bandeja] '.get_class($e));$errorMisLlamadas=$e instanceof DomainException?$e->getMessage():'No se pudo consultar la bandeja comercial. Inténtalo nuevamente.';}
        $tituloPagina='Mis llamadas';$subtituloPagina='Atención telefónica de prospectos';$opcionActiva='mis_llamadas';
        require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';require __DIR__.($esAgendaVenta?'/../views/telefonia/ventas_programadas.php':($esDevolucionVenta?'/../views/telefonia/ventas_devoluciones.php':'/../views/telefonia/ventas_llamadas.php'));
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    /**
     * Configuración PBX separada del reporte administrativo.
     * Conserva los formularios y modales de asignación existentes.
     */
    public function extensiones()
    {
        $this->validarAdministrador();

        $usuariosTelefonia = [];
        $resumenTelefonia = ['usuarios'=>0,'configurados'=>0,'activos'=>0,'pendientes'=>0];
        $mensajeExito = $_SESSION['mensaje_telefonia'] ?? '';
        $mensajeError = $_SESSION['error_telefonia'] ?? '';
        $datosFormulario = $_SESSION['datos_telefonia'] ?? [];
        unset($_SESSION['mensaje_telefonia'], $_SESSION['error_telefonia'], $_SESSION['datos_telefonia']);

        try {
            $usuariosTelefonia = $this->service->listarUsuariosConfigurables();
            $resumenTelefonia = $this->service->resumenConfiguracion($usuariosTelefonia);
        } catch (Throwable $e) {
            error_log('[telefonia_extensiones_admin] ' . $e->getMessage());
            $mensajeError = $mensajeError !== '' ? $mensajeError
                : 'No fue posible recuperar la configuración de extensiones.';
        }

        $tituloPagina = 'Telefonía · Extensiones';
        $subtituloPagina = 'Administra las extensiones y capacidades de los usuarios autorizados';
        $opcionActiva = 'telefonia';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/telefonia/extensiones.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function marcador()
    {
        // Ventas utiliza el teléfono desde Inicio. Los analistas solo
        // llaman desde Vinculación; ningún otro rol abre este módulo.
        if (
            (int)($_SESSION['usuario_id'] ?? 0) > 0 &&
            (int)($_SESSION['rol_id'] ?? 0) === 3
        ) {
            header('Location: ' . BASE_URL . 'index.php?controller=home&action=index');
            exit;
        }
        http_response_code(403);
        die('El marcador comercial está disponible exclusivamente en Inicio de Ventas.');
    }

    /**
     * Registra el origen comercial ANTES de enviar el número a WebRTC.
     * Se vincula después al pbx_call_id que confirma Zadarma.
     */
    public function iniciarMarcacionVenta()
    {
        $this->validarMarcadorPersonalJson();
        try {
            $token = (new TelefoniaMarcacionesVentasService())->iniciar(
                (int)$_SESSION['usuario_id'],
                (string)($_POST['destino'] ?? ''),
                (string)($_POST['token'] ?? '')
            );
            $this->responderJson(['ok' => true, 'token' => $token]);
        } catch (InvalidArgumentException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[telefonia_ventas_inicio] ' . $e->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No se pudo preparar la marcación comercial.'
            ], 500);
        }
    }

    public function vincularMarcacionVenta()
    {
        $this->validarMarcadorPersonalJson();
        try {
            (new TelefoniaMarcacionesVentasService())->vincular(
                (int)$_SESSION['usuario_id'],
                (string)($_POST['token'] ?? ''),
                (string)($_POST['pbx_call_id'] ?? '')
            );
            $this->responderJson(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            error_log('[telefonia_ventas_vinculo] ' . $e->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No se pudo asociar la llamada con el historial comercial.'
            ], 500);
        }
    }

    /**
     * Agenda personal del marcador. Nunca acepta usuario_id del formulario.
     */
    public function guardarContacto()
    {
        $this->validarAtencionVentaJson(true);

        try {
            $contacto = (new TelefoniaContactosService())->guardar(
                (int)$_SESSION['usuario_id'],
                (string)($_POST['nombre'] ?? ''),
                (string)($_POST['telefono'] ?? ''), $_POST
            );
            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Teléfono guardado en tu agenda.',
                'contacto' => $contacto
            ]);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Guardar teléfono personal: ' . $e->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar el teléfono. Inténtalo nuevamente.'
            ], 500);
        }
    }

    public function eliminarContacto()
    {
        $this->validarAtencionVentaJson(true);

        try {
            $eliminado = (new TelefoniaContactosService())->eliminar(
                (int)$_SESSION['usuario_id'],
                (int)($_POST['contacto_id'] ?? 0)
            );

            if (!$eliminado) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'El teléfono ya no existe en tu agenda.'
                ], 404);
            }

            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Teléfono eliminado de tu agenda.'
            ]);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Eliminar teléfono personal: ' . $e->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible eliminar el teléfono.'
            ], 500);
        }
    }

    /**
     * Clasificación posterior a la llamada. Nunca considera ANSWERED
     * prueba suficiente de conversación: el asesor decide el resultado.
     */
    public function guardarResultadoVenta()
    {
        $this->validarAtencionVentaJson(true);
        if ((int)($_SESSION['rol_id'] ?? 0) !== 3) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La clasificación de Ventas solo está disponible para asesores.'
            ], 403);
        }

        try {
            $resultado = (new TelefoniaResultadoVentasService())->guardar(
                (int)$_SESSION['usuario_id'],
                (string)($_POST['pbx_call_id'] ?? ''),
                (string)($_POST['resultado'] ?? ''),
                $_POST
            );
            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Resultado de la llamada registrado.',
                'registro' => $resultado
            ]);
        } catch (InvalidArgumentException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            $this->responderJson(['ok' => false, 'mensaje' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            error_log('[telefonia_ventas_resultado] ' . $e->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible registrar el resultado. Inténtalo nuevamente.'
            ], 500);
        }
    }

    public function detalleVenta()
    {
        $this->validarAtencionVentaJson(false);
        require_once __DIR__.'/../services/TelefoniaVentasLlamadasService.php';
        try {
            $svc=new TelefoniaVentasLlamadasService();$uid=(int)$_SESSION['usuario_id'];
            $svc->sincronizar($uid);
            $this->responderJson(['ok'=>true,'llamada'=>$svc->obtener($uid,
                (string)($_GET['pbx_call_id']??''),(string)($_GET['extension']??''))]);
        }catch(DomainException|InvalidArgumentException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[detalle_venta] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No se pudo consultar tu llamada.'],500);}
    }

    private function validarAtencionVentaJson(bool $post): void
    {
        if((int)($_SESSION['usuario_id']??0)<=0||(int)($_SESSION['rol_id']??0)!==3||
            !tienePermiso('telefonia.usar')||(!tienePermiso('telefonia.recibir')&&!tienePermiso('telefonia.salientes')))
            $this->responderJson(['ok'=>false,'mensaje'=>'No tienes acceso a la atención comercial.'],403);
        if(($_SERVER['REQUEST_METHOD']??'GET')!==($post?'POST':'GET'))$this->responderJson(['ok'=>false],405);
        $sent=(string)($_POST['csrf_token']??'');$general=(string)($_SESSION['csrf_token']??'');
        $valid=$general!==''&&$sent!==''&&hash_equals($general,$sent);
        if($post&&!$valid&&!TelefoniaContactosService::validarToken($sent))
            $this->responderJson(['ok'=>false,'mensaje'=>'El formulario caducó. Recarga la página.'],403);
    }

    private function validarMarcadorPersonalJson()
    {
        if (
            (int)($_SESSION['usuario_id'] ?? 0) <= 0 ||
            (int)($_SESSION['rol_id'] ?? 0) !== 3 ||
            !tienePermiso('telefonia.usar') ||
            !tienePermiso('telefonia.salientes')
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permisos para gestionar teléfonos.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        if (!TelefoniaContactosService::validarToken(
            (string)($_POST['csrf_token'] ?? '')
        )) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión del formulario caducó. Recarga la página.'
            ], 403);
        }
    }

    public function estadoUsuario()
    {
        $this->validarUsuarioTelefoniaJson();

        try {
            $asignacion = $this->service->resolverParaUsuario(
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            if (!$asignacion) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Tu usuario no tiene una extensión Zadarma activa asignada.'
                ], 422);
            }

            if (
                empty($asignacion['permite_salientes']) &&
                empty($asignacion['permite_entrantes'])
            ) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Tu extensión no tiene capacidades telefónicas habilitadas.'
                ], 403);
            }

            $this->responderJson([
                'ok' => true,
                'extension' => (string)($asignacion['extension'] ?? ''),
                'caller_id' => (string)($asignacion['caller_id'] ?? ''),
                'permite_salientes' => !empty($asignacion['permite_salientes']),
                'permite_entrantes' => !empty($asignacion['permite_entrantes']),
                'permite_transferir' =>
                    !empty($asignacion['permite_transferir']) &&
                    tienePermiso('telefonia.transferir'),
                'origen' => (string)($asignacion['origen'] ?? 'USUARIO')
            ]);
        } catch (Throwable $e) {
            error_log(
                'No fue posible consultar estado de telefonía: ' .
                $e->getMessage()
            );

            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible consultar tu configuración de telefonía.'
            ], 500);
        }
    }

    /**
     * Shell persistente para el CRM. El SDK WebRTC reside en un iframe hijo
     * de esta página, y las vistas PHP se navegan en otro iframe. Esto evita
     * ventanas adicionales y conserva la llamada al cambiar de módulo.
     */
    public function shell()
    {
        $this->validarUsuarioTelefoniaHtml();
        if (!empty($_SESSION['requiere_cambio_password'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=home&action=index');
            exit;
        }

        try {
            $asignacionTelefonica = $this->service->resolverParaUsuario(
                (int)($_SESSION['usuario_id'] ?? 0)
            );
        } catch (Throwable $error) {
            error_log('[Telefonia shell] ' . $error->getMessage());
            $asignacionTelefonica = null;
        }

        if (!$asignacionTelefonica || (
            empty($asignacionTelefonica['permite_entrantes']) &&
            empty($asignacionTelefonica['permite_salientes'])
        )) {
            header('Location: ' . BASE_URL . 'index.php?controller=home&action=index');
            exit;
        }

        // Seguridad UX: no entregar una capa sin estilos ni controles.
        // Si faltan archivos estáticos del despliegue, volver al CRM
        // tradicional en lugar de presentar un iframe de 300 x 150 px.
        foreach ([
            ROOT_PATH . '/public/css/telefonia_shell.css',
            ROOT_PATH . '/public/javascript/telefonia_shell.js',
            ROOT_PATH . '/public/javascript/telefonia_shell_navegacion.js'
        ] as $recursoShell) {
            if (!is_file($recursoShell) || !is_readable($recursoShell)) {
                error_log('[Telefonia shell] Recurso ausente: ' . basename($recursoShell));
                header('Location: ' . BASE_URL . 'index.php?controller=home&action=index');
                exit;
            }
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Frame-Options: SAMEORIGIN');
        require __DIR__ . '/../views/telefonia/shell.php';
    }

    public function host()
    {
        $this->validarUsuarioTelefoniaHtml();

        try {
            $asignacionTelefonica =
                $this->service->resolverParaUsuario(
                    (int)($_SESSION['usuario_id'] ?? 0)
                );

            if (
                !$asignacionTelefonica ||
                (
                    empty($asignacionTelefonica['permite_salientes']) &&
                    empty($asignacionTelefonica['permite_entrantes'])
                )
            ) {
                http_response_code(403);
                die(
                    'Tu usuario no tiene una extensión activa con capacidades telefónicas.'
                );
            }
        } catch (Throwable $e) {
            http_response_code(500);
            die('No fue posible preparar el motor de telefonía.');
        }

        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );
        header('Pragma: no-cache');

        require_once __DIR__ . '/../views/telefonia/host.php';
    }

    /**
     * Directorio de canalización: Recepción ve territorios y responsables;
     * Analistas y Asesores sólo ven su Cuenta Clave explícitamente asignado.
     */
    public function destinosTransferencia()
    {
        $this->validarUsuarioTelefoniaJson();
        if (!tienePermiso('telefonia.transferir')) {
            $this->responderJson(['ok'=>false,
                'mensaje'=>'No tienes permiso para transferir llamadas.'], 403);
        }
        try {
            require_once __DIR__ . '/../services/TelefoniaEnrutamientoService.php';
            $servicio = new TelefoniaEnrutamientoService();
            $usuarioId = (int)$_SESSION['usuario_id'];
            $rol = (string)($_SESSION['rol'] ?? '');
            $estadoId = filter_var($_GET['estado_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($estadoId === false || $estadoId < 0) $estadoId = 0;
            $tipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
            $buscar = mb_substr(trim((string)($_GET['buscar'] ?? '')), 0, 80, 'UTF-8');
            $categoria = TelefoniaEnrutamientoService::categoriaRol($rol);
            $destinos = $servicio->destinos($usuarioId, $rol, $estadoId, $tipo, $buscar);
            $instituciones = $categoria === 'RECEPCION' && $tipo === 'INSTITUCION'
                ? $servicio->instituciones($usuarioId, $rol, $estadoId, $buscar) : [];
            $telefonoOrigen = trim((string)($_GET['telefono_origen'] ?? ''));
            $identificacion = $categoria === 'RECEPCION' && $tipo === 'INSTITUCION'
                && $telefonoOrigen !== ''
                ? $servicio->identificarOrigen($usuarioId, $rol, $telefonoOrigen) : [];
            $this->responderJson([
                'ok'=>true,
                'categoria'=>$categoria,
                'destinos'=>$destinos,
                'estados'=>$categoria === 'RECEPCION' ? $servicio->estados() : [],
                'instituciones'=>$instituciones,
                'identificacion'=>$identificacion
            ]);
        } catch (InvalidArgumentException $e) {
            $this->responderJson(['ok'=>false, 'mensaje'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[telefonia_enrutamiento] '.$e->getMessage());
            $this->responderJson(['ok'=>false,
                'mensaje'=>'No se pudo consultar el directorio de transferencia.'], 500);
        }
    }

    /** Datos identificados por Recepción. Autorización por rol, usuario y extensión.
     * El contexto no certifica la identidad del llamante ni el evento PBX.
     */
    public function contextoTransferencia()
    {
        $this->validarUsuarioTelefoniaJson();
        if (!tienePermiso('telefonia.recibir')) {
            $this->responderJson(['ok'=>false,'mensaje'=>'Sin acceso.'],403);
        }
        try {
            require_once __DIR__ . '/../services/TelefoniaTransferenciaContextoService.php';
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
            $asignacion = $this->service->resolverParaUsuario($usuarioId);
            if (!$asignacion || empty($asignacion['permite_entrantes'])) {
                $this->responderJson(['ok'=>false,'mensaje'=>'Extensión no habilitada.'],403);
            }
            $numero = trim((string)($_GET['numero'] ?? ''));
            $referencia = trim((string)($_GET['referencia_llamada'] ?? ''));
            $contexto = (new TelefoniaTransferenciaContextoService())
                ->obtenerParaDestinatario($usuarioId, (string)$asignacion['extension'], $numero, $referencia);
            $this->responderJson(['ok'=>true,'contexto'=>$contexto]);
        } catch (Throwable $e) {
            error_log('[telefonia_contexto_consulta] ' . get_class($e));
            // El audio y los botones jamás dependen de metadatos opcionales.
            $this->responderJson(['ok'=>true,'contexto'=>null]);
        }
    }

    private function validarTransferenciaPost(): void
    {
        $this->validarUsuarioTelefoniaJson();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            $this->responderJson(['ok'=>false, 'mensaje'=>'Método no permitido.'], 405);
        }
        if (!tienePermiso('telefonia.transferir')) {
            $this->responderJson(['ok'=>false, 'mensaje'=>'Transferencia no autorizada.'], 403);
        }
        // El shell, el host WebRTC y el teléfono clásico comparten
        // $_SESSION['csrf_token']. La agenda personal utiliza OTRO token
        // (telefonia_contactos_csrf), que no debe validar transferencias.
        $csrfSesion = (string)($_SESSION['csrf_token'] ?? '');
        $csrfRecibido = (string)($_POST['csrf_token'] ?? '');
        if ($csrfSesion === '' || $csrfRecibido === '' ||
            !hash_equals($csrfSesion, $csrfRecibido)) {
            $this->responderJson(['ok'=>false, 'mensaje'=>'Sesión caducada. Recarga el CRM.'], 403);
        }
    }

    /**
     * Autorizar destino antes de generar una orden al WebRTC. No marca éxito
     * de PBX ni registra llamadas efectivas. La orden expira en 20 segundos.
     */
    public function autorizarTransferencia()
    {
        $this->validarTransferenciaPost();
        try {
            require_once __DIR__.'/../services/TelefoniaEnrutamientoService.php';
            require_once __DIR__.'/../services/TelefoniaDisponibilidadService.php';
            $usuarioId=(int)$_SESSION['usuario_id'];$rol=(string)($_SESSION['rol']??'');
            $disponibilidad=new TelefoniaDisponibilidadService();
            $solicitar=($_POST['intencion']??'')==='SOLICITAR';
            $id=(int)($_POST['disponibilidad_id']??0);$version=(int)($_POST['version']??0);
            $route=$solicitar?[
                'extension'=>trim((string)($_POST['extension']??'')),
                'estado_id'=>(int)($_POST['estado_id']??0),'tipo'=>strtoupper(trim((string)($_POST['tipo']??''))),
                'responsable_id'=>(int)($_POST['responsable_id']??0),
                'seguimiento_id'=>(int)($_POST['seguimiento_id']??0),
                'institucion_nombre'=>trim((string)($_POST['institucion_nombre']??'')),
                'numero_origen'=>trim((string)($_POST['numero_origen']??'')),
                'referencia_llamada'=>trim((string)($_POST['referencia_llamada']??'')),
                'modo'=>'DIRECTA'
            ]:$disponibilidad->rutaAceptada($usuarioId,$id,$version);
            $destino=(new TelefoniaEnrutamientoService())->validarDestino($usuarioId,$rol,
                (string)$route['extension'],(int)$route['estado_id'],(string)$route['tipo'],(int)$route['responsable_id']);
            if($solicitar){
                $route['nombre_destino']=(string)$destino['nombre'];
                $this->responderJson(['ok'=>true,'solicitud'=>$disponibilidad->solicitar($usuarioId,$route)]);
            }
            $token=bin2hex(random_bytes(24));
            $_SESSION['telefonia_transferencia_autorizada']=$route+[
                'usuario_id'=>$usuarioId,'disponibilidad_id'=>$id,'version'=>$version,
                'token_hash'=>hash('sha256',$token),'expires'=>time()+20
            ];
            $this->responderJson(['ok'=>true,'token'=>$token,'destino'=>$destino,
                'mensaje'=>'Responsable confirmado. La transferencia aún no se ha enviado.']);
        }catch(InvalidArgumentException|DomainException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[telefonia_disponibilidad_autorizar] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No fue posible solicitar la atención.'],500);}
    }

    public function disponibilidad()
    {
        $this->validarUsuarioTelefoniaJson();
        if(!tienePermiso('telefonia.recibir'))$this->responderJson(['ok'=>false],403);
        require_once __DIR__.'/../services/TelefoniaDisponibilidadService.php';
        try{
            $user=(int)$_SESSION['usuario_id'];$svc=new TelefoniaDisponibilidadService();
            if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
                $id=(int)($_GET['id']??0);
                $this->responderJson(['ok'=>true,'solicitud'=>$id?$svc->obtener($user,$id):$svc->entrante($user),'devoluciones'=>$id?null:count($svc->devoluciones($user))]);
            }
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$this->responderJson(['ok'=>false],405);
            $csrf=(string)($_SESSION['csrf_token']??'');
            if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf_token']??'')))$this->responderJson(['ok'=>false],403);
            if(in_array(($_POST['accion']??''),['PRESENCIA','POLL'],true)){
                $svc->presencia($user,($_POST['disponible']??'')==='1');
                if(($_POST['accion']??'')==='POLL')$this->responderJson(['ok'=>true,
                    'solicitud'=>(int)($_POST['id']??0)>0?$svc->obtener($user,(int)$_POST['id']):null,
                    'entrante'=>$svc->entrante($user),'devoluciones'=>count($svc->devoluciones($user))]);
                $this->responderJson(['ok'=>true]);
            }
            if(($_POST['accion']??'')==='ATENDIDA')$this->responderJson(['ok'=>false,'mensaje'=>'Registra el resultado desde Llamadas por devolver.'],409);
            $this->responderJson(['ok'=>true,'solicitud'=>$svc->accion($user,(int)($_POST['id']??0),(int)($_POST['version']??0),(string)($_POST['accion']??''),$_POST)]);
        }catch(DomainException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[telefonia_disponibilidad] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No se pudo actualizar la solicitud. Intenta de nuevo.'],500);}
    }

    public function completarProgramacionVenta(): void {
        $this->validarAtencionVentaJson(true);require_once __DIR__.'/../services/TelefoniaProgramacionVentasService.php';
        try{(new TelefoniaProgramacionVentasService())->completar((int)$_SESSION['usuario_id'],(string)($_POST['origen']??''),(int)($_POST['id']??0),(string)($_POST['fecha']??''));$this->responderJson(['ok'=>true]);}
        catch(DomainException|InvalidArgumentException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[ventas_programacion] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No se pudo cerrar el recordatorio.'],500);}
    }

    public function devolucionVenta(): void
    {
        $post=($_SERVER['REQUEST_METHOD']??'')==='POST';$this->validarAtencionVentaJson($post);
        require_once __DIR__.'/../services/TelefoniaDevolucionVentasService.php';
        try{
            $svc=new TelefoniaDevolucionVentasService();$uid=(int)$_SESSION['usuario_id'];
            if(!$post){$id=(int)($_GET['intento_id']??0);$this->responderJson($id?['ok'=>true,'intento'=>$svc->estado($uid,$id)]:['ok'=>true,'devoluciones'=>$svc->listar($uid)]);}
            $action=(string)($_POST['accion']??'');
            if($action==='PREPARAR'){$this->responderJson(['ok'=>true,'intento'=>$svc->preparar($uid,(int)($_POST['solicitud_id']??0))]);}
            if($action==='CANCELAR'){$svc->cancelar($uid,(int)($_POST['intento_id']??0));$this->responderJson(['ok'=>true]);}
            $this->responderJson(['ok'=>false,'mensaje'=>'Acción comercial no válida.'],422);
        }catch(DomainException|InvalidArgumentException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[ventas_devolucion] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No se pudo actualizar la devolución.'],500);}
    }

    public function devolucion()
    {
        if((int)($_SESSION['rol_id']??0)===3){$this->devolucionVenta();return;}
        $this->validarUsuarioTelefoniaJson();if(!tienePermiso('telefonia.recibir'))$this->responderJson(['ok'=>false],403);
        require_once __DIR__.'/../services/TelefoniaDevolucionService.php';
        try{
            $svc=new TelefoniaDevolucionService();$user=(int)$_SESSION['usuario_id'];
            if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
                $id=(int)($_GET['intento_id']??0);$this->responderJson($id?['ok'=>true,'intento'=>$svc->estado($user,$id)]:['ok'=>true,'devoluciones'=>$svc->listar($user)]);
            }
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$this->responderJson(['ok'=>false],405);
            $csrf=(string)($_SESSION['csrf_token']??'');if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf_token']??'')))$this->responderJson(['ok'=>false],403);
            $action=(string)($_POST['accion']??'');
            if($action==='PREPARAR'){
                if(!tienePermiso('telefonia.salientes'))$this->responderJson(['ok'=>false],403);
                $this->responderJson(['ok'=>true,'intento'=>$svc->preparar($user,(int)($_POST['solicitud_id']??0))]);
            }
            $id=(int)($_POST['intento_id']??0);
            if($action==='CANCELAR'){$svc->cancelar($user,$id);$this->responderJson(['ok'=>true]);}
            if($action!=='RESULTADO')$this->responderJson(['ok'=>false],422);
            $this->responderJson(['ok'=>true,'intento'=>$svc->registrar($user,$id,$_POST)]);
        }catch(DomainException|InvalidArgumentException $e){$this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
        catch(Throwable $e){error_log('[telefonia_devolucion] '.get_class($e));$this->responderJson(['ok'=>false,'mensaje'=>'No se pudo actualizar la devolución. Intenta nuevamente.'],500);}
    }

    public function devoluciones()
    {
        if((int)($_SESSION['rol_id']??0)===3){header('Location: '.BASE_URL.'index.php?controller=telefonia&action=misLlamadas&vista=devoluciones');exit;}
        $this->validarUsuarioTelefoniaJson();
        if(!tienePermiso('telefonia.recibir'))$this->responderJson(['ok'=>false],403);
        require_once __DIR__.'/../services/TelefoniaDisponibilidadService.php';
        $svc=new TelefoniaDisponibilidadService();$usuario=(int)$_SESSION['usuario_id'];
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            $csrf=(string)($_SESSION['csrf_token']??'');
            if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf_token']??'')))$this->responderJson(['ok'=>false],403);
            $_SESSION['telefonia_devolucion_aviso']=['mensaje'=>'Registra el resultado de la llamada para cerrar esta devolución.','error'=>true];
            header('Location: '.BASE_URL.'index.php?controller=telefonia&action=devoluciones');exit;
        }
        require_once __DIR__.'/../services/TelefoniaDevolucionService.php';
        $estadosEntrantes=(new TelefoniaEntrantesAnalistaService())->estados($usuario);
        $devoluciones=(new TelefoniaDevolucionService())->listar($usuario);$tituloPagina='Llamadas por devolver';$subtituloPagina='Seguimiento de contactos y resultados de devolución';$opcionActiva='seguimiento_vinculacion';
        require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';require __DIR__.'/../views/telefonia/devoluciones.php';
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    /**
     * El HOST WebRTC valida y consume la autorización de un solo uso
     * inmediatamente antes de intentar DTMF. Esto no equivale a confirmar
     * que Zadarma haya completado la transferencia.
     */
    public function validarOrdenTransferencia()
    {
        $this->validarTransferenciaPost();
        $data = $_SESSION['telefonia_transferencia_autorizada'] ?? null;
        unset($_SESSION['telefonia_transferencia_autorizada']);
        $token = trim((string)($_POST['token'] ?? ''));
        if (!is_array($data) || strlen($token) !== 48 ||
            time() > (int)($data['expires'] ?? 0) ||
            (int)($data['usuario_id'] ?? 0) !== (int)$_SESSION['usuario_id'] ||
            !hash_equals((string)($data['token_hash'] ?? ''), hash('sha256', $token))) {
            $this->responderJson(['ok'=>false,
                'mensaje'=>'La autorización de transferencia caducó o ya se utilizó.'], 403);
        }
        try {
            require_once __DIR__ . '/../services/TelefoniaEnrutamientoService.php';
            $destino = (new TelefoniaEnrutamientoService())->validarDestino(
                (int)$_SESSION['usuario_id'], (string)($_SESSION['rol'] ?? ''),
                (string)$data['extension'], (int)$data['estado_id'],
                (string)$data['tipo'], (int)$data['responsable_id']
            );
            if (($data['tipo'] ?? '') === 'INSTITUCION' &&
                (int)($data['seguimiento_id'] ?? 0) > 0) {
                try {
                    require_once __DIR__ . '/../services/TelefoniaTransferenciaContextoService.php';
                    $origen = $this->service->resolverParaUsuario((int)$_SESSION['usuario_id']);
                    $contextoGuardado = (new TelefoniaTransferenciaContextoService())->registrar(
                        (int)$_SESSION['usuario_id'], (string)($_SESSION['rol'] ?? ''),
                        (string)($origen['extension'] ?? ''),
                        (int)$destino['usuario_id'], (string)$destino['extension'],
                        (int)$destino['estado_id'], (int)$data['seguimiento_id'],
                        (string)($data['referencia_llamada'] ?? ''),
                        (string)($data['numero_origen'] ?? '')
                    );
                    if (!$contextoGuardado) {
                        error_log('[telefonia_contexto_guardar] Contexto institucional no confirmado.');
                        // No emitir orden DTMF cuando se perdería el expediente.
                        // La llamada original sigue activa en Recepción.
                        $this->responderJson([
                            'ok'=>false,
                            'mensaje'=>'No fue posible confirmar la institución para transferir. Verifica la llamada e inténtalo de nuevo.'
                        ], 409);
                    }
                } catch (Throwable $e) {
                    error_log('[telefonia_contexto_guardar] ' . get_class($e));
                    $this->responderJson([
                        'ok'=>false,
                        'mensaje'=>'No fue posible conservar la institución. La llamada no se transfirió; inténtalo de nuevo.'
                    ], 409);
                }
            }
            require_once __DIR__.'/../services/TelefoniaDisponibilidadService.php';
            $disponibilidad=new TelefoniaDisponibilidadService();
            $disponibilidad->rutaAceptada((int)$_SESSION['usuario_id'],(int)($data['disponibilidad_id']??0),(int)($data['version']??0));
            $disponibilidad->consumir((int)$_SESSION['usuario_id'],(int)$data['disponibilidad_id'],(int)$data['version'],(string)$data['referencia_llamada']);
            require_once __DIR__.'/../services/TelefoniaTransferenciaOperacionService.php';
            $operacion=(new TelefoniaTransferenciaOperacionService())->iniciar(
                (int)$_SESSION['usuario_id'],(string)$data['referencia_llamada'],
                (int)$destino['usuario_id'],(string)$destino['extension'],(string)$data['modo']
            );
            $this->responderJson([
                'ok'=>true,
                'extension'=>(string)$data['extension'],
                'modo'=>(string)$data['modo'],
                'operacion'=>$operacion
            ]);
        } catch (DomainException $e) {
            if(isset($disponibilidad))try{$disponibilidad->accion((int)$_SESSION['usuario_id'],(int)$data['disponibilidad_id'],(int)$data['version'],'FALLO');}catch(Throwable $ignored){}
            $this->responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);
        } catch (Throwable $e) {
            if(isset($disponibilidad))try{$disponibilidad->accion((int)$_SESSION['usuario_id'],(int)$data['disponibilidad_id'],(int)$data['version'],'FALLO');}catch(Throwable $ignored){}
            $this->responderJson(['ok'=>false,
                'mensaje'=>'El destino ya no está autorizado.'], 403);
        }
    }

    public function guardarExtension()
    {
        $this->validarAdministrador();
        $this->validarPost();

        $datos = [
            'usuario_id' =>
                (int)($_POST['usuario_id'] ?? 0),
            'extension' =>
                trim((string)($_POST['extension'] ?? '')),
            'caller_id' =>
                trim((string)($_POST['caller_id'] ?? '')),
            'permite_salientes' =>
                isset($_POST['permite_salientes']) ? 1 : 0,
            'permite_entrantes' =>
                isset($_POST['permite_entrantes']) ? 1 : 0,
            'activo' =>
                isset($_POST['activo']) ? 1 : 0,
        ];

        try {
            $this->service->guardarAsignacion(
                $datos['usuario_id'],
                $datos['extension'],
                $datos['caller_id'],
                $datos['permite_salientes'] === 1,
                $datos['permite_entrantes'] === 1,
                $datos['activo'] === 1,
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            $_SESSION['mensaje_telefonia'] =
                'Extensión telefónica guardada correctamente.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_telefonia'] = $e->getMessage();
            $_SESSION['datos_telefonia'] = $datos;
        } catch (Throwable $e) {
            error_log(
                'No fue posible guardar telefonía: ' .
                $e->getMessage()
            );
            $_SESSION['error_telefonia'] =
                'No fue posible guardar la extensión. Revisa los datos e intenta nuevamente.';
            $_SESSION['datos_telefonia'] = $datos;
        }

        $this->volver();
    }

    public function liberarExtension()
    {
        $this->validarAdministrador();
        $this->validarPost();

        $usuarioId = (int)($_POST['usuario_id'] ?? 0);

        try {
            $this->service->liberarAsignacion(
                $usuarioId,
                (int)($_SESSION['usuario_id'] ?? 0)
            );

            $_SESSION['mensaje_telefonia'] =
                'La extensión quedó libre para poder asignarla a otro usuario.';
        } catch (Throwable $e) {
            error_log(
                'No fue posible liberar telefonía: ' .
                $e->getMessage()
            );
            $_SESSION['error_telefonia'] =
                'No fue posible liberar la extensión seleccionada.';
        }

        $this->volver();
    }

    private function validarUsuarioTelefoniaJson()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if (
            $usuarioId <= 0 ||
            !tienePermiso('telefonia.usar')
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Tu perfil no tiene permiso para utilizar telefonía.'
            ], 403);
        }
    }

    private function validarUsuarioTelefoniaHtml()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if (
            $usuarioId <= 0 ||
            !tienePermiso('telefonia.usar')
        ) {
            http_response_code(403);
            die('Tu perfil no tiene permiso para utilizar telefonía.');
        }
    }

    private function responderJson(array $datos, $status = 200)
    {
        http_response_code((int)$status);
        header('Content-Type: application/json; charset=utf-8');
        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );

        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    private function validarAccesoControlLlamadas()
    {
        if ((int)($_SESSION['usuario_id'] ?? 0) > 0
            && tienePermiso('telefonia.control_ver')) {
            return;
        }
        http_response_code(403);
        $tituloPagina = 'Acceso restringido';
        $subtituloPagina = 'Control de llamadas';
        $opcionActiva = 'telefonia';
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        echo '<div class="dashboard-panel" role="alert" style="max-width:780px;margin:20px auto;'
            . 'padding:24px;border:1px solid #dce5f2;border-radius:14px;background:#fff">'
            . '<h2 style="font-size:18px;color:#233f7a;font-weight:800;margin:0 0 9px">'
            . '<i class="bi bi-shield-lock" aria-hidden="true"></i> Acceso restringido</h2>'
            . '<p style="color:#63738d;font-size:13px;line-height:1.7;margin:0">'
            . 'Tu perfil no cuenta con el permiso para consultar el Control de llamadas. '
            . 'Solicita la autorización desde Roles y permisos.</p></div>';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
        exit;
    }

    private function validarAdministrador()
    {
        if (
            (int)($_SESSION['rol_id'] ?? 0) !== 1 ||
            !tienePermiso('telefonia.configurar')
        ) {
            http_response_code(403);
            die('No tienes permiso para configurar la telefonía.');
        }
    }

    private function validarPost()
    {
        if (
            strtoupper(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
            ) !== 'POST'
        ) {
            http_response_code(405);
            die('Método no permitido.');
        }
    }

    private function volver()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=telefonia&action=extensiones'
        );
        exit;
    }
}
