<?php
declare(strict_types=1);

/** Instalación única. Repetirla nunca vuelve a conceder permisos revocados. */
final class VentasActividadPermisosService
{
    public const CATALOGO = [
        'reportes.ventas'=>['Reportes de llamadas de asesores','Consultar reportes de actividad telefónica de asesores.'],
        'reportes.ventas.propio'=>['Consultar mis reportes de llamadas','Consultar únicamente las llamadas del asesor autenticado.'],
        'reportes.ventas.equipo'=>['Consultar reportes de mi equipo de asesores','Consultar asesores vinculados a la Cuenta Clave vigente en Territorios.'],
        'reportes.ventas.global'=>['Consultar reportes nacionales de asesores','Consultar todos los asesores desde Administración o Dirección de Operaciones.'],
    ];
    public const COMUNES = ['reportes.ver','reportes.exportar','desempeno.ver','desempeno.exportar'];
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db=$db; }
    public function verificarEstructura(): void
    {
        $required=[
            'telefonia_ventas_llamadas'=>['id','usuario_id','extension','pbx_call_id','direccion','numero','nombre','origen_extension','solicitud_id','fecha'],
            'telefonia_ventas_resultados'=>['id','usuario_id','extension','pbx_call_id','resultado','observacion','prospecto_nombre','contacto_id','proxima_accion_at','recordatorio_atendido_at'],
            'telefonia_zadarma_eventos'=>['id','pbx_call_id','evento','internal','disposition','duration','call_id_with_rec','is_recorded','received_at','payload_json'],
            'asignaciones_territorio'=>['usuario_id','estado_id','tipo_asignacion','cuenta_clave_asignacion_id','activo','fecha_inicio','fecha_fin'],
        ];
        $optional=[
            'telefonia_ventas_evidencia'=>['usuario_id','extension','pbx_call_id','datos_json'],
            'telefonia_ventas_marcaciones'=>['id','usuario_id','extension','destino','pbx_call_id','created_at'],
            'telefonia_contactos_personales'=>['id','usuario_id','proxima_accion_at'],
            'telefonia_disponibilidad'=>['id','origen_usuario','destino_usuario','destino_extension','referencia','ruta_json','devolucion'],
            'telefonia_transferencia_operaciones'=>['id','origen_usuario','destino_usuario','destino_extension','referencia','numero','created_at'],
        ];
        foreach ($required+$optional as $table=>$columns) {
            $s=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();
            $found=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');$s->close();
            if (!$found && isset($optional[$table])) continue;
            if (array_diff($columns,$found)) throw new RuntimeException('Falta actualizar la estructura de '.$table.'. No se han activado permisos.');
        }
    }
    public function comprobar(): bool
    {
        $codes=array_keys(self::CATALOGO);$s=$this->db->prepare('SELECT codigo FROM permisos WHERE codigo IN (?,?,?,?)');
        $s->bind_param('ssss',...$codes);$s->execute();$n=$s->get_result()->num_rows;$s->close();
        if ($n!==0 && $n!==4) throw new RuntimeException('Catálogo de reportes de asesores incompleto. Revisa la instalación antes de continuar.');
        return $n===4;
    }
    public function aplicar(callable $respaldar): bool
    {
        if ((int)$this->db->query("SELECT GET_LOCK('impe_reportes_asesores_v1',10) ok")->fetch_assoc()['ok']!==1) throw new RuntimeException('Hay otra instalación en curso.');
        try {
            if ($this->comprobar()) return false;
            $this->db->begin_transaction();
            try {
                $grants=[];
                foreach ([1=>'global',2=>'global',3=>'propio',6=>'equipo'] as $role=>$scope) {
                    $roleCheck=$this->db->query('SELECT id FROM roles WHERE id='.(int)$role.' FOR UPDATE');
                    if (!$roleCheck->num_rows) throw new RuntimeException('Falta un rol requerido por la instalación.');
                    $grants[$role]=array_merge(self::COMUNES,['reportes.ventas','reportes.ventas.'.$scope,'desempeno.ver_'.$scope]);
                }
                $snapshot=[];
                foreach ($grants as $role=>$codes) foreach ($codes as $code) {
                    if (isset(self::CATALOGO[$code])) continue;
                    $s=$this->db->prepare('SELECT p.id,p.estado,EXISTS(SELECT 1 FROM rol_permisos r WHERE r.rol_id=? AND r.permiso_id=p.id) asignado FROM permisos p WHERE p.codigo=?');
                    $s->bind_param('is',$role,$code);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
                    if (!$p || !(int)$p['estado']) throw new RuntimeException('Falta activar un permiso base requerido: '.$code);
                    $snapshot[]=['rol_id'=>$role,'codigo'=>$code,'asignado'=>(bool)$p['asignado']];
                }
                $respaldar(['catalogo_nuevo'=>array_keys(self::CATALOGO),'permisos_previos'=>$snapshot]);
                foreach (self::CATALOGO as $code=>[$name,$description]) {
                    $s=$this->db->prepare("INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES('Reportes',?,?,?,1)");
                    $s->bind_param('sss',$code,$name,$description);$s->execute();$s->close();
                }
                foreach ($grants as $role=>$codes) foreach ($codes as $code) {
                    $s=$this->db->prepare('INSERT IGNORE INTO rol_permisos(rol_id,permiso_id) SELECT ?,id FROM permisos WHERE codigo=? AND estado=1');
                    $s->bind_param('is',$role,$code);$s->execute();$s->close();
                }
                $this->db->commit(); return true;
            } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        } finally { $this->db->query("SELECT RELEASE_LOCK('impe_reportes_asesores_v1')"); }
    }
    public function restaurar(array $snapshot): void
    {
        if (($snapshot['catalogo_nuevo']??[])!==array_keys(self::CATALOGO) || !is_array($snapshot['permisos_previos']??null)) throw new RuntimeException('Respaldo de permisos inválido.');
        if ((int)$this->db->query("SELECT GET_LOCK('impe_reportes_asesores_v1',10) ok")->fetch_assoc()['ok']!==1) throw new RuntimeException('Hay otra instalación en curso.');
        try {
            if (!$this->comprobar()) return;
            $this->db->begin_transaction();
            try {
                $allowed=['reportes.ventas'=>[1,2,3,6],'reportes.ventas.propio'=>[3],'reportes.ventas.equipo'=>[6],'reportes.ventas.global'=>[1,2]];
                foreach ($allowed as $code=>$roles) {
                    $s=$this->db->prepare('SELECT r.rol_id FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE p.codigo=? FOR UPDATE');$s->bind_param('s',$code);$s->execute();
                    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r)
                        if (!in_array((int)$r['rol_id'],$roles,true)) throw new RuntimeException('Hay asignaciones posteriores. Revisa los permisos antes de restaurar.');
                    $s->close();
                }
                foreach ($snapshot['permisos_previos'] as $r) {
                    $role=(int)($r['rol_id']??0);$code=(string)($r['codigo']??'');
                    if (!in_array($role,[1,2,3,6],true) || !in_array($code,array_merge(self::COMUNES,['desempeno.ver_propio','desempeno.ver_equipo','desempeno.ver_global']),true)) throw new RuntimeException('Respaldo de permisos inválido.');
                    if (!empty($r['asignado'])) continue; // No volver a conceder permisos que el usuario revocó.
                    $s=$this->db->prepare('DELETE r FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id=? AND p.codigo=?');$s->bind_param('is',$role,$code);$s->execute();$s->close();
                }
                foreach (array_keys(self::CATALOGO) as $code) {
                    $s=$this->db->prepare('DELETE r FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE p.codigo=?');$s->bind_param('s',$code);$s->execute();$s->close();
                    $s=$this->db->prepare('DELETE FROM permisos WHERE codigo=?');$s->bind_param('s',$code);$s->execute();$s->close();
                }
                $this->db->commit();
            } catch (Throwable $e) { $this->db->rollback();throw $e; }
        } finally { $this->db->query("SELECT RELEASE_LOCK('impe_reportes_asesores_v1')"); }
    }
}
