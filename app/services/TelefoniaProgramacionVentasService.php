<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
/** Recordatorios propios; completarlos no genera una conversación ni cambia su resultado. */
final class TelefoniaProgramacionVentasService
{
    private mysqli $db;
    public function __construct(?mysqli $db=null){$this->db=$db??(new Database())->connect();}
    public function listar(int $uid): array {
        (new TelefoniaVentasLlamadasService($this->db))->autorizar($uid);
        $s=$this->db->prepare("SELECT 'agenda' origen, a.id,
                CONVERT(a.nombre USING utf8mb4) COLLATE utf8mb4_general_ci nombre,
                CONVERT(a.telefono USING utf8mb4) COLLATE utf8mb4_general_ci telefono,
                a.proxima_accion_at fecha,
                CONVERT(a.observacion USING utf8mb4) COLLATE utf8mb4_general_ci detalle,
                '' pbx_call_id, '' extension
            FROM telefonia_contactos_personales a WHERE a.usuario_id=? AND a.proxima_accion_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_resultados r WHERE r.usuario_id=a.usuario_id AND r.contacto_id=a.id AND r.proxima_accion_at=a.proxima_accion_at AND r.recordatorio_atendido_at IS NULL)
            UNION ALL SELECT 'resultado', r.id,
                CONVERT(r.prospecto_nombre USING utf8mb4) COLLATE utf8mb4_general_ci,
                CONVERT(c.numero USING utf8mb4) COLLATE utf8mb4_general_ci,
                r.proxima_accion_at,
                CONVERT(r.observacion USING utf8mb4) COLLATE utf8mb4_general_ci,
                r.pbx_call_id, r.extension
            FROM telefonia_ventas_resultados r JOIN telefonia_ventas_llamadas c ON c.usuario_id=r.usuario_id AND c.extension=BINARY r.extension AND c.pbx_call_id=BINARY r.pbx_call_id
            WHERE r.usuario_id=? AND r.proxima_accion_at IS NOT NULL AND r.recordatorio_atendido_at IS NULL ORDER BY fecha,id");
        $s->bind_param('ii',$uid,$uid);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    public function completar(int $uid,string $source,int $id,string $date): void {
        (new TelefoniaVentasLlamadasService($this->db))->autorizar($uid);
        if(!in_array($source,['agenda','resultado'],true)||$id<=0)throw new InvalidArgumentException('Recordatorio no válido.');
        $sql=$source==='agenda'?'UPDATE telefonia_contactos_personales SET proxima_accion_at=NULL WHERE usuario_id=? AND id=? AND proxima_accion_at=?':
            'UPDATE telefonia_ventas_resultados SET recordatorio_atendido_at=NOW(),updated_at=updated_at WHERE usuario_id=? AND id=? AND proxima_accion_at=? AND recordatorio_atendido_at IS NULL';
        $this->db->begin_transaction();try {
        $s=$this->db->prepare($sql);$s->bind_param('iis',$uid,$id,$date);$s->execute();$ok=$s->affected_rows===1;$s->close();
        if($ok&&$source==='resultado'){$s=$this->db->prepare('UPDATE telefonia_contactos_personales a JOIN telefonia_ventas_resultados r ON r.contacto_id=a.id AND r.usuario_id=a.usuario_id SET a.proxima_accion_at=NULL WHERE r.usuario_id=? AND r.id=? AND a.proxima_accion_at=?');$s->bind_param('iis',$uid,$id,$date);$s->execute();$s->close();}
        if(!$ok)throw new DomainException('El recordatorio cambió, ya se atendió o no te corresponde.');
        $this->db->commit();}catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
