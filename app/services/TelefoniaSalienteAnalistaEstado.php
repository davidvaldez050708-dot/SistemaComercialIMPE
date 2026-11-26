<?php
/** Sólo interpreta eventos ya autenticados de la centralita; no acepta metadata del formulario. */
final class TelefoniaSalienteAnalistaEstado
{
    /**
     * Reconciliación con estadísticas PBX consultadas por el servidor, sólo
     * cuando el webhook de fin falta. No simula NOTIFY_ANSWER ni grabaciones.
     * La referencia, extensión, destino y ventana temporal deben coincidir.
     */
    public static function desdeEstadistica(
        ?array $evento, array $stats, string $extension, string $referencia, int $desde
    ): ?array {
        if ($evento && ($evento['estado'] ?? '') === 'ended') return $evento;
        if (!hash_equals($referencia, (string)($stats['pbx_call_id'] ?? '')) ||
            ltrim((string)($stats['sip'] ?? ''), '0') !== ltrim($extension, '0')) return $evento;
        $inicio = strtotime((string)($stats['callstart'] ?? ''));
        if ($inicio === false || $inicio < $desde || $inicio > time() + 120) return $evento;
        $destino = preg_replace('/[^0-9]/', '', (string)($stats['destination'] ?? '')) ?: '';
        if (strlen($destino) < 8) return $evento;
        if ($evento) {
            $original = preg_replace('/[^0-9]/', '', (string)($evento['caller_id'] ?? '')) ?: '';
            if ($original === '' || substr($destino, -10) !== substr($original, -10)) return $evento;
            $inicioEvento = strtotime((string)($evento['call_start'] ?? ''));
            if ($inicioEvento !== false && abs($inicioEvento - $inicio) > 300) return $evento;
        }
        $resultado = strtolower(trim((string)($stats['disposition'] ?? '')));
        if (!in_array($resultado, [
            'answered','busy','no answer','no-answer','no_answer',
            'canceled','cancelled','cancel','failed'
        ], true)) return $evento;
        $segundos = max(0, (int)($stats['seconds'] ?? 0));
        if ($resultado === 'answered' && $segundos === 0) return $evento;
        $contestada = $resultado === 'answered';
        $saliente = $evento ?: [
            'pbx_call_id' => $referencia,
            'extension' => $extension,
            'caller_id' => (string)$stats['destination'],
            'call_start' => (string)$stats['callstart'],
            'answer_at' => '',
            'end_at' => '',
            'duration' => 0,
            'estado' => 'ringing',
            'contestada' => false,
            'tipo_llamada' => 'SALIENTE',
            'fecha_evento' => (string)$stats['callstart'],
            'origen_registro' => 'Llamada saliente de la extensión ' . $extension .
                ' confirmada mediante estadísticas de Zadarma.'
        ];
        $saliente['estado'] = 'ended';
        $saliente['contestada'] = $contestada;
        $saliente['duration'] = $contestada ? max($segundos, (int)($saliente['duration'] ?? 0)) : 0;
        $saliente['estadistica_solo'] = true;
        $saliente['estadistica_contestada'] = $contestada;
        // Una duración PBX no implica que se emitió NOTIFY_ANSWER firmado.
        // Su uso como verificación efectiva queda deshabilitado en el servicio.
        return $saliente;
    }

    public static function desdeEventos(array $eventos, string $extension, string $referencia, int $desde): ?array
    {
        $inicio = $respuesta = $fin = null;
        foreach ($eventos as $evento) {
            if (($evento['pbx_call_id'] ?? '') !== $referencia ||
                (string)($evento['internal'] ?? '') !== $extension) continue;
            switch ($evento['event'] ?? '') {
                case 'NOTIFY_OUT_START':
                    if (!$inicio && (strtotime((string)($evento['received_at'] ?? '')) ?: 0) >= $desde) $inicio = $evento;
                    break;
                case 'NOTIFY_ANSWER': $respuesta = $evento; break;
                case 'NOTIFY_OUT_END': $fin = $evento; break;
            }
        }
        if (!$inicio) return null;
        $segundos = $fin ? max(0, (int)($fin['duration'] ?? 0)) : 0;
        $contestada = $respuesta !== null || ($fin['disposition'] ?? '') === 'answered';
        return [
            'pbx_call_id'=>$referencia, 'extension'=>$extension,
            'caller_id'=>(string)($inicio['destination'] ?? ''),
            'call_start'=>(string)($inicio['call_start'] ?: $inicio['received_at']),
            'answer_at'=>$contestada ? (string)($respuesta['received_at'] ?? '') : '',
            'end_at'=>(string)($fin['received_at'] ?? ''),
            'duration'=>$contestada ? $segundos : 0,
            'estado'=>$fin ? 'ended' : ($contestada ? 'answered' : 'ringing'),
            'contestada'=>$contestada, 'tipo_llamada'=>'SALIENTE',
            'fecha_evento'=>(string)$inicio['received_at'],
            'origen_registro'=>'Llamada saliente desde el auricular de la extensión '.$extension.'.'
        ];
    }
}
