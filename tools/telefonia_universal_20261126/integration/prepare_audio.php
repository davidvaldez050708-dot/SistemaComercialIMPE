<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$lab=getenv('IMPE_PHONE_LAB_ROOT');$c=json_decode(file_get_contents($lab.'/credentials.json'),true);
if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Synthetic loopback only');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($c['host'],$c['root_user'],$c['root_password'],$c['database'],$c['port']);
$db->query(file_get_contents(dirname(__DIR__).'/schema_audio_evidencias.sql'));
echo "Synthetic audio evidence table prepared explicitly. No application data or production migration.\n";
