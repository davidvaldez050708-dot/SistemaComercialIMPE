<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
require_once __DIR__.'/TelefoniaContactosService.php';
/** Datos informados durante la atención; separados del resultado y sin fusión por número. */
final class TelefoniaProspectoLlamadaService
{
    private mysqli $db;
    public function __construct(mysqli $db){$this->db=$db;}
    private function tabla(): void{if(!$this->db->query("SHOW TABLES LIKE 'telefonia_ventas_prospecto_llamada'")->num_rows)throw new DomainException('Falta publicar la migración de datos del prospecto.');}
    public function leer(int $uid,string $ref,string $ext): array
    {
        $call=(new TelefoniaVentasLlamadasService($this->db))->obtener($uid,$ref,$ext);$this->tabla();
        $s=$this->db->prepare('SELECT version,datos_json FROM telefonia_ventas_prospecto_llamada WHERE usuario_id=? AND extension=? AND pbx_call_id=?');$s->bind_param('iss',$uid,$call['extension'],$ref);$s->execute();$row=$s->get_result()->fetch_assoc();
        return ['version'=>(int)($row['version']??0),'valores'=>$row?(json_decode($row['datos_json'],true)?:[]):['nombre'=>$call['nombre'],'telefono'=>$call['numero'],'correo'=>'','programa'=>'','notas'=>'']];
    }
    public function guardar(int $uid,string $ref,string $ext,array $values,int $version): array
    {
        $current=$this->leer($uid,$ref,$ext);$data=[];
        foreach(['nombre'=>150,'telefono'=>40,'correo'=>180,'programa'=>120,'notas'=>1000] as $key=>$limit){$v=$values[$key]??'';if(!is_string($v)||!preg_match('//u',$v)||mb_strlen($v)>$limit)throw new InvalidArgumentException('Revisa la longitud y formato de los datos del prospecto.');$data[$key]=trim($v);}
        if(mb_strlen($data['nombre'])<2)throw new InvalidArgumentException('Escribe el nombre completo del prospecto.');
        $data['telefono']=TelefoniaContactosService::normalizarTelefono($data['telefono']);
        if($data['correo']!==''&&!filter_var($data['correo'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Escribe un correo válido.');
        $this->db->begin_transaction();try{
            $s=$this->db->prepare("INSERT IGNORE INTO telefonia_ventas_prospecto_llamada(usuario_id,extension,pbx_call_id,datos_json) VALUES(?,?,?,'{}')");$s->bind_param('iss',$uid,$ext,$ref);$s->execute();
            $s=$this->db->prepare('SELECT version,datos_json FROM telefonia_ventas_prospecto_llamada WHERE usuario_id=? AND extension=? AND pbx_call_id=? FOR UPDATE');$s->bind_param('iss',$uid,$ext,$ref);$s->execute();$row=$s->get_result()->fetch_assoc();
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($json!==$row['datos_json']){
                if((int)$row['version']!==$version)throw new DomainException('Hay una captura más reciente. Recupera los datos antes de reemplazarlos.');
                $s=$this->db->prepare('UPDATE telefonia_ventas_prospecto_llamada SET datos_json=?,version=version+1 WHERE usuario_id=? AND extension=? AND pbx_call_id=?');$s->bind_param('siss',$json,$uid,$ext,$ref);$s->execute();
            }
            $this->db->commit();return $this->leer($uid,$ref,$ext);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
