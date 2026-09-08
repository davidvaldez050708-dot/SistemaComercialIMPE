<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';
require_once __DIR__ . '/AgendaReunionRepository.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReprogramacionReunionService.php';

class CorreoFirmadoService
{
    private $connection;
    private $sender;
    private $agendaRepo;
    private $agendaService;
    private $reprogramacionService;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->sender = new CorreoSalidaInstitucionalService();
        $this->agendaRepo = new AgendaReunionRepository();
        $this->agendaService = new AgendaReunionService();
        $this->reprogramacionService = new ReprogramacionReunionService();
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

        $envio = $this->sender->enviar([
            'remitente' => (string)($usuario['correo'] ?? ''),
            'nombre_remitente' => $this->nombreUsuario($usuario),
            'destinatario' => $destinatario,
            'nombre_destinatario' => (string)($reunion['contacto_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $cuerpo
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
            ? 'Correo de reprogramación enviado. La nueva fecha quedó formalmente agendada.'
            : 'Correo de reunión enviado. La reunión quedó formalmente agendada y el flujo avanzó al paso 12.';
        $resultado['firma_incluida'] = (bool)($envio['firma_incluida'] ?? false);

        return $resultado;
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
