<?php
/**
 * Autorización de grabaciones salientes para el usuario Marketing.
 * Primero verifica en MySQL que la llamada se originó en su extensión
 * DESPUÉS de la asignación; luego examina los eventos firmados Zadarma.
 * No recibe destinos, URLs de audio ni privilegios desde el navegador.
 */
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

class TelefoniaGrabacionSalienteMarketingService
{
    public static function autorizada(
        mysqli $db,
        int $usuarioId,
        string $extension,
        string $pbxCallId,
        ?array $eventos = null
    ): bool {
        if ($usuarioId <= 0 ||
            preg_match('/^\\d{3,6}$/', $extension) !== 1 ||
            preg_match('/^[a-zA-Z0-9_-]{6,100}$/', $pbxCallId) !== 1) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT 1
             FROM telefonia_zadarma_eventos inicio
             INNER JOIN telefonia_extensiones te
               ON te.usuario_id = ?
              AND te.extension = inicio.internal
              AND te.proveedor = 'ZADARMA'
              AND te.activo = 1
              AND te.permite_salientes = 1
             WHERE inicio.pbx_call_id = BINARY ?
               AND inicio.internal = ?
               AND inicio.evento = 'NOTIFY_OUT_START'
               AND inicio.received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND inicio.received_at >= GREATEST(te.created_at, te.updated_at)
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No fue posible verificar la extensión de origen.');
        }
        $stmt->bind_param('iss', $usuarioId, $pbxCallId, $extension);
        $stmt->execute();
        $pertenece = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pertenece) return false;

        $eventos ??= (new ZadarmaWebhookEventStoreService($db))->obtenerPorPbxCallId($pbxCallId);
        return self::eventosAutorizados($eventos, $extension);
    }

    // La revisión de eventos puede probarse sin consultar MySQL ni Zadarma.
    public static function eventosAutorizados(array $eventos, string $extension): bool
    {
        require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
        $raw=[];$iniciada=false;
        foreach($eventos as $event){
            $event['evento']=strtoupper((string)($event['event']??$event['evento']??''));$raw[]=$event;
            if($event['evento']==='NOTIFY_OUT_START'&&(string)($event['internal']??'')===$extension)$iniciada=true;
        }
        $facts=TelefoniaPbxSegmentosService::hechos($raw,$extension);
        return $iniciada&&$facts['finalizada']&&$facts['conectada']&&$facts['transferida_a']===''&&
            (count($facts['segmentos'])>0||$facts['audio_motivo']==='pendiente_referencia');

    }
}
