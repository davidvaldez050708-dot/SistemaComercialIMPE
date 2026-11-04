<?php
error_reporting(E_ALL);
set_error_handler(static function ($n, $m, $f, $l) { throw new ErrorException($m, 0, $n, $f, $l); });
define('BASE_URL', 'http://denue.test/');
function tienePermiso($p) { return true; }
$_SESSION = ['usuario_id' => 99, 'rol_id' => 4];
$estado = ['id' => 9, 'nombre' => 'Ciudad de México'];
$municipiosCandidatos = [['id'=>11,'nombre'=>'Tláhuac'],['id'=>12,'nombre'=>'Coyoacán']];
$seguimientos = []; $puedeCrearSeguimiento = true; $modoSeguimiento = 'analista';
require __DIR__ . '/../../app/views/seguimiento_vinculacion/estado.php';
