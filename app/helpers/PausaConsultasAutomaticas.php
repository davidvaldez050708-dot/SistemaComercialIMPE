<?php
/** Contención reversible ANTES de sesión, permisos, modelos y conexión MySQL. */
final class ImpePausaConsultasAutomaticas
{
    public static function objetivo(string $script, array $get, array $post, string $method): bool
    {
        $script = basename(str_replace('\\','/',$script));
        if ($script === 'cron_zoho_leads.php') return PHP_SAPI === 'cli';
        if ($script === 'avisos_llamadas.php') return $method === 'GET';
        if ($script !== 'index.php') return false;
        $controller = (string)($get['controller'] ?? '');
        $action = (string)($get['action'] ?? '');
        if ($controller === 'telefonia' && $action === 'disponibilidad') {
            // Una transferencia ya solicitada sigue consultando su ID concreto.
            return $method === 'POST' && ($post['accion'] ?? '') === 'POLL' && (int)($post['id'] ?? 0) <= 0;
        }
        if ($method !== 'GET') return false;
        return in_array($controller.'.'.$action, [
            'reminder.pendientes', 'convocatoriaNotificacion.pendientes',
            'seguimientoVinculacion.resumenVerificacionTelefonicaHoy', 'whatsapp.mensajes'
        ], true);
    }

    public static function interceptar(string $site): void
    {
        $flag = dirname($site).'/impe-private/pausa-consultas-automaticas.json';
        if (!is_file($flag)) return;
        $data = json_decode((string)@file_get_contents($flag),true);
        if (!is_array($data) || (int)($data['hasta'] ?? 0) <= time()) return;
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (!self::objetivo($script, $_GET, $_POST, strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')))) return;
        if (basename($script) === 'cron_zoho_leads.php' && PHP_SAPI === 'cli') {
            echo "PAUSA_TEMPORAL: importación automática diferida; sin consultas MySQL.\n";
            exit(0);
        }
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 120');
        echo json_encode(['ok'=>false,'pausa_automatica'=>true,'reintentar_en'=>120,
            'mensaje'=>'Las consultas automáticas están pausadas temporalmente para recuperar el servicio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
ImpePausaConsultasAutomaticas::interceptar(dirname(__DIR__,2));
