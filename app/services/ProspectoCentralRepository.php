<?php
require_once __DIR__.'/ProspectoCentralSchema.php';
require_once __DIR__.'/../helpers/ProspectoCentralDatos.php';
require_once __DIR__.'/../helpers/ProspectoFechaHoraHelper.php';

final class ProspectoCentralRepository
{
    private mysqli $db;
    private ?array $schema=null;
    public function __construct(mysqli $db) {$this->db=$db;}
    public function estructura(): array {return $this->schema??=$this->revisarEstructura();}
    private function revisarEstructura(): array {return ProspectoCentralSchema::revisar($this->db);}
    public function consulta(string $sql,array $args=[]): mysqli_result
    {
        $stmt=$this->db->prepare($sql);
        if ($args) {$types=implode('',array_map(static fn($a)=>is_int($a)?'i':'s',$args));$stmt->bind_param($types,...$args);}
        $stmt->execute();$result=$stmt->get_result();$stmt->close();
        if (!$result) throw new RuntimeException('Consulta comercial no disponible.');return $result;
    }
    public function ejecutar(string $sql,array $args=[]): int
    {
        $stmt=$this->db->prepare($sql);
        if ($args) {$types=implode('',array_map(static fn($a)=>is_int($a)?'i':'s',$args));$stmt->bind_param($types,...$args);}
        $stmt->execute();$changed=$stmt->affected_rows;$stmt->close();return $changed;
    }
    private function insertar(string $table,array $d): int
    {
        $columns=implode(',',array_keys($d));
        $this->ejecutar("INSERT INTO $table ($columns) VALUES (".implode(',',array_fill(0,count($d),'?')).')',array_values($d));
        return (int)$this->db->insert_id;
    }
    public function crearPersona(array $datos): int
    {
        $d=ProspectoCentralDatos::persona($datos);$now=gmdate('Y-m-d H:i:s');
        $id=$this->insertar('prospectos_comerciales',$d+['created_at'=>$now,'updated_at'=>$now]);
        $this->insertar('prospecto_integraciones',['prospecto_id'=>$id,'sistema'=>'ZOHO','referencia_estable'=>'CRM-PROS-'.str_pad((string)$id,5,'0',STR_PAD_LEFT),'estado'=>'NO_CONFIGURADO']);
        return $id;
    }
    public function origen(string $sistema,string $tipo,string $identificador): ?array
    {
        return $this->consulta('SELECT * FROM prospecto_origenes WHERE sistema_origen=? AND tipo_registro=? AND identificador_origen=?',[$sistema,$tipo,$identificador])->fetch_assoc();
    }
    public function guardarOrigen(int $persona,array $d): int
    {
        foreach (['tipo_origen','sistema_origen','tipo_registro','identificador_origen','datos_originales_ref'] as $key) if (!isset($d[$key]) || !is_string($d[$key]) || $d[$key]==='') throw new DomainException('Procedencia incompleta.');
        if (!in_array($d['tipo_origen'],['ALIADO','FORMULARIO_GENERAL','META_ADS'],true)) throw new DomainException('Procedencia no válida.');
        $columns=['tipo_origen','sistema_origen','tipo_registro','identificador_origen','datos_originales_ref','origen_padre_id',
            'registro_tabla','registro_id','aliado_id','aliado_nombre','formulario_id','formulario_nombre','campania_id','campania_nombre',
            'conjunto_id','conjunto_nombre','anuncio_id','anuncio_nombre','meta_lead_id','plataforma','fecha_registro_original',
            'fecha_registro_texto','zona_horaria_origen','fuente_hash','lote_importacion_ref'];
        $row=array_intersect_key($d,array_flip($columns));
        foreach (['identificador_origen','formulario_id','campania_id','conjunto_id','anuncio_id','meta_lead_id','registro_id','lote_importacion_ref'] as $key) {
            if (isset($row[$key]) && (!is_string($row[$key]) || mb_strlen($row[$key])>190)) throw new DomainException('Los identificadores de origen deben ser texto.');
        }
        $existing=$this->origen($d['sistema_origen'],$d['tipo_registro'],$d['identificador_origen']);
        if ($existing) {
            if ((int)$existing['prospecto_id']!==$persona) throw new DomainException('El evento ya pertenece a otra identidad comercial.');
            $set=implode(',',array_map(static fn($k)=>$k.'=?',array_keys($row)));
            $this->ejecutar('UPDATE prospecto_origenes SET '.$set.' WHERE id=?',[...array_values($row),(int)$existing['id']]);
            return (int)$existing['id'];
        }
        return $this->insertar('prospecto_origenes',['prospecto_id'=>$persona]+$row+['incorporado_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public function interes(int $persona,int $origen,?string $codigo,string $nombre,?string $fecha): void
    {
        if ($nombre==='') return;
        $key=hash('sha256',$codigo!==null?'catalogo:'.$codigo:'texto:'.$nombre);
        if ($this->consulta('SELECT id FROM prospecto_intereses WHERE origen_id=? AND programa_clave=?',[$origen,$key])->num_rows) return;
        $this->insertar('prospecto_intereses',['prospecto_id'=>$persona,'origen_id'=>$origen,'programa_clave'=>$key,'programa_codigo'=>$codigo,
            'nombre_original'=>$nombre,'fecha_interes'=>$fecha,'clasificacion_estado'=>$codigo!==null?'CLASIFICADO':'PENDIENTE']);
    }
    public function preferencia(int $origen,?string $original): void
    {
        if ($this->consulta('SELECT origen_id FROM prospecto_preferencias_contacto WHERE origen_id=?',[$origen])->num_rows) return;
        $this->insertar('prospecto_preferencias_contacto',['origen_id'=>$origen]+ProspectoCentralDatos::horario($original));
    }
    public function resumen(): array
    {
        if (!$this->estructura()['disponible']) return ['personas'=>null,'meta'=>null,'por_incorporar'=>null];
        $personas=(int)$this->consulta('SELECT COUNT(*) n FROM prospectos_comerciales')->fetch_assoc()['n'];
        $meta=(int)$this->consulta("SELECT COUNT(*) n FROM prospecto_origenes WHERE tipo_origen='META_ADS' AND origen_padre_id IS NULL")->fetch_assoc()['n'];
        $pending=(int)$this->consulta("SELECT COUNT(*) n FROM formulario_registros r WHERE NOT EXISTS(SELECT 1 FROM prospecto_origenes o WHERE o.sistema_origen='IMPE_FORMULARIOS' AND o.tipo_registro='REGISTRO' AND o.identificador_origen=CAST(r.id AS CHAR))")->fetch_assoc()['n'];
        return ['personas'=>$personas,'meta'=>$meta,'por_incorporar'=>$pending];
    }
    public function listar(array $filtros): array
    {
        $page=filter_var($filtros['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:1;
        $size=filter_var($filtros['limite']??25,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])?:25;
        $search=$filtros['buscar']??'';$source=$filtros['origen']??'';$program=$filtros['programa']??'';
        if (!is_string($search)||mb_strlen($search)>180 || !is_string($source) || !in_array($source,['','ALIADO','FORMULARIO_GENERAL','META_ADS'],true) || !is_string($program) || ($program!==''&&!preg_match('/^[a-f0-9]{64}$/D',$program))) throw new DomainException('Filtros de prospectos no válidos.');
        $where=' WHERE 1=1';$args=[];
        if ($search!=='') {
            $like='%'.strtr(trim($search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where.=" AND (p.nombre_completo LIKE ? ESCAPE '!' OR p.telefono_original LIKE ? ESCAPE '!' OR p.telefono_normalizado LIKE ? ESCAPE '!' OR p.correo LIKE ? ESCAPE '!')";$args=[$like,$like,$like,$like];
        }
        if ($source!=='') {$where.=' AND EXISTS(SELECT 1 FROM prospecto_origenes o WHERE o.prospecto_id=p.id AND o.tipo_origen=?)';$args[]=$source;}
        if ($program!=='') {$where.=' AND EXISTS(SELECT 1 FROM prospecto_intereses i WHERE i.prospecto_id=p.id AND i.programa_clave=?)';$args[]=$program;}
        $total=(int)$this->consulta('SELECT COUNT(*) n FROM prospectos_comerciales p'.$where,$args)->fetch_assoc()['n'];
        $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        $rows=$this->consulta("SELECT p.*,
            (SELECT MIN(o.fecha_registro_original) FROM prospecto_origenes o WHERE o.prospecto_id=p.id AND o.origen_padre_id IS NULL AND o.zona_horaria_origen='UTC') fecha_origen,
            (SELECT GROUP_CONCAT(DISTINCT i.nombre_original ORDER BY i.id SEPARATOR ' · ') FROM prospecto_intereses i WHERE i.prospecto_id=p.id) programas,
            (SELECT GROUP_CONCAT(DISTINCT COALESCE(o.aliado_nombre,CASE o.tipo_origen WHEN 'META_ADS' THEN COALESCE(o.formulario_nombre,'Meta Ads') WHEN 'FORMULARIO_GENERAL' THEN COALESCE(o.formulario_nombre,'Registro general') ELSE NULL END) ORDER BY o.id SEPARATOR ' · ') FROM prospecto_origenes o WHERE o.prospecto_id=p.id) procedencias,
            (SELECT pref.valor_original FROM prospecto_preferencias_contacto pref JOIN prospecto_origenes o ON o.id=pref.origen_id WHERE o.prospecto_id=p.id ORDER BY o.fecha_registro_original DESC,o.id DESC LIMIT 1) horario,
            (SELECT a.estado FROM prospecto_asignaciones a WHERE a.prospecto_id=p.id AND a.vigente=1 LIMIT 1) gestion
            FROM prospectos_comerciales p $where ORDER BY p.created_at DESC,p.id DESC LIMIT ? OFFSET ?",[...$args,$size,($page-1)*$size])->fetch_all(MYSQLI_ASSOC);
        $programs=$this->consulta('SELECT programa_clave,MIN(nombre_original) nombre FROM prospecto_intereses GROUP BY programa_clave ORDER BY nombre LIMIT 500')->fetch_all(MYSQLI_ASSOC);
        return ['registros'=>$rows,'total'=>$total,'pagina'=>$page,'paginas'=>$pages,'limite'=>$size,'programas'=>$programs];
    }
    public function detalle(int $id,int $pagina=1,int $paginaIntereses=1,?int $registroId=null): ?array
    {
        $p=$this->consulta('SELECT p.*,e.nombre estado_nombre,m.nombre municipio_nombre FROM prospectos_comerciales p LEFT JOIN estados e ON e.id=p.estado_id LEFT JOIN municipios m ON m.id=p.municipio_id WHERE p.id=?',[$id])->fetch_assoc();
        if (!$p) return null;
        $total=(int)$this->consulta('SELECT COUNT(*) n FROM prospecto_origenes WHERE prospecto_id=?',[$id])->fetch_assoc()['n'];
        $pages=max(1,(int)ceil($total/25));$pagina=max(1,min($pagina,$pages));
        $p['origenes']=$this->consulta('SELECT o.*,pref.valor_original,pref.dias_semana,pref.hora_desde,pref.hora_hasta,pref.zona_horaria,pref.interpretacion_estado FROM prospecto_origenes o LEFT JOIN prospecto_preferencias_contacto pref ON pref.origen_id=o.id WHERE o.prospecto_id=? ORDER BY o.fecha_registro_original DESC,o.id DESC LIMIT 25 OFFSET ?',[$id,($pagina-1)*25])->fetch_all(MYSQLI_ASSOC);
        $p['origenes_total']=$total;$p['origenes_pagina']=$pagina;$p['origenes_paginas']=$pages;
        $p['intereses_total']=(int)$this->consulta('SELECT COUNT(*) n FROM prospecto_intereses WHERE prospecto_id=?',[$id])->fetch_assoc()['n'];
        $p['intereses_paginas']=max(1,(int)ceil($p['intereses_total']/25));$p['intereses_pagina']=max(1,min($paginaIntereses,$p['intereses_paginas']));
        $p['intereses']=$this->consulta('SELECT i.* FROM prospecto_intereses i WHERE i.prospecto_id=? ORDER BY i.id DESC LIMIT 25 OFFSET ?',[$id,($p['intereses_pagina']-1)*25])->fetch_all(MYSQLI_ASSOC);
        $p['asignaciones']=$this->consulta('SELECT a.*,CONCAT_WS(CHAR(32),u.nombre,u.apellidos) asesor FROM prospecto_asignaciones a LEFT JOIN usuarios u ON u.id=a.asesor_id WHERE a.prospecto_id=? ORDER BY a.id DESC LIMIT 25',[$id])->fetch_all(MYSQLI_ASSOC);
        $p['integraciones']=$this->consulta('SELECT sistema,externo_id,modulo_destino,estado,ultimo_intento_at,sincronizado_at,referencia_estable FROM prospecto_integraciones WHERE prospecto_id=?',[$id])->fetch_all(MYSQLI_ASSOC);
        $p['coincidencias']=$p['telefono_normalizado']===null?0:(int)$this->consulta('SELECT COUNT(*) n FROM prospectos_comerciales WHERE telefono_normalizado=? AND id<>?',[$p['telefono_normalizado'],$id])->fetch_assoc()['n'];
        $root=$this->consulta("SELECT registro_id FROM prospecto_origenes WHERE prospecto_id=? AND sistema_origen='IMPE_FORMULARIOS' AND tipo_registro='REGISTRO' ".($registroId!==null?' AND registro_id=?':'').' ORDER BY id LIMIT 1', $registroId!==null?[$id,(string)$registroId]:[$id])->fetch_assoc();
        $p['original']=$root?$this->registroOriginal((int)$root['registro_id']):null;
        return $p;
    }
    public function registroOriginal(int $id): ?array
    {
        return $this->consulta('SELECT r.*,e.nombre estado_nombre,m.nombre municipio_nombre FROM formulario_registros r LEFT JOIN estados e ON e.id=r.estado_id LEFT JOIN municipios m ON m.id=r.municipio_id WHERE r.id=?',[$id])->fetch_assoc();
    }
    public function asignaciones(int $pagina=1): array
    {
        $total=(int)$this->consulta('SELECT COUNT(*) n FROM prospecto_asignaciones')->fetch_assoc()['n'];
        $pages=max(1,(int)ceil($total/25));$pagina=max(1,min($pagina,$pages));
        $rows=$this->consulta('SELECT a.*,p.nombre_completo,CONCAT_WS(CHAR(32),u.nombre,u.apellidos) asesor FROM prospecto_asignaciones a JOIN prospectos_comerciales p ON p.id=a.prospecto_id LEFT JOIN usuarios u ON u.id=a.asesor_id ORDER BY a.id DESC LIMIT 25 OFFSET ?',[($pagina-1)*25])->fetch_all(MYSQLI_ASSOC);
        return ['registros'=>$rows,'total'=>$total,'pagina'=>$pagina,'paginas'=>$pages];
    }
}
