<?php

require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';

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
