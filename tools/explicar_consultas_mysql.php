<?php
/** Sólo CLI, tres EXPLAIN de lectura; no crea índices ni muestra datos de personas. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../config/db_connection.php';
$db=null;$salida=0;
try {
    $db=(new Database())->connect(false);
    foreach ([
        'PERMISOS'=>"SELECT id FROM permisos WHERE codigo='telefonia.usar' LIMIT 1",
        'EXTENSIONES'=>"SELECT extension FROM telefonia_extensiones WHERE usuario_id=0 AND proveedor='ZADARMA' AND activo=1 LIMIT 1",
        'PERMISOS_ROL'=>"SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=0 AND p.estado=1 ORDER BY p.codigo"
    ] as $name=>$sql) {
        foreach ($db->query('EXPLAIN '.$sql)->fetch_all(MYSQLI_ASSOC) as $row) {
            echo $name.' '.json_encode(array_intersect_key($row,array_flip(['table','type','possible_keys','key','rows','Extra'])),JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
    }
} catch (Throwable $e) {
    echo 'EXPLAIN_NO_DISPONIBLE_CODIGO='.(int)$e->getCode().PHP_EOL;
    $salida=1;
} finally {if ($db instanceof mysqli) $db->close();}
exit($salida);
