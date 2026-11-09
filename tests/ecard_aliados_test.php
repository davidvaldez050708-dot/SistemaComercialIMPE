<?php
// SQL en memoria y render de imágenes con datos ficticios; sin CRM ni red.
require_once __DIR__ . '/../app/services/AliadoNuevoNotificationService.php';
require_once __DIR__ . '/../app/services/EcardReunionService.php';
require_once __DIR__ . '/../app/controllers/ReminderController.php';
set_error_handler(static function ($n,$s,$f,$l) { if (error_reporting() & $n) throw new ErrorException($s,0,$n,$f,$l); return false; });
function comprobarEA($v,$m) { if (!$v) throw new RuntimeException($m); }
class ResultadoEA {
    public $num_rows; private $rows;
    public function __construct($r) {$this->rows=$r;$this->num_rows=count($r);}
    public function fetch_assoc() {return array_shift($this->rows) ?: null;}
}
class SentenciaEA {
    private $s,$values=[];
    public function __construct($p,$sql) {$this->s=$p->prepare($sql);}
    public function bind_param($t,&...$values) {comprobarEA(strlen($t)===count($values),'Bindings');$this->values=&$values;}
    public function execute() {return $this->s->execute($this->values);}
    public function get_result() {return new ResultadoEA($this->s->fetchAll(PDO::FETCH_ASSOC));}
}
class ConexionEA {
    public $pdo,$queries=[];
    public function __construct() {
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE aliados_asignaciones(id INTEGER PRIMARY KEY,seguimiento_id INTEGER,cuenta_clave_usuario_id INTEGER,asignado_at TEXT,activo INTEGER)');
        $this->pdo->exec('CREATE TABLE seguimientos_vinculacion(id INTEGER PRIMARY KEY,nombre_entidad TEXT,estado_id INTEGER,activo INTEGER)');
        $this->pdo->exec('CREATE TABLE seguimientos_vinculacion_post_envio(seguimiento_id INTEGER PRIMARY KEY,convenio_formalizado_at TEXT)');
        $this->pdo->exec('CREATE TABLE aliados_novedades_lecturas(asignacion_id INTEGER,usuario_id INTEGER,asignado_at TEXT,formalizado_at TEXT,leida_at TEXT,PRIMARY KEY(asignacion_id,usuario_id,asignado_at,formalizado_at))');
        foreach ([[1,7,1,1,'2026-10-26 12:00:00'],[2,99,1,1,'2026-10-26 12:00:00'],[3,7,0,1,'2026-10-26 12:00:00'],[4,7,1,0,'2026-10-26 12:00:00'],[5,7,1,1,null]] as [$id,$uid,$active,$sactive,$formal]) {
            $this->pdo->prepare('INSERT INTO aliados_asignaciones VALUES(?,?,?,?,?)')->execute([$id,$id+100,$uid,'2026-10-26 12:00:00',$active]);
            $this->pdo->prepare('INSERT INTO seguimientos_vinculacion VALUES(?,?,11,?)')->execute([$id+100,$id===1?'Institución <prueba> México':'Institución '.$id,$sactive]);
            $this->pdo->prepare('INSERT INTO seguimientos_vinculacion_post_envio VALUES(?,?)')->execute([$id+100,$formal]);
        }
    }
    public function query($sql) {
        $this->queries[]=$sql;
        if (preg_match("/^SHOW TABLES LIKE '(\\w+)'$/",$sql,$m)) {
            return new ResultadoEA($this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='".$m[1]."'")->fetchAll(PDO::FETCH_ASSOC));
        }
        throw new RuntimeException('Consulta no prevista');
    }
    public function prepare($sql) {
        $this->queries[]=$sql;
        // Sólo adaptar la sintaxis de idempotencia; joins/WHERE/bindings son reales.
        $sql=str_replace('ON DUPLICATE KEY UPDATE leida_at = leida_at','ON CONFLICT DO NOTHING',$sql);
        return new SentenciaEA($this->pdo,$sql);
    }
}
$db=new ConexionEA();$service=new AliadoNuevoNotificationService($db);$_SESSION=[];
$before=$db->pdo->query('SELECT * FROM aliados_asignaciones')->fetchAll();
$r=$service->obtener(7);comprobarEA($r['ok']&&count($r['recordatorios'])===1&&count($r['avisos'])===1,'Sólo aliado formalizado activo del destinatario');
comprobarEA($r['avisos'][0]['accion']===$r['recordatorios'][0]['accion']&&$r['avisos'][0]['fecha']===$r['recordatorios'][0]['fecha'],'Clave del toast idéntica a la campana');
comprobarEA(str_contains($r['avisos'][0]['url'],'estado_id=11&aviso_aliado=1'),'Destino propio');
$out=__DIR__.'/../tmp/ecard_aliados';if(!is_dir($out))mkdir($out,0777,true);
file_put_contents($out.'/respuestas.json',json_encode(['generated_at_ms'=>(int)(microtime(true)*1000),'aliado'=>$r],JSON_UNESCAPED_UNICODE));
$controller=(new ReflectionClass(ReminderController::class))->newInstanceWithoutConstructor();
$sort=new ReflectionMethod($controller,'ordenarRecordatorios');
$urgentes=array_fill(0,12,['id'=>900,'accion'=>'Pendiente urgente','prioridad'=>0,'fecha'=>'2026-10-26 10:00:00']);
$retenidos=array_slice($sort->invoke($controller,array_merge($r['recordatorios'],$urgentes)),0,12);
comprobarEA(!array_filter($retenidos,static fn($v)=>isset($v['aliado_asignacion_id'])),'Límite real del controlador prioriza urgencias');
$service->registrarEntregados(7, $retenidos);
comprobarEA(count($service->obtener(7)['avisos'])===1,'Un aviso fuera del límite global no se pierde');
$service->registrarEntregados(7, $r['recordatorios']);
comprobarEA($service->obtener(7)['avisos']===[]&&count($service->obtener(7)['recordatorios'])===1,'Toast no se repite al sondear; campana persiste');
comprobarEA($service->obtener(0)['recordatorios']===[]&&$service->obtener(5)['recordatorios']===[],'Sin avisos para usuarios sin asignación');
comprobarEA($service->obtener(99)['recordatorios'][0]['aliado_asignacion_id']===2,'Otro responsable sólo recibe su aliado');
$service->marcarLeido(1,99);comprobarEA((int)$db->pdo->query('SELECT COUNT(*) FROM aliados_novedades_lecturas')->fetchColumn()===0,'No se puede consumir un aviso ajeno');
$service->marcarLeido(1,7);$service->marcarLeido(1,7);$_SESSION=[];
comprobarEA($service->obtener(7)['recordatorios']===[],'La lectura persiste entre sesiones y es idempotente');
$db->pdo->exec("UPDATE aliados_asignaciones SET asignado_at='2026-10-27 12:00:00' WHERE id=1");
comprobarEA(count($service->obtener(7)['avisos'])===1,'Nueva asignación vuelve a avisar');
$db->pdo->exec('UPDATE aliados_asignaciones SET cuenta_clave_usuario_id=88 WHERE id=1');
comprobarEA($service->obtener(7)['recordatorios']===[]&&count($service->obtener(88)['avisos'])===1,'Reasignación retira aviso al responsable anterior');
$db->pdo->exec("UPDATE aliados_asignaciones SET cuenta_clave_usuario_id=7,asignado_at='2026-10-26 12:00:00' WHERE id=1");
comprobarEA($before===$db->pdo->query('SELECT * FROM aliados_asignaciones')->fetchAll(),'El servicio no cambia asignaciones');
$db->pdo->exec('DROP TABLE aliados_novedades_lecturas');
comprobarEA($service->obtener(7)['requiere_migracion']&&!$service->obtener(7)['ok'],'Dependencia ausente devuelve estado parcial controlado');

// La tipografía debe funcionar con las fuentes distribuidas por Dompdf, sin fuentes Linux.
$ecard=new EcardReunionService(new stdClass());
$font=new ReflectionMethod($ecard,'resolverFuente');$regular=$font->invoke($ecard,false);$bold=$font->invoke($ecard,true);
comprobarEA(str_contains($regular,'vendor/dompdf/')&&str_contains($bold,'vendor/dompdf/'),'Fuente portable antes de las fuentes del sistema');
comprobarEA(abs(imagettfbbox(38,0,$bold,'UPEMOR')[2])>200,'Texto de institución legible al tamaño solicitado');
$canvas=imagecreatetruecolor(1254,820);$white=imagecolorallocate($canvas,255,255,255);$ink=imagecolorallocate($canvas,3,43,78);imagefill($canvas,0,0,$white);
$block=new ReflectionMethod($ecard,'dibujarBloqueTextoCentradoVertical');$block->invoke($ecard,$canvas,['UNIVERSIDAD POLITÉCNICA','MORELOS Y CENTRO DE INVESTIGACIÓN','EDUCATIVA'],28,650,710,$ink,$bold,2);
for($y=0;$y<820;$y++)for($x=0;$x<1254;$x++)if(imagecolorat($canvas,$x,$y)!==$white)comprobarEA($y>=650&&$y<=710,'Nombres de tres líneas no invaden fecha/hora');
imagedestroy($canvas);
foreach (['Manuel','Sergio'] as $template) {
    $render=new ReflectionMethod($ecard,'crearImagen'.$template.'Plantilla');
    foreach (['UPEMOR','UNIVERSIDAD POLITÉCNICA DEL ESTADO DE MORELOS Y CENTRO DE INVESTIGACIÓN EDUCATIVA'] as $i=>$name) {
        $args=[$out.'/'.strtolower($template).'-'.$i.'.jpg',new DateTime('2026-10-26 11:05:00'),$name];
        if($template==='Manuel')$args=array_merge($args,['En línea','Virtual','https://zoom.us/j/prueba']);else $args[]='Virtual';
        comprobarEA($render->invokeArgs($ecard,$args)['ok'],'Render '.$template);
        $image=imagecreatefromjpeg($args[0]);comprobarEA(imagesx($image)===($template==='Manuel'?1200:1254),'Plantilla conserva resolución');
        $small=imagescale($image,720);imagepng($small,$out.'/'.strtolower($template).'-'.$i.'.png');imagedestroy($image);imagedestroy($small);
    }
}
// Reproducir el fallo previo de servidor sin fuentes del sistema.
$source=shell_exec('git show 050428d9:app/services/EcardReunionService.php');
$source=str_replace("require_once __DIR__ . '/../../config/db_connection.php';",'', $source);
$source=str_replace('class EcardReunionService','class EcardAntesEA',$source);
$source=str_replace(['C:/Windows/Fonts/','/usr/share/fonts/'],['/fuente-inexistente/','/fuente-inexistente/'],$source);
eval('?>'.$source);$old=(new ReflectionClass('EcardAntesEA'))->newInstanceWithoutConstructor();
$p=new ReflectionProperty($old,'rootPath');$p->setValue($old,dirname(__DIR__));
$render=new ReflectionMethod($old,'crearImagenManuelPlantilla');comprobarEA($render->invoke($old,$out.'/antes.jpg',new DateTime('2026-10-26 11:05:00'),'UPEMOR','En línea','Virtual','')['ok'],'Reproducción previa');
$oldfont=new ReflectionMethod($old,'resolverFuente');comprobarEA($oldfont->invoke($old,true)==='','El fallo anterior cae a letra bitmap fija');
$image=imagecreatefromjpeg($out.'/antes.jpg');$small=imagescale($image,720);imagepng($small,$out.'/antes.png');
echo "OK: tipografía TrueType portable en ambas plantillas; nombres cortos/largos; aliados propios/formalizados/activos, toast único, lectura persistente e idempotente, reasignación y dependencia ausente. Sin producción.\n";
