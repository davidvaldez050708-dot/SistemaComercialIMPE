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
}
