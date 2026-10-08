<?php
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

/** Registro de atenciones por extensión confirmado por webhooks; no es facturación Zadarma. */
class TelefoniaActividadService
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
        (new ZadarmaWebhookEventStoreService())->asegurarEstructura();
    }

    public function consultar(?string $extension = null): array
    {
        if ($extension !== null && !preg_match('/^\d{3,6}$/', $extension)) {
            throw new InvalidArgumentException('Extensión inválida.');
        }
        $sql = "SELECT pbx_call_id, internal, MIN(received_at) AS fecha,
                  MAX(CASE WHEN evento IN ('NOTIFY_OUT_START','NOTIFY_OUT_END') THEN 1 ELSE 0 END) AS saliente,
                  MAX(CASE WHEN evento IN ('NOTIFY_END','NOTIFY_OUT_END') THEN GREATEST(duration, 0) ELSE 0 END) AS segundos,
                  MAX(CASE WHEN evento = 'NOTIFY_ANSWER' OR LOWER(COALESCE(disposition,'')) = 'answered' THEN 1 ELSE 0 END) AS contestada,
                  MAX(CASE WHEN evento='NOTIFY_OUT_START' THEN destination ELSE NULL END) AS destino,
                  MAX(CASE WHEN evento IN ('NOTIFY_INTERNAL','NOTIFY_END') THEN caller_id ELSE NULL END) AS origen
                FROM telefonia_zadarma_eventos
                WHERE received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND internal IS NOT NULL AND internal <> ''";
        if ($extension !== null) $sql .= " AND internal = ?";
        $sql .= " GROUP BY pbx_call_id, internal ORDER BY fecha DESC";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No fue posible consultar la actividad telefónica.');
        if ($extension !== null) $stmt->bind_param('s', $extension);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [
            'atenciones' => 0, 'contestadas' => 0,
            'entrantes' => 0, 'salientes' => 0, 'segundos' => 0,
            'por_extension' => [], 'recientes' => []
        ];
        while ($row = $result->fetch_assoc()) {
            $ext = (string)$row['internal'];
            $out = (int)$row['saliente'] === 1;
            $answered = (int)$row['contestada'] === 1;
            $secs = max(0, (int)$row['segundos']);
            $data['atenciones']++;
            $data['contestadas'] += (int)$answered;
            $data[$out ? 'salientes' : 'entrantes']++;
            $data['segundos'] += $secs;
            if (!isset($data['por_extension'][$ext])) {
                $data['por_extension'][$ext] = ['extension'=>$ext, 'atenciones'=>0, 'contestadas'=>0, 'salientes'=>0, 'entrantes'=>0, 'segundos'=>0];
            }
            $data['por_extension'][$ext]['atenciones']++;
            $data['por_extension'][$ext]['contestadas'] += (int)$answered;
            $data['por_extension'][$ext][$out ? 'salientes' : 'entrantes']++;
            $data['por_extension'][$ext]['segundos'] += $secs;
            if (count($data['recientes']) < 30) {
                $data['recientes'][] = [
                    'extension'=>$ext, 'fecha'=>(string)$row['fecha'],
                    'tipo'=>$out ? 'Saliente' : 'Entrante',
                    'numero'=>$out ? (string)($row['destino'] ?? '') : (string)($row['origen'] ?? ''),
                    'contestada'=>$answered, 'segundos'=>$secs
                ];
            }
        }
        $stmt->close();
        uasort($data['por_extension'], static function ($a, $b) {
            return $b['atenciones'] <=> $a['atenciones'];
        });
        $data['por_extension'] = array_values($data['por_extension']);
        return $data;
    }
}
