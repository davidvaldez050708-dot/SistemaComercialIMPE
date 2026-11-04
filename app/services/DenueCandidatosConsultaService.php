<?php
require_once __DIR__ . '/DenueService.php';

/** Páginas reanudables del mismo filtro. Sólo caché; nunca crea seguimientos. */
class DenueCandidatosConsultaService
{
    private DenueService $denue;
    private string $directorio;

    public function __construct(?DenueService $denue = null, ?string $directorio = null)
    {
        $this->denue = $denue ?? new DenueService();
        $this->directorio = $directorio ?? __DIR__ . '/../../storage/cache/denue_consultas';
    }

    public function pagina(string $propietario, array $filtros, int $pagina, callable $seleccionar): array
    {
        $clave = hash('sha256', 'v1|' . $propietario . '|' . json_encode($filtros));
        $dir = $this->directorio . '/' . $clave;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return $this->errorCache();
        $lock = @fopen($dir . '/consulta.lock', 'c');
        if (!$lock) return $this->errorCache();
        $tieneLock = false;
        $hasta = microtime(true) + 1;
        try {
            do {
                $tieneLock = flock($lock, LOCK_EX | LOCK_NB);
                if (!$tieneLock) usleep(50000);
            } while (!$tieneLock && microtime(true) < $hasta);
            if (!$tieneLock) return ['ok' => false, 'reintentable' => true, 'codigo' => 'OCUPADO',
                'mensaje' => 'La consulta sigue en curso. Espera un momento.'];
            $meta = $dir . '/estado.json';
            $archivo = $dir . '/candidatos.jsonl';
            $texto = is_file($meta) ? @file_get_contents($meta) : false;
            $estado = $texto === false ? null : json_decode($texto, true);
            if (!is_array($estado) || !isset($estado['inicio'], $estado['total'], $estado['bytes'], $estado['vistos'], $estado['offsets']) ||
                !is_int($estado['inicio']) || $estado['inicio'] < 1 || !is_int($estado['total']) || $estado['total'] < 0 ||
                !is_int($estado['bytes']) || $estado['bytes'] < 0 || !is_array($estado['vistos']) || !is_array($estado['offsets']) ||
                !is_bool($estado['agotado'] ?? null) || !is_int($estado['revisados'] ?? null) ||
                count($estado['vistos']) !== $estado['total'] || count($estado['offsets']) !== (int)ceil($estado['total'] / 10) ||
                (time() - (int)($estado['actualizado'] ?? 0)) > 1800) {
                $estado = ['inicio' => 1, 'total' => 0, 'bytes' => 0, 'vistos' => [], 'offsets' => [],
                    'agotado' => false, 'revisados' => 0, 'actualizado' => time(), 'ultima_firma' => ''];
                if (@file_put_contents($archivo, '') === false) return $this->errorCache();
            }
            $datos = @fopen($archivo, 'c+b');
            if (!$datos) return $this->errorCache();
            try {
                // Descarta únicamente un apéndice no confirmado, por ejemplo después de una interrupción.
                $tamano = fstat($datos)['size'];
                if ($tamano < $estado['bytes']) return $this->errorCache();
                if ($tamano > $estado['bytes'] && !ftruncate($datos, $estado['bytes'])) return $this->errorCache();
                if ($pagina < 1 || $pagina > max(1, (int)ceil($estado['total'] / 10) + 1)) {
                    return ['ok' => false, 'reintentable' => false, 'codigo' => 'PAGINA', 'mensaje' => 'Consulta las páginas en orden para continuar la búsqueda.'];
                }
                if (!$estado['agotado'] && $estado['total'] < $pagina * 10) {
                    $respuesta = $this->denue->buscarPaginaCandidatos($filtros['estado'], $filtros['municipio'], $filtros['termino'], $estado['inicio']);
                    if (!$respuesta['ok']) return $respuesta;
                    $clavesPagina = array_column($respuesta['resultados'], 'clave_origen');
                    sort($clavesPagina, SORT_STRING);
                    $firma = hash('sha256', json_encode($clavesPagina));
                    if (!$respuesta['agotado'] && $estado['inicio'] > 1 && $firma === $estado['ultima_firma']) {
                        return ['ok' => false, 'reintentable' => false, 'codigo' => 'PAGINACION',
                            'mensaje' => 'DENUE repitió una página y no permitió avanzar. La búsqueda conserva su posición.'];
                    }
                    fseek($datos, $estado['bytes']);
                    foreach ($seleccionar($respuesta['resultados']) as $candidato) {
                        $id = $candidato['clave_origen'];
                        if (isset($estado['vistos'][$id])) continue;
                        if ($estado['total'] % 10 === 0) $estado['offsets'][(string)(int)($estado['total'] / 10)] = ftell($datos);
                        $linea = json_encode($candidato, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                        if (fwrite($datos, $linea) !== strlen($linea)) return $this->errorCache();
                        $estado['vistos'][$id] = true;
                        $estado['total']++;
                    }
                    if (!fflush($datos)) return $this->errorCache();
                    $estado['bytes'] = ftell($datos);
                    $estado['inicio'] += $respuesta['recibidos'];
                    $estado['revisados'] += $respuesta['recibidos'];
                    $estado['agotado'] = $respuesta['agotado'];
                    $estado['ultima_firma'] = $firma;
                }
                $estado['actualizado'] = time();
                if (!$this->guardarEstado($meta, $estado)) return $this->errorCache();
                $pagina = $estado['agotado'] ? min($pagina, max(1, (int)ceil($estado['total'] / 10))) : $pagina;
                $filas = [];
                $offset = $estado['offsets'][(string)($pagina - 1)] ?? null;
                if ($offset !== null) {
                    fseek($datos, $offset);
                    while (count($filas) < 10 && ($linea = fgets($datos)) !== false) {
                        $fila = json_decode($linea, true);
                        if (!is_array($fila)) return $this->errorCache();
                        $filas[] = $fila;
                    }
                }
                return ['ok' => true, 'candidatos' => $filas, 'pagina' => $pagina,
                    'resultados_por_pagina' => 10, 'total_resultados' => $estado['total'],
                    'total_exacto' => $estado['agotado'], 'paginacion_denue' => true,
                    'denue_revisados' => $estado['revisados'],
                    'denue_continuar' => !$estado['agotado'] && $estado['total'] < $pagina * 10,
                    'hay_mas' => !$estado['agotado'] || $estado['total'] > $pagina * 10];
            } finally { fclose($datos); }
        } finally {
            if ($tieneLock) flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function guardarEstado(string $archivo, array $estado): bool
    {
        $temporal = @tempnam(dirname($archivo), '.estado-');
        if ($temporal === false) return false;
        try {
            $json = json_encode($estado, JSON_INVALID_UTF8_SUBSTITUTE);
            return $json !== false && @file_put_contents($temporal, $json, LOCK_EX) === strlen($json) && @rename($temporal, $archivo);
        } finally { if (is_file($temporal)) @unlink($temporal); }
    }

    private function errorCache(): array
    {
        error_log('[DENUE paginado] No fue posible conservar el avance de la consulta.');
        return ['ok' => false, 'codigo' => 'CACHE', 'reintentable' => false,
            'mensaje' => 'No fue posible conservar el avance de la búsqueda. Solicita revisar el almacenamiento del servidor.'];
    }
}
