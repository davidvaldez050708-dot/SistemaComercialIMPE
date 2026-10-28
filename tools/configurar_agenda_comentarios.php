<?php
// Sólo instalación explícita en CLI. No se ejecuta DDL desde el navegador.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$modo = $argv[1] ?? '--check';
if ($argc>2 || !in_array($modo,['--check','--apply'],true)) { fwrite(STDERR,"Uso: --check|--apply\n");exit(2); }
require_once __DIR__.'/../config/db_connection.php';
try {
    $db = (new Database())->connect(false);
    if ($modo==='--apply') {
        $sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_28_agenda_comentarios.sql');
        foreach (explode(';',$sql) as $sentencia) {
            if (trim($sentencia)!=='') { $db->query($sentencia); }
        }
    }
    foreach ([
        'agenda_comentarios'=>['id,fecha,recordatorio_at,comentario,creado_por,autor_nombre,creado_at,eliminado_at,eliminado_por',['id']],
        'agenda_comentarios_lecturas'=>['comentario_id,usuario_id,novedad_leida_at,recordatorio_atendido_at',['comentario_id','usuario_id']]
    ] as $tabla=>[$columnas,$pk]) {
        $db->query('SELECT '.$columnas.' FROM '.$tabla.' LIMIT 0');
        $indices=$db->query("SHOW INDEX FROM {$tabla} WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
        usort($indices,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if(array_column($indices,'Column_name')!==$pk)throw new RuntimeException('Estructura incompatible.');
    }
    echo "OK: comentarios de agenda preparados, lecturas independientes por usuario y hora opcional.\n";
    echo "No se modificaron reuniones, seguimientos, roles, permisos ni credenciales.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Comentarios aún no disponibles. Ejecuta --apply o revisa las dos tablas nuevas. Código=".(int)$e->getCode()."\n");exit(1);
}
