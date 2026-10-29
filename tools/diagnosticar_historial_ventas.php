<?php
declare(strict_types=1);

/** Diagnóstico CLI: no sincroniza, no vincula, no llama a Zadarma y no escribe datos. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options=getopt('', ['site:']);
$site=realpath((string)($options['site']??''));
if (!$site || !is_file($site.'/config/db_connection.php')) {
    fwrite(STDERR,"Uso: php diagnosticar_historial_ventas.php --site /ruta/public_html\n");exit(1);
}
require_once $site.'/config/db_connection.php';
require_once $site.'/app/services/TelefoniaMarcadorPanelService.php';
require_once $site.'/app/services/TelefoniaVentasLlamadasService.php';
date_default_timezone_set('America/Mexico_City');

/** Reutiliza la selección vigente de pendientes, sustituyendo su única escritura. */
final class ImpeDiagnosticoMarcacionesLectura extends TelefoniaMarcacionesVentasService
{
    public array $candidatos=[];
    public function __construct(private mysqli $lectura) {parent::__construct($lectura,false);}
    public function vincular(int $usuarioId,string $token,string $pbxCallId): void
    {
        $s=$this->lectura->prepare('SELECT usuario_id,extension,token FROM telefonia_ventas_marcaciones WHERE pbx_call_id=?');
        $s->bind_param('s',$pbxCallId);$s->execute();$existentes=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $conflicto=false;foreach($existentes as $r)if($r['token']!==$token)$conflicto=true;
        $this->candidatos[]=['referencia_oculta'=>substr(hash('sha256',$pbxCallId),0,12),
            'ya_vinculada_a_otro_intento'=>$conflicto,'coincidencias_existentes'=>count($existentes)];
    }
}

function errorDiagnostico(Throwable $e): array
{
    $message=preg_replace('/\b(?:out_|sdk-)[A-Za-z0-9_-]+\b/','[referencia oculta]',$e->getMessage());
    $message=preg_replace('/\+?\d[\d ()-]{7,}\d/','[valor oculto]',(string)$message);
    return ['clase'=>get_class($e),'codigo'=>$e->getCode(),'mensaje'=>$message,
        'archivo'=>basename($e->getFile()),'linea'=>$e->getLine()];
}

try {
    $versions=[];
    foreach(['TelefoniaVentasLlamadasService.php','TelefoniaResultadoVentasCatalogo.php',
        'TelefoniaMarcadorPanelService.php','TelefoniaMarcacionesVentasService.php','TelefoniaVentasEvidenciaService.php'] as $file) {
        $path=$site.'/app/services/'.$file;$versions[$file]=is_file($path)?hash_file('sha256',$path):'AUSENTE';
    }
    $db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
    try {
        $users=$db->query("SELECT u.id,t.extension FROM usuarios u LEFT JOIN telefonia_extensiones t ON t.usuario_id=u.id AND t.activo=1 WHERE u.rol_id=3 AND u.estado=1 ORDER BY u.id")->fetch_all(MYSQLI_ASSOC);
        $results=[];
        foreach($users as $u){
            $row=['usuario_id'=>(int)$u['id'],'extension'=>$u['extension']];
            try{$calls=(new TelefoniaVentasLlamadasService($db))->listar((int)$u['id'],[],false);$row['historial_guardado']=count($calls);}
            catch(Throwable $e){$row['error_lectura']=errorDiagnostico($e);}
            if($u['extension']!==null){
                try{$test=new ImpeDiagnosticoMarcacionesLectura($db);$test->reconciliarPendientes((int)$u['id'],(string)$u['extension']);$row['conciliacion_sin_escrituras']=$test->candidatos;}
                catch(Throwable $e){$row['error_conciliacion']=errorDiagnostico($e);}
            }
            $results[]=$row;
        }
    }finally{$db->rollback();}
    echo json_encode(['versiones'=>$versions,'cuentas'=>$results,'estado'=>'Sólo lectura; ninguna vinculación ni sincronización ejecutada.'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){echo json_encode(['error'=>errorDiagnostico($e)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit(1);}
