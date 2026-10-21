<?php

require_once __DIR__ . '/CorreoEstadoPrivado.php';

/** Evidencia del transporte por registro, fuera de la BD y sin alterar históricos. */
final class CorreoRegistroEstadoService
{
    private static $reported = false;
    public static function guardar(int $user, int $id, string $status, array $result = []): void
    {
        if ($user <= 0 || $id <= 0 || !in_array($status, ['PENDIENTE', 'EN_PROCESO', 'ACEPTADO', 'FALLIDO', 'DESCONOCIDO'], true)) {
            throw new RuntimeException('MAIL_RECORD_STATE');
        }
        CorreoEstadoPrivado::ejecutar('mail-record', $user . ':' . $id, static function ($old, $save) use ($status, $result) {
            $save(['estado' => $status, 'at' => time(), 'proveedor' => (string)($result['proveedor'] ?? ''),
                'message_id' => (string)($result['message_id'] ?? ''), 'entrega_confirmada' => false,
                'remitente_visible' => (string)($result['remitente_visible'] ?? ''), 'reply_to' => (string)($result['reply_to'] ?? '')]);
        });
    }

    public static function consultar(int $user, int $id, string $dbStatus): array
    {
        try { $state = CorreoEstadoPrivado::leer('mail-record', $user . ':' . $id); }
        catch (Throwable $e) {
            if (!self::$reported) { error_log('MAIL_RECORD_READ_FAILED exception=' . get_class($e)); self::$reported = true; }
            $state = ['estado' => 'DESCONOCIDO'];
        }
        $status = $state['estado'] ?? (strtoupper($dbStatus) === 'ENVIADO' ? 'ACEPTADO' : 'PENDIENTE');
        $labels = ['PENDIENTE' => 'Pendiente', 'EN_PROCESO' => 'En proceso', 'ACEPTADO' => 'Aceptado por proveedor',
            'FALLIDO' => 'Fallido', 'DESCONOCIDO' => 'Resultado sin confirmar'];
        if (!isset($labels[$status])) { $status = 'DESCONOCIDO'; }
        $label = isset($state['estado']) ? $labels[$status] : null;
        if ($status === 'ACEPTADO' && strtoupper($dbStatus) !== 'ENVIADO') { $label = 'Aceptado; registro incompleto'; }
        return ['estado_envio' => $status, 'etiqueta_envio' => $label, 'entrega_confirmada' => false,
            'remitente_visible' => $state['remitente_visible'] ?? '', 'reply_to' => $state['reply_to'] ?? '',
            'registro_confirmado' => strtoupper($dbStatus) === 'ENVIADO'];
    }
}
