<?php

/** Estado técnico privado para OAuth y exclusión de envíos duplicados. Sin BD. */
final class CorreoEstadoPrivado
{
    private static function directorio(bool $crear): ?string
    {
        $root = realpath(dirname(__DIR__, 2));
        $dir = rtrim((string)(getenv('IMPE_MAIL_STATE_DIR') ?: dirname($root) . '/impe-private/mail-state'), '/\\');
        if (!is_dir($dir) && !$crear) { return null; }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('MAIL_STATE_DIRECTORY'); }
        $real = realpath($dir);
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $webRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
        if (!$real || is_link($dir) || str_starts_with(strtolower($real . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR)) ||
            ($webRoot && str_starts_with(strtolower($real . DIRECTORY_SEPARATOR), strtolower($webRoot . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('MAIL_STATE_NOT_PRIVATE');
        }
        return $real;
    }

    public static function leer(string $grupo, string $clave): array
    {
        $dir = self::directorio(false);
        if ($dir === null || !is_file($dir . DIRECTORY_SEPARATOR . hash('sha256', $grupo . "\0" . $clave) . '.json')) { return []; }
        return self::ejecutar($grupo, $clave, static function ($state) { return $state; });
    }

    public static function ejecutar(string $grupo, string $clave, callable $operacion)
    {
        $real = self::directorio(true);
        $name = hash('sha256', $grupo . "\0" . $clave);
        $path = $real . DIRECTORY_SEPARATOR . $name . '.json';
        $lockPath = $real . DIRECTORY_SEPARATOR . $name . '.lock';
        if (is_link($path) || is_link($lockPath)) { throw new RuntimeException('MAIL_STATE_LINK'); }
        $new = !file_exists($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if (!$lock) { throw new RuntimeException('MAIL_STATE_OPEN'); }
        if ($new) { @chmod($lockPath, 0600); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('MAIL_STATE_BUSY'); }
            $state = [];
            if (is_file($path)) {
                $state = json_decode((string)file_get_contents($path), true);
                if (!is_array($state)) { throw new RuntimeException('MAIL_STATE_CORRUPT'); }
            }
            $save = static function (array $next) use ($path) {
                $bytes = json_encode($next, JSON_THROW_ON_ERROR);
                $temporary = $path . '.' . bin2hex(random_bytes(8));
                try {
                    $h = @fopen($temporary, 'xb');
                    if (!$h) { throw new RuntimeException('MAIL_STATE_WRITE'); }
                    @chmod($temporary, 0600);
                    try {
                        if (fwrite($h, $bytes) !== strlen($bytes) || !fflush($h)) { throw new RuntimeException('MAIL_STATE_WRITE'); }
                        if (function_exists('fsync')) { fsync($h); }
                    } finally { fclose($h); }
                    if (!@rename($temporary, $path)) { throw new RuntimeException('MAIL_STATE_WRITE'); }
                } finally { if (is_file($temporary)) { @unlink($temporary); } }
            };
            return $operacion($state, $save);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
