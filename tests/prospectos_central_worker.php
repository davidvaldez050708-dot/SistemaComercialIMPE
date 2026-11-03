<?php
if (PHP_SAPI!=='cli'||!preg_match('/^impe_test_prospectos_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require __DIR__.'/../config/db_connection.php';require __DIR__.'/../app/services/ProspectoCentralIncorporacionService.php';
$id=filter_var($argv[1]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if (!$id) exit(2);
$db=(new Database())->connect(false);echo json_encode((new ProspectoCentralIncorporacionService($db))->incorporarRegistro($id));
