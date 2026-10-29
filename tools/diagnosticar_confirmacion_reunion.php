<?php
// Sólo CLI. Valida PHP y prepara SQL real; jamás ejecuta INSERT/UPDATE/DELETE.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc>2 || ($argv[1]??'--check')!=='--check') { fwrite(STDERR,"Uso: --check\n");exit(2); }
$raiz=getenv('IMPE_CORREOS_PUBLIC') ?: (is_file(dirname(__DIR__).'/index.php') ? dirname(__DIR__) : getenv('HOME').'/domains/fundacionrededumex.org/public_html');

class ConfirmacionConsultaSegura
{
    public $affected_rows=1;
    private $stmt;
    private $lectura;
    public function __construct($db,$sql) {
        $this->lectura=(bool)preg_match('/^\s*SELECT\b/i',$sql);
        if (!$this->lectura && !preg_match('/^\s*(UPDATE|INSERT)\b/i',$sql)) throw new RuntimeException('SQL no previsto.');
        $this->stmt=$db->prepare($sql);
    }
    public function bind_param($tipos,&...$valores) { return $this->stmt->bind_param($tipos,...$valores); }
    public function execute() { return $this->lectura ? $this->stmt->execute() : true; }
    public function get_result() {
        if (!$this->lectura) throw new RuntimeException('No se ejecutan escrituras.');
        return $this->stmt->get_result();
    }
}
class ConfirmacionConexionSegura
{
    private $db;
    public function __construct($db) { $this->db=$db; }
    public function prepare($sql) { return new ConfirmacionConsultaSegura($this->db,$sql); }
}

$fase='inicio';$fallos=0;
$probar=static function($nombre,$fn)use(&$fase,&$fallos) {
    $fase=$nombre;
    try {$fn();echo $nombre,"=OK",PHP_EOL;}
    catch(Throwable $e){$fallos++;echo $nombre,"=ERROR CLASE=",get_class($e)," CODIGO=",(int)$e->getCode()," ARCHIVO=",basename($e->getFile())," LINEA=",$e->getLine(),PHP_EOL;}
};
try {
    require_once $raiz.'/app/services/AgendaReunionRepository.php';
    $db=(new Database())->connect(false);
    $repo=(new ReflectionClass(AgendaReunionRepository::class))->newInstanceWithoutConstructor();
    $property=new ReflectionProperty(AgendaReunionRepository::class,'connection');$property->setAccessible(true);
    $property->setValue($repo,new ConfirmacionConexionSegura($db));
    $fila=$db->query('SELECT id,seguimiento_id,analista_id,cuenta_clave_id,fecha_propuesta,duracion_minutos FROM reuniones_vinculacion ORDER BY id DESC LIMIT 1')->fetch_assoc();
    if (!$fila) {echo "SIN_REUNIONES_PARA_CONSULTA",PHP_EOL;exit;}
    $id=(int)$fila['id'];$kam=(int)$fila['cuenta_clave_id'];
    $probar('consulta_reunion',static fn()=>$repo->reunion($id,$kam,6));
    $probar('consulta_cruces',static fn()=>$repo->conflictoHorario($fila['fecha_propuesta'],(int)$fila['duracion_minutos'],(int)$fila['analista_id'],$kam,$id));
    $probar('preparar_confirmacion',static fn()=>$repo->confirmar($id,$kam,'https://zoom.us/j/diagnostico','',''));
    $probar('preparar_interaccion',static fn()=>$repo->registrarInteraccion((int)$fila['seguimiento_id'],$kam,'Diagnóstico sin escritura'));
    $probar('valores_interaccion',static function()use($db) {
        $campos=$db->query('SHOW COLUMNS FROM interacciones_vinculacion')->fetch_all(MYSQLI_ASSOC);
        $tipos=array_column($campos,'Type','Field');
        foreach(['canal'=>'SISTEMA','resultado'=>'OTRO']as$campo=>$valor){
            if(!isset($tipos[$campo]))throw new RuntimeException('Campo ausente.');
            if(stripos($tipos[$campo],'enum(')===0&&strpos($tipos[$campo],"'".$valor."'")===false)throw new RuntimeException('Valor no disponible.',1265);
        }
    });
    foreach(['app/services/AgendaReunionRepository.php','app/services/AgendaReunionService.php','app/controllers/AgendaReunionController.php']as$f)echo 'SHA256 ',basename($f),'=',hash_file('sha256',$raiz.'/'.$f),PHP_EOL;
    echo "SOLO LECTURA: SELECT y preparación de sentencias. No se ejecutó ningún cambio, confirmación, interacción ni envío.",PHP_EOL;
    exit($fallos?1:0);
} catch(Throwable $e) {
    echo $fase,"=ERROR CLASE=",get_class($e)," CODIGO=",(int)$e->getCode()," ARCHIVO=",basename($e->getFile())," LINEA=",$e->getLine(),PHP_EOL;
    exit(1);
}
