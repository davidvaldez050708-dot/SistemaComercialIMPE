<?php
require_once __DIR__.'/../../app/helpers/ProgramaEducativo.php';

/** Migración DML reversible. Nunca desactiva claves foráneas ni borra personas. */
final class UnificarBachillerato
{
    private mysqli $db;
    private array $tables=[];
    private const FIELDS=[
        'formulario_programas'=>['codigo','nombre'],
        'formulario_aliado_programas'=>['programa_codigo'],
        'formulario_aliado_procedencias'=>['programa_codigo'],
        'formulario_aliado_convocatorias'=>['programa_codigo'],
        'marketing_aliado_manual_convocatorias'=>['programa_codigo'],
        'marketing_aliado_convocatorias_origen'=>['programa_codigo'],
        'formulario_registros'=>['perfil_interes'],
        'formulario_inscripciones'=>['perfil_interes'],
        'convocatorias'=>['subtipo_convocatoria','titulo'],
        'aliados_convocatorias_envios'=>['convocatoria_titulo'],
    ];

    public function __construct(mysqli $db)
    {
        $this->db=$db;
        $meta=$db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_all(MYSQLI_ASSOC);
        $engines=array_column($meta,'ENGINE','TABLE_NAME');
        if(!isset($engines['formulario_programas'])) throw new RuntimeException('Falta formulario_programas.');
        foreach(self::FIELDS as $table=>$fields) {
            if(!isset($engines[$table])) continue;
            $columns=$db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC);
            foreach($fields as $field) if(!in_array($field,array_column($columns,'Field'),true)) throw new RuntimeException("Estructura no revisada: $table.$field");
            if(strcasecmp($engines[$table],'InnoDB')!==0) throw new RuntimeException("Se requiere InnoDB: $table. No se cambió el motor.");
            foreach($columns as $column) if(stripos($column['Extra'],'GENERATED')!==false) throw new RuntimeException("Columna generada no revisada: $table");
            $this->tables[$table]=$fields;
        }
        // No dejar referencias nuevas sin migrar: cualquier extensión se revisa.
        $refs=$db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='formulario_programas' AND REFERENCED_COLUMN_NAME='codigo'")->fetch_all(MYSQLI_ASSOC);
        $columns=$db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('programa_codigo','perfil_interes')")->fetch_all(MYSQLI_ASSOC);
        foreach(array_merge($refs,$columns) as $ref) {
            $table=$ref['TABLE_NAME'];$field=$ref['COLUMN_NAME'];
            if(in_array($field,$this->tables[$table]??[],true)) continue;
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table.$field)) throw new RuntimeException('Identificador no compatible.');
            $legacy=$db->real_escape_string(ProgramaEducativo::ALIAS);
            $count=$db->query("SELECT COUNT(*) n FROM `$table` WHERE `$field`='$legacy' OR `$field` REGEXP '^Bachillerato([[:space:]]+|-)?286$'")->fetch_assoc()['n'];
            if((int)$count) throw new RuntimeException("Referencia adicional pendiente de revisar: $table.$field ($count). SITIO INTACTO.");
        }
    }

    private function scope(string $table): string
    {
        $parts=[];
        foreach($this->tables[$table] as $field) {
            if(in_array($field,['titulo','convocatoria_titulo'],true)) $parts[]="`$field` REGEXP 'Bachillerato([[:space:]]+|-)?(286|General)'";
            elseif(in_array($field,['nombre','perfil_interes'],true)) $parts[]="(`$field`='Bachillerato General' OR `$field` REGEXP '^Bachillerato([[:space:]]+|-)?286$')";
            else $parts[]="`$field` IN ('bachillerato-general','bachillerato-286')";
        }
        return implode(' OR ',$parts);
    }

    public function snapshot(bool $lock=false): array
    {
        $result=[];
        foreach($this->tables as $table=>$unused) {
            $rows=$this->db->query("SELECT * FROM `$table` WHERE ".$this->scope($table).($lock?' FOR UPDATE':''))->fetch_all(MYSQLI_ASSOC);
            foreach($rows as &$row) ksort($row);unset($row);
            usort($rows,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
            $result[$table]=$rows;
        }
        return $result;
    }

    public function plan(array $before): array
    {
        $plan=[];$general=null;$legacy=null;
        foreach($before['formulario_programas'] as $row) {
            if($row['codigo']===ProgramaEducativo::BACHILLERATO) $general=$row;
            elseif($row['codigo']===ProgramaEducativo::ALIAS) $legacy=$row;
            else throw new RuntimeException('Nombre de Bachillerato en otro código; requiere conciliación explícita.');
        }
        $target=$general??$legacy??['codigo'=>ProgramaEducativo::BACHILLERATO,'nombre'=>ProgramaEducativo::NOMBRE,'activo'=>'1'];
        $target['codigo']=ProgramaEducativo::BACHILLERATO;$target['nombre']=ProgramaEducativo::NOMBRE;
        $target['activo']=(string)max((int)($general['activo']??0),(int)($legacy['activo']??0),($general===null&&$legacy===null)?1:0);
        ksort($target);
        // Una edición previa puede haber renombrado el alias a General sin
        // cambiar su clave. Liberar el nombre UNIQUE dentro de la transacción.
        if($legacy!==null && $legacy['nombre']===ProgramaEducativo::NOMBRE && ($general['nombre']??null)!==ProgramaEducativo::NOMBRE) {
            $temporary=$legacy;$temporary['nombre']='__impe_bachillerato_en_fusion__';
            $plan[]=['table'=>'formulario_programas','before'=>$legacy,'after'=>$temporary];
            $legacy=$temporary;
        }
        if($general!==$target) $plan[]=['table'=>'formulario_programas','before'=>$general,'after'=>$target];
        foreach($before as $table=>$rows) {
            if($table==='formulario_programas') continue;
            foreach($rows as $row) {
                $after=$row;
                foreach($this->tables[$table] as $field) {
                    $value=(string)$row[$field];
                    $after[$field]=$row[$field]===null?null:(in_array($field,['titulo','convocatoria_titulo'],true)?ProgramaEducativo::texto($value):(in_array($field,['nombre','perfil_interes'],true)?ProgramaEducativo::nombre($value):ProgramaEducativo::codigo($value)));
                }
                if($after===$row) continue;
                if($table==='formulario_aliado_programas') {
                    foreach($rows as $other) if($other['aliado_id']===$after['aliado_id'] && $other['programa_codigo']===ProgramaEducativo::BACHILLERATO) {
                        if($other!==$after) throw new RuntimeException('Atributos distintos en la asignación duplicada; revisar antes de fusionar.');
                        $after=null;break;
                    }
                }
                $plan[]=['table'=>$table,'before'=>$row,'after'=>$after];
            }
        }
        if($legacy!==null) $plan[]=['table'=>'formulario_programas','before'=>$legacy,'after'=>null];
        return $plan;
    }

    private function literal($value): string
    {
        return $value===null?'NULL':"'".$this->db->real_escape_string((string)$value)."'";
    }

    private function write(array $change): void
    {
        $table=$change['table'];$before=$change['before'];$after=$change['after'];
        if(!isset($this->tables[$table])) throw new RuntimeException('Tabla del respaldo fuera de alcance.');
        foreach(array_keys($before??$after) as $field) if(!preg_match('/^[a-zA-Z0-9_]+$/D',$field)) throw new RuntimeException('Campo no válido.');
        if($before===null) {
            $sql="INSERT INTO `$table` (`".implode('`,`',array_keys($after)).'`) VALUES ('.implode(',',array_map([$this,'literal'],array_values($after))).')';
        } else {
            $where=[];foreach($before as $key=>$value) $where[]="BINARY `$key` <=> BINARY ".$this->literal($value);
            if($after===null) $sql="DELETE FROM `$table` WHERE ".implode(' AND ',$where).' LIMIT 1';
            else {
                $set=[];foreach($after as $key=>$value) $set[]="`$key`=".$this->literal($value);
                $sql="UPDATE `$table` SET ".implode(',',$set).' WHERE '.implode(' AND ',$where).' LIMIT 1';
            }
        }
        $this->db->query($sql);
        if($this->db->affected_rows!==1) throw new RuntimeException('Cambio concurrente o fila no identificada en '.$table);
    }

    public function check(): array
    {
        $before=$this->snapshot();$plan=$this->plan($before);$counts=[];
        foreach($plan as $change) $counts[$change['table']]=($counts[$change['table']]??0)+1;
        return ['acciones'=>count($plan),'tablas'=>$counts];
    }

    public function apply(string $directory): ?string
    {
        $lock='impe_bachillerato_'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetch_row()[0]),0,24);
        if((int)$this->db->query("SELECT GET_LOCK('$lock',0)")->fetch_row()[0]!==1) throw new RuntimeException('Otra unificación está en curso.');
        $this->db->begin_transaction();
        try {
            $before=$this->snapshot(true);$plan=$this->plan($before);
            if(!$plan){$this->db->rollback();return null;}
            $path=$directory.'/bachillerato-general-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
            $backup=['version'=>1,'database'=>$this->db->query('SELECT DATABASE()')->fetch_row()[0],'before'=>$before,'plan'=>$plan];
            $this->save($path,$backup,true);
            foreach($plan as $change) $this->write($change);
            $after=$this->snapshot(true);
            if($this->plan($after)) throw new RuntimeException('La verificación final detectó referencias pendientes.');
            $backup['after']=$after;
            $this->save($path,$backup,false);
            $this->db->commit();return $path;
        } catch(Throwable $error) {$this->db->rollback();throw $error;}
        finally {$this->db->query("SELECT RELEASE_LOCK('$lock')");}
    }

    private function save(string $path,array $data,bool $exclusive): void
    {
        $handle=fopen($path,$exclusive?'x':'r+');if(!$handle) throw new RuntimeException('No se pudo guardar el respaldo privado.');
        try {
            chmod($path,0600);$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(!$exclusive){rewind($handle);ftruncate($handle,0);}
            if(fwrite($handle,$json)!==strlen($json)||!fflush($handle)) throw new RuntimeException('Respaldo incompleto.');
            if(function_exists('fsync')&&!fsync($handle)) throw new RuntimeException('No se confirmó el respaldo en disco.');
        } finally {fclose($handle);}
    }

    public function restore(string $path): void
    {
        $backup=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($backup['version']??0)!==1 || ($backup['database']??null)!==$this->db->query('SELECT DATABASE()')->fetch_row()[0] || !isset($backup['after'],$backup['before'],$backup['plan'])) throw new RuntimeException('Respaldo de otra BD o no confirmado.');
        $this->db->begin_transaction();
        try {
            if($this->snapshot(true)!==$backup['after']) throw new RuntimeException('Restauración bloqueada: hay cambios posteriores en Bachillerato.');
            foreach(array_reverse($backup['plan']) as $change) $this->write(['table'=>$change['table'],'before'=>$change['after'],'after'=>$change['before']]);
            if($this->snapshot(true)!==$backup['before']) throw new RuntimeException('La restauración no coincide con el respaldo.');
            $this->db->commit();
        } catch(Throwable $error) {$this->db->rollback();throw $error;}
    }
}
