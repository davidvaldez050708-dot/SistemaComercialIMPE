<?php
require_once __DIR__.'/ControlCargaMysql.php';

/** Sólo evita repetir la instalación del catálogo; NO almacena autorización de usuarios. */
final class ImpeCatalogoPermisosInicializador
{
    public static function ejecutar(callable $instalar, array $fuentes, ?string $directorio = null, ?callable $reloj = null): void
    {
        $ahora = $reloj ? $reloj() : time();
        $fuentes[] = __FILE__;
        $hashes = [];
        foreach ($fuentes as $f) if (is_file($f)) $hashes[] = hash_file('sha256',$f);
        $version = hash('sha256',implode('|',$hashes));
        $identity = __DIR__.'|'.(defined('DB_HOST')?DB_HOST:'').'|'.(defined('DB_NAME')?DB_NAME:'');
        $dir = $directorio ?? dirname(__DIR__,3).'/impe-private/catalogos';
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el control privado del catálogo.');
        }
        $file = $dir.'/'.hash('sha256',$identity).'.json';
        $vigente = static function () use ($file,$version,$ahora): bool {
            $s = json_decode((string)@file_get_contents($file),true);
            return is_array($s) && ($s['version'] ?? '')===$version &&
                (int)($s['hasta'] ?? 0)>$ahora;
        };
        if ($vigente()) return;
        $lock = @fopen($file.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            if (PHP_SAPI!=='cli' && !headers_sent()) { http_response_code(503); header('Retry-After: 5'); }
            throw new RuntimeException('El catálogo se está preparando. Espera unos segundos.',503);
        }
        try {
            if ($vigente()) return;
            try { $instalar(); }
            catch (Throwable $e) { ImpeControlCargaMysql::registrarFallo($e); throw $e; }
            $tmp = tempnam($dir,'.catalogo-');
            if ($tmp === false) throw new RuntimeException('No se pudo registrar la inicialización del catálogo.');
            chmod($tmp,0600);
            try {
                if (file_put_contents($tmp,json_encode(['version'=>$version,'hasta'=>$ahora+21600]))===false || !rename($tmp,$file)) {
                    throw new RuntimeException('No se pudo guardar el control del catálogo.');
                }
            } finally { if (is_file($tmp)) unlink($tmp); }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
