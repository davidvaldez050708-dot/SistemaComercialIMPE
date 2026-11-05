<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaResultadoVentasService.php';

/**
 * Autoriza mostrar el botón de CONSULTA CDR en Supervisión sólo para una
 * saliente propia CONVERSADA, con resultado definitivo y sin cierre webhook.
 *
 * No concede permisos: el repositorio valida previamente el alcance del
 * supervisor y el servicio de audio comprueba de nuevo ese alcance.
 * No revela grabación; el tramo único se verifica en Zadarma al consultar.
 */
final class SupervisionVentasAudioCdrPolicy
{
    public static function candidata(array $call): bool
    {
        $ref=trim((string)($call['pbx_call_id']??''));
        $ext=trim((string)($call['extension']??''));
        return ($call['source']??'')==='venta'
            && ($call['area']??'')==='asesores'
            && ($call['direccion']??'')==='Saliente'
            && empty($call['finalizada'])
            && !empty($call['conectada'])
            && !empty($call['resultado_guardado'])
            && (int)($call['usuario_id']??0)>0
            && preg_match('/^out_[A-Za-z0-9_-]{6,100}$/D',$ref)===1
            && preg_match('/^[0-9]{3,6}$/D',$ext)===1
            && trim((string)($call['origen_extension']??''))===''
            && empty($call['solicitud_id'])
            && TelefoniaResultadoVentasService::esConversacion(
                (string)($call['resultado']??''));
    }

    public static function paraVerificar(array $call): array
    {
        // Registro humano ya validado como propiedad del asesor y de su PBX.
        // Sólo adaptar la representación para el verificador existente.
        return array_replace($call,[
            'registro'=>['resultado'=>(string)($call['resultado']??'')],
        ]);
    }
}
