<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__,3).'/prueba_telefonia/api/vincular_interaccion_zadarma.php');
$a=strpos($source,'function fechaMysqlDesdeIso(');$b=strpos($source,'$usuarioId =',$a);
if($a===false||$b===false)throw new RuntimeException('Missing actual date functions');
// Execute only these pure, repository-owned functions. No endpoint initialization.
eval(substr($source,$a,$b-$a));
date_default_timezone_set('UTC');$checks=0;
$assert=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$assert(fechaMysqlDesdeIso('2026-11-27 12:34:56')==='2026-11-27 12:34:56','Stored Mexico wall time must not be interpreted as UTC');
$assert(fechaMysqlDesdeIso('2026-11-27T18:34:56Z')==='2026-11-27 12:34:56','Explicit UTC converted once to Mexico');
$assert(fechaMysqlDesdeIso('2026-11-27T12:34:56-06:00')==='2026-11-27 12:34:56','Explicit Mexico offset is not shifted twice');
$assert(fechaMysqlDesdeIso(null)===null&&fechaMysqlDesdeIso('')===null,'Missing endpoint timestamp stays unknown');
$assert(fechaMysqlDesdeIso('2026-02-31 12:00:00')===null,'Malformed provider day cannot normalize silently');
$assert(fechaMysqlLocal('2026-02-31 12:00:00')===null,'Malformed legacy wall time cannot normalize silently');
echo "PASS $checks actual reconciliation date assertions: UTC host default, explicit offsets, missing and invalid provider timestamps. Pure source functions; no SQL/network.\n";
