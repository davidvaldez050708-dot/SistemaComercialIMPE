<?php

class ReporteSeguimientoPdfCacheService
{
    private const TTL_SEGUNDOS = 45;

    private $directorio;

    public function __construct()
    {
        $base = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $this->directorio = $base . DIRECTORY_SEPARATOR . 'sistema_comercial_impe_pdf_cache';
    }

    public function crearClave(array $contexto): string
    {
        $normalizado = $this->normalizar($contexto);
        return hash(
            'sha256',
            json_encode(
                $normalizado,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: serialize($normalizado)
        );
    }

    public function obtener(string $clave): ?array
    {
        if (!$this->claveValida($clave)) {
            return null;
        }

        $rutaPdf = $this->ruta($clave, 'pdf');
        $rutaMeta = $this->ruta($clave, 'json');

        if (!is_file($rutaPdf) || !is_file($rutaMeta)) {
            return null;
        }

        $modificado = @filemtime($rutaPdf);
        if (!$modificado || (time() - $modificado) > self::TTL_SEGUNDOS) {
            @unlink($rutaPdf);
            @unlink($rutaMeta);
            return null;
        }

        $contenido = @file_get_contents($rutaPdf);
        $metaRaw = @file_get_contents($rutaMeta);
        $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;

        if (!is_string($contenido) || $contenido === '' || !is_array($meta)) {
            return null;
        }

        return [
            'contenido_pdf' => $contenido,
            'nombre_archivo' => (string)($meta['nombre_archivo'] ?? 'Reporte_Seguimiento_Vinculacion.pdf')
        ];
    }

    public function guardar(string $clave, string $contenidoPdf, string $nombreArchivo): void
    {
        if (!$this->claveValida($clave) || $contenidoPdf === '') {
            return;
        }

        if (!$this->asegurarDirectorio()) {
            return;
        }

        $this->limpiarExpirados();

        $rutaPdf = $this->ruta($clave, 'pdf');
        $rutaMeta = $this->ruta($clave, 'json');

        $pdfTemporal = $rutaPdf . '.tmp.' . getmypid();
        $metaTemporal = $rutaMeta . '.tmp.' . getmypid();

        $meta = json_encode([
            'nombre_archivo' => $nombreArchivo,
            'creado_at' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (
            @file_put_contents($pdfTemporal, $contenidoPdf, LOCK_EX) === false ||
            @file_put_contents($metaTemporal, (string)$meta, LOCK_EX) === false
        ) {
            @unlink($pdfTemporal);
            @unlink($metaTemporal);
            return;
        }

        @rename($pdfTemporal, $rutaPdf);
        @rename($metaTemporal, $rutaMeta);
    }

    private function limpiarExpirados(): void
    {
        $pdfs = @glob($this->directorio . DIRECTORY_SEPARATOR . '*.pdf');
        $metas = @glob($this->directorio . DIRECTORY_SEPARATOR . '*.json');
        $archivos = array_merge(
            is_array($pdfs) ? $pdfs : [],
            is_array($metas) ? $metas : []
        );

        if (empty($archivos)) {
            return;
        }

        $limite = time() - (self::TTL_SEGUNDOS * 4);

        foreach ($archivos as $archivo) {
            $modificado = @filemtime($archivo);
            if ($modificado && $modificado < $limite) {
                @unlink($archivo);
            }
        }
    }

    private function asegurarDirectorio(): bool
    {
        if (is_dir($this->directorio)) {
            return is_writable($this->directorio);
        }

        return @mkdir($this->directorio, 0700, true) || is_dir($this->directorio);
    }

    private function ruta(string $clave, string $extension): string
    {
        return $this->directorio . DIRECTORY_SEPARATOR . $clave . '.' . $extension;
    }

    private function claveValida(string $clave): bool
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', $clave);
    }

    private function normalizar($valor)
    {
        if (!is_array($valor)) {
            return $valor;
        }

        if ($this->esLista($valor)) {
            return array_map([$this, 'normalizar'], $valor);
        }

        ksort($valor);
        foreach ($valor as $clave => $item) {
            $valor[$clave] = $this->normalizar($item);
        }

        return $valor;
    }

    private function esLista(array $valor): bool
    {
        if ($valor === []) {
            return true;
        }

        return array_keys($valor) === range(0, count($valor) - 1);
    }
}
