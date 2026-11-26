<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';

/**
 * Sólo Analista: audios de una SALIENTE iniciada por Recepción, transferida
 * a su extensión y vinculada a una institución autorizada.
 *
 * Nunca requiere END del segundo tramo ni infiere autoría por teléfono/hora.
 * Las llamadas entrantes directas, individuales y Recepción -> Ventas no
 * utilizan esta clase.
 */
final class TelefoniaGrabacionesTransferenciaInstitucionService
{
    public function __construct(private mysqli $db) {}

    public function identidad(string $pbx,int $usuario,string $extension,int $seguimiento=0): ?array
    {
        if(!preg_match('/^out_[A-Fa-f0-9]{32,64}$/D',$pbx)||
            !preg_match('/^[0-9]{3,6}$/D',$extension)||$usuario<=0)return null;
        $q=$this->db->prepare("SELECT o.id,o.origen_extension,o.destino_extension,
            o.destino_usuario,o.referencia,d.ruta_json
            FROM telefonia_transferencia_operaciones o
            INNER JOIN telefonia_disponibilidad d
              ON d.referencia=BINARY o.referencia
             AND d.origen_usuario=o.origen_usuario
             AND d.destino_usuario=o.destino_usuario
             AND d.origen_extension=BINARY o.origen_extension
             AND d.destino_extension=BINARY o.destino_extension
            WHERE o.referencia=BINARY ? AND o.destino_usuario=?
              AND o.destino_extension=BINARY ? AND o.modo IN ('DIRECTA','CONSULTADA')
              AND (o.entregada_at IS NOT NULL OR
                   (o.estado='ENTREGADA' AND o.destino_atendida IS NOT NULL))
            LIMIT 2");
        $q->bind_param('sis',$pbx,$usuario,$extension);
        $q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if(count($rows)!==1)return null;
        $op=$rows[0];$route=json_decode((string)($op['ruta_json']??''),true);
        if(!is_array($route)||($route['tipo']??'')!=='INSTITUCION'||
            (int)($route['responsable_id']??0)!==$usuario||
            (string)($route['extension']??'')!==$extension||
            (string)($route['referencia_llamada']??'')!==$pbx||
            ($seguimiento>0&&(int)($route['seguimiento_id']??0)!==$seguimiento)||
            (string)$op['origen_extension']===$extension)return null;
        return ['pbx'=>$pbx,'origen'=>(string)$op['origen_extension'],
            'destino'=>$extension,'seguimiento_id'=>(int)($route['seguimiento_id']??0)];
    }

    /** Prueba pura de eventos firmados: conservar 2 RECORD aunque falte END 101. */
    public static function referencias(array $events,string $source,string $dest): array
    {
        if($source===$dest||!preg_match('/^[0-9]{3,6}$/D',$source)||
            !preg_match('/^[0-9]{3,6}$/D',$dest))throw new DomainException('TRAMO_NO_ACREDITADO');
        $started=false;$answered=false;$third=false;$ids=[];
        foreach($events as $event){
            $type=(string)($event['evento']??'');
            $internal=trim((string)($event['internal']??''));
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            $last=trim((string)($event['last_internal']??$payload['last_internal']??''));
            foreach([$internal,$last] as $member){
                if(preg_match('/^[0-9]{3,6}$/D',$member)&&
                    $member!==$source&&$member!==$dest)$third=true;
            }
            if($type==='NOTIFY_OUT_START'&&$internal===$source)$started=true;
            if($type==='NOTIFY_ANSWER'&&$internal===$dest&&$started)$answered=true;
            if($type!=='NOTIFY_RECORD')continue;
            $id=trim((string)($event['call_id_with_rec']??''));
            if(ZadarmaPbxIdentifierHelper::callIdValido($id))$ids[$id]=true;
        }
        if(!$started||!$answered||$third||count($ids)>8)
            throw new DomainException('TRAMO_NO_ACREDITADO');
        return array_keys($ids);
    }

    public function eventos(string $pbx): array
    {
        $q=$this->db->prepare('SELECT evento,internal,call_id_with_rec,payload_json,received_at
            FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();
        $events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        return $events;
    }

    public function verificar(string $pbx,int $usuario,string $extension,int $seguimiento=0): array
    {
        $op=$this->identidad($pbx,$usuario,$extension,$seguimiento);
        if(!$op)throw new DomainException('TRAMO_NO_ACREDITADO');
        $ids=self::referencias($this->eventos($pbx),$op['origen'],$op['destino']);
        return ['op'=>$op,'ids'=>$ids];
    }

    public function resolver(string $pbx,int $usuario,string $extension,int $seguimiento,
                             callable $record): array
    {
        $verified=$this->verificar($pbx,$usuario,$extension,$seguimiento);
        $ids=$verified['ids'];$records=[];$segments=[];
        foreach($ids as $i=>$id){
            try {
                $audio=$record($id);
                $link=(string)($audio['link']??'');
                if(!$link||!filter_var($link,FILTER_VALIDATE_URL)||
                    !str_starts_with(strtolower($link),'https://'))continue;
                $records[$i]=$audio;
                $segments[]=['indice'=>$i,'tramo'=>$i+1,
                    // NOTIFY_RECORD no certifica la extensión que produjo audio.
                    'etiqueta'=>'Tramo PBX '.($i+1).' · extensión por verificar',
                    'extension'=>'','segundos'=>null];
            }catch(Throwable $ignored){}
        }
        return ['ok'=>true,'disponible'=>(bool)$records,'segmentos'=>count($records),
            'segmentos_info'=>$segments,'estado'=>$records?
                (count($records)===count($ids)?'DISPONIBLE':'DISPONIBLE_PARCIAL'):
                ($ids?'GRABACION_EN_PREPARACION':'SIN_GRABACION_REPORTADA'),
            'records'=>$records];
    }
}
