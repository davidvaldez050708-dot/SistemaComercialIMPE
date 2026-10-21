<?php

require_once __DIR__ . '/CorreoEstadoPrivado.php';

/** No reintenta automáticamente operaciones aceptadas o con resultado incierto. */
final class CorreoEnvioControl
{
    public static function ejecutar(string $key, string $fingerprint, callable $send, bool $explicit = true): array
    {
        return CorreoEstadoPrivado::ejecutar('send', $key, static function ($state, $save) use ($fingerprint, $send, $explicit) {
            if ($state && !hash_equals((string)($state['fingerprint'] ?? ''), $fingerprint)) {
                return ['ok' => false, 'codigo_http' => 409, 'mensaje' => 'La solicitud de envío cambió. Abre una nueva redacción antes de enviar.'];
            }
            if (in_array($state['status'] ?? '', ['sending', 'DESCONOCIDO'], true)) {
                return ['ok' => false, 'codigo_http' => 409, 'estado_entrega' => 'DESCONOCIDO',
                    'mensaje' => 'Hay un envío en curso o con resultado desconocido. Comprueba el proveedor y el historial antes de repetirlo.'];
            }
            if (($state['status'] ?? '') === 'ACEPTADO' && ($explicit || time() - (int)($state['time'] ?? 0) < 60)) {
                return ['ok' => false, 'codigo_http' => 409, 'estado_entrega' => 'ACEPTADO',
                    'mensaje' => 'El proveedor ya aceptó esta solicitud. No se enviará otra copia; consulta el historial.'];
            }
            $save(['fingerprint' => $fingerprint, 'status' => 'sending', 'time' => time()]);
            // Si el proceso termina o falla el guardado, persiste sending: no reenviar a ciegas.
            $result = $send();
            $status = $result['estado_entrega'] ?? (!empty($result['ok']) ? 'ACEPTADO' : 'RECHAZADO');
            $save(['fingerprint' => $fingerprint, 'status' => $status, 'time' => time(), 'message_id' => $result['message_id'] ?? '']);
            return $result;
        });
    }
}
