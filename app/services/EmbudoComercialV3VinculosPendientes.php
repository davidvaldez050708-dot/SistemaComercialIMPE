<?php
require_once __DIR__.'/EmbudoComercialResultadoBridge.php';
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';

final class EmbudoComercialV3VinculosPendientes
{
    public function __construct(private mysqli $db) {}

    public function reintentar(int $usuario,int $asignacion): array
    {
        $sql="SELECT r.extension,r.pbx_call_id
            FROM telefonia_ventas_resultados r
            JOIN prospecto_asignaciones a ON a.id=r.embudo_asignacion_id
                AND a.asesor_id=r.usuario_id AND a.vigente=1
            WHERE r.usuario_id=? AND a.id=?
            AND NOT EXISTS (
                SELECT 1 FROM prospecto_embudo_llamadas l
                WHERE BINARY l.extension=BINARY r.extension
                  AND BINARY l.pbx_call_id=BINARY r.pbx_call_id
            )
            ORDER BY r.id LIMIT 10";
        $s=$this->db->prepare($sql);
        $s->bind_param('ii',$usuario,$asignacion);
        $s->execute();
        $filas=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        $llamadas=new TelefoniaVentasLlamadasService($this->db);
        $bridge=new EmbudoComercialResultadoBridge($this->db);
        $vinculadas=0;
        foreach ($filas as $fila) {
            try {
                $call=$llamadas->obtener($usuario,$fila['pbx_call_id'],$fila['extension']);
                $r=$call['registro']??[];
                if ((int)($r['embudo_asignacion_id']??0)!==$asignacion ||
                    empty($call['finalizada'])) continue;
                $this->db->begin_transaction();
                $bridge->aplicar($usuario,$asignacion,$call,(string)$r['resultado'],
                    $r['proxima_accion_at']??null);
                $this->db->commit();
                $vinculadas++;
            } catch (Throwable $e) {
                $this->db->rollback();
                error_log('[embudo_v3_vinculo_pendiente] '.get_class($e).' codigo='.(int)$e->getCode());
            }
        }
        return ['vinculadas'=>$vinculadas,'pendientes'=>count($filas)-$vinculadas];
    }
}
