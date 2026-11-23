<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root',$argv[1]??'',null,33472);
$name='impe_zona_marcaciones_test';$db->query("CREATE DATABASE IF NOT EXISTS $name");$db->select_db($name);
// Sólo dependencias de permisos y normalización del entorno sintético. Los
// métodos reconciliar/vincular/extension y todas las fechas se ejecutan completos.
class TelefoniaVentasLlamadasService {
    private mysqli $db;public function __construct(mysqli $db){$this->db=$db;}
    public function autorizar(int $uid):void{if(!$this->db->query('SELECT id FROM usuarios WHERE id='.(int)$uid.' AND estado=1 AND rol_id=3')->num_rows)throw new DomainException('Denegado');}
}
class TelefoniaContactosService {public static function normalizarTelefono(string $number):string{return preg_replace('/\D/','',$number);}}
$old=file_get_contents(__DIR__.'/fixtures/TelefoniaMarcacionesVentasService.instalada_20261123.php.txt');
$spec=json_decode(file_get_contents(__DIR__.'/../tools/zona_marcaciones_release.json'),true);
$new=$old;
foreach($spec['files'][0]['hunks'] as $h){if(substr_count($new,$h['before'])!==1)throw new RuntimeException('Bloque instalado distinto');$new=str_replace($h['before'],$h['after'],$new);}
foreach(['AnteriorMarcaciones'=>$old,'NuevaMarcaciones'=>$new] as $class=>$source){
    $source=preg_replace('/^<\?php\s*/','',$source);
    $source=preg_replace("/require_once\\s+__DIR__\\s*\\.\\s*'[^']+';/",'',$source);
    $source=str_replace('class TelefoniaMarcacionesVentasService','class '.$class,$source);
    eval($source);
}
try {
    $db->query('CREATE TABLE usuarios(id INT PRIMARY KEY,estado INT,rol_id INT)');
    $db->query('CREATE TABLE telefonia_extensiones(usuario_id INT,extension VARCHAR(12),activo INT,proveedor VARCHAR(20),permite_salientes INT)');
    $db->query('CREATE TABLE permisos(id INT PRIMARY KEY,codigo VARCHAR(80),estado INT)');
    $db->query('CREATE TABLE rol_permisos(rol_id INT,permiso_id INT)');
    $db->query('CREATE TABLE interacciones_vinculacion(proveedor_externo VARCHAR(20),canal VARCHAR(20),id_externo VARCHAR(100))');
    $db->query('CREATE TABLE telefonia_zadarma_eventos(id INT AUTO_INCREMENT PRIMARY KEY,pbx_call_id VARCHAR(100),internal VARCHAR(12),evento VARCHAR(40),destination VARCHAR(30),received_at DATETIME)');
    (new AnteriorMarcaciones($db,false))->asegurarEstructura();
    $db->query("INSERT INTO usuarios VALUES(7,1,3),(8,1,4)");
    $db->query("INSERT INTO telefonia_extensiones VALUES(7,'123',1,'ZADARMA',1),(8,'124',1,'ZADARMA',1)");
    $db->query("INSERT INTO permisos VALUES(1,'telefonia.salientes',1)");$db->query('INSERT INTO rol_permisos VALUES(3,1)');
    $stats=static function():int{$s=mysqli_get_client_stats();return (int)$s['com_query']+(int)$s['com_stmt_execute'];};
    foreach(['+00:00','-06:00'] as $zone){
        $db->query("SET time_zone='$zone'");
        $db->query('DELETE FROM telefonia_ventas_marcaciones');$db->query('DELETE FROM telefonia_zadarma_eventos');$db->query('DELETE FROM interacciones_vinculacion');
        $clock=new DateTimeImmutable($db->query('SELECT NOW() n')->fetch_assoc()['n'],new DateTimeZone($zone));
        $intent=$db->prepare("INSERT INTO telefonia_ventas_marcaciones(usuario_id,extension,destino,token,created_at) VALUES(7,'123',?,?,?)");
        $event=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,internal,evento,destination,received_at) VALUES(?,'123','NOTIFY_OUT_START',?,?)");
        for($i=1;$i<=92;$i++){
            $token=sprintf('%032x',$i);$number='551'.sprintf('%07d',$i);
            $date=$clock->modify('-'.(20000+$i*120).' seconds');
            if($i===91){$date=$clock->modify('-'.(20000+90*120).' seconds');$number='551'.sprintf('%07d',90);}
            $at=$date->format('Y-m-d H:i:s');
            $intent->bind_param('sss',$number,$token,$at);$intent->execute();
            if($i<=80||$i===91)continue;
            $ref='out_'.sprintf('%032x',$i);$received=$date->modify('+2 seconds')->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $event->bind_param('sss',$ref,$number,$received);$event->execute();
            if($i===89){$extra='out_'.sprintf('%032x',1000);$event->bind_param('sss',$extra,$number,$received);$event->execute();}
            if($i===92){$stmt=$db->prepare("INSERT INTO interacciones_vinculacion VALUES('ZADARMA','LLAMADA_IP',?)");$stmt->bind_param('s',$ref);$stmt->execute();}
        }
        $before=$stats();(new AnteriorMarcaciones($db,false))->reconciliarPendientes(7,'123');$qOld=$stats()-$before;
        $expected=$db->query('SELECT token,pbx_call_id FROM telefonia_ventas_marcaciones ORDER BY token')->fetch_all(MYSQLI_ASSOC);
        $db->query('UPDATE telefonia_ventas_marcaciones SET pbx_call_id=NULL,vinculada_at=NULL');
        $before=$stats();$svc=new NuevaMarcaciones($db,false);$svc->reconciliarPendientes(7,'123');$qNew=$stats()-$before;
        $actual=$db->query('SELECT token,pbx_call_id FROM telefonia_ventas_marcaciones ORDER BY token')->fetch_all(MYSQLI_ASSOC);
        if($expected!==$actual)throw new RuntimeException('Cambió la asociación por fecha');
        if(count(array_filter($actual,static fn($r)=>$r['pbx_call_id']!==null))!==8)throw new RuntimeException('Se perdió ambigüedad o exclusión institucional');
        if($qOld-$qNew!==183)throw new RuntimeException("No se redujeron consultas: $qOld / $qNew");
        $svc->reconciliarPendientes(7,'123');
        if($db->query('SELECT token,pbx_call_id FROM telefonia_ventas_marcaciones ORDER BY token')->fetch_all(MYSQLI_ASSOC)!==$actual)throw new RuntimeException('Cambió una asociación previa');
        try{$svc->reconciliarPendientes(8,'124');throw new RuntimeException('Acceso indebido');}catch(DomainException $ok){}
        // El offset no queda almacenado en el objeto: un cambio explícito de
        // zona en esta conexión vuelve a leerse en la siguiente conversión.
        $db->query("SET time_zone='+00:00'");$a=$svc->fechaMarcacionLocal('2026-11-23 00:00:00');
        $db->query("SET time_zone='-06:00'");$b=$svc->fechaMarcacionLocal('2026-11-23 00:00:00');
        if($a!=='2026-11-22 18:00:00'||$b!=='2026-11-23 00:00:00')throw new RuntimeException('Zona obsoleta o día incorrecto');
        try{$svc->fechaMarcacionLocal('fecha inválida');throw new RuntimeException('Aceptó fecha inválida');}catch(DomainException $ok){}
        echo "PASS MariaDB $zone: $qOld -> $qNew consultas; mismos 8 enlaces, ambigüedades y llamadas institucionales intactas; cruce de día y zona actualizada.\n";
    }
} finally {$db->query("DROP DATABASE $name");}
