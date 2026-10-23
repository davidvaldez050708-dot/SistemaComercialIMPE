<?php
require_once __DIR__.'/../helpers/ProgramaEducativo.php';

/** Lectura de identidades persistidas. Nunca deduce un aliado del título o URL. */
class ConvocatoriaOrigenService
{
    private mysqli $db;
    private array $tablas = [];
    private array $columnas = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $result = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
        while ($row = $result->fetch_row()) $this->tablas[$row[0]] = true;
        $result=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='formulario_aliados'");
        while($row=$result->fetch_row()) $this->columnas[$row[0]]=true;
    }

    public static function programa(array $fila): string
    {
        $subtipo = ProgramaEducativo::codigo((string)($fila['subtipo_convocatoria'] ?? ''));
        return [
            'bachillerato-general'=>'Bachillerato General', 'bachillerato-2-anos'=>'Bachillerato en 2 años',
            'ingles'=>'Inglés', 'experiencia-laboral'=>'Titulación por experiencia laboral',
            'ejecutivas'=>'Titulación ejecutiva', 'inscripciones-abiertas'=>'Inscripciones abiertas',
            'sindicatos'=>'Sindicatos'
        ][$subtipo] ?? 'Clasificación por revisar';
    }

    private function leer(string $tabla, string $columnas, string $campo, array $ids): array
    {
        if (!isset($this->tablas[$tabla]) || !$ids) return [];
        // Tablas y columnas proceden exclusivamente de constantes internas.
        $stmt = $this->db->prepare("SELECT $columnas FROM $tabla WHERE $campo IN (".implode(',',array_fill(0,count($ids),'?')).')');
        $stmt->bind_param(str_repeat('i',count($ids)),...$ids);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function completar(array $filas): array
    {
        if (!$filas) return [];
        $ids = array_values(array_unique(array_map('intval',array_column($filas,'id'))));
        $relaciones = [];
        $agregar = static function(int $cid,string $tipo,int $id,string $programa='') use (&$relaciones): void {
            $relaciones[$cid][] = ['tipo'=>$tipo,'id'=>$id,'programa'=>ProgramaEducativo::codigo($programa)];
        };
        // V3/V4: el origen explícito se conserva como primera evidencia.
        foreach ($this->leer('marketing_aliado_convocatorias_origen','convocatoria_id,origen_tipo,origen_id,programa_codigo','convocatoria_id',$ids) as $r)
            $agregar((int)$r['convocatoria_id'],$r['origen_tipo'],(int)$r['origen_id'],$r['programa_codigo']);
        foreach ($this->leer('marketing_aliado_manual_convocatorias','convocatoria_id,manual_id,programa_codigo','convocatoria_id',$ids) as $r)
            $agregar((int)$r['convocatoria_id'],'manual',(int)$r['manual_id'],$r['programa_codigo']);
        foreach ($this->leer('marketing_convenio_convocatorias','convocatoria_id,seguimiento_id','convocatoria_id',$ids) as $r)
            $agregar((int)$r['convocatoria_id'],'institucional',(int)$r['seguimiento_id']);

        $externos = $this->leer('formulario_aliado_convocatorias','convocatoria_id,aliado_id,programa_codigo','convocatoria_id',$ids);
        $perfiles = array_values(array_unique(array_map('intval',array_column($externos,'aliado_id'))));
        $mapaPerfiles = [];
        foreach ($this->leer('formulario_aliado_registro','aliado_id,manual_id,seguimiento_id','aliado_id',$perfiles) as $r) {
            if ((int)$r['manual_id']>0) $mapaPerfiles[(int)$r['aliado_id']][]=['manual',(int)$r['manual_id']];
            if ((int)$r['seguimiento_id']>0) $mapaPerfiles[(int)$r['aliado_id']][]=['institucional',(int)$r['seguimiento_id']];
        }
        foreach ($externos as $r) {
            foreach ($mapaPerfiles[(int)$r['aliado_id']] ?? [['formulario',(int)$r['aliado_id']]] as [$tipo,$id])
                $agregar((int)$r['convocatoria_id'],$tipo,$id,$r['programa_codigo']);
        }
        $origenes = ['manual'=>[],'institucional'=>[],'formulario'=>[]];
        foreach ($relaciones as $grupo) foreach ($grupo as $r) if(isset($origenes[$r['tipo']])) $origenes[$r['tipo']][]=$r['id'];
        $registros = [];
        foreach ([
            'manual'=>['aliados_manuales','id,nombre_entidad,estado_id,municipio_id'],
            'institucional'=>['seguimientos_vinculacion','id,nombre_entidad,estado_id,municipio_id'],
            'formulario'=>['formulario_aliados','id,nombre,'.implode(',',array_map(fn($col)=>isset($this->columnas[$col])?$col:"NULL AS $col",['pais_codigo','region','ciudad']))]
        ] as $tipo=>[$tabla,$columnas]) {
            foreach ($this->leer($tabla,$columnas,'id',array_values(array_unique($origenes[$tipo]))) as $r) $registros[$tipo.':'.$r['id']]=$r;
        }
        $municipios=[];
        foreach ($registros as $r) if(!empty($r['municipio_id'])) $municipios[]=(int)$r['municipio_id'];
        $municipios=array_column($this->leer('municipios','id,nombre','id',array_values(array_unique($municipios))),'nombre','id');

        foreach ($filas as &$fila) {
            $fila['programa_nombre']=self::programa($fila);
            $grupo=$relaciones[(int)$fila['id']] ?? [];
            $identidades=[];$programas=[];
            foreach($grupo as $r) {
                $identidades[$r['tipo'].':'.$r['id']]=$r;
                if($r['programa']!=='') $programas[$r['programa']]=true;
            }
            $codigo=ProgramaEducativo::codigo((string)($fila['subtipo_convocatoria']??''));
            if($codigo==='experiencia-laboral') $codigo='titulacion-experiencia-laboral';
            $conflicto=count($identidades)>1 || count($programas)>1 || ($programas && !isset($programas[$codigo]));
            $r=$identidades ? reset($identidades) : null;
            $registro=$r ? ($registros[$r['tipo'].':'.$r['id']]??null) : null;
            if($r && !$registro) $conflicto=true;
            $nombre=$registro['nombre_entidad']??$registro['nombre']??'';
            $origen=$r ? ($r['tipo']==='manual'?'Aliado manual':($r['tipo']==='institucional'?'Vinculación':((!empty($registro['pais_codigo']) && $registro['pais_codigo']!=='MX')?'Aliado internacional':'Registro de aliado'))) : 'Publicación territorial';
            $fila['aliado']=[
                'estado'=>$conflicto?'revision':($r?'verificado':'sin_asociacion'),
                'nombre'=>$conflicto?'Relaciones contradictorias o incompletas':($r?$nombre:'Sin aliado asociado'),
                'procedencia'=>$conflicto?'Revisión requerida':$origen,
                'tipo'=>$conflicto?'':($r['tipo']??''),'id'=>$conflicto?0:($r['id']??0),
                'estado_id'=>$conflicto?0:(int)($registro['estado_id']??0),
                'municipio'=>$conflicto?'':($municipios[(int)($registro['municipio_id']??0)]??($registro['ciudad']??'')),
                'region'=>$conflicto?'':($registro['region']??''),
                'programa_codigo'=>$codigo,
                'tiene_relacion'=>(bool)$grupo,
                'detalle_url'=>''
            ];
        }
        unset($fila);
        return $filas;
    }

    public static function filtrar(array $filas,string $buscar): array
    {
        $normalizar=static fn(string $v):string=>strtr(mb_strtolower(trim($v),'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $buscar=$normalizar($buscar);
        if($buscar==='') return $filas;
        return array_values(array_filter($filas,static function(array $r) use($buscar,$normalizar): bool {
            $texto=implode(' ',[$r['titulo']??'',$r['programa_nombre']??'',$r['aliado']['nombre']??'']);
            return mb_strpos($normalizar($texto),$buscar)!==false;
        }));
    }
}
