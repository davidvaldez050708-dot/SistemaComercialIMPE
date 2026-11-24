<?php
/** Conexión compartida por petición y freno entre peticiones ante fallos temporales. */
final class ImpeControlCargaMysql
{
    private static ?mysqli $conexion = null;
    private static ?SplObjectStorage $fallosProcesados = null;

    private static function consultaAutomatica(): bool
    {
        $controller=is_string($_GET['controller']??null)?$_GET['controller']:'';
        $action=is_string($_GET['action']??null)?$_GET['action']:'';
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') return false;
        return in_array($controller.'.'.$action,[
            'reminder.pendientes','convocatoriaNotificacion.pendientes',
            'seguimientoVinculacion.resumenVerificacionTelefonicaHoy'
        ],true) || basename((string)($_SERVER['SCRIPT_NAME']??''))==='avisos_llamadas.php';
    }

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
        // Un fallo de conexión de otra petición no congela la operación humana.
        // 1142 conserva el freno general: abrir otra conexión no restaura SELECT.
        if (PHP_SAPI!=='cli' && (int)($data['codigo']??0)!==1142 && !self::consultaAutomatica()) return;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(503); header('Retry-After: '.min(120,$espera));
            header('Cache-Control: no-store');
            $html=stripos((string)($_SERVER['HTTP_ACCEPT']??''),'text/html')!==false &&
                !in_array(strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??'')),['fetch','xmlhttprequest'],true);
            header('Content-Type: '.($html?'text/html':'application/json').'; charset=utf-8');
            if ($html) self::paginaEspera(min(120,$espera));
            else echo json_encode(['ok'=>false,'reintentar_en'=>min(120,$espera),
                'mensaje'=>'El sistema está en una pausa breve de protección. Espera antes de volver a intentar.'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw new ImpeMysqlCircuitoAbierto('MySQL en recuperación temporal. No se intentó otra conexión.');
    }

    private static function paginaEspera(int $segundos): void
    {
        $get=($_SERVER['REQUEST_METHOD']??'GET')==='GET'?'true':'false';
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.
            '<title>Sistema Comercial · Conexión en espera</title><style>body{margin:0;background:#f4f7fc;color:#192540;font:16px Segoe UI,Arial,sans-serif;display:grid;place-items:center;min-height:100vh}main{box-sizing:border-box;background:white;border:1px solid #dce5f2;border-radius:18px;padding:32px;max-width:520px;margin:20px}small{color:#2d3e8f;font-weight:700}h1{font-size:24px}p{color:#526889;line-height:1.6}button{border:0;border-radius:10px;background:#2d3e8f;color:white;padding:12px 20px;font:inherit}button:disabled{opacity:.5}</style>'.
            '<main><small>SISTEMA COMERCIAL</small><h1>Conexión temporalmente en espera</h1><p>El acceso a la base de datos requiere una pausa de protección. No necesitas recargar repetidamente.</p><p role="status">Próximo intento en <strong id="segundos">'.$segundos.'</strong> segundos.</p><button id="reintentar" disabled>Reintentar</button></main>'.
            '<script>let segundos='.$segundos.';const automatico='.$get.';const boton=document.getElementById("reintentar");boton.onclick=()=>{if(automatico)location.reload();else location.assign("index.php?controller=login&action=mostrarLogin");};const reloj=setInterval(()=>{segundos=Math.max(0,segundos-1);document.getElementById("segundos").textContent=segundos;if(!segundos){clearInterval(reloj);boton.disabled=false;if(automatico)location.reload();}},1000);</script></html>';
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
        // La fábrica, el catálogo y el manejador global pueden recibir la misma causa.
        $causa=$error;while($causa->getPrevious())$causa=$causa->getPrevious();
        self::$fallosProcesados ??= new SplObjectStorage();
        if (self::$fallosProcesados->contains($causa)) return;
        self::$fallosProcesados->attach($causa);
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
            $controller=is_string($_GET['controller']??null)?$_GET['controller']:'';
            $action=is_string($_GET['action']??null)?$_GET['action']:'';
            $ruta=preg_replace('/[^A-Za-z0-9_.-]/','',$controller.'.'.$action);
            if (@file_put_contents($tmp,json_encode(['codigo'=>$code,'fallos'=>$fallos,
                'ultimo'=>time(),'hasta'=>time()+$seconds,'archivo'=>basename($causa->getFile()),
                'linea'=>$causa->getLine(),'ruta'=>$ruta])) === false || !@rename($tmp,$file)) @unlink($tmp);
            if (PHP_SAPI !== 'cli' && !headers_sent()) header('Retry-After: '.$seconds);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
final class ImpeMysqlCircuitoAbierto extends RuntimeException {}
