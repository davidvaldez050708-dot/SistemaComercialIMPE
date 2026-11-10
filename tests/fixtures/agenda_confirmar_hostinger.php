<?php
// Método instalado compartido por el usuario. Sólo fixture del fallo 1064.
class AgendaConfirmarHostingerFixture
{
    public function confirmar($reunionId, $kamId, $zoomUrl, $ubicacion, $notasKam)
    {
        // Las propuestas se guardan en la zona horaria de PHP, no en la del servidor SQL.
        $ahora = date('Y-m-d H:i:s');
        $sql = 'UPDATE reuniones_vinculacion SET ' . SeguimientoTerritorialScope::reunionSupervisor('reuniones_vinculacion') . ',zoom_url=?,ubicacion=?,notas_kam=?,estado=\'CONFIRMADA\',
                    confirmada_at=NOW(),confirmada_por=?,notificado_analista_at=NULL
                WHERE id=?
                  AND ' . SeguimientoTerritorialScope::reunionSupervisor('reuniones_vinculacion') . '
                  AND estado=\'SOLICITADA\'
                  AND fecha_propuesta > ?';
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('isssiiis', $kamId, $zoomUrl, $ubicacion, $notasKam, $kamId, $reunionId, $kamId, $ahora);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }
}
