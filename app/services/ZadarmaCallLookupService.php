<?php

class ZadarmaCallLookupService
{
    private $api;
    private $timezone;
    private $estadisticasCache = [];

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        $configPath = $root . '/config/zadarma_config.php';
        $autoloadPath = $root . '/vendor/autoload.php';

        if (!is_file($configPath)) {
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

        $this->api = new \Zadarma_API\Api($apiKey, $apiSecret, false);
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

                $inicio = strtotime((string)($fila['callstart'] ?? ''));
                return $inicio !== false && $inicio >= $desdeUnix;
            }
        ));

        if (empty($candidatas)) {
            return null;
        }

        usort($candidatas, static function ($a, $b) {
            return strcmp(
                (string)($b['callstart'] ?? ''),
                (string)($a['callstart'] ?? '')
            );
        });

        return $this->normalizar($candidatas[0]);
    }

    public function buscarPorPbxCallId(string $pbxCallId): ?array
    {
        $pbxCallId = trim($pbxCallId);
        if ($pbxCallId === '') {
            return null;
        }

        $estadisticas = $this->estadisticas(time() - 86400, time() + 30);

        foreach ($estadisticas as $fila) {
            if (hash_equals(
                $pbxCallId,
                trim((string)($fila['pbx_call_id'] ?? ''))
            )) {
                return $this->normalizar($fila);
            }
        }

        return null;
    }

    private function estadisticas(int $desdeUnix, int $hastaUnix): array
    {
        $inicio = (new DateTimeImmutable('@' . $desdeUnix))
            ->setTimezone($this->timezone)
            ->format('Y-m-d H:i:s');
        $fin = (new DateTimeImmutable('@' . $hastaUnix))
            ->setTimezone($this->timezone)
            ->format('Y-m-d H:i:s');
        $clave = $inicio . '|' . $fin;

        if (array_key_exists($clave, $this->estadisticasCache)) {
            return $this->estadisticasCache[$clave];
        }

        $body = $this->api->call(
            '/v1/statistics/pbx/',
            [
                'start' => $inicio,
                'end' => $fin,
                'version' => 2,
                'call_type' => 'out',
                'limit' => 100,
            ],
            'get'
        );

        $data = json_decode((string)$body, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            $this->estadisticasCache[$clave] = [];
            return [];
        }

        $this->estadisticasCache[$clave] =
            is_array($data['stats'] ?? null) ? $data['stats'] : [];

        return $this->estadisticasCache[$clave];
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
