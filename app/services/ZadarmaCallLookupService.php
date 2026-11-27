<?php

class ZadarmaCallLookupService
{
    private $api;
    private $timezone;
    private ?TelefoniaPbxEstadisticasService $catalogo = null;

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/config/private_config.php';
        $configPath = impeRutaConfigPrivada('zadarma_config.php');
        $autoloadPath = $root . '/vendor/autoload.php';

        if (!$configPath || !is_file($configPath)) {
            throw new RuntimeException('Falta config/zadarma_config.php.');
        }

        if (!is_file($autoloadPath)) {
            throw new RuntimeException('No se encontró vendor/autoload.php.');
        }

        require_once $autoloadPath;

        $config = require $configPath;
        $apiKey = trim((string)($config['api_key'] ?? ''));
        $apiSecret = trim((string)($config['api_secret'] ?? ''));

        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('La configuración privada de Zadarma está incompleta.');
        }

        require_once __DIR__.'/TelefoniaZadarmaApi.php';
        $this->api = new TelefoniaZadarmaApi($apiKey, $apiSecret, false);
        $this->timezone = new DateTimeZone('America/Mexico_City');
    }

    public function buscarSalienteReciente(
        string $extension,
        string $destino,
        int $desdeUnix
    ): ?array {
        $desdeUnix = max(time() - 1800, $desdeUnix - 45);
        $hastaUnix = time() + 30;
        $estadisticas = $this->estadisticas($desdeUnix, $hastaUnix);

        $candidatas = array_values(array_filter(
            $estadisticas,
            function ($fila) use ($extension, $destino, $desdeUnix) {
                if (!$this->extensionCoincide((string)($fila['sip'] ?? ''), $extension)) {
                    return false;
                }

                if (!$this->telefonosCoinciden((string)($fila['destination'] ?? ''), $destino)) {
                    return false;
                }

                $inicio = TelefoniaPbxEstadisticasService::fechaLocal((string)($fila['callstart'] ?? ''))->getTimestamp();
                return $inicio >= $desdeUnix;
            }
        ));

        if (count($candidatas)!==1) {
            return null;
        }

        return $this->normalizar($candidatas[0]);
    }

    public function buscarPorPbxCallId(string $pbxCallId, ?string $extension=null): ?array
    {
        $pbxCallId = trim($pbxCallId);
        if ($pbxCallId === '') {
            return null;
        }

        try { $estadisticas = $this->estadisticas(time() - 86400, time() + 30,str_starts_with($pbxCallId,'in_')?'in':'out'); }
        catch (RuntimeException $e) { return null; } // Evidencia incompleta conserva la captura.

        require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
        $candidatas=[];$ajenas=[];
        foreach ($estadisticas as $fila) {
            if (hash_equals(
                $pbxCallId,
                trim((string)($fila['pbx_call_id'] ?? ''))
            )) {
                if(filter_var($fila['seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]])===false)continue;
                $normalizada=$this->normalizar($fila);
                if($extension!==null&&TelefoniaPbxSegmentosService::extensionDeFila($fila)!==$extension){
                    if($normalizada['call_id']!=='')$ajenas[$normalizada['call_id']]=true;
                    continue;
                }
                $candidatas[json_encode($normalizada,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]=$normalizada;
            }
        }

        foreach($candidatas as $key=>$candidate)
            if($candidate['call_id']!==''&&isset($ajenas[$candidate['call_id']]))unset($candidatas[$key]);
        return count($candidatas)===1?reset($candidatas):null;
    }

    /**
     * Estadísticas finales PBX para una ventana pequeña, sin buscar por teléfono.
     * El consumidor debe contrastar referencia, extensión y fecha exactas.
     */
    public function estadisticasPbx(string $desde, string $hasta): array
    {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $desde, $this->timezone);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $hasta, $this->timezone);
        if (!$inicio || !$fin || $inicio->format('Y-m-d H:i:s') !== $desde ||
            $fin->format('Y-m-d H:i:s') !== $hasta ||
            $fin->getTimestamp() <= $inicio->getTimestamp() ||
            $fin->getTimestamp() - $inicio->getTimestamp() > 18000) {
            throw new InvalidArgumentException('Ventana de estadísticas PBX inválida.');
        }
        $filas = $this->estadisticas($inicio->getTimestamp(), $fin->getTimestamp());
        return $filas;
    }

    private function estadisticas(int $desdeUnix, int $hastaUnix, string $tipo='out'): array
    {
        require_once __DIR__.'/TelefoniaPbxEstadisticasService.php';
        $this->catalogo ??= new TelefoniaPbxEstadisticasService($this->api);
        return $this->catalogo->consultar($desdeUnix,$hastaUnix,$tipo);
    }

    private function normalizar(array $fila): array
    {
        return [
            'pbx_call_id' => trim((string)($fila['pbx_call_id'] ?? '')),
            'call_id' => trim((string)($fila['call_id'] ?? '')),
            'sip' => trim((string)($fila['sip'] ?? '')),
            'destination' => trim((string)($fila['destination'] ?? '')),
            'callstart' => trim((string)($fila['callstart'] ?? '')),
            'disposition' => strtolower(trim((string)($fila['disposition'] ?? ''))),
            'seconds' => max(0, (int)($fila['seconds'] ?? 0)),
            'is_recorded' => filter_var(
                $fila['is_recorded'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    private function extensionCoincide(string $valor, string $extension): bool
    {
        return ltrim(trim($valor), '0') === ltrim(trim($extension), '0');
    }

    private function telefonosCoinciden(string $a, string $b): bool
    {
        $a = preg_replace('/\D+/', '', $a) ?: '';
        $b = preg_replace('/\D+/', '', $b) ?: '';

        if ($a === '' || $b === '') {
            return false;
        }

        if (hash_equals($a, $b)) {
            return true;
        }

        return strlen($a) >= 10 &&
            strlen($b) >= 10 &&
            hash_equals(substr($a, -10), substr($b, -10));
    }
}
