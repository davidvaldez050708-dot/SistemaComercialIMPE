<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/db_connection.php';
require_once dirname(__DIR__).'/app/services/TelefoniaVentasSchema.php';
$mode=$argv[1]??'--check';
if(count($argv)>2||!in_array($mode,['--check','--apply'],true))exit("Uso: php tools/migrar_telefonia_ventas.php --check|--apply\n");
try {
    $db=(new Database())->connect(false);$todo=TelefoniaVentasSchema::pendientes($db);
    if(!$todo)exit("OK: estructura comercial actualizada. Sin cambios.\n");
    echo count($todo)." operación(es) aditiva(s) pendientes.\n";
    if($mode==='--check')exit("SITIO INTACTO. Respalda la base antes de ejecutar --apply.\n");
    foreach($todo as $sql)if(!$db->query($sql))throw new RuntimeException('Migración incompleta.');
    echo "OK: migración aditiva aplicada. No se borraron registros ni se cambiaron permisos.\n";
} catch(Throwable $e) {fwrite(STDERR,"ERROR: no se completó la revisión/migración. Revisa el registro privado de PHP.\n");error_log('[migracion_ventas] '.get_class($e));exit(1);}
