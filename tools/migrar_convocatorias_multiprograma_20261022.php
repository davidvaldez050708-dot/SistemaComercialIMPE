<?php
/** Prevalidación e instalación aislada del vínculo por programa.
 * php tools/migrar_convocatorias_multiprograma_20261022.php --check
 * php tools/migrar_convocatorias_multiprograma_20261022.php --apply --respaldo-confirmado
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db_connection.php';
try {
    $db = (new Database())->connect(false);
    $meta = $db->query("SELECT COLUMN_TYPE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='convocatorias' AND COLUMN_NAME='id'")->fetch_assoc();
    if (!$meta || strtolower($meta['DATA_TYPE'])!=='int' || stripos($meta['COLUMN_TYPE'],'unsigned')!==false) {
        throw new RuntimeException('La clave convocatorias.id no es INT firmado; requiere conciliación sin ALTER.');
    }
    $name='marketing_aliado_convocatorias_origen';
    $existente=(bool)$db->query("SHOW TABLES LIKE '$name'")->num_rows;
    if ($existente) {
        $columnas=$db->query("SHOW COLUMNS FROM `$name`")->fetch_all(MYSQLI_ASSOC);
        $campos=array_column($columnas,'Field');
        foreach(['convocatoria_id','origen_tipo','origen_id','programa_codigo','creado_por'] as $campo) {
            if (!in_array($campo,$campos,true)) throw new RuntimeException('Estructura previa del vínculo incompatibile: '.$campo);
        }
        echo "OK: vínculo por programa y origen instalado.\n";
        exit(0);
    }
    echo "PENDIENTE: vínculo de convocatorias por programa y origen.\n";
    if (!in_array('--apply', $argv, true)) {
        echo "SITIO INTACTO. SQL NO ejecutado.\n"; exit(2);
    }
    if (!in_array('--respaldo-confirmado', $argv, true)) {
        throw new RuntimeException('Es obligatorio confirmar respaldo SQL completo de producción.');
    }
    $sql=trim(file_get_contents(__DIR__.'/../database/migrations/2026_10_22_convocatorias_multiprograma_origen.sql'));
    if ($sql==='' || !$db->query($sql)) {
        throw new RuntimeException('No se creó vínculo de convocatoria: '.$db->error);
    }
    echo "OK: vínculo por programa y origen instalado.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\n"); exit(1);
}
