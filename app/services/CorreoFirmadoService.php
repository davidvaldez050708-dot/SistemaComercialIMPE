<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';
require_once __DIR__ . '/AgendaReunionRepository.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReprogramacionReunionService.php';
require_once __DIR__ . '/EcardReunionService.php';

class CorreoFirmadoService
{
    private $connection;
    private $sender;
    private $agendaRepo;
    private $agendaService;
    private $reprogramacionService;
    private $ecardService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->sender = new CorreoSalidaInstitucionalService();
        $this->agendaRepo = new AgendaReunionRepository();
        $this->agendaService = new AgendaReunionService();
        $this->reprogramacionService = new ReprogramacionReunionService();
        $this->ecardService = new EcardReunionService($this->connection);
    }

    public function enviarSeguimiento($seguimientoId, $usuarioId, $asunto, $cuerpo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);

        $seguimiento = $this->obtenerSeguimiento($seguimientoId, $usuarioId);
        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }
        if (strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) === 'DESCARTADO') {
            return $this->error('Este seguimiento ya fue descartado.', 409);
        }
        if (trim((string)($seguimiento['respuesta_at'] ?? '')) === '') {
            return $this->error('Primero registra la respuesta de la institución.', 409);
        }
        if (trim((string)($seguimiento['reunion_agendada_at'] ?? '')) !== '') {
            return $this->error('La reunión ya fue formalmente agendada.', 409);
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El seguimiento no tiene un correo de contacto válido.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }

        $usuario = $this->obtenerUsuario($usuarioId);
        if (!$usuario) {
            return $this->error('No fue posible identificar al remitente.', 404);
        }

        $envio = $this->sender->enviar([
            'remitente' => (string)($usuario['correo'] ?? ''),
            'nombre_remitente' => $this->nombreUsuario($usuario),
            'destinatario' => $destinatario,
            'nombre_destinatario' => (string)($seguimiento['contacto_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $cuerpo
        ]);

        if (!($envio['ok'] ?? false)) {
            return $envio;
        }

        $notasPost = 'Asunto: ' . $asunto . "\nMensaje: " . $cuerpo;
        $notasInteraccion = "Seguimiento por correo enviado\n" .
            'Para: ' . $destinatario . "\n" .
            'Asunto: ' . $asunto . "\n\n" .
            $cuerpo;

        $this->connection->begin_transaction();
        try {
            $this->asegurarPostEnvio($seguimientoId);

            $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                        SET seguimiento_correo_notas = ?,
                            seguimiento_correo_at = NOW(),
                            seguimiento_correo_por = ?
                        WHERE seguimiento_id = ?";
            $stmtPost = $this->connection->prepare($sqlPost);
            $stmtPost->bind_param('sii', $notasPost, $usuarioId, $seguimientoId);
            $stmtPost->execute();

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                seguimiento_id,
                                usuario_id,
                                canal,
                                fecha_inicio,
                                resultado,
                                notas
                            ) VALUES (?, ?, 'CORREO', NOW(), 'CORREO_ENVIADO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notasInteraccion);
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW()
                               WHERE id = ? AND analista_id = ? AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Correo firmado enviado pero no registrado: ' . $error->getMessage());
            return $this->error(
                'El correo fue enviado, pero no fue posible registrarlo en el expediente. Revisa el correo enviado antes de volver a intentar.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => !empty($envio['firma_incluida'])
                ? 'Correo de seguimiento enviado y registrado con tu firma.'
                : 'Correo de seguimiento enviado y registrado correctamente.',
            'firma_incluida' => (bool)($envio['firma_incluida'] ?? false),
            'total_enviados' => $this->contarSeguimientosCorreo($seguimientoId)
        ];
    }

    public function enviarReunion($reunionId, $usuarioId, $asunto, $cuerpo, $esReprogramacion = false)
    {
        $reunionId = (int)$reunionId;
        $usuarioId = (int)$usuarioId;
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);

        $reunion = $this->agendaRepo->reunion(
            $reunionId,
            $usuarioId,
            AgendaReunionService::ROL_ANALISTA
        );

        if (!$reunion || (string)($reunion['estado'] ?? '') !== 'CONFIRMADA') {
            return $this->error('La reunión todavía no está lista para enviar la confirmación.', 409);
        }

        $destinatario = trim((string)($reunion['contacto_correo'] ?? ''));
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('La institución no tiene un correo de contacto válido.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            return $this->error('Revisa el asunto y el mensaje antes de enviar.', 422);
        }

        $usuario = $this->obtenerUsuario($usuarioId);
        if (!$usuario) {
            return $this->error('No fue posible identificar al remitente.', 404);
        }

        $ecard = $this->ecardService->generar($reunion);
        if (!($ecard['ok'] ?? false)) {
            return $this->error(
                (string)($ecard['mensaje'] ?? 'No fue posible generar la Ecard de la reunión.'),
                500
            );
        }

        $envio = $this->sender->enviar([
            'remitente' => (string)($usuario['correo'] ?? ''),
            'nombre_remitente' => $this->nombreUsuario($usuario),
            'destinatario' => $destinatario,
            'nombre_destinatario' => (string)($reunion['contacto_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
            'imagenes_embebidas' => [[
                'ruta' => (string)$ecard['ruta'],
                'cid' => (string)$ecard['cid'],
                'nombre' => (string)$ecard['archivo'],
                'mime' => (string)$ecard['mime']
            ]],
            'html_adicional' => $this->construirBloqueEcardCorreo($ecard, $reunion)
        ]);

        if (!($envio['ok'] ?? false)) {
            return $envio;
        }

        $datos = [
            'reunion_id' => $reunionId,
            'asunto' => $asunto,
            'cuerpo' => $cuerpo
        ];

        $resultado = $esReprogramacion || (int)($reunion['es_reprogramacion'] ?? 0) === 1
            ? $this->reprogramacionService->marcarCorreoAnalista(
                $usuarioId,
                AgendaReunionService::ROL_ANALISTA,
                $datos
            )
            : $this->agendaService->marcarCorreoEnviado(
                $usuarioId,
                AgendaReunionService::ROL_ANALISTA,
                $datos
            );

        if (!($resultado['ok'] ?? false)) {
            return $this->error(
                'El correo fue enviado, pero la agenda no pudo registrar el envío. No lo envíes nuevamente sin revisar primero el expediente.',
                500
            );
        }

        $resultado['mensaje'] = $esReprogramacion || (int)($reunion['es_reprogramacion'] ?? 0) === 1
            ? 'Correo de reprogramación y Ecard enviados. La nueva fecha quedó formalmente agendada.'
            : 'Correo de reunión y Ecard enviados. La reunión quedó formalmente agendada y el flujo avanzó al paso 12.';
        $resultado['firma_incluida'] = (bool)($envio['firma_incluida'] ?? false);
        $resultado['ecard_incluida'] = true;
        $resultado['ecard_template'] = (string)($ecard['template'] ?? 'manuel');
        $resultado['ecard_ponente'] = (string)($ecard['ponente'] ?? '');

        return $resultado;
    }

    private function construirBloqueEcardCorreo($ecard, $reunion)
    {
        $cid = htmlspecialchars(
            (string)($ecard['cid'] ?? EcardReunionService::CID),
            ENT_QUOTES,
            'UTF-8'
        );
        $ponente = htmlspecialchars(
            (string)($ecard['ponente'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        $enlace = trim((string)($reunion['zoom_url'] ?? ''));
        $html = '<div style="margin-top:24px;padding-top:18px;border-top:1px solid #e2e8f0;">';
        $html .= '<div style="margin-bottom:10px;font-size:12px;font-weight:700;color:#40516d;">';
        $html .= 'Ecard de reunión · ' . $ponente;
        $html .= '</div>';
        $html .= '<img src="cid:' . $cid . '" alt="Ecard de reunión" ';
        $html .= 'style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:10px;">';

        if ($enlace !== '' && filter_var($enlace, FILTER_VALIDATE_URL)) {
            $seguro = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
            $html .= '<div style="margin-top:16px;">';
            $html .= '<a href="' . $seguro . '" target="_blank" rel="noopener" ';
            $html .= 'style="display:inline-block;background:#062a4e;color:#ffffff;text-decoration:none;';
            $html .= 'font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;';
            $html .= 'padding:11px 18px;border-radius:7px;">Unirse a la reunión</a>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function obtenerSeguimiento($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    s.id,
                    s.analista_id,
                    s.nombre_entidad,
                    s.contacto_nombre,
                    s.estado_seguimiento,
                    COALESCE(
                        NULLIF(TRIM(s.correo_verificado), ''),
                        NULLIF(TRIM(s.correo_fuente), '')
                    ) AS destinatario_correo,
                    p.respuesta_at,
                    p.reunion_agendada_at
                FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                WHERE s.id = ?
                    AND s.analista_id = ?
                    AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function obtenerUsuario($usuarioId)
    {
        $modelo = new UsuarioModel();
        return $modelo->buscarPorId((int)$usuarioId) ?: null;
    }

    private function nombreUsuario($usuario)
    {
        return trim(
            (string)($usuario['nombre'] ?? '') . ' ' .
            (string)($usuario['apellidos'] ?? '')
        );
    }

    private function asegurarPostEnvio($seguimientoId)
    {
        $stmt = $this->connection->prepare(
            'INSERT IGNORE INTO seguimientos_vinculacion_post_envio (seguimiento_id) VALUES (?)'
        );
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
    }

    private function contarSeguimientosCorreo($seguimientoId)
    {
        $patron = 'Seguimiento por correo enviado%';
        $sql = "SELECT COUNT(*) AS total
                FROM interacciones_vinculacion
                WHERE seguimiento_id = ?
                  AND canal = 'CORREO'
                  AND resultado = 'CORREO_ENVIADO'
                  AND notas LIKE ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('is', $seguimientoId, $patron);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return (int)($fila['total'] ?? 0);
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
