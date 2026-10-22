<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';

/**
 * Una llamada transferida es un grupo PBX con varias grabaciones.
 * Los NOTIFY_RECORD firmados aportan identificadores, NO identifican la
 * extension responsable. Nunca se atribuyen audios por numero/fecha.
 */
final class TelefoniaGrabacionesTransferenciaVentasService
{
    public function __construct(private mysqli $db) {}

    /** Prueba pura de propiedad del grupo: eventos PBX firmados y respuesta 150.
     * No se usa teléfono, fecha ni una referencia de audio enviada por frontend. */
    public static function referenciasVerificadas(array $events,string $source,string $dest): array
    {
        if($source===$dest||!preg_match('/^[0-9]{3,6}$/D',$source)||
            !preg_match('/^[0-9]{3,6}$/D',$dest))
            throw new DomainException('ACCESO_NO_AUTORIZADO');
        $sourceStarted=false;$destAnswered=false;$otherParticipant=false;$refs=[];
        foreach($events as $event){
            $kind=(string)($event['evento']??'');
            $internal=trim((string)($event['internal']??''));
            // NOTIFY_RECORD normalmente carece de internal. Si el
            // proveedor lo incluyó, una tercera extensión es igualmente
            // evidencia contradictoria. last_internal también se verifica.
            $payload=json_decode((string)($event['payload_json']??''),true)?:[];
            $last=trim((string)($event['last_internal']??$payload['last_internal']??''));
            foreach([$internal,$last] as $member)
                if(preg_match('/^[0-9]{3,6}$/D',$member) &&
                    $member!==$source && $member!==$dest)
                    $otherParticipant=true;
            if($kind==='NOTIFY_OUT_START'&&$internal===$source)$sourceStarted=true;
            if($sourceStarted&&$kind==='NOTIFY_ANSWER'&&$internal===$dest)$destAnswered=true;
            if($kind!=='NOTIFY_RECORD')continue;
            $id=trim((string)($event['call_id_with_rec']??''));
            if(!ZadarmaPbxIdentifierHelper::callIdValido($id))continue;
            if(!isset($refs[$id]))$refs[$id]=['id'=>$id,'inicio'=>'','segundos'=>null,
                'extension'=>'','etiqueta'=>'Tramo PBX · participante por verificar'];
        }
        if(!$sourceStarted||!$destAnswered||$otherParticipant)
            throw new DomainException('ACCESO_NO_AUTORIZADO');
        if(count($refs)>8)throw new DomainException('TRAMO_PENDIENTE_VINCULACION');
        return $refs;
    }

    /** También permite conservar el servicio histórico del Analista. */
    public function operacionComercial(string $pbx,int $user,string $rol): bool
    {
        $prop=$rol==='origen'?'o.origen_usuario':'o.destino_usuario';
        $stmt=$this->db->prepare("SELECT o.id,d.ruta_json FROM telefonia_transferencia_operaciones o
            INNER JOIN telefonia_disponibilidad d
              ON d.referencia=BINARY o.referencia
             AND d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
             AND d.origen_extension=BINARY o.origen_extension
             AND d.destino_extension=BINARY o.destino_extension
            WHERE o.referencia=BINARY ? AND $prop=? AND o.estado='ENTREGADA'
              AND d.estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')
            LIMIT 2");
        $stmt->bind_param('si',$pbx,$user);$stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if(count($rows)!==1)return false;
        $route=json_decode((string)$rows[0]['ruta_json'],true)?:[];
        return ($route['tipo']??'')==='PROSPECTO';
    }

    /**
     * @return array{ok:bool,estado:string,disponible:bool,segmentos:int,segmentos_info:array,records:array}
     * No almacenar enlaces del proveedor; se vuelven a resolver por peticion.
     */
    public function resolver(string $pbx,int $user,string $rol,string $ext,
                             callable $record,?callable $stats=null): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$pbx)||
            !preg_match('/^[0-9]{3,6}$/D',$ext)||!in_array($rol,['origen','destino'],true))
            throw new DomainException('ACCESO_NO_AUTORIZADO');
        $owner=$rol==='origen'?'o.origen_usuario':'o.destino_usuario';
        $ownExtension=$rol==='origen'?'o.origen_extension':'o.destino_extension';
        $sql="SELECT o.*,d.ruta_json FROM telefonia_transferencia_operaciones o
            INNER JOIN telefonia_disponibilidad d
              ON d.referencia=BINARY o.referencia AND d.origen_usuario=o.origen_usuario
             AND d.destino_usuario=o.destino_usuario
             AND d.origen_extension=BINARY o.origen_extension
             AND d.destino_extension=BINARY o.destino_extension
            WHERE o.referencia=BINARY ? AND $owner=? AND $ownExtension=BINARY ?
              AND o.estado='ENTREGADA'
              AND d.estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')
            LIMIT 2";
        $s=$this->db->prepare($sql);$s->bind_param('sis',$pbx,$user,$ext);
        $s->execute();$ops=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($ops)!==1)throw new DomainException('ACCESO_NO_AUTORIZADO');
        $op=$ops[0];$route=json_decode((string)$op['ruta_json'],true)?:[];
        if(($route['tipo']??'')!=='PROSPECTO' ||
            (int)($route['responsable_id']??0)!==(int)$op['destino_usuario'] ||
            (string)($route['extension']??'')!==(string)$op['destino_extension'] ||
            (string)($route['referencia_llamada']??'')!==$pbx)
            throw new DomainException('ACCESO_NO_AUTORIZADO');
        $q=$this->db->prepare('SELECT id,evento,internal,call_id_with_rec,received_at,payload_json
            FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id');
        $q->bind_param('s',$pbx);$q->execute();
        $events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $source=(string)$op['origen_extension'];$dest=(string)$op['destino_extension'];
        $refs=self::referenciasVerificadas($events,$source,$dest);
        // Estadísticas oficiales: corroboran metadatos para un call_id ya
        // verificado, sin incorporar por proximidad otros audios ni alterar el
        // índice de reproducción estable durante el GET posterior.
        if($stats&&$refs){
            try{
                $date=new DateTimeImmutable((string)$events[0]['received_at'],new DateTimeZone('America/Mexico_City'));
                $rows=$stats($date->modify('-1 day')->format('Y-m-d H:i:s'),
                             $date->modify('+1 day')->format('Y-m-d H:i:s'));
                if(count($rows)<1000){
                    $seen=[];
                    foreach($rows as $entry){
                        if(!is_array($entry)||!hash_equals($pbx,(string)($entry['pbx_call_id']??'')))continue;
                        $id=ZadarmaPbxIdentifierHelper::callIdDeFila($entry);
                        if(!isset($refs[$id]))continue;
                        $sip=(string)($entry['sip']??'');
                        if(isset($seen[$id])){
                            // Filas estadísticas contradictorias desautorizan
                            // la atribución de extensión, no el RECORD firmado.
                            $refs[$id]['etiqueta']='Tramo PBX · identidad por verificar';
                            $refs[$id]['extension']='';$refs[$id]['segundos']=null;
                            continue;
                        }
                        $seen[$id]=true;
                        $refs[$id]['inicio']=(string)($entry['callstart']??'');
                        $seconds=filter_var($entry['seconds']??null,FILTER_VALIDATE_INT,
                            ['options'=>['min_range'=>0,'max_range'=>86400]]);
                        if($seconds!==false)$refs[$id]['segundos']=$seconds;
                        if($sip===$source||$sip===$dest){
                            $refs[$id]['extension']=$sip;
                            $refs[$id]['etiqueta']=$sip===$source?
                                'Recepción · Ext. '.$source:'Asesor · Ext. '.$dest;
                        }
                    }
                }
            }catch(Throwable $e){
                error_log('[ventas_tramos] Estadistica temporal '.substr(hash('sha256',$pbx),0,12));
            }
        }
        $result=['ok'=>true,'estado'=>'GRABACION_EN_PREPARACION','disponible'=>false,
            'segmentos'=>0,'segmentos_info'=>[],'records'=>[]];
        $available=0;$missing=0;
        foreach(array_values($refs) as $i=>$part){
            try{
                $audio=$record($part['id']);
                $link=(string)($audio['link']??'');
                if(!$link||!filter_var($link,FILTER_VALIDATE_URL)||
                    !str_starts_with(strtolower($link),'https://')){$missing++;continue;}
                $result['records'][$i]=$audio;
                $result['segmentos_info'][]=['indice'=>$i,'tramo'=>$i+1,
                    'etiqueta'=>$part['etiqueta'],'extension'=>$part['extension'],
                    'segundos'=>$part['segundos']];
                $available++;
            }catch(Throwable $e){$missing++;}
        }
        $result['segmentos']=$available;$result['disponible']=$available>0;
        $result['estado']=$available?($missing?'DISPONIBLE_PARCIAL':'DISPONIBLE'):
            ($refs?'GRABACION_EN_PREPARACION':'SIN_GRABACION_REPORTADA');
        return $result;
    }
}
