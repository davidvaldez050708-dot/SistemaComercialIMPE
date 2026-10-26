<?php
declare(strict_types=1);
/** Catálogo nuevo: instalación única, sin restablecer concesiones revocadas. */
final class SupervisionLlamadasPermisosService
{
    public const CATALOGO=[
        'llamadas.supervision.ver'=>['Consultar historial del equipo','Consultar resultados de analistas y asesores. Cuenta Clave sólo accede a su equipo vigente.'],
        'llamadas.supervision.audio'=>['Escuchar grabaciones del equipo','Reproducir y descargar grabaciones verificadas del mismo alcance del historial.'],
    ];
    public function __construct(private mysqli $db){}
    public function verificarEstructura(): void
    {
        $required=[
            'usuarios'=>['id','rol_id','estado','nombre','apellidos','foto_perfil'],
            'roles'=>['id','estado'],'permisos'=>['id','codigo','estado','nombre','descripcion','modulo'],'rol_permisos'=>['rol_id','permiso_id'],
            'asignaciones_territorio'=>['id','usuario_id','estado_id','tipo_asignacion','cuenta_clave_asignacion_id','activo','fecha_inicio','fecha_fin'],
            'telefonia_ventas_llamadas'=>['id','usuario_id','extension','pbx_call_id','fecha'],
            'telefonia_ventas_resultados'=>['id','usuario_id','extension','pbx_call_id','resultado','observacion','prospecto_nombre'],
            'interacciones_vinculacion'=>['id','usuario_id','canal','id_externo','fecha_inicio','duracion_segundos'],
            'telefonia_zadarma_eventos'=>['id','pbx_call_id','evento','internal','call_id_with_rec','payload_json'],
        ];
        $optional=[
            'telefonia_analista_entrantes'=>['id','usuario_id','extension','pbx_call_id','interaccion_id','estado','created_at'],
            'telefonia_analista_webrtc_sesiones'=>['id','usuario_id','extension','session_id','fecha_inicio','duracion_observada_segundos','clasificacion','interaccion_id'],
            'telefonia_transferencia_operaciones'=>['id','referencia','destino_usuario','destino_extension','origen_usuario','origen_extension','sdk_consulta','estado','modo','entregada_at','destino_atendida'],
            'telefonia_disponibilidad'=>['id','referencia','destino_usuario','destino_extension','origen_usuario','origen_extension','ruta_json','estado'],
        ];
        foreach($required+$optional as $table=>$columns){
            $s=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();$found=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');$s->close();
            if(!$found&&isset($optional[$table]))continue;
            if(array_diff($columns,$found))throw new RuntimeException('Falta la estructura requerida: '.$table.'. No se han activado permisos.');
        }
    }
    public function comprobar(): bool
    {
        $n=(int)$this->db->query("SELECT COUNT(*) n FROM permisos WHERE codigo IN ('llamadas.supervision.ver','llamadas.supervision.audio')")->fetch_assoc()['n'];
        if($n!==0&&$n!==2)throw new RuntimeException('Catálogo incompleto. Revisa la instalación.');return $n===2;
    }
    private function bloqueo(): void
    {
        if((int)$this->db->query("SELECT GET_LOCK('impe_supervision_llamadas_v1',10) ok")->fetch_assoc()['ok']!==1)throw new RuntimeException('Hay otra instalación en curso.');
    }
    public function aplicar(callable $backup): bool
    {
        $this->bloqueo();
        try{
            if($this->comprobar())return false;
            $this->db->begin_transaction();
            try{
                foreach([1,2,6] as $role)if(!$this->db->query("SELECT id FROM roles WHERE id=$role FOR UPDATE")->num_rows)throw new RuntimeException('Falta un rol requerido.');
                $backup(['catalogo_nuevo'=>array_keys(self::CATALOGO),'permisos_previos'=>[]]);
                foreach(self::CATALOGO as $code=>[$name,$description]){
                    $s=$this->db->prepare("INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES('Supervisión de llamadas',?,?,?,1)");$s->bind_param('sss',$code,$name,$description);$s->execute();$s->close();
                }
                foreach([1,2,6] as $role)$this->db->query("INSERT INTO rol_permisos(rol_id,permiso_id) SELECT $role,id FROM permisos WHERE codigo IN ('llamadas.supervision.ver','llamadas.supervision.audio')");
                $this->db->commit();return true;
            }catch(Throwable $e){$this->db->rollback();throw $e;}
        }finally{$this->db->query("SELECT RELEASE_LOCK('impe_supervision_llamadas_v1')");}
    }
    public function restaurar(array $snapshot): void
    {
        if(($snapshot['catalogo_nuevo']??[])!==array_keys(self::CATALOGO)||($snapshot['permisos_previos']??null)!==[])throw new RuntimeException('Respaldo inválido.');
        $this->bloqueo();
        try{
            if(!$this->comprobar())return;
            $this->db->begin_transaction();
            try{
                $other=$this->db->query("SELECT rp.rol_id FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo IN ('llamadas.supervision.ver','llamadas.supervision.audio') AND rp.rol_id NOT IN (1,2,6) FOR UPDATE");
                if($other->num_rows)throw new RuntimeException('Hay asignaciones posteriores. Revisa antes de restaurar.');
                $this->db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo IN ('llamadas.supervision.ver','llamadas.supervision.audio')");
                $this->db->query("DELETE FROM permisos WHERE codigo IN ('llamadas.supervision.ver','llamadas.supervision.audio')");$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}
        }finally{$this->db->query("SELECT RELEASE_LOCK('impe_supervision_llamadas_v1')");}
    }
}
