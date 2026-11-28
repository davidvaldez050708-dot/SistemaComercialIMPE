<?php

require_once __DIR__ . '/../helpers/ZadarmaPbxIdentifierHelper.php';

class ZadarmaRecordingService
{
    private string $apiKey;
    private string $apiSecret;
    private string $rootPath;
    private \Zadarma_API\Api $api;
    private ?TelefoniaPbxEstadisticasService $catalogo = null;

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

        require_once __DIR__.'/TelefoniaZadarmaApi.php';
        $this->api = new TelefoniaZadarmaApi($this->apiKey, $this->apiSecret, false);

        // El argumento se conserva para compatibilidad de llamadas existentes.
        // El transporte no abre otra conexión SQL ni interpreta un grupo PBX.
    }

    public function cacheTramosTransferidos(int $usuario,string $extension,string $asignadaDesde,string $pbx,?array $value=null):?array
    {
        if($usuario<1||!preg_match('/^[0-9]{3,6}$/D',$extension)||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$pbx))throw new InvalidArgumentException('Ámbito de audio inválido.');
        $identity=hash('sha256',json_encode([$usuario,$extension,$asignadaDesde,$pbx],JSON_THROW_ON_ERROR));
        return $this->api->cachePrivada('audio-transferido',$identity,$value);
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
        require_once __DIR__.'/TelefoniaPbxEstadisticasService.php';
        $this->catalogo ??= new TelefoniaPbxEstadisticasService($this->api);
        return $this->catalogo->consultar(
            TelefoniaPbxEstadisticasService::fechaLocal($desde)->getTimestamp(),
            TelefoniaPbxEstadisticasService::fechaLocal($hasta)->getTimestamp());
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

}
