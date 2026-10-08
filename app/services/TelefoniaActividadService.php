<?php
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';
require_once __DIR__ . '/TelefoniaResultadoVentasService.php';

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

    public function consultar(?string $extension = null, ?int $usuarioId = null): array
    {
        if ($extension !== null && !preg_match('/^\d{3,6}$/', $extension)) {
            throw new InvalidArgumentException('Extensión inválida.');
        }
        $sql = "SELECT pbx_call_id, internal, MIN(received_at) AS fecha,
                  MAX(CASE WHEN evento IN ('NOTIFY_OUT_START','NOTIFY_OUT_END') THEN 1 ELSE 0 END) AS saliente,
                  MAX(CASE WHEN evento IN ('NOTIFY_END','NOTIFY_OUT_END') THEN GREATEST(duration, 0) ELSE 0 END) AS segundos,
                  MAX(CASE WHEN EXISTS (
                      SELECT 1 FROM telefonia_zadarma_eventos grab
                      WHERE grab.pbx_call_id = e.pbx_call_id
                        AND (
                            grab.evento = 'NOTIFY_RECORD'
                            OR grab.is_recorded = 1
                            OR NULLIF(grab.call_id_with_rec, '') IS NOT NULL
                        )
                  ) THEN 1 ELSE 0 END) AS grabacion_reportada,
                  MAX(CASE WHEN evento = 'NOTIFY_ANSWER' OR LOWER(COALESCE(disposition,'')) = 'answered' THEN 1 ELSE 0 END) AS contestada,
                  MAX(CASE WHEN evento='NOTIFY_OUT_START' THEN destination ELSE NULL END) AS destino,
                  MAX(CASE WHEN evento IN ('NOTIFY_INTERNAL','NOTIFY_END') THEN caller_id ELSE NULL END) AS origen
                FROM telefonia_zadarma_eventos e
                WHERE e.received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND internal IS NOT NULL AND internal <> ''
                  AND evento IN (
                      'NOTIFY_INTERNAL', 'NOTIFY_ANSWER', 'NOTIFY_END',
                      'NOTIFY_OUT_START', 'NOTIFY_OUT_END'
                  )";
        if ($extension !== null) $sql .= " AND internal = ?";
        $sql .= " GROUP BY pbx_call_id, internal ORDER BY fecha DESC";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No fue posible consultar la actividad telefónica.');
        if ($extension !== null) $stmt->bind_param('s', $extension);
        $stmt->execute();
        $result = $stmt->get_result();

        $resultados = [];
        if ($usuarioId !== null && $usuarioId > 0 && $extension !== null) {
            $resultados = (new TelefoniaResultadoVentasService())
                ->consultarExtension($usuarioId, $extension);
        }
        $etiquetasResultados = TelefoniaResultadoVentasService::opciones();

        $data = [
            'atenciones' => 0, 'contestadas' => 0,
            'entrantes' => 0, 'salientes' => 0, 'segundos' => 0,
            'por_extension' => [], 'recientes' => [], 'conversaciones_reales' => 0
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
            $pbxCallId = (string)$row['pbx_call_id'];
            $esSalienteClasificado = $out &&
                TelefoniaResultadoVentasService::esConversacion(
                    (string)($resultados[$pbxCallId] ?? '')
                );
            if ($esSalienteClasificado) {
                $data['conversaciones_reales']++;
            }
            if (count($data['recientes']) < 30) {
                $esSalienteConIdValido = $out &&
                    (bool)preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId);
                $resultadoRegistrado = (string)($resultados[$pbxCallId] ?? '');
                $esConversacionReal = $esSalienteConIdValido &&
                    TelefoniaResultadoVentasService::esConversacion($resultadoRegistrado);
                $grabacionReportada = (int)$row['grabacion_reportada'] === 1;
                // El proveedor puede grabar locuciones: solo exhibir audio
                // cuando el asesor confirmó conversación con una persona.
                $grabacionLista = $esConversacionReal && $grabacionReportada;
                $timestamp = strtotime((string)$row['fecha']);
                $grabacionProcesando = $esConversacionReal &&
                    !$grabacionLista && $timestamp !== false &&
                    $timestamp >= time() - 900;

                $data['recientes'][] = [
                    'extension' => $ext,
                    'pbx_call_id' => $pbxCallId,
                    'fecha' => (string)$row['fecha'],
                    'tipo' => $out ? 'Saliente' : 'Entrante',
                    'numero' => $out
                        ? (string)($row['destino'] ?? '')
                        : (string)($row['origen'] ?? ''),
                    'contestada' => $answered,
                    'segundos' => $secs,
                    'resultado_ventas' => $resultadoRegistrado,
                    'resultado_etiqueta' => (string)($etiquetasResultados[$resultadoRegistrado] ?? 'Por clasificar'),
                    'resultado_pendiente' => $esSalienteConIdValido && $resultadoRegistrado === '',
                    'grabacion_excluida' => $esSalienteConIdValido &&
                        $resultadoRegistrado !== '' && !$esConversacionReal,
                    'tiene_grabacion' => $grabacionLista,
                    'grabacion_procesando' => $grabacionProcesando
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
