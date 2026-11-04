<?php
/** Sólo interpreta eventos ya autenticados de la centralita; no acepta metadata del formulario. */
final class TelefoniaSalienteAnalistaEstado
{
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
