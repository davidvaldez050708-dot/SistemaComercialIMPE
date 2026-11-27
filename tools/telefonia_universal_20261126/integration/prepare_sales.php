<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$lab=getenv('IMPE_PHONE_LAB_ROOT');
if(!is_string($lab)||!is_file($lab.'/credentials.json'))throw new RuntimeException('Set IMPE_PHONE_LAB_ROOT to the existing private synthetic laboratory.');
// Explicit schema setup for the existing loopback fixture; never a site migrator.
$c=json_decode(file_get_contents($lab.'/credentials.json'),true,32,JSON_THROW_ON_ERROR);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Wrong fixture target');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($c['host'],$c['root_user'],$c['root_password'],$c['database'],$c['port']);$db->set_charset('utf8mb4');
require_once $lab.'/site/app/services/TelefoniaResultadoVentasService.php';
require_once $lab.'/site/app/services/TelefoniaVentasSchema.php';
(new TelefoniaResultadoVentasService($db))->asegurarEstructura();
foreach(TelefoniaVentasSchema::pendientes($db) as $sql)$db->query($sql);
$availability=file_get_contents(dirname(__DIR__,3).'/database/migrations/2026_10_18_disponibilidad_devoluciones.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS telefonia_(?:presencia|disponibilidad)\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s',$availability,$statements);
if(count($statements[0])!==2)throw new RuntimeException('Unexpected availability fixture definition');
foreach($statements[0] as $sql)$db->query($sql);
$db->query("CREATE OR REPLACE TRIGGER fixture_fail_sales_contact BEFORE INSERT ON telefonia_contactos_personales FOR EACH ROW BEGIN IF NEW.nombre='fixture-fail-contact' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic contact failure'; END IF; END");
echo "Sales fixture schema prepared from installed services on loopback only. No application rows, roles or permissions changed.\n";
