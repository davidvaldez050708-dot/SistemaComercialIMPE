<?php
// El helper real del controlador recibe un catálogo simulado; sin base de datos.
$source=file_get_contents(dirname(__DIR__).'/app/controllers/SeguimientoVinculacionController.php');
eval('?>'.preg_replace('/^require_once .*;\s*$/m','',$source));
class ExcelScopeFixture {
    public array $asignados=[17,16];
    public function obtenerEstadosAdministrador(){return [['id'=>17,'nombre'=>'Morelos'],['id'=>16,'nombre'=>'Michoacán'],['id'=>15,'nombre'=>'Estado de México']];}
    public function obtenerEstadosAsignadosAnalista($id){return $id===501?$this->obtenerEstadosAdministrador():[];}
    public function obtenerEstadosSupervisadosCuentaClave($id){return $id===600?array_slice($this->obtenerEstadosAdministrador(),0,2):[];}
    public function analistaValidoParaAdministradorEstado($state,$id){return $id===501&&in_array($state,$this->asignados,true);}
    public function analistaValidoParaCuentaClaveEstado($supervisor,$state,$id){return $supervisor===600&&$this->analistaValidoParaAdministradorEstado($state,$id);}
}
$model=new ExcelScopeFixture;$controller=new SeguimientoVinculacionController;
$method=(new ReflectionClass($controller))->getMethod('territoriosImportacionExcel');
$scope=fn($uid,$mode,$analyst)=>array_column($method->invoke($controller,$model,$uid,$mode,$analyst),'id');
if($scope(501,'analista',501)!==[17,16,15]||$scope(501,'analista',502)!==[]||$scope(1,'administrador',501)!==[17,16]||$scope(600,'supervisor',501)!==[17,16]||$scope(600,'supervisor',502)!==[]||$scope(600,'supervisor',0)!==[])throw new RuntimeException('Alcance territorial incorrecto');
$model->asignados=[16];
if($scope(600,'supervisor',501)!==[16]||$scope(1,'administrador',501)!==[16])throw new RuntimeException('No vuelve a consultar asignación vigente');
echo "PASS: controlador conserva intersección territorial de analista, supervisor, responsable y consulta global; asignación revocada y responsable inválido.\n";
