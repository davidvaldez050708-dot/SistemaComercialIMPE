<?php
require_once __DIR__.'/../helpers/VentasActividadAccess.php';
require_once __DIR__.'/VentasActividadRepository.php';
require_once __DIR__.'/VentasActividadMetricas.php';

final class VentasActividadService
{
    private VentasActividadRepository $repo;
    public function __construct(?mysqli $db=null) { $this->repo=new VentasActividadRepository($db); }
    public function preparar(array $f, bool $desempeno, bool $generar = true): array
    {
        $scope=VentasActividadAccess::alcance($desempeno);
        if ($scope==='') throw new DomainException('No tienes permiso para consultar esta actividad.');
        foreach (['periodo','fecha_desde','fecha_hasta','persona_id','direccion','resultado','pagina'] as $key)
            if (isset($f[$key]) && !is_scalar($f[$key])) throw new InvalidArgumentException('Los filtros deben contener valores individuales.');
        $people=$this->repo->personas($scope,(int)$_SESSION['usuario_id']);
        $person=filter_var($f['persona_id']??0,FILTER_VALIDATE_INT);
        if ($person===false || $person<0) throw new InvalidArgumentException('Selecciona un asesor válido.');
        if ($scope==='propio') {
            if ($person>0 && $person!==(int)$_SESSION['usuario_id']) throw new DomainException('Sólo puedes consultar tu actividad.');
            $person=(int)$_SESSION['usuario_id'];
        }
        if ($person>0 && !in_array($person,array_map('intval',array_column($people,'id')),true)) throw new DomainException('El asesor no pertenece a tu alcance autorizado.');
        $selected=$person>0?array_values(array_filter($people,static fn($p)=>(int)$p['id']===$person)):$people;
        $p=VentasActividadMetricas::periodo($f);
        $direction=(string)($f['direccion']??'');$result=(string)($f['resultado']??'');
        if (!in_array($direction,['','Saliente','Entrante'],true) || ($result!=='' && $result!=='PENDIENTE' && !isset(TelefoniaResultadoVentasService::opciones()[$result])))
            throw new InvalidArgumentException('Selecciona un tipo y resultado válidos.');
        $calls=$generar ? $this->repo->llamadas(array_column($selected,'id'),$p) : [];
        $calls=array_values(array_filter($calls,static fn($c)=>($direction===''||$c['direccion']===$direction) &&
            ($result===''||($c['registro']['resultado']??'PENDIENTE')===$result)));
        return VentasActividadMetricas::calcular($calls,$selected,$p)+['area'=>'asesores','area_label'=>'Asesores',
            'vista'=>$scope,'periodo'=>$p,'personas'=>$people,'persona_id'=>$person,'direccion'=>$direction,'resultado'=>$result,
            'organizacion'=>$generar?$this->repo->organizacion(array_column($selected,'id')):[],
            'es_desempeno'=>$desempeno,'generado'=>$generar,'generado_por'=>trim(($_SESSION['nombre']??'').' '.($_SESSION['apellidos']??'')),
            'generado_por_rol'=>$_SESSION['rol']??'','fecha_generacion'=>date('d/m/Y H:i')];
    }
}
