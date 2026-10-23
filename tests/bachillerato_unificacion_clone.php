<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
$target=$argv[1]??'';
if(!preg_match('/^impe_test_handly_aliados_union_[a-z0-9_]+$/D',$target))exit(2);
require_once __DIR__.'/../config/db_connection.php';
$db=(new Database())->connect(false);$source=$db->query('SELECT DATABASE()')->fetch_row()[0];
if($target===$source)throw new RuntimeException('La copia debe usar otra BD sintética.');
$db->query("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$source' AND TABLE_TYPE='BASE TABLE'")->fetch_all(MYSQLI_ASSOC);
// Sólo construcción de fixture aislada, nunca parte de la migración entregada.
$db->query('SET FOREIGN_KEY_CHECKS=0');
try{
    $db->select_db($target);
    foreach($tables as $row){$name=$row['TABLE_NAME'];$ddl=$db->query("SHOW CREATE TABLE `$source`.`$name`")->fetch_row()[1];$ddl=str_replace("`$source`.","`$target`.",$ddl);$db->query($ddl);$db->query("INSERT INTO `$name` SELECT * FROM `$source`.`$name`");}
}finally{$db->query('SET FOREIGN_KEY_CHECKS=1');}
$foreign=(int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$target' AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>'$target'")->fetch_row()[0];
if($foreign)throw new RuntimeException('La copia conserva una referencia fuera de su esquema sintético.');
echo "OK: copia sintética independiente $target\n";
