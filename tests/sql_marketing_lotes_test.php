<?php
// Clases instaladas/candidatas reales, con transporte MySQL sintético y reloj fijo.
const RELOJ_PRUEBA=1795608000;
date_default_timezone_set('America/Mexico_City');
function check($value,$message){if(!$value)throw new RuntimeException($message);}
class ResultadoSqlPrueba {
    public $num_rows;private $rows;private $i=0;
    function __construct($rows){$this->rows=array_values($rows);$this->num_rows=count($rows);}
    function fetch_assoc(){return $this->rows[$this->i++]??null;}
    function fetch_all($mode=0){return array_slice($this->rows,$this->i);}
}
class ConsultaSqlPrueba {
    public $affected_rows=0;private $db,$sql,$args=[],$rows=[];
    function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
    function bind_param($types,&...$args){$this->args=$args;}
    function execute(){$this->rows=$this->db->execute($this->sql,$this->args);return true;}
    function get_result(){return new ResultadoSqlPrueba($this->rows);}
    function close(){}
}
class ConexionSqlPrueba {
    public $rows=[],$events=[],$queries=0,$updates=0,$locks=0,$begins=0,$exclusions=[],$hasTable=true,$onLock=null,$failBatch=false;
    function __construct($rows=[],$events=[]){foreach($rows as $r)$this->rows[$r['id']]=$r;$this->events=$events;}
    function prepare($sql){return new ConsultaSqlPrueba($this,$sql);}
    function query($sql){$this->queries++;if(str_contains($sql,'information_schema.TABLES'))return new ResultadoSqlPrueba($this->hasTable?[['existe'=>1]]:[]);throw new RuntimeException('SQL inesperado: '.$sql);}
    function begin_transaction(){$this->queries++;$this->begins++;return true;}
    function commit(){$this->queries++;return true;}
    function rollback(){$this->queries++;return true;}
    function execute($sql,$args){
        $this->queries++;
        if(str_contains($sql,'FROM telefonia_transferencia_operaciones')&&str_contains($sql,'AND id IN (')){
            return array_values(array_filter($this->rows,fn($r)=>((int)$r['origen_usuario']===(int)$args[0]||(int)$r['destino_usuario']===(int)$args[1])&&in_array((int)$r['id'],array_map('intval',array_slice($args,2)),true)));
        }
        if(str_starts_with($sql,'SELECT * FROM telefonia_transferencia_operaciones WHERE (origen_usuario=')){
            $rows=array_filter($this->rows,fn($r)=>((int)$r['origen_usuario']===(int)$args[0]||(int)$r['destino_usuario']===(int)$args[1])&&$r['created_at']>=$args[2]);
            usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);return array_slice($rows,0,100);
        }
        if(str_contains($sql,'FOR UPDATE')){
            $this->locks++;if($this->onLock)($this->onLock)($this,(int)$args[0]);return isset($this->rows[$args[0]])?[$this->rows[$args[0]]]:[];
        }
        if(str_contains($sql,'FROM telefonia_zadarma_eventos')){
            $batch=str_contains($sql,'WHERE (pbx_call_id=');
            if($batch&&$this->failBatch)throw new RuntimeException('Fallo de lectura sintético');
            $rows=array_filter($this->events,function($e)use($args){for($i=0;$i<count($args);$i+=2)if(strcasecmp($e['pbx_call_id'],(string)$args[$i])===0 && $e['id']>(int)$args[$i+1])return true;return false;});
            usort($rows,fn($a,$b)=>$a['id']<=>$b['id']);return $rows;
        }
        if(str_starts_with($sql,'UPDATE telefonia_transferencia_operaciones SET ')){
            $this->updates++;$id=(int)end($args);preg_match('/SET (.+),updated_at=\? WHERE id=\?/',$sql,$m);
            foreach(explode(',',$m[1]) as $i=>$assignment){$key=explode('=',$assignment)[0];$this->rows[$id][$key]=$args[$i];}
            $this->rows[$id]['updated_at']=$args[count($args)-2];return [];
        }
        if(str_contains($sql,'FROM telefonia_crm_exclusiones')){
            $batch=str_starts_with($sql,'SELECT pbx_call_id');$ext=(string)end($args);$refs=$batch?array_slice($args,0,-1):[$args[0]];
            $rows=[];foreach($this->exclusions as $e)if(in_array($e['pbx_call_id'],$refs,true)&&($e['extension']===''||$e['extension']===$ext))$rows[]=$batch?['pbx_call_id'=>$e['pbx_call_id']]:['existe'=>1];return $rows;
        }
        throw new RuntimeException('Consulta no prevista: '.$sql);
    }
}
function cargarClase($namespace,$path,$clase){
    $source=file_get_contents($path);$source=substr($source,strpos($source,'final class '.$clase));
    eval('namespace '.$namespace.';use \\DateTimeImmutable;use \\DateTimeZone;use \\Throwable;use \\DomainException;use \\LogicException;'.$source);
}
$root=dirname(__DIR__);$base=$root.'/tests/fixtures/sql_marketing_20261125';
foreach(['Anterior'=>$base,'Candidato'=>$root] as $ns=>$dir){
    eval('namespace '.$ns.';class mysqli extends \\ConexionSqlPrueba{} function time(){return \\RELOJ_PRUEBA;} function gmdate($format,$t=null){return \\gmdate($format,$t??\\RELOJ_PRUEBA);}');
    cargarClase($ns,$dir.'/app/services/TelefoniaTransferenciaOperacionService.php','TelefoniaTransferenciaOperacionService');
    cargarClase($ns,$dir.'/app/services/TelefoniaLimpiezaGuard.php','TelefoniaLimpiezaGuard');
}
function rowPrueba($id,$extra=[]){return array_merge(['id'=>$id,'origen_usuario'=>30,'destino_usuario'=>40,'referencia'=>'out_'.str_pad((string)$id,32,'0',STR_PAD_LEFT),'evento_desde'=>0,'origen_extension'=>'100','destino_extension'=>'161','modo'=>'DIRECTA','estado'=>'ENTREGADA','destino_atendida'=>'2026-11-25 19:00:00','destino_fin'=>'2026-11-25 19:01:00','entregada_at'=>null,'created_at'=>'2026-11-24 10:00:00','updated_at'=>'2026-11-24 10:00:00','intento'=>1],$extra);}
function eventosPrueba($r,$answered=true){return [
 ['id'=>$r['id']*100+1,'pbx_call_id'=>$r['referencia'],'evento'=>'NOTIFY_ANSWER','internal'=>'161','disposition'=>'','duration'=>0,'received_at'=>'2026-11-25 13:00:00','payload_json'=>'{}'],
 ['id'=>$r['id']*100+2,'pbx_call_id'=>$r['referencia'],'evento'=>'NOTIFY_END','internal'=>'161','disposition'=>$answered?'answered':'no answer','duration'=>$answered?60:0,'received_at'=>'2026-11-25 13:01:00','payload_json'=>'{"last_internal":"161"}']
];}
function comparar($rows,$events,$setup=null){
 $a=new Anterior\mysqli($rows,$events);$b=new Candidato\mysqli($rows,$events);if($setup){$setup($a);$setup($b);}
 $before=(new Anterior\TelefoniaTransferenciaOperacionService($a))->delUsuario(30);
 $after=(new Candidato\TelefoniaTransferenciaOperacionService($b))->delUsuarioParaAvisos(30);
 check($before===$after,'Resultado del lote difiere del instalado');
 return [$a,$b,$after];
}
$rows=[];$events=[];for($i=1;$i<=100;$i++){$r=rowPrueba($i);$rows[]=$r;array_push($events,...eventosPrueba($r));}
[$a,$b]=comparar($rows,$events);check($a->queries===501&&$b->queries===2,'Debe eliminar transacciones redundantes del historial');check($b->updates===0&&$b->locks===0,'No escribir transferencias sin cambios');
echo "PASS: 100 transferencias sin cambios; 501 -> 2 consultas; mismos datos.\n";
$r=rowPrueba(1,['estado'=>'SOLICITADA','destino_atendida'=>null,'destino_fin'=>null]);
[$a,$b,$out]=comparar([$r],eventosPrueba($r));check($b->locks===1&&$b->updates===1&&$out[0]['estado']==='ENTREGADA','Un cambio real requiere bloqueo y relectura');
echo "PASS: respuesta tardía actualiza con bloqueo de fila.\n";
$events=eventosPrueba($r);$events[0]['pbx_call_id']=strtoupper($r['referencia']);comparar([$r],$events);
$other=rowPrueba(2,['referencia'=>$r['referencia'],'evento_desde'=>102]);comparar([$r,$other],eventosPrueba($r));
$rows=[rowPrueba(3,['estado'=>'DEVUELTA','modo'=>'CONSULTADA']),rowPrueba(4,['estado'=>'SIN_CONFIRMAR','destino_atendida'=>null,'destino_fin'=>null]),rowPrueba(5,['origen_usuario'=>99,'destino_usuario'=>98])];comparar($rows,[]);
$r=rowPrueba(8,['estado'=>'SOLICITADA','destino_atendida'=>null,'destino_fin'=>null]);$e=eventosPrueba($r,false);array_shift($e);comparar([$r],$e);
echo "PASS: sin respuesta, consultada, sin confirmar, referencia repetida, marca de agua e identidad.\n";
$r=rowPrueba(9,['estado'=>'SOLICITADA','destino_atendida'=>null,'destino_fin'=>null]);
[$a,$b,$out]=comparar([$r],[],function($db){$db->onLock=function($db,$id){$db->rows[$id]['estado']='ENTREGADA';$db->rows[$id]['destino_atendida']='2026-11-25 19:00:00';};});check($out[0]['estado']==='ENTREGADA','No sobrescribir respuesta concurrente');
echo "PASS: conserva una entrega confirmada por otra petición.\n";
$db=new Candidato\mysqli([$r],[]);$db->failBatch=true;try{(new Candidato\TelefoniaTransferenciaOperacionService($db))->delUsuarioParaAvisos(30);throw new LogicException('Debía fallar');}catch(RuntimeException $e){check($db->begins===0&&$db->updates===0,'Una lectura fallida no altera datos');}
$a=new Anterior\mysqli();$b=new Candidato\mysqli();$ex=[['pbx_call_id'=>'Out_A','extension'=>'161'],['pbx_call_id'=>'out_b','extension'=>''],['pbx_call_id'=>'out_c','extension'=>'100']];$a->exclusions=$ex;$b->exclusions=$ex;$refs=['Out_A','out_a','out_b','out_c'];$expected=[];foreach($refs as $ref)if(Anterior\TelefoniaLimpiezaGuard::bloqueada($a,$ref,'161'))$expected[$ref]=true;
check($expected===Candidato\TelefoniaLimpiezaGuard::referenciasBloqueadas($b,$refs,'161'),'Exclusiones deben conservar extensión e identidad exacta');check($b->queries===2&&$a->queries===8,'Agrupar exclusiones');$b->hasTable=false;check(Candidato\TelefoniaLimpiezaGuard::referenciasBloqueadas($b,$refs,'161')===[],'Compatibilidad sin tabla');
echo "PASS: exclusiones exactas por extensión, sin caché y sin tabla.\n";

$r=rowPrueba(15,['estado'=>'SIN_CONFIRMAR','destino_atendida'=>null,'destino_fin'=>null]);
$db=new Candidato\mysqli([$r],[]);$service=new Candidato\TelefoniaTransferenciaOperacionService($db);
check($service->delUsuarioParaAvisos(30)[0]['estado']==='SIN_CONFIRMAR','Primera petición todavía pendiente');
$db->events=eventosPrueba($r);check($service->delUsuarioParaAvisos(30)[0]['estado']==='ENTREGADA','La próxima petición debe recoger el evento tardío');
echo "PASS: evento entre peticiones visible sin caché de estados.\n";
