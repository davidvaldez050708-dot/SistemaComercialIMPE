<?php
require_once __DIR__ . '/../helpers/PermissionHelper.php';

/** Operaciones territoriales y perfil de Registro. Nunca crea expedientes. */
class AliadoRegistroService
{
    private mysqli $db;
    public const TIPOS = ['EMPRESA','ORGANIZACION','INSTITUCION','SECRETARIA','MUNICIPIO','OTRO'];
    public function __construct(mysqli $db) { $this->db = $db; }
    private function query(string $sql, array $params = []): mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        if ($params) { $types = implode('', array_map(fn($v) => is_int($v) ? 'i' : 's', $params)); $s->bind_param($types, ...$params); }
        $s->execute(); return $s;
    }
    public function disponible(): bool
    {
        return (bool)$this->db->query("SHOW TABLES LIKE 'formulario_aliado_registro'")->num_rows;
    }
    public function catalogo(): array
    {
        return $this->db->query('SELECT codigo,nombre FROM formulario_programas WHERE activo=1 ORDER BY nombre')->fetch_all(MYSQLI_ASSOC);
    }
    public function autorizar(string $permiso): void
    {
        if (!tienePermiso($permiso)) { throw new DomainException('No tienes permiso para esta operación.',403); }
    }
    public function territorio(int $estadoId): void
    {
        $s = $this->query("SELECT a.id FROM asignaciones_territorio a JOIN estados e ON e.id=a.estado_id AND e.estado=1 WHERE a.usuario_id=? AND a.estado_id=? AND a.tipo_asignacion='CUENTA_CLAVE' AND a.activo=1 AND (a.fecha_inicio IS NULL OR a.fecha_inicio<=CURDATE()) AND (a.fecha_fin IS NULL OR a.fecha_fin>=CURDATE()) LIMIT 1", [(int)($_SESSION['usuario_id']??0),$estadoId]);
        if (!$s->get_result()->num_rows) { throw new DomainException('No tienes acceso a este territorio.',403); }
    }
    public function origen(string $tipo, int $id, bool $marketing = false): array
    {
        if ($tipo === 'manual') {
            $row = $this->query('SELECT a.*, e.nombre estado_nombre, m.nombre municipio_nombre FROM aliados_manuales a JOIN estados e ON e.id=a.estado_id LEFT JOIN municipios m ON m.id=a.municipio_id WHERE a.id=? AND a.activo=1',[$id])->get_result()->fetch_assoc();
        } elseif ($tipo === 'institucional') {
            $row = $this->query("SELECT s.*, e.nombre estado_nombre, m.nombre municipio_nombre, COALESCE(NULLIF(s.correo_verificado,''),s.correo_fuente) correo_contacto FROM aliados_asignaciones a JOIN seguimientos_vinculacion s ON s.id=a.seguimiento_id JOIN estados e ON e.id=s.estado_id LEFT JOIN municipios m ON m.id=s.municipio_id WHERE s.id=? AND s.activo=1 AND a.activo=1",[$id])->get_result()->fetch_assoc();
        } else { throw new DomainException('Origen de aliado inválido.'); }
        if (!$row) { throw new DomainException('Aliado no disponible.',404); }
        if (!$marketing) { $this->territorio((int)$row['estado_id']); }
        return $row;
    }
    public static function normalizarNombre(string $nombre): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',$nombre)), 'UTF-8');
    }
    private function programas(array $codigos): array
    {
        $codigos = array_values(array_unique($codigos));
        $validos = array_column($this->catalogo(),'codigo');
        if (!$codigos || count($codigos)>50 || array_diff($codigos,$validos)) { throw new DomainException('Selecciona programas activos del catálogo.'); }
        return $codigos;
    }
    public function guardarManual(array $datos): int
    {
        $id = (int)($datos['id']??0);
        $this->autorizar($id ? 'aliados.editar_manual' : 'aliados.crear_manual');
        $this->autorizar('aliados.gestionar_programas');
        $estado = (int)($datos['estado_id']??0); $this->territorio($estado);
        if ($id) { $original = $this->origen('manual',$id); if ((int)$original['estado_id']!==$estado) { throw new DomainException('No se puede trasladar el aliado a otro estado.'); } }
        $nombre = trim((string)($datos['nombre_entidad']??'')); $normalizado = self::normalizarNombre($nombre);
        $tipo = (string)($datos['tipo_entidad']??'');
        if (mb_strlen($nombre)<2 || mb_strlen($nombre)>180 || !in_array($tipo,self::TIPOS,true)) { throw new DomainException('Nombre o tipo de institución inválido.'); }
        $municipio = (int)($datos['municipio_id']??0);
        if (!$this->query('SELECT id FROM municipios WHERE id=? AND estado_id=? AND estado=1',[$municipio,$estado])->get_result()->num_rows) { throw new DomainException('Selecciona un municipio del estado.'); }
        $campos = [];
        foreach (['contacto_nombre'=>120,'contacto_cargo'=>120,'correo_contacto'=>180,'telefono_fuente'=>30,'referencia'=>180,'notas'=>1500] as $campo=>$limite) {
            $valor = trim((string)($datos[$campo]??''));
            if (mb_strlen($valor)>$limite) { throw new DomainException('Longitud excedida: '.$campo); } $campos[$campo]=$valor;
        }
        if ($campos['correo_contacto']!=='' && !filter_var($campos['correo_contacto'],FILTER_VALIDATE_EMAIL)) { throw new DomainException('Correo inválido.'); }
        if ($campos['telefono_fuente']!=='' && !preg_match('/^[+0-9 ()-]{7,30}$/D',$campos['telefono_fuente'])) { throw new DomainException('Teléfono inválido.'); }
        $fecha = trim((string)($datos['relacion_fecha']??''));
        if ($fecha!=='' && (!($f=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha)) || $f->format('Y-m-d')!==$fecha)) { throw new DomainException('Fecha inválida.'); }
        $situacion = (string)($datos['situacion']??'VIGENTE');
        if (!in_array($situacion,['VIGENTE','HISTORICA'],true)) { throw new DomainException('Situación inválida.'); }
        $programas = $this->programas((array)($datos['programas']??[]));
        $usuario = (int)$_SESSION['usuario_id'];
        $this->db->begin_transaction();
        try {
            // Serialización territorial: también evita carreras entre dos altas manuales.
            $this->query('SELECT id FROM estados WHERE id=? FOR UPDATE',[$estado]);
            $dup = $this->query('SELECT id FROM aliados_manuales WHERE estado_id=? AND municipio_id=? AND nombre_normalizado=? AND id<>? LIMIT 1',[$estado,$municipio,$normalizado,$id])->get_result()->num_rows;
            $instituciones = $this->query('SELECT s.nombre_entidad FROM seguimientos_vinculacion s JOIN aliados_asignaciones a ON a.seguimiento_id=s.id AND a.activo=1 WHERE s.estado_id=? AND COALESCE(s.municipio_id,0)=? AND s.activo=1',[$estado,$municipio])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($instituciones as $institucion) { if (self::normalizarNombre($institucion['nombre_entidad'])===$normalizado) { $dup=true; } }
            if ($dup) { throw new DomainException('La institución ya existe en este municipio. Revisa el directorio, incluidos los aliados desactivados.'); }
            $valores = [$estado,$municipio,$nombre,$normalizado,$tipo,...array_values($campos),$fecha?:null,$situacion,$usuario];
            if ($id) {
                $this->query('UPDATE aliados_manuales SET estado_id=?,municipio_id=?,nombre_entidad=?,nombre_normalizado=?,tipo_entidad=?,contacto_nombre=?,contacto_cargo=?,correo_contacto=?,telefono_fuente=?,referencia=?,notas=?,relacion_fecha=?,situacion=?,actualizado_por=? WHERE id=?',[...$valores,$id]);
            } else {
                $this->query('INSERT INTO aliados_manuales (estado_id,municipio_id,nombre_entidad,nombre_normalizado,tipo_entidad,contacto_nombre,contacto_cargo,correo_contacto,telefono_fuente,referencia,notas,relacion_fecha,situacion,actualizado_por,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$valores,$usuario]); $id=(int)$this->db->insert_id;
            }
            $origen = $this->origen('manual',$id); $perfil=$this->perfil('manual',$id,$origen);
            $this->asignar($perfil,$programas);
            $this->db->commit(); return $id;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private function perfil(string $tipo,int $id,array $origen): int
    {
        $col = $tipo==='manual' ? 'manual_id' : 'seguimiento_id';
        $fila = $this->query("SELECT aliado_id FROM formulario_aliado_registro WHERE $col=?",[$id])->get_result()->fetch_assoc();
        $ubicacion = trim(($origen['municipio_nombre']??'').' · '.$origen['estado_nombre'],' ·');
        if ($fila) {
            $this->query('SELECT id FROM formulario_aliados WHERE id=? FOR UPDATE',[(int)$fila['aliado_id']]);
            $this->query('UPDATE formulario_aliado_registro SET actualizado_por=?,updated_at=NOW() WHERE aliado_id=?',[(int)$_SESSION['usuario_id'],(int)$fila['aliado_id']]);
            $this->query('UPDATE formulario_aliados SET nombre=?,ubicacion=? WHERE id=?',[$origen['nombre_entidad'],$ubicacion,(int)$fila['aliado_id']]);
            return (int)$fila['aliado_id'];
        }
        $token = bin2hex(random_bytes(32)); $slug='registro-'.$tipo.'-'.$id;
        $this->query('INSERT INTO formulario_aliados (slug,nombre,ubicacion,token,activo) VALUES (?,?,?,?,0)',[$slug,$origen['nombre_entidad'],$ubicacion,$token]); $perfil=(int)$this->db->insert_id;
        $this->query("INSERT INTO formulario_aliado_registro (aliado_id,$col,actualizado_por) VALUES (?,?,?)",[$perfil,$id,(int)$_SESSION['usuario_id']]); return $perfil;
    }
    private function asignar(int $perfil,array $programas): void
    {
        $this->query('DELETE FROM formulario_aliado_programas WHERE aliado_id=?',[$perfil]);
        foreach ($programas as $codigo) { $this->query('INSERT INTO formulario_aliado_programas VALUES (?,?)',[$perfil,$codigo]); }
    }
    public function configurar(string $tipo,int $id,array $codigos): void
    {
        $this->autorizar('aliados.gestionar_programas'); $origen=$this->origen($tipo,$id); $codigos=$this->programas($codigos);
        $this->db->begin_transaction();
        try {
            $tabla=$tipo==='manual'?'aliados_manuales':'seguimientos_vinculacion';
            $this->query("SELECT id FROM $tabla WHERE id=? FOR UPDATE",[$id]);
            $origen=$this->origen($tipo,$id);
            $this->asignar($this->perfil($tipo,$id,$origen),$codigos); $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function desactivar(int $id): void
    {
        $this->autorizar('aliados.editar_manual'); $this->origen('manual',$id);
        $this->db->begin_transaction();
        try {
            $this->query('SELECT id FROM aliados_manuales WHERE id=? FOR UPDATE',[$id]);
            $perfil=$this->query('SELECT aliado_id FROM formulario_aliado_registro WHERE manual_id=?',[$id])->get_result()->fetch_assoc();
            if ($perfil) $this->query('SELECT id FROM formulario_aliados WHERE id=? FOR UPDATE',[(int)$perfil['aliado_id']]);
            $this->query('UPDATE aliados_manuales SET activo=0,actualizado_por=? WHERE id=?',[(int)$_SESSION['usuario_id'],$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function detalle(string $tipo,int $id,bool $marketing=false): array
    {
        $origen=$this->origen($tipo,$id,$marketing); $col=$tipo==='manual'?'manual_id':'seguimiento_id';
        $perfil=$this->query("SELECT r.*,a.token FROM formulario_aliado_registro r JOIN formulario_aliados a ON a.id=r.aliado_id WHERE r.$col=?",[$id])->get_result()->fetch_assoc();
        $codigos=$perfil?$this->query('SELECT programa_codigo FROM formulario_aliado_programas WHERE aliado_id=?',[(int)$perfil['aliado_id']])->get_result()->fetch_all(MYSQLI_ASSOC):[];
        return ['origen'=>$origen,'programas'=>array_column($codigos,'programa_codigo'),'catalogo'=>$this->catalogo(),'publicado'=>(bool)($perfil['publicado']??false),'habilitado'=>(bool)($perfil['habilitado']??false),'url'=>($marketing && !empty($perfil['publicado']))?BASE_URL.'index.php?controller=formularioPublico&action=registro&aliado='.$perfil['token']:''];
    }
    public function enlace(string $tipo,int $id,string $accion): array
    {
        $this->autorizar('formularios_aliados.gestionar_enlaces');
        if (strcasecmp($_SESSION['rol']??'','Marketing')!==0) { throw new DomainException('Operación exclusiva de Marketing.',403); }
        $this->origen($tipo,$id,true); $col=$tipo==='manual'?'manual_id':'seguimiento_id';
        $this->db->begin_transaction();
        try {
            $perfil=$this->query("SELECT aliado_id,habilitado FROM formulario_aliado_registro WHERE $col=?",[$id])->get_result()->fetch_assoc();
            if (!$perfil) { throw new DomainException('Cuenta Clave debe seleccionar los programas antes de generar el enlace.'); }
            $this->query('SELECT id FROM formulario_aliados WHERE id=? FOR UPDATE',[(int)$perfil['aliado_id']]);
            $perfil=$this->query('SELECT aliado_id,habilitado FROM formulario_aliado_registro WHERE aliado_id=? FOR UPDATE',[(int)$perfil['aliado_id']])->get_result()->fetch_assoc();
            $programas=$this->query('SELECT p.codigo FROM formulario_aliado_programas ap JOIN formulario_programas p ON p.codigo=ap.programa_codigo AND p.activo=1 WHERE ap.aliado_id=?',[(int)$perfil['aliado_id']])->get_result()->num_rows;
            if (!$programas && $accion!=='deshabilitar') { throw new DomainException('El aliado no tiene programas activos.'); }
            if (!in_array($accion,['generar','habilitar','deshabilitar'],true)) { throw new DomainException('Acción inválida.'); }
            $this->query('UPDATE formulario_aliado_registro SET publicado=1,habilitado=?,actualizado_por=? WHERE aliado_id=?',[$accion==='generar'?(int)$perfil['habilitado']:($accion==='deshabilitar'?0:1),(int)$_SESSION['usuario_id'],(int)$perfil['aliado_id']]);
            $this->db->commit(); return $this->detalle($tipo,$id,true);
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private function verificarManualModulos(): void
    {
        foreach (['aliados_manuales_contactos','aliados_manuales_difusiones'] as $tabla) {
            if (!$this->db->query("SHOW TABLES LIKE '$tabla'")->num_rows) {
                throw new DomainException('Falta actualizar la estructura de contactos de aliados manuales.',503);
            }
        }
    }
    public function consultarGestionManual(int $id): array
    {
        $this->autorizar('aliados.gestionar_programas');
        $this->origen('manual',$id);
        $this->verificarManualModulos();
        $contactos=$this->query('SELECT id,nombre,cargo,correo,telefono,whatsapp_autorizado FROM aliados_manuales_contactos WHERE aliado_id=? AND activo=1 ORDER BY id',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        $historial=$this->query('SELECT d.id,d.canal,d.nota,d.realizado_at,CONCAT_WS(" ",u.nombre,u.apellidos) responsable FROM aliados_manuales_difusiones d LEFT JOIN usuarios u ON u.id=d.registrado_por WHERE d.aliado_id=? ORDER BY d.realizado_at DESC,d.id DESC LIMIT 100',[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
        return ['contactos'=>$contactos,'historial'=>$historial];
    }
    public function guardarContactoManual(array $datos): void
    {
        $this->autorizar('aliados.editar_manual');
        $aliado=(int)($datos['id']??0);
        $this->origen('manual',$aliado);
        $this->verificarManualModulos();
        $contactoId=(int)($datos['contacto_id']??0);
        $nombre=trim((string)($datos['nombre']??''));
        $cargo=trim((string)($datos['cargo']??''));
        $correo=trim((string)($datos['correo']??''));
        $telefono=trim((string)($datos['telefono']??''));
        $whatsapp=(int)(!empty($datos['whatsapp_autorizado']));
        if (mb_strlen($nombre)<2||mb_strlen($nombre)>120||mb_strlen($cargo)>120||mb_strlen($correo)>180||mb_strlen($telefono)>30)
            throw new DomainException('Revisa los datos del contacto.');
        if ($correo!==''&&!filter_var($correo,FILTER_VALIDATE_EMAIL)) throw new DomainException('Correo del contacto inválido.');
        if ($telefono!==''&&!preg_match('/^[+0-9 ()-]{7,30}$/D',$telefono)) throw new DomainException('Teléfono del contacto inválido.');
        if ($whatsapp&&!$telefono) throw new DomainException('Se requiere teléfono para autorizar WhatsApp.');
        if ($contactoId) {
            $stmt=$this->query('UPDATE aliados_manuales_contactos SET nombre=?,cargo=?,correo=?,telefono=?,whatsapp_autorizado=? WHERE id=? AND aliado_id=? AND activo=1',[$nombre,$cargo,$correo,$telefono,$whatsapp,$contactoId,$aliado]);
            if (!$stmt->affected_rows) throw new DomainException('Contacto no disponible.');
        } else {
            $this->query('INSERT INTO aliados_manuales_contactos (aliado_id,nombre,cargo,correo,telefono,whatsapp_autorizado,creado_por) VALUES (?,?,?,?,?,?,?)',[$aliado,$nombre,$cargo,$correo,$telefono,$whatsapp,(int)$_SESSION['usuario_id']]);
        }
    }
    public function desactivarContactoManual(int $aliado,int $contacto): void
    {
        $this->autorizar('aliados.editar_manual');
        $this->origen('manual',$aliado);
        $this->verificarManualModulos();
        $stmt=$this->query('UPDATE aliados_manuales_contactos SET activo=0 WHERE aliado_id=? AND id=? AND activo=1',[$aliado,$contacto]);
        if (!$stmt->affected_rows) throw new DomainException('Contacto no disponible.');
    }
    public function registrarDifusionManual(array $datos): void
    {
        $this->autorizar('aliados.editar_manual');
        $aliado=(int)($datos['id']??0);
        $this->origen('manual',$aliado);
        $this->verificarManualModulos();
        $canal=strtoupper(trim((string)($datos['canal']??'')));
        $nota=trim((string)($datos['nota']??''));
        if (!in_array($canal,['CORREO','WHATSAPP','OTRO'],true)||mb_strlen($nota)>500)
            throw new DomainException('Selecciona canal y una nota válida.');
        $this->query('INSERT INTO aliados_manuales_difusiones (aliado_id,canal,nota,registrado_por) VALUES (?,?,?,?)',[$aliado,$canal,$nota,(int)$_SESSION['usuario_id']]);
    }

    public function manuales(?int $estado=null): array
    {
        if (!$this->disponible()) { return []; }
        $sql="SELECT a.*,0 seguimiento_id,'manual' origen_tipo,a.id origen_id,e.nombre estado_nombre,COALESCE(m.nombre,'') municipio_nombre,NULL convenio_formalizado_at,a.relacion_fecha convenio_fecha,a.notas convenio_notas,0 tiene_convocatoria,'' analista_nombre,'' analista_apellidos FROM aliados_manuales a JOIN estados e ON e.id=a.estado_id LEFT JOIN municipios m ON m.id=a.municipio_id WHERE a.activo=1";
        return $this->query($sql.($estado!==null?' AND a.estado_id=?':'').' ORDER BY a.nombre_entidad',$estado!==null?[$estado]:[])->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function decorar(array $filas): array
    {
        if (!$this->disponible()) { return $filas; }
        $perfiles=$this->db->query("SELECT r.manual_id,r.seguimiento_id,r.publicado,r.habilitado,GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' · ') programas FROM formulario_aliado_registro r LEFT JOIN formulario_aliado_programas ap ON ap.aliado_id=r.aliado_id LEFT JOIN formulario_programas p ON p.codigo=ap.programa_codigo AND p.activo=1 GROUP BY r.aliado_id,r.manual_id,r.seguimiento_id,r.publicado,r.habilitado")->fetch_all(MYSQLI_ASSOC);
        $map=[];
        foreach ($perfiles as $p) $map[($p['manual_id']?'manual:'.$p['manual_id']:'institucional:'.$p['seguimiento_id'])]=$p;
        foreach ($filas as &$fila) {
            $tipo=$fila['origen_tipo']??'institucional'; $id=$fila['origen_id']??$fila['seguimiento_id'];
            $p=$map[$tipo.':'.$id]??[];
            $fila['registro_programas']=$p['programas']??'';
            $fila['registro_estado']=empty($p['publicado'])?'Sin enlace':(empty($p['habilitado'])?'Registro deshabilitado':'Registro habilitado');
        }
        return $filas;
    }
    public function resumenManuales(array $estadoIds): array
    {
        if (!$this->disponible() || !$estadoIds) return [];
        $marks=implode(',',array_fill(0,count($estadoIds),'?'));
        return $this->query("SELECT estado_id,COUNT(*) total FROM aliados_manuales WHERE activo=1 AND estado_id IN ($marks) GROUP BY estado_id",array_map('intval',$estadoIds))->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function municipiosTerritoriales(int $usuario,bool $administrador,array $estadoIds): array
    {
        if (!$this->disponible() || !$estadoIds) return [];
        $marks=implode(',',array_fill(0,count($estadoIds),'?'));
        $scope=$administrador?'':' AND a.cuenta_clave_usuario_id=? AND a.activo=1';
        $params=$administrador?[]:[$usuario];
        $params=array_merge($params,array_map('intval',$estadoIds),array_map('intval',$estadoIds));
        return $this->query("SELECT estado_id,COUNT(DISTINCT municipio_id) total FROM (SELECT s.estado_id,s.municipio_id FROM seguimientos_vinculacion s JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id LEFT JOIN aliados_asignaciones a ON a.seguimiento_id=s.id WHERE s.activo=1 AND p.convenio_formalizado_at IS NOT NULL $scope AND s.estado_id IN ($marks) UNION ALL SELECT estado_id,municipio_id FROM aliados_manuales WHERE activo=1 AND estado_id IN ($marks)) red WHERE municipio_id>0 GROUP BY estado_id",$params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
