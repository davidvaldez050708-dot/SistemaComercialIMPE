<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_union_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../tools/lib/UnificarBachillerato.php';
$admin=(new Database())->connect(false);$count=0;
$cases=[
    'ambos'=>[['bachillerato-general','Bachillerato General',1],['bachillerato-286','Bachillerato 286',1]],
    'solo_anterior'=>[['bachillerato-286','Bachillerato 286',1]],
    'anterior_renombrado'=>[['bachillerato-286','Bachillerato General',1]],
    'nombres_intercambiados'=>[['bachillerato-general','Bachillerato 286',1],['bachillerato-286','Bachillerato General',1]],
    'general_inactivo'=>[['bachillerato-general','Bachillerato General',0]],
    'alias_inactivo'=>[['bachillerato-general','Bachillerato General',1],['bachillerato-286','Bachillerato 286',0]],
    'general_desactivado'=>[['bachillerato-general','Bachillerato General',0],['bachillerato-286','Bachillerato 286',1]],
];
$directory=sys_get_temp_dir().'/impe-union-variantes-'.bin2hex(random_bytes(4));mkdir($directory,0700);
foreach($cases as $label=>$rows){
    $schema='impe_test_handly_aliados_union_var_'.bin2hex(random_bytes(5));
    $admin->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
    $db=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,$schema);$db->set_charset('utf8mb4');
    $db->query('CREATE TABLE formulario_programas (codigo VARCHAR(64) PRIMARY KEY,nombre VARCHAR(180) UNIQUE,activo TINYINT) ENGINE=InnoDB');
    $db->query('CREATE TABLE formulario_aliado_programas (aliado_id INT,programa_codigo VARCHAR(64),PRIMARY KEY(aliado_id,programa_codigo),FOREIGN KEY(programa_codigo) REFERENCES formulario_programas(codigo)) ENGINE=InnoDB');
    foreach($rows as [$code,$name,$active]){$db->query("INSERT INTO formulario_programas VALUES ('$code','$name',$active)");$db->query("INSERT INTO formulario_aliado_programas VALUES (1,'$code')");}
    // Replicate calculated columns found in production schemas on both engines.
    $db->query("ALTER TABLE formulario_aliado_programas ADD COLUMN programa_calculado VARCHAR(96) GENERATED ALWAYS AS (CONCAT('programa:', programa_codigo)) STORED");
    $migration=new UnificarBachillerato($db);$before=$migration->snapshot();$path=$migration->apply($directory);
    $expectedActive=max(array_column($rows,2));$canonical=$db->query('SELECT * FROM formulario_programas')->fetch_all(MYSQLI_ASSOC);
    if(count($canonical)!==1||$canonical[0]['codigo']!=='bachillerato-general'||$canonical[0]['nombre']!=='Bachillerato General'||(int)$canonical[0]['activo']!==$expectedActive)throw new RuntimeException('No unifica '.$label);
    if((int)$db->query('SELECT COUNT(*) FROM formulario_aliado_programas')->fetch_row()[0]!==1)throw new RuntimeException('No conserva autorización '.$label);
    if($db->query('SELECT programa_calculado FROM formulario_aliado_programas')->fetch_row()[0]!=='programa:bachillerato-general')throw new RuntimeException('No recalcula columna generada '.$label);
    if($migration->apply($directory)!==null)throw new RuntimeException('No es idempotente '.$label);
    if($path!==null)$migration->restore($path);
    if($migration->snapshot()!==$before)throw new RuntimeException('No restaura '.$label);
    $count++;echo "OK variante SQL $label: unifica, conserva actividad, idempotencia y restauración\n";
}
echo "PASS $count variantes de catálogo; sólo bases sintéticas independientes\n";
