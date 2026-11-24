<?php
/** Conexión compartida por petición y freno entre peticiones ante fallos temporales. */
final class ImpeControlCargaMysql
{
    private static ?mysqli $conexion = null;

    private static function archivo(): string
    {
        return dirname(__DIR__,3).'/impe-private/mysql-circuito.json';
    }

    public static function comprobar(bool $permitirCli = true): void
    {
        if ($permitirCli && PHP_SAPI === 'cli') return;
        $data = json_decode((string)@file_get_contents(self::archivo()),true);
        $espera = is_array($data) ? (int)($data['hasta'] ?? 0)-time() : 0;
        if ($espera <= 0) return;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(503); header('Retry-After: '.min(120,$espera));
            header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
            echo json_encode(['ok'=>false,'reintentar_en'=>min(120,$espera),
                'mensaje'=>'La base de datos está recuperándose. Espera antes de volver a intentar.'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw new ImpeMysqlCircuitoAbierto('MySQL en recuperación temporal. No se intentó otra conexión.');
    }

    public static function conexion(): mysqli
    {
        if (self::$conexion !== null) return self::$conexion;
        self::comprobar(false);
        try {
            return self::$conexion = method_exists(Database::class,'compartida')
                ? Database::compartida() : (new Database())->connect();
        } catch (Throwable $e) { self::registrarFallo($e); throw $e; }
    }

    public static function codigoTemporal(Throwable $error): int
    {
        do {
            if (in_array((int)$error->getCode(),[2002,1203,1142,1040],true)) return (int)$error->getCode();
            $error = $error->getPrevious();
        } while ($error !== null);
        return 0;
    }

    public static function registrarFallo(Throwable $error): void
    {
        if ($error instanceof ImpeMysqlCircuitoAbierto) return;
        $code = self::codigoTemporal($error);
        if (!$code) return;
        $file = self::archivo(); $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return;
        $lock = @fopen($file.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) { if (is_resource($lock)) fclose($lock); return; }
        try {
            $old = json_decode((string)@file_get_contents($file),true);
            $fallos = is_array($old) && (int)($old['ultimo'] ?? 0)>time()-300
                ? min(8,(int)($old['fallos'] ?? 0)+1) : 1;
            $seconds = $code===1142 ? 120 : min(120,2**($fallos-1));
            $tmp = @tempnam($dir,'.circuito-');
            if ($tmp === false) return;
            @chmod($tmp,0600);
            if (@file_put_contents($tmp,json_encode(['codigo'=>$code,'fallos'=>$fallos,
                'ultimo'=>time(),'hasta'=>time()+$seconds])) === false || !@rename($tmp,$file)) @unlink($tmp);
            if (PHP_SAPI !== 'cli' && !headers_sent()) header('Retry-After: '.$seconds);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
final class ImpeMysqlCircuitoAbierto extends RuntimeException {}
