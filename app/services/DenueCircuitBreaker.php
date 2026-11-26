<?php
/**
 * Circuito compartido entre procesos PHP. No contiene tokens ni datos de DENUE.
 * La generación impide que una respuesta tardía reabra/cierre otro intento.
 */
final class DenueCircuitBreaker
{
    private const PAUSAS = [120, 300, 600, 900];
    private string $archivo;
    private $reloj;

    public function __construct(string $archivo, ?callable $reloj = null)
    {
        $this->archivo = $archivo;
        $this->reloj = $reloj ?? static fn(): int => time();
    }

    private function operar(callable $operacion): mixed
    {
        $dir = dirname($this->archivo);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $lock = @fopen($this->archivo . '.lock', 'c');
        if (!$lock) {
            return null;
        }
        try {
            // Sólo serializa la lectura/escritura local; nunca se conserva durante cURL.
            if (!flock($lock, LOCK_EX)) {
                return null;
            }
            $estado = ['generacion' => 0, 'fallos' => 0, 'hasta' => 0, 'sonda_hasta' => 0];
            if (is_file($this->archivo)) {
                $raw = @file_get_contents($this->archivo);
                $datos = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($datos)) {
                    return null;
                }
                foreach ($estado as $key => $value) {
                    if (!isset($datos[$key]) || !is_int($datos[$key]) || $datos[$key] < 0) {
                        return null;
                    }
                    $estado[$key] = $datos[$key];
                }
            }
            $anterior = $estado;
            $resultado = $operacion($estado, (int)($this->reloj)());
            if ($estado !== $anterior) {
                $tmp = @tempnam($dir, '.circuito-');
                if ($tmp === false) {
                    return null;
                }
                $json = json_encode($estado);
                if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $this->archivo)) {
                    @unlink($tmp);
                    return null;
                }
            }
            return $resultado;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** null: suspendido u otro proceso está comprobando la recuperación. */
    public function admitir(): ?array
    {
        return $this->operar(static function (array &$e, int $ahora): ?array {
            if ($e['hasta'] > $ahora || $e['sonda_hasta'] > $ahora) {
                return null;
            }
            $sonda = $e['fallos'] > 0;
            if ($sonda) {
                $e['generacion']++;
                $e['sonda_hasta'] = $ahora + 35;
            }
            return ['generacion' => $e['generacion'], 'sonda' => $sonda];
        });
    }

    /** Una respuesta no transitoria acredita conectividad, no validez comercial. */
    public function informar(array $ticket, bool $transitorio): bool
    {
        return $this->operar(static function (array &$e, int $ahora) use ($ticket, $transitorio): bool {
            if (($ticket['generacion'] ?? -1) !== $e['generacion']) {
                return false;
            }
            if ($transitorio) {
                $e['fallos'] = min(count(self::PAUSAS), $e['fallos'] + 1);
                $e['hasta'] = $ahora + self::PAUSAS[$e['fallos'] - 1];
                $e['sonda_hasta'] = 0;
                $e['generacion']++;
            } elseif (!empty($ticket['sonda'])) {
                $e = ['generacion' => $e['generacion'] + 1, 'fallos' => 0, 'hasta' => 0, 'sonda_hasta' => 0];
            }
            return true;
        }) === true;
    }

    public static function falloTransitorio(int $http, int $curl, bool $respuestaValida): bool
    {
        return $curl !== 0 || $http === 0 || $http === 408 || $http === 429 ||
            $http >= 500 || ($http === 200 && !$respuestaValida);
    }
}
