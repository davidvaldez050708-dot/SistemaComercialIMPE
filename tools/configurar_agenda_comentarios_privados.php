<?php
// Migración explícita de privacidad, únicamente desde CLI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$modo=$argv[1]??'--check';
if ($argc>2 || !in_array($modo,['--check','--apply'],true)) { fwrite(STDERR,"Uso: --check|--apply\n");exit(2); }
require_once __DIR__.'/../config/db_connection.php';
try {
    $db=(new Database())->connect(false);
    $columna=$db->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'privado'")->fetch_assoc();
    if (!$columna && $modo==='--apply') {
        $db->query(file_get_contents(__DIR__.'/../database/migrations/2026_10_29_agenda_comentarios_privados.sql'));
        $columna=$db->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'privado'")->fetch_assoc();
    }
    if (!$columna || !preg_match('/^tinyint(?:\(\d+\))? unsigned$/i',$columna['Type']) ||
        $columna['Null']!=='NO' || (string)$columna['Default']!=='0') throw new RuntimeException('Estructura incompatible.');
    echo "OK: privacidad instalada. Los comentarios existentes conservan su visibilidad compartida.\n";
    echo "Sin cambios en reuniones, lecturas, permisos, horarios o credenciales.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Privacidad aún no disponible. Ejecuta --apply o revisa la columna privado de agenda_comentarios. Código=".(int)$e->getCode()."\n");exit(1);
}
