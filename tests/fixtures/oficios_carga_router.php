<?php
// Router exclusivo de pruebas locales. Nunca forma parte del publicador.
$root=dirname(__DIR__,2);
$work=realpath(getenv('IMPE_OFICIOS_TEST_WORK') ?: '');
$base=realpath($root.'/tmp');
if (!$work || !$base || strpos($work,$base.DIRECTORY_SEPARATOR)!==0) { http_response_code(403); exit; }
require_once $root.'/app/controllers/OficioVinculacionController.php';
class OficiosModelPrueba extends OficioVinculacionModel {
    private $file;
    public function __construct($file) { $this->file=$file; }
    public function registrarPlantillaOficioDocx($name,$path,$user) {
        $rows=json_decode(file_get_contents($this->file),true)?:[];
        $id=count($rows)+1;
        $rows[$id]=['id'=>$id,'nombre'=>$name,'archivo_docx'=>$path,'activo'=>1];
        file_put_contents($this->file,json_encode($rows)); return $id;
    }
    public function obtenerPlantillaGuardada($id) {
        $rows=json_decode(file_get_contents($this->file),true)?:[];
        $row=$rows[$id]??null;
        return $row && $row['activo'] && strpos(basename($row['archivo_docx']),'oficio_')===0 ? $row : null;
    }
}
$role=$_GET['role']??'analista';
$_SESSION=['usuario_id'=>999999, 'rol_id'=>$role==='admin'?1:($role==='cuenta'?6:2),
    'permisos'=>$role==='cuenta'?['oficios.ver','seguimientos_vinculacion.supervisar']:['oficios.ver','oficios.generar','seguimientos_vinculacion.operar_propios']];
$controller=new OficioVinculacionController();
$mode=$_GET['mode']??'upload';
if ($mode==='guard') { $controller->generarBorrador(); exit; }
if ($mode==='unauth') { $_SESSION=[]; $controller->subirPlantilla(); exit; }
$model=new OficiosModelPrueba($work.'/registry.json');
if ($mode==='saved') {
    $method=new ReflectionMethod($controller,'copiarPlantillaGuardada');
    $result=$method->invoke($controller,(int)($_POST['id']??0),999999,$model);
} else {
    $method=new ReflectionMethod($controller,'guardarPlantillaParaGeneracion');
    $result=$method->invoke($controller,$_FILES['archivo']??null,999999,$model,
        $mode==='library'?'Biblioteca de prueba':null);
}
http_response_code($result['codigo_http']??200);
header('Content-Type: application/json'); echo json_encode($result);
