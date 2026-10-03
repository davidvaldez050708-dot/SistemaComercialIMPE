<?php

require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../models/WhatsAppModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';
require_once __DIR__ . '/WhatsAppCloudApiService.php';

class AliadoDifusionService
{
    private $modelo;
    private $correo;
    private $rootPath;

    public function __construct()
    {
        $this->modelo = new AliadoModel();
        $this->correo = new CorreoSalidaInstitucionalService();
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function construirBorrador(array $aliado, array $convocatoria)
    {
        $contacto = trim((string)($aliado['contacto_nombre'] ?? ''));
        $institucion = trim((string)($aliado['nombre_entidad'] ?? ''));
        $titulo = trim((string)($convocatoria['titulo'] ?? 'Convocatoria'));
        $inicio = $this->fechaLegible($convocatoria['fecha_inicio'] ?? '');
        $termino = $this->fechaLegible($convocatoria['fecha_termino'] ?? '');

        $saludo = $contacto !== ''
            ? 'Buen día, ' . $contacto . ':'
            : 'Buen día:';

        $periodo = '';
        if ($inicio !== '' && $termino !== '') {
            $periodo = ' La convocatoria se encuentra vigente del ' .
                $inicio . ' al ' . $termino . '.';
        }

        $cuerpo = implode("\n", [
            $saludo,
            '',
            'Como institución aliada, queremos compartirle la convocatoria "' .
                $titulo . '".' . $periodo,
            '',
            'Adjuntamos la información disponible para su conocimiento y difusión interna.',
            '',
            'Si requiere orientación adicional, quedamos atentos para apoyarle.',
            '',
            'Saludos cordiales.'
        ]);

        return [
            'asunto' => 'Convocatoria para institución aliada - ' . $titulo,
            'cuerpo' => $cuerpo,
            'institucion' => $institucion
        ];
    }

    public function construirBorradorWhatsapp(
        array $aliado,
        array $convocatoria
    ) {
        $contacto = trim((string)($aliado['contacto_nombre'] ?? ''));
        $titulo = trim((string)($convocatoria['titulo'] ?? 'Convocatoria'));
        $inicio = $this->fechaLegible($convocatoria['fecha_inicio'] ?? '');
        $termino = $this->fechaLegible($convocatoria['fecha_termino'] ?? '');

        $saludo = $contacto !== ''
            ? 'Buen día, ' . $contacto . '.'
            : 'Buen día.';

        $lineas = [
            $saludo,
            '',
            'Queremos compartirle la convocatoria *' . $titulo . '*.'
        ];

        if ($inicio !== '' && $termino !== '') {
            $lineas[] = 'Vigencia: ' . $inicio . ' al ' . $termino . '.';
        }

        $lineas[] = '';
        $lineas[] =
            'La compartimos para su conocimiento y difusión interna.';
        $lineas[] =
            'Si requiere orientación adicional, quedamos atentos para apoyarle.';

        return mb_substr(implode("\n", $lineas), 0, 1024);
    }

    public function prepararWhatsapp($usuarioId, array $aliado)
    {
        $resultado = [
            'disponible' => false,
            'telefono' => '',
            'etiqueta' => '',
            'canal' => '',
            'ventana_abierta' => false,
            'ventana_hasta' => '',
            'conversacion_id' => 0,
            'url_conversacion' => '',
            'motivo' => ''
        ];

        if (!$this->modelo->consentimientoWhatsappDisponible()) {
            $resultado['motivo'] =
                'Falta preparar el consentimiento de WhatsApp para Aliados.';
            return $resultado;
        }

        $contacto = $this->seleccionarContactoWhatsapp($aliado);
        if (!$contacto) {
            $resultado['motivo'] =
                'El aliado no tiene un número de WhatsApp confirmado y autorizado.';
            return $resultado;
        }

        $resultado['telefono'] = (string)$contacto['numero'];
        $resultado['etiqueta'] = (string)($contacto['etiqueta'] ?? 'WhatsApp');

        $modeloWhatsapp = new WhatsAppModel();
        if (!$modeloWhatsapp->estructuraDisponible()) {
            $resultado['motivo'] =
                'El módulo de WhatsApp todavía no está preparado.';
            return $resultado;
        }

        $cuenta = $modeloWhatsapp->resolverCuentaUsuario((int)$usuarioId);
        if (!$cuenta) {
            $resultado['motivo'] =
                'No tienes un canal de WhatsApp asignado ni existe uno predeterminado.';
            return $resultado;
        }

        $resultado['canal'] = (string)($cuenta['nombre'] ?? 'WhatsApp');
        $resultado['disponible'] = true;
        $resultado['url_conversacion'] =
            BASE_URL .
            'index.php?controller=whatsapp&action=abrirAliado&seguimiento_id=' .
            (int)($aliado['seguimiento_id'] ?? 0);

        $conversacion = $modeloWhatsapp->obtenerConversacionPorCuentaTelefono(
            (int)$cuenta['id'],
            (string)$contacto['numero']
        );

        if ($conversacion) {
            $resultado['conversacion_id'] =
                (int)($conversacion['id'] ?? 0);
            $resultado['ventana_abierta'] =
                $modeloWhatsapp->ventanaServicioAbierta($conversacion);
            $resultado['ventana_hasta'] =
                (string)($conversacion['ventana_servicio_hasta'] ?? '');
            $resultado['url_conversacion'] =
                BASE_URL .
                'index.php?controller=whatsapp&action=index&conversacion_id=' .
                (int)$conversacion['id'];
        }

        return $resultado;
    }

    public function enviarWhatsapp(
        $usuarioId,
        $seguimientoId,
        $convocatoriaId,
        $mensaje,
        $confirmarReenvio = false,
        $esAdministrador = false
    ) {
        if (!$this->modelo->estructuraDisponible()) {
            return $this->error(
                'El módulo de Aliados todavía no está preparado.',
                500
            );
        }

        $aliado = $this->modelo->obtenerAliado(
            (int)$seguimientoId,
            (int)$usuarioId,
            (bool)$esAdministrador
        );

        if (!$aliado) {
            return $this->error('No tienes acceso a este aliado.', 403);
        }

        $convocatoria = $this->modelo->obtenerConvocatoriaAplicable(
            (int)$convocatoriaId,
            (int)$aliado['estado_id']
        );

        if (!$convocatoria) {
            return $this->error(
                'La convocatoria ya no está vigente o no corresponde al territorio del aliado.',
                409
            );
        }

        $mensaje = trim((string)$mensaje);
        if ($mensaje === '') {
            $mensaje = $this->construirBorradorWhatsapp(
                $aliado,
                $convocatoria
            );
        }

        if ($mensaje === '' || mb_strlen($mensaje) > 1024) {
            return $this->error(
                'El mensaje de WhatsApp debe tener entre 1 y 1024 caracteres.',
                422
            );
        }

        $ultimoEnvio = $this->modelo->obtenerUltimoEnvioExitoso(
            (int)$seguimientoId,
            (int)$convocatoriaId,
            'WHATSAPP'
        );

        if ($ultimoEnvio && !$confirmarReenvio) {
            $fecha = $this->fechaHoraLegible($ultimoEnvio['enviado_at'] ?? '');

            return [
                'ok' => false,
                'requiere_confirmacion' => true,
                'codigo_http' => 409,
                'mensaje' =>
                    'Esta convocatoria ya fue enviada por WhatsApp' .
                    ($fecha !== '' ? ' el ' . $fecha : '') .
                    '. Confirma si deseas enviarla nuevamente.'
            ];
        }

        $preparacion = $this->prepararWhatsapp(
            (int)$usuarioId,
            $aliado
        );

        if (empty($preparacion['disponible'])) {
            return $this->error(
                (string)(
                    $preparacion['motivo'] ??
                    'WhatsApp no está disponible para este aliado.'
                ),
                409
            );
        }

        if (empty($preparacion['ventana_abierta'])) {
            return [
                'ok' => false,
                'requiere_ventana' => true,
                'codigo_http' => 409,
                'mensaje' =>
                    'La ventana de atención de 24 horas está cerrada. Abre la conversación y usa una plantilla aprobada para iniciar el contacto; cuando el aliado responda podrás enviar la convocatoria.',
                'url_conversacion' =>
                    (string)($preparacion['url_conversacion'] ?? '')
            ];
        }

        $modeloWhatsapp = new WhatsAppModel();
        $cuenta = $modeloWhatsapp->resolverCuentaUsuario((int)$usuarioId);

        if (!$cuenta) {
            return $this->error(
                'No fue posible resolver el canal de WhatsApp.',
                409
            );
        }

        try {
            $conversacionId = $modeloWhatsapp->crearORecuperarConversacion(
                (int)$cuenta['id'],
                (string)$preparacion['telefono'],
                (string)(
                    $aliado['contacto_nombre'] ??
                    $aliado['nombre_entidad'] ??
                    'Aliado'
                ),
                (int)$usuarioId,
                (int)$seguimientoId
            );
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible preparar la conversación: ' .
                    $error->getMessage(),
                409
            );
        }

        $servicioWhatsapp = new WhatsAppCloudApiService();
        $imagen = $this->resolverImagenConvocatoria(
            (string)($convocatoria['imagen'] ?? '')
        );

        if ($imagen !== null) {
            $resultado = $servicioWhatsapp->enviarImagenLocal(
                (string)$cuenta['phone_number_id'],
                (string)$preparacion['telefono'],
                (string)$imagen['ruta'],
                $mensaje
            );
            $tipoMensaje = 'IMAGE';
        } else {
            $resultado = $servicioWhatsapp->enviarTexto(
                (string)$cuenta['phone_number_id'],
                (string)$preparacion['telefono'],
                $mensaje
            );
            $tipoMensaje = 'TEXT';
        }

        try {
            $modeloWhatsapp->registrarMensajeSalida(
                (int)$conversacionId,
                (int)$usuarioId,
                $mensaje,
                $resultado,
                $tipoMensaje
            );
        } catch (Throwable $error) {
            error_log(
                'WhatsApp convocatoria historial conversación: ' .
                $error->getMessage()
            );
        }

        $registro = [
            'seguimiento_id' => (int)$seguimientoId,
            'convocatoria_id' => (int)$convocatoriaId,
            'usuario_id' => (int)$usuarioId,
            'canal' => 'WHATSAPP',
            'destinatario' => (string)$preparacion['telefono'],
            'asunto' => '',
            'mensaje' => $mensaje,
            'convocatoria_titulo' => (string)$convocatoria['titulo'],
            'convocatoria_imagen' => (string)($convocatoria['imagen'] ?? ''),
            'convocatoria_fecha_inicio' =>
                (string)($convocatoria['fecha_inicio'] ?? ''),
            'convocatoria_fecha_termino' =>
                (string)($convocatoria['fecha_termino'] ?? ''),
            'estado_envio' => !empty($resultado['ok']) ? 'ENVIADO' : 'ERROR',
            'proveedor' => 'META_CLOUD_API',
            'error_detalle' => !empty($resultado['ok'])
                ? ''
                : mb_substr(
                    (string)($resultado['mensaje'] ?? 'Error de envío'),
                    0,
                    700
                )
        ];

        try {
            $registrado = $this->modelo->registrarEnvio($registro);
        } catch (Throwable $error) {
            $registrado = false;
            error_log(
                'Historial de difusión WhatsApp: ' . $error->getMessage()
            );
        }

        if (empty($resultado['ok'])) {
            return $this->error(
                (string)(
                    $resultado['mensaje'] ??
                    'No fue posible enviar la convocatoria por WhatsApp.'
                ),
                502
            );
        }

        if (!$registrado) {
            return $this->error(
                'WhatsApp aceptó el mensaje, pero no fue posible registrar la difusión en el historial. Revisa la conversación antes de intentar nuevamente.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' =>
                'Convocatoria enviada correctamente por WhatsApp.',
            'conversacion_id' => (int)$conversacionId,
            'url_conversacion' =>
                BASE_URL .
                'index.php?controller=whatsapp&action=index&conversacion_id=' .
                (int)$conversacionId
        ];
    }

    public function enviar(
        $usuarioId,
        $seguimientoId,
        $convocatoriaId,
        $asunto,
        $mensaje,
        $confirmarReenvio = false,
        $esAdministrador = false
    ) {
        if (!$this->modelo->estructuraDisponible()) {
            return $this->error(
                'El módulo de Aliados todavía no tiene aplicada su migración de base de datos.',
                500
            );
        }

        $aliado = $this->modelo->obtenerAliado(
            (int)$seguimientoId,
            (int)$usuarioId,
            (bool)$esAdministrador
        );

        if (!$aliado) {
            return $this->error('No tienes acceso a este aliado.', 403);
        }

        $convocatoria = $this->modelo->obtenerConvocatoriaAplicable(
            (int)$convocatoriaId,
            (int)$aliado['estado_id']
        );

        if (!$convocatoria) {
            return $this->error(
                'La convocatoria ya no está vigente o no corresponde al territorio del aliado.',
                409
            );
        }

        $destinatario = trim((string)($aliado['correo_contacto'] ?? ''));
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'Este aliado no tiene un correo válido registrado.',
                422
            );
        }

        $asunto = trim((string)$asunto);
        $mensaje = trim((string)$mensaje);

        if ($asunto === '' || $mensaje === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }

        if (mb_strlen($asunto) > 255 || mb_strlen($mensaje) > 20000) {
            return $this->error('El asunto o el mensaje supera el tamaño permitido.', 422);
        }

        $ultimoEnvio = $this->modelo->obtenerUltimoEnvioExitoso(
            (int)$seguimientoId,
            (int)$convocatoriaId,
            'CORREO'
        );

        if ($ultimoEnvio && !$confirmarReenvio) {
            $fecha = $this->fechaHoraLegible($ultimoEnvio['enviado_at'] ?? '');

            return [
                'ok' => false,
                'requiere_confirmacion' => true,
                'codigo_http' => 409,
                'mensaje' => 'Esta convocatoria ya fue enviada a la institución' .
                    ($fecha !== '' ? ' el ' . $fecha : '') .
                    '. Confirma si deseas enviarla nuevamente.'
            ];
        }

        $remitente = $this->modelo->obtenerUsuarioRemitente((int)$usuarioId);
        if (!$remitente) {
            return $this->error(
                'No fue posible obtener la cuenta institucional del remitente.',
                422
            );
        }

        $correoRemitente = trim((string)($remitente['correo'] ?? ''));
        if ($correoRemitente === '' || !filter_var($correoRemitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'Tu cuenta no tiene un correo institucional válido para realizar el envío.',
                422
            );
        }

        $nombreRemitente = trim(
            (string)($remitente['nombre'] ?? '') . ' ' .
            (string)($remitente['apellidos'] ?? '')
        );

        $imagenes = [];
        $htmlAdicional = $this->construirTarjetaHtml($convocatoria, false);
        $imagen = $this->resolverImagenConvocatoria(
            (string)($convocatoria['imagen'] ?? '')
        );

        if ($imagen !== null) {
            $imagenes[] = [
                'ruta' => $imagen['ruta'],
                'cid' => 'convocatoria-aliado',
                'nombre' => $imagen['nombre'],
                'mime' => $imagen['mime']
            ];
            $htmlAdicional = $this->construirTarjetaHtml($convocatoria, true);
        }

        $resultado = $this->correo->enviar([
            'remitente' => $correoRemitente,
            'nombre_remitente' => $nombreRemitente,
            'destinatario' => $destinatario,
            'nombre_destinatario' => (string)($aliado['contacto_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $mensaje,
            'imagenes_embebidas' => $imagenes,
            'html_adicional' => $htmlAdicional
        ]);

        $registro = [
            'seguimiento_id' => (int)$seguimientoId,
            'convocatoria_id' => (int)$convocatoriaId,
            'usuario_id' => (int)$usuarioId,
            'canal' => 'CORREO',
            'destinatario' => $destinatario,
            'asunto' => $asunto,
            'mensaje' => $mensaje,
            'convocatoria_titulo' => (string)$convocatoria['titulo'],
            'convocatoria_imagen' => (string)($convocatoria['imagen'] ?? ''),
            'convocatoria_fecha_inicio' => (string)($convocatoria['fecha_inicio'] ?? ''),
            'convocatoria_fecha_termino' => (string)($convocatoria['fecha_termino'] ?? ''),
            'estado_envio' => ($resultado['ok'] ?? false) ? 'ENVIADO' : 'ERROR',
            'proveedor' => (string)($resultado['proveedor'] ?? ''),
            'error_detalle' => ($resultado['ok'] ?? false)
                ? ''
                : mb_substr((string)($resultado['mensaje'] ?? 'Error de envío'), 0, 700)
        ];

        try {
            $registrado = $this->modelo->registrarEnvio($registro);
        } catch (Throwable $error) {
            $registrado = false;
            error_log('Historial de difusión de aliado: ' . $error->getMessage());
        }

        if (!($resultado['ok'] ?? false)) {
            return $resultado;
        }

        if (!$registrado) {
            return $this->error(
                'El correo fue aceptado por el proveedor, pero no fue posible registrar el envío en el historial. Revisa el correo enviado antes de intentar nuevamente.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => 'Convocatoria enviada correctamente por correo.',
            'proveedor' => (string)($resultado['proveedor'] ?? '')
        ];
    }

    private function seleccionarContactoWhatsapp(array $aliado)
    {
        $contactos = $this->modelo->obtenerContactosDifusion($aliado);
        $primeroAutorizado = null;

        foreach ($contactos as $contacto) {
            if (
                empty($contacto['confirmado_whatsapp']) ||
                empty($contacto['autorizado_whatsapp'])
            ) {
                continue;
            }

            if (!empty($contacto['preferido_difusion'])) {
                return $contacto;
            }

            if ($primeroAutorizado === null) {
                $primeroAutorizado = $contacto;
            }
        }

        return $primeroAutorizado;
    }

    private function construirTarjetaHtml(array $convocatoria, $incluirImagen)
    {
        $titulo = htmlspecialchars(
            (string)($convocatoria['titulo'] ?? 'Convocatoria'),
            ENT_QUOTES,
            'UTF-8'
        );
        $inicio = htmlspecialchars(
            $this->fechaLegible($convocatoria['fecha_inicio'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        $termino = htmlspecialchars(
            $this->fechaLegible($convocatoria['fecha_termino'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );

        $html = '<div style="margin-top:18px;padding:16px;border:1px solid #dfe5ef;border-radius:12px;background:#f8fafc;">';

        if ($incluirImagen) {
            $html .= '<img src="cid:convocatoria-aliado" alt="Convocatoria" style="display:block;width:100%;max-width:520px;height:auto;margin:0 0 14px;border-radius:10px;">';
        }

        $html .= '<div style="font-size:16px;font-weight:700;color:#172642;">' . $titulo . '</div>';

        if ($inicio !== '' && $termino !== '') {
            $html .= '<div style="margin-top:6px;font-size:13px;color:#64748b;">Vigencia: ' .
                $inicio . ' - ' . $termino . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function resolverImagenConvocatoria($rutaRelativa)
    {
        $rutaRelativa = ltrim(
            str_replace('\\', '/', trim((string)$rutaRelativa)),
            '/'
        );

        if (
            $rutaRelativa === '' ||
            strpos($rutaRelativa, '..') !== false ||
            strpos($rutaRelativa, 'public/uploads/convocatorias/') !== 0
        ) {
            return null;
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa);

        if (!is_file($ruta)) {
            return null;
        }

        $mime = function_exists('mime_content_type')
            ? (string)mime_content_type($ruta)
            : '';

        if (strpos($mime, 'image/') !== 0) {
            $mime = 'image/jpeg';
        }

        return [
            'ruta' => $ruta,
            'nombre' => basename($ruta),
            'mime' => $mime
        ];
    }

    private function fechaLegible($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y');
        } catch (Throwable $error) {
            return '';
        }
    }

    private function fechaHoraLegible($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return '';
        }
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
