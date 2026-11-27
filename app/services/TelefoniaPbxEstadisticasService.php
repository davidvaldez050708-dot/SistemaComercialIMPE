<?php
declare(strict_types=1);

/** Catálogo CDR común: ventanas en México, cuenta del proveedor explícita,
 * paginación acotada y nunca una decisión basada en páginas incompletas. */
final class TelefoniaPbxEstadisticasService
{
    private ?DateTimeZone $providerZone=null;
    private array $cache=[];
    public function __construct(private object $api) {}

    public static function fechaLocal(string $value): DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('America/Mexico_City'));
        if(!$date || $date->format('Y-m-d H:i:s')!==$value)
            throw new InvalidArgumentException('Fecha de estadísticas inválida.');
        return $date;
    }

    private function zonaProveedor(): DateTimeZone
    {
        if($this->providerZone!==null)return $this->providerZone;
        $data=json_decode((string)$this->api->call('/v1/info/timezone/',[],'get'),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data) || ($data['status']??'')!=='success' || !is_string($data['timezone']??null) || !is_string($data['datetime']??null))
            throw new RuntimeException('La zona horaria del proveedor no está confirmada.');
        $name=$data['timezone'];
        if(preg_match('/^UTC([+-])(\d{1,2})(?::([0-5]\d))?$/D',$name,$m)){
            $hours=(int)$m[2];$minutes=(int)($m[3]??0);
            if($hours>14 || ($hours===14&&$minutes!==0))throw new RuntimeException('Zona horaria del proveedor inválida.');
            $zone=new DateTimeZone(sprintf('%s%02d:%02d',$m[1],$hours,$minutes));
        }elseif($name==='UTC' || in_array($name,DateTimeZone::listIdentifiers(),true)){
            $zone=new DateTimeZone($name);
        }else throw new RuntimeException('Zona horaria del proveedor desconocida.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($data['datetime']??''),$zone);
        $stamp=filter_var($data['unixtime']??null,FILTER_VALIDATE_INT);
        if(!$date || $date->format('Y-m-d H:i:s')!==($data['datetime']??'') || $stamp===false || $date->getTimestamp()!==$stamp)
            throw new RuntimeException('El reloj y la zona del proveedor son incompatibles.');
        return $this->providerZone=$zone;
    }

    public function consultar(int $desde,int $hasta,?string $tipo=null): array
    {
        if($hasta<=$desde || $hasta-$desde>172800 || !in_array($tipo,[null,'in','out'],true))
            throw new InvalidArgumentException('Ventana de estadísticas PBX inválida.');
        $key=$desde.'|'.$hasta.'|'.$tipo;
        if(array_key_exists($key,$this->cache))return $this->cache[$key];
        $zone=$this->zonaProveedor();$local=new DateTimeZone('America/Mexico_City');
        $start=(new DateTimeImmutable('@'.$desde))->setTimezone($zone)->format('Y-m-d H:i:s');
        $end=(new DateTimeImmutable('@'.$hasta))->setTimezone($zone)->format('Y-m-d H:i:s');
        $params=['start'=>$start,'end'=>$end,'version'=>2,'limit'=>1000];
        if($tipo!==null)$params['call_type']=$tipo;
        require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
        $rows=[];$seen=[];
        // El transporte conserva el presupuesto compartido y el plazo total.
        // Tres páginas llenas no prueban completitud: devolver pendiente.
        for($page=0;$page<3;++$page){
            $data=ZadarmaPbxIdentifierHelper::decodificarEstadisticas((string)$this->api->call(
                '/v1/statistics/pbx/',$params+['skip'=>$page*1000],'get'));
            if(($data['status']??'')!=='success' || !is_array($data['stats']??null) || !array_is_list($data['stats']) || count($data['stats'])>1000 ||
                (isset($data['start'])&&$data['start']!==$start) || (isset($data['end'])&&$data['end']!==$end))
                throw new RuntimeException('Estadísticas PBX incompletas o fuera de la ventana solicitada.');
            foreach($data['stats'] as $row){
                if(!is_array($row))throw new RuntimeException('Fila CDR inválida.');
                $fingerprint=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
                if(isset($seen[$fingerprint])&&$seen[$fingerprint]!==$page)
                    throw new RuntimeException('La paginación CDR cambió o no avanzó. Se conserva pendiente.');
                $seen[$fingerprint]=$page;
                if(!is_string($row['callstart']??null))throw new RuntimeException('Falta la fecha CDR.');
                $raw=$row['callstart'];
                $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$zone);
                if(!$at || $at->format('Y-m-d H:i:s')!==$raw || $at->getTimestamp()<$desde || $at->getTimestamp()>$hasta)
                    throw new RuntimeException('Fecha CDR inválida o fuera de la ventana solicitada.');
                $row['callstart']=$at->setTimezone($local)->format('Y-m-d H:i:s');
                $rows[]=$row;
            }
            if(count($data['stats'])<1000)return $this->cache[$key]=$rows;
        }
        throw new RuntimeException('Estadísticas PBX truncadas: acota la ventana antes de verificar.');
    }
}
