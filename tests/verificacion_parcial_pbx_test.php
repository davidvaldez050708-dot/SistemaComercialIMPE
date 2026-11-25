<?php
require __DIR__.'/../app/services/TelefoniaVerificacionEvidenciaService.php';
final class ResultadoParcialFake {
    private array $rows;
    function __construct(array $rows){$this->rows=$rows;}
    function fetch_assoc(){return $this->rows[0]??null;}
    function fetch_all($modo){return $this->rows;}
}
final class SentenciaParcialFake {
    private $db; private string $sql; private array $values=[];
    function __construct($db,string $sql){$this->db=$db;$this->sql=$sql;}
    function bind_param($types,&...$values){$this->values=&$values;}
    function execute(){if(str_starts_with($this->sql,'UPDATE interacciones'))$this->db->notas=$this->values[4];return true;}
    function get_result(){return new ResultadoParcialFake(str_contains($this->sql,'SELECT id,seguimiento_id')?[['id'=>1,'seguimiento_id'=>2,'notas'=>'[VERIFICACION_PENDIENTE_PBX] Nota']]:$this->db->detalles);}
    function close(){}
}
final class ConexionParcialFake {
    public array $detalles; public string $notas=''; public array $consultas=[];
    function __construct(array $detalles){$this->detalles=$detalles;}
    function prepare($sql){$this->consultas[]=$sql;return new SentenciaParcialFake($this,$sql);}
}
$telefono=['campo'=>'telefono','accion'=>'CONFIRMADO','valor'=>'5550000000'];
$correo=['campo'=>'correo','accion'=>'CONFIRMADO','valor'=>'contacto@example.test'];
foreach([[$telefono],[$telefono,$correo]] as $datos){
    $db=new ConexionParcialFake($datos);$svc=new TelefoniaVerificacionEvidenciaService($db);
    $ok=$svc->confirmar(30,1,'sdk-sintetico','pbx-sintetico',['answer_at'=>'2026-11-25 10:00:00','end_at'=>'2026-11-25 10:01:00']);
    if($ok!==(count($datos)===2)||str_contains($db->notas,'[VERIFICACION_EFECTIVA]')!==$ok)throw new RuntimeException('PBX parcial no debe acreditar la llamada');
    if(!array_filter($db->consultas,fn($s)=>str_starts_with($s,'UPDATE telefonia_verificacion')))throw new RuntimeException('Conservar evidencia individual confirmada por PBX');
}
$db=new ConexionParcialFake([$telefono,$correo]);
if((new TelefoniaVerificacionEvidenciaService($db))->confirmar(30,1,'sdk','pbx',['answer_at'=>'2026-11-25 10:00:00','end_at'=>'2026-11-25 10:00:00'])||$db->consultas)throw new RuntimeException('Sin conversación real no se confirma');
echo "PASS: confirmación PBX parcial conserva datos sin crédito; par confirmado acredita; duración cero rechazada.\n";
