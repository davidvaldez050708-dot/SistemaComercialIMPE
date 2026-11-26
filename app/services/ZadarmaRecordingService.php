<?php

require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';
require_once __DIR__ . '/../helpers/ZadarmaPbxIdentifierHelper.php';

class ZadarmaRecordingService
{
    private string $apiKey;
    private string $apiSecret;
    private string $rootPath;
    private \Zadarma_API\Api $api;
    private ?ZadarmaWebhookEventStoreService $eventStore = null;

    public function __construct(bool $cargarEventos=true)
    {
        $this->rootPath = dirname(__DIR__, 2);
        require_once $this->rootPath . '/config/private_config.php';
        $configPath = impeRutaConfigPrivada('zadarma_config.php');
        $autoloadPath = $this->rootPath . '/vendor/autoload.php';

        if (!$configPath || !is_file($configPath)) {
            throw new RuntimeException('La telefonía Zadarma no está configurada en este servidor.');
        }

        if (!is_file($autoloadPath)) {
            throw new RuntimeException('No está disponible la librería de Zadarma.');
        }

        require_once $autoloadPath;
        $config = require $configPath;

        $this->apiKey = trim((string)($config['api_key'] ?? ''));
        $this->apiSecret = trim((string)($config['api_secret'] ?? ''));

        if (
            $this->apiKey === '' ||
            $this->apiSecret === '' ||
            $this->apiKey === 'TU_API_KEY' ||
            $this->apiSecret === 'TU_API_SECRET'
        ) {
            throw new RuntimeException('La configuración privada de Zadarma está incompleta.');
        }

        $this->api = new \Zadarma_API\Api($this->apiKey, $this->apiSecret, false);

        if($cargarEventos)try {
            $this->eventStore = new ZadarmaWebhookEventStoreService();
        } catch (Throwable $errorStore) {
            $this->eventStore = null;
            error_log('[zadarma_recording_store] ' . $errorStore->getMessage());
        }
    }

    public function obtenerGrabacionParaLlamada(string $pbxCallId): ?array
    {
        $pbxCallId = trim($pbxCallId);

        // Una grabación PBX puede pertenecer también a una llamada entrante.
        // La autorización por usuario, extensión y llamada se verifica
        // previamente en el endpoint antes de invocar este servicio.
        if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/', $pbxCallId)) {
            return null;
        }

        $callId = $this->buscarCallIdEnWebhook($pbxCallId);
        $link = '';

        if ($callId !== '') {
            try {
                $record = $this->api->getPbxRecord($callId, null, 300);
                $link = trim((string)($record->link ?? ''));
            } catch (Throwable $e) {
                $link = '';
            }
        }

        if ($link === '') {
            $record = $this->api->getPbxRecord(null, $pbxCallId, 300);
            $links = is_array($record->links ?? null) ? $record->links : [];
            $link = trim((string)($links[0] ?? ''));
        }

        if ($link === '' || filter_var($link, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return [
            'pbx_call_id' => $pbxCallId,
            'call_id_with_rec' => $callId,
            'link' => $link,
        ];
    }

    /**
     * Las llamadas internas pueden generar varios segmentos por pbx_call_id.
     * Sólo se reproduce el segmento exacto, corroborado y guardado por CLI.
     */
    public function obtenerGrabacionPorCallId(string $callId): ?array
    {
        $callId = trim($callId);
        if (!ZadarmaPbxIdentifierHelper::callIdValido($callId)) {
            return null;
        }
        $record = $this->api->getPbxRecord($callId, null, 300);
        $link = trim((string)($record->link ?? ''));
        if (!filter_var($link, FILTER_VALIDATE_URL) ||
            !str_starts_with(strtolower($link), 'https://')) {
            return null;
        }
        return ['call_id_with_rec' => $callId, 'link' => $link];
    }

    /** Mismo catálogo oficial que usa la conciliación de llamadas internas.
     * Nunca consulta por número ni convierte IDs decimales en float. */
    public function estadisticasPbx(string $desde,string $hasta): array
    {
        $data=ZadarmaPbxIdentifierHelper::decodificarEstadisticas((string)$this->api->call(
            '/v1/statistics/pbx/',['start'=>$desde,'end'=>$hasta,'version'=>2,'limit'=>1000],'get'));
        if(($data['status']??'')!=='success'||!is_array($data['stats']??null)||count($data['stats'])>=1000)
            throw new RuntimeException('La estadística está incompleta o no está disponible. Vuelve a comprobarla más tarde.');
        return $data['stats'];
    }

    public function descargarGrabacion(string $link, string $rangeHeader = ''): array
    {
        $link = trim($link);
        if ($link === '' || filter_var($link, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('El enlace de grabación Zadarma no es válido.');
        }

        $headers = [];
        $rangeHeader = trim($rangeHeader);
        if ($rangeHeader !== '' && preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
            $headers[] = 'Range: ' . $rangeHeader;
        }

        $responseHeaders = [];
        $ch = curl_init($link);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE-Zadarma/1.0',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $trimmed = trim($line);

                if ($trimmed === '') {
                    return $length;
                }

                if (stripos($trimmed, 'HTTP/') === 0) {
                    $responseHeaders = [];
                    return $length;
                }

                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = trim((string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''));
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                $error !== '' ? $error : 'Zadarma respondió HTTP ' . $status . '.'
            );
        }

        return [
            'body' => (string)$body,
            'content_type' => $contentType,
            'status' => $status,
            'headers' => $responseHeaders,
        ];
    }

    private function buscarCallIdEnWebhook(string $pbxCallId): string
    {
        if ($this->eventStore !== null) {
            try {
                $callId = $this->eventStore->buscarCallIdGrabacion($pbxCallId);
                if ($callId !== '') {
                    return $callId;
                }
            } catch (Throwable $errorStore) {
                error_log('[zadarma_recording_lookup_store] ' . $errorStore->getMessage());
            }
        }

        $logPath = $this->rootPath . '/storage/zadarma_webhooks.log';
        if (!is_file($logPath)) {
            return '';
        }

        $lineas = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        for ($i = count($lineas) - 1; $i >= 0; $i--) {
            $fila = json_decode($lineas[$i], true);
            if (!is_array($fila)) {
                continue;
            }

            if (!hash_equals($pbxCallId, trim((string)($fila['pbx_call_id'] ?? '')))) {
                continue;
            }

            $callId = trim((string)($fila['call_id_with_rec'] ?? ''));
            if ($callId !== '') {
                return $callId;
            }
        }

        return '';
    }
}
