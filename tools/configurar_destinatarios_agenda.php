<?php
// Instalación aditiva explícita, sólo CLI. No modifica comentarios existentes.
if (PHP_SAPI !== 'cli') { http_response_code(404);exit; }
$modo=$argv[1]??'--check';
if ($argc>2 || !in_array($modo,['--check','--apply'],true)) { fwrite(STDERR,"Uso: --check|--apply\n");exit(2); }
require_once __DIR__.'/../config/db_connection.php';
try {
    $db=(new Database())->connect(false);
    $columna=$db->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'destinatario_id'")->fetch_assoc();
    if (!$columna && $modo==='--apply') {
        $db->query(file_get_contents(__DIR__.'/../database/migrations/2026_10_29_agenda_comentarios_destinatario.sql'));
        $columna=$db->query("SHOW COLUMNS FROM agenda_comentarios LIKE 'destinatario_id'")->fetch_assoc();
    }
    if (!$columna || !preg_match('/^int(?:\(\d+\))? unsigned$/i',$columna['Type']) ||
        $columna['Null']!=='YES' || $columna['Default']!==null) throw new RuntimeException('Estructura incompatible.');
    require_once __DIR__.'/../app/services/AgendaComentarioRepository.php';
    $destinatarios=(new AgendaComentarioRepository($db))->destinatarios();
    echo 'OK: destinatarios preparados. Usuarios activos con Agenda: '.count($destinatarios).".\n";
    echo "Los comentarios anteriores conservan sus avisos generales y su privacidad. Sin cambios en reuniones, permisos, horarios o credenciales.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Destinatarios aún no disponibles. Revisa la instalación con --apply. Código='.(int)$e->getCode()."\n");exit(1);
}
