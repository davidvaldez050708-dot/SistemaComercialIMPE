<?php
if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_usuarios_errores_[a-z0-9]+$/D',getenv('IMPE_DB_NAME')?:'')||getenv('IMPE_DB_HOST')!=='127.0.0.1:33319') exit(2);
$db=new mysqli('127.0.0.1:33319','root','');$db->set_charset('utf8mb4');$name=getenv('IMPE_DB_NAME');
if (($argv[1]??'')==='--drop') {$db->query('DROP DATABASE IF EXISTS `'.$name.'`');exit;}
if (($argv[1]??'')==='--seed') {
    $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->select_db($name);
    $db->query('CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)');$db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(5,'Marketing',1)");
    $db->query('CREATE TABLE usuarios(id INT AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(120),telefono VARCHAR(30),foto_perfil VARCHAR(255),correo VARCHAR(190) UNIQUE,usuario VARCHAR(80) UNIQUE,password VARCHAR(255),rol_id INT,estado INT,ultimo_acceso DATETIME NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,requiere_cambio_password INT DEFAULT 0,password_temporal_expira DATETIME NULL)');
    $s=$db->prepare("INSERT INTO usuarios(id,nombre,apellidos,correo,usuario,password,rol_id,estado) VALUES(?,'Persona','Ficticia',?,?,?, ?,1)");
    foreach ([1=>1,2=>1,3=>5] as $id=>$role) {$mail='fixture'.$id.'@example.test';$user='fixture'.$id;$hash=password_hash('ClavePrueba123',PASSWORD_DEFAULT);$s->bind_param('isssi',$id,$mail,$user,$hash,$role);$s->execute();}
    exit;
}
$db->select_db($name);
if (($argv[1]??'')==='--count') {echo $db->query('SELECT COUNT(*) n FROM usuarios')->fetch_assoc()['n'];exit;}
if (($argv[1]??'')==='--fail-insert') {$db->query("CREATE TRIGGER fallo_insert BEFORE INSERT ON usuarios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DETALLE_SQL_PRIVADO_DE_PRUEBA'");exit;}
if (($argv[1]??'')==='--fail-update') {$db->query("CREATE TRIGGER fallo_update BEFORE UPDATE ON usuarios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DETALLE_SQL_PRIVADO_DE_PRUEBA'");exit;}
if (($argv[1]??'')==='--last-admin') {$db->query('UPDATE usuarios SET rol_id=5 WHERE id=1');exit;}
exit(2);
