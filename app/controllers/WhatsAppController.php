<?php

require_once __DIR__ . '/../models/WhatsAppModel.php';
require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../services/WhatsAppCloudApiService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class WhatsAppController
{
    public function index()
    {
        $this->validarPermiso('whatsapp.ver');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $puedeGestionarConversaciones =
            tienePermiso('whatsapp.gestionar_conversaciones');
        $puedeGestionarCuentas =
            tienePermiso('whatsapp.gestionar_cuentas');

        $modelo = new WhatsAppModel();
        $servicio = new WhatsAppCloudApiService();

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
                $modelo->marcarConversacionLeida($conversacionId);
                $conversacionSeleccionada['no_leidos'] = 0;
            }
        }

        if (!$conversacionSeleccionada && !empty($conversaciones)) {
            $conversacionSeleccionada = $conversaciones[0];
            $conversacionId = (int)$conversacionSeleccionada['id'];
            $mensajesIniciales = $modelo->obtenerMensajes(
                $conversacionId,
                $usuarioId,
                $puedeGestionarConversaciones
            );
            $modelo->marcarConversacionLeida($conversacionId);
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

        $puedeEnviarWhatsapp = tienePermiso('whatsapp.enviar');
        $ventanaServicioAbierta = $conversacionSeleccionada
            ? $modelo->ventanaServicioAbierta($conversacionSeleccionada)
            : false;

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
        $gestionar = tienePermiso('whatsapp.gestionar_conversaciones');

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
        $gestionar = tienePermiso('whatsapp.gestionar_conversaciones');
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

        $modelo->marcarConversacionLeida($conversacionId);

        $this->responder([
            'ok' => true,
            'conversacion' => $conversacion,
            'ventana_servicio_abierta' =>
                $modelo->ventanaServicioAbierta($conversacion),
            'mensajes' => $mensajes
        ]);
    }

    public function enviar()
    {
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = tienePermiso('whatsapp.gestionar_conversaciones');
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

        $servicio = new WhatsAppCloudApiService();
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
            error_log('WhatsApp historial salida: ' . $error->getMessage());
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
        $this->validarPermiso('whatsapp.enviar');
        $this->validarMetodoPost();

        $modelo = new WhatsAppModel();
        $this->validarEstructura($modelo);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $gestionar = tienePermiso('whatsapp.gestionar_conversaciones');
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

        $servicio = new WhatsAppCloudApiService();
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
            error_log('WhatsApp plantilla prueba: ' . $error->getMessage());
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

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;

        $modeloAliado = new AliadoModel();
        $aliado = $modeloAliado->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $_SESSION['error_whatsapp'] =
                'No tienes acceso al aliado seleccionado.';
            $this->redirigirIndex();
        }

        if (!$modeloAliado->consentimientoWhatsappDisponible()) {
            $_SESSION['error_whatsapp'] =
                'Falta aplicar la migración de consentimiento de WhatsApp para usar Aliados con este canal.';
            $this->redirigirIndex();
        }

        $numero = '';
        $contactos = $modeloAliado->obtenerContactosDifusion($aliado);

        foreach ($contactos as $contacto) {
            if (
                !empty($contacto['confirmado_whatsapp']) &&
                !empty($contacto['autorizado_whatsapp']) &&
                !empty($contacto['preferido_difusion'])
            ) {
                $numero = (string)$contacto['numero'];
                break;
            }
        }

        if ($numero === '') {
            foreach ($contactos as $contacto) {
                if (
                    !empty($contacto['confirmado_whatsapp']) &&
                    !empty($contacto['autorizado_whatsapp'])
                ) {
                    $numero = (string)$contacto['numero'];
                    break;
                }
            }
        }

        if ($numero === '') {
            $_SESSION['error_whatsapp'] =
                'El aliado no tiene un número con WhatsApp confirmado y autorización para recibir comunicaciones. Registra la autorización en Contactos de difusión.';
            $this->redirigirIndex();
        }

        $modelo = new WhatsAppModel();

        if (!$modelo->estructuraDisponible()) {
            $_SESSION['error_whatsapp'] =
                'Falta aplicar la migración de WhatsApp.';
            $this->redirigirIndex();
        }

        $cuenta = $modelo->resolverCuentaUsuario($usuarioId);

        if (!$cuenta) {
            $_SESSION['error_whatsapp'] =
                'No tienes un canal de WhatsApp asignado y no existe un canal predeterminado.';
            $this->redirigirIndex();
        }

        try {
            $conversacionId = $modelo->crearORecuperarConversacion(
                (int)$cuenta['id'],
                $numero,
                (string)(
                    $aliado['contacto_nombre'] ??
                    $aliado['nombre_entidad'] ??
                    'Aliado'
                ),
                $usuarioId,
                $seguimientoId
            );
        } catch (Throwable $error) {
            $_SESSION['error_whatsapp'] = $error->getMessage();
            $this->redirigirIndex();
        }

        header(
            'Location: ' . BASE_URL .
            'index.php?controller=whatsapp&action=index&conversacion_id=' .
            $conversacionId
        );
        exit;
    }

    public function crearConversacion()
    {
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
