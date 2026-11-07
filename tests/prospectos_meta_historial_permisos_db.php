<?php
/**
 * Prueba sobre base temporal MySQL: el permiso de historial sólo se concede
 * inicialmente a quienes ya tenían consulta+operación. Nunca reautoriza una
 * revocación explícita al volver a ejecutar la migración.
 */
if (getenv('IMPE_HIST_PERM_DB_TEST')!=='YES') {
    fwrite(STDERR,"Sólo base MySQL desechable.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'','',
    (int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_meta_hist_permisos_'.bin2hex(random_bytes(4));
$checks=0;
$test=static function(bool $valid,string $name)use(&$checks) {
    if (!$valid)throw new RuntimeException('FALLÓ: '.$name);
    $checks++;
};
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->query(
        "CREATE TABLE permisos (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            modulo VARCHAR(80),codigo VARCHAR(100) NOT NULL UNIQUE,
            nombre VARCHAR(150),descripcion VARCHAR(400),
            estado TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB"
    );
    $db->query(
        'CREATE TABLE rol_permisos (
            rol_id INT NOT NULL, permiso_id INT NOT NULL,
            PRIMARY KEY (rol_id,permiso_id)
        ) ENGINE=InnoDB'
    );
    $db->query(
        "INSERT INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES
        ('Prospectos','prospectos.ver_todos','Ver todos','',1),
        ('Prospectos','prospectos.configurar_formularios','Configurar','',1),
        ('Prospectos','prospectos.importar_meta','Importar','',1)"
    );
    $db->query(
        "INSERT INTO rol_permisos (rol_id,permiso_id)
         SELECT 1,id FROM permisos
         WHERE codigo IN ('prospectos.ver_todos','prospectos.configurar_formularios')"
    );
    $db->query(
        "INSERT INTO rol_permisos (rol_id,permiso_id)
         SELECT 5,id FROM permisos
         WHERE codigo IN ('prospectos.ver_todos','prospectos.importar_meta')"
    );
    $db->query(
        "INSERT INTO rol_permisos (rol_id,permiso_id)
         SELECT 6,id FROM permisos WHERE codigo='prospectos.ver_todos'"
    );
    $db->query(
        "INSERT INTO rol_permisos (rol_id,permiso_id)
         SELECT 7,id FROM permisos WHERE codigo='prospectos.importar_meta'"
    );
    $migration=file_get_contents(__DIR__.'/../database/migrations/2026_11_07_prospectos_meta_historial_permiso_consulta.sql');
    $run=static function()use($db,$migration) {
        $db->multi_query($migration);
        do { $r=$db->store_result();if($r)$r->free(); }
        while ($db->more_results()&&$db->next_result());
    };
    $run();
    $rows=$db->query(
        "SELECT rp.rol_id
         FROM rol_permisos rp
         JOIN permisos p ON p.id=rp.permiso_id
         WHERE p.codigo='prospectos.ver_historial_meta'
         ORDER BY rp.rol_id"
    )->fetch_all(MYSQLI_ASSOC);
    $test(array_map('intval',array_column($rows,'rol_id'))===[1,5],
        'se otorga a Administrador y Marketing ya autorizados');
    $new=$db->query(
        "SELECT estado,modulo,nombre FROM permisos
         WHERE codigo='prospectos.ver_historial_meta'"
    )->fetch_assoc();
    $test((int)$new['estado']===1&&$new['modulo']==='Prospectos',
        'aparece en el catálogo de Roles y permisos');
    $counts=$db->query(
        "SELECT COUNT(*) n FROM rol_permisos rp
         JOIN permisos p ON p.id=rp.permiso_id
         WHERE p.codigo='prospectos.importar_meta' AND rp.rol_id=1"
    )->fetch_assoc();
    $test((int)$counts['n']===0,
        'Administrador no obtiene importación operativa');
    $db->query(
        "DELETE rp FROM rol_permisos rp
         JOIN permisos p ON p.id=rp.permiso_id
         WHERE p.codigo='prospectos.ver_historial_meta' AND rp.rol_id=5"
    );
    $run();
    $rows=$db->query(
        "SELECT rp.rol_id
         FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
         WHERE p.codigo='prospectos.ver_historial_meta'"
    )->fetch_all(MYSQLI_ASSOC);
    $test(array_map('intval',array_column($rows,'rol_id'))===[1],
        'volver a instalar no restablece la revocación de Marketing');
    $test((int)$db->query(
        "SELECT COUNT(*) n FROM permisos
         WHERE codigo='prospectos.ver_historial_meta'"
    )->fetch_assoc()['n']===1,'no hay permisos duplicados');
    $test((int)$db->query(
        "SELECT COUNT(*) n FROM rol_permisos rp JOIN permisos p
         ON rp.permiso_id=p.id WHERE p.codigo='prospectos.importar_meta'"
    )->fetch_assoc()['n']===2,
        'las concesiones previas del importador permanecen intactas');
    echo "OK: $checks comprobaciones SQL de concesión inicial y revocación persistente.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->close();
}
