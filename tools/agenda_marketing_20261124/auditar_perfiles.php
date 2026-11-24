<?php
// Sólo SELECT y lectura de archivos. No inicia sesiones ni cambia permisos.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$root=realpath($argv[1]??(getenv('IMPE_AGENDA_PUBLIC')?:getenv('HOME').'/domains/fundacionrededumex.org/public_html'));
if (!$root || !is_file($root.'/index.php')) { fwrite(STDERR,"No se encontró la raíz del CRM.\n");exit(1); }
function fuenteAuditoria(string $root,string $name): string {
    $text=is_file($root.'/'.$name)?file_get_contents($root.'/'.$name):false;
    return $text===false?'':$text;
}
try {
    $engine=fuenteAuditoria($root,'public/javascript/agenda_alertas.js');
    $normal=fuenteAuditoria($root,'public/javascript/recordatorios.js');
    $marketing=fuenteAuditoria($root,'public/javascript/convocatoria_notificaciones.js');
    $footer=fuenteAuditoria($root,'app/views/layout/dashboard_footer.php');
    $topbar=fuenteAuditoria($root,'app/views/layout/topbar.php');
    $controller=fuenteAuditoria($root,'app/controllers/ReminderController.php');
    $marketingController=fuenteAuditoria($root,'app/controllers/ConvocatoriaNotificacionController.php');
    $shared=[
        'motor_de_agenda'=>str_contains($engine,'window.IMPEAgendaAlerts'),
        'sonido_habilitado_globalmente'=>(bool)preg_match('/sound\s*:\s*true/',$engine),
        'archivo_audio_disponible'=>is_file($root.'/public/audio/agenda-aviso.mp3') && filesize($root.'/public/audio/agenda-aviso.mp3')>0,
        'motor_incluido_en_layout'=>str_contains($footer,"'agenda_alertas.js'"),
        'campana_generica_para_sesiones'=>(bool)preg_match('/\$mostrarCentroAvisos\s*=\s*!empty\(\$_SESSION\[[\x27\x22]usuario_id[\x27\x22]\]\)/',$topbar),
        'generico_ingesta'=>str_contains($normal,"alertasAgenda('ingest'"),
        'generico_presentador'=>str_contains($normal,"alertasAgenda('setPresenter'"),
        'generico_sonido'=>str_contains($normal,"alertasAgenda('onToastShown'"),
        'generico_fuente_comentarios'=>str_contains($controller,'AgendaComentarioService') && str_contains($controller,'->notificaciones($usuarioId)'),
        'administrador_fuente_comentarios'=>substr_count($controller,'->notificaciones($usuarioId)')>=2,
        'marketing_fuente_comentarios'=>str_contains($marketingController,'AgendaMarketingNotificationService'),
        'marketing_toast'=>str_contains($marketing,'/* IMPE_AGENDA_MARKETING_V1 */'),
        'marketing_sonido'=>str_contains($marketing,"call('onToastShown',item)"),
        'marketing_carga_motor'=>str_contains($marketing,'function ensureEngine()'),
    ];
    require_once $root.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $sql="SELECT r.id,r.nombre,COUNT(DISTINCT u.id) AS usuarios_activos,
        MAX(CASE WHEN p.codigo='agenda.ver' THEN 1 ELSE 0 END) AS agenda_ver,
        MAX(CASE WHEN p.codigo='agenda.comentarios.ver' THEN 1 ELSE 0 END) AS comentarios_ver,
        MAX(CASE WHEN p.codigo='agenda.comentarios.recibir_dirigidos' THEN 1 ELSE 0 END) AS recibir_dirigidos,
        MAX(CASE WHEN p.codigo='agenda.comentarios.enviar_administrador' THEN 1 ELSE 0 END) AS enviar_administrador,
        MAX(CASE WHEN p.codigo='convocatorias.gestionar' THEN 1 ELSE 0 END) AS convocatorias_gestionar
        FROM roles r LEFT JOIN usuarios u ON u.rol_id=r.id AND u.estado=1
        LEFT JOIN rol_permisos rp ON rp.rol_id=r.id
        LEFT JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1
        WHERE r.estado=1 GROUP BY r.id,r.nombre ORDER BY r.id";
    $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
    $roles=[];$without=0;$incomplete=0;$eligible=0;
    $baseReady=$shared['motor_de_agenda'] && $shared['sonido_habilitado_globalmente'] && $shared['archivo_audio_disponible'];
    foreach ($rows as $row) {
        $full=!empty($row['agenda_ver']) && !empty($row['comentarios_ver']);
        $allowed=$full || !empty($row['recibir_dirigidos']);
        $isMarketing=(int)$row['id']!==1 && strcasecmp(trim($row['nombre']),'Marketing')===0 && !empty($row['convocatorias_gestionar']);
        $ready=$isMarketing
            ? $shared['marketing_fuente_comentarios'] && $shared['marketing_toast'] && $shared['marketing_sonido'] && ($shared['motor_incluido_en_layout'] || $shared['marketing_carga_motor'])
            : $shared['campana_generica_para_sesiones'] && $shared['motor_incluido_en_layout'] && $shared['generico_ingesta'] && $shared['generico_presentador'] && $shared['generico_sonido'] && $shared['generico_fuente_comentarios'] && ((int)$row['id']!==1 || $shared['administrador_fuente_comentarios']);
        $count=(int)$row['usuarios_activos'];
        if (!$allowed) $without+=$count;else {$eligible+=$count;if (!$baseReady || !$ready) $incomplete+=$count;}
        $roles[]=[
            'perfil'=>$row['nombre'],'usuarios_activos'=>$count,
            'puede_recibir_comentarios'=>$allowed,
            'alcance'=>$full?'Agenda y dirigidos':($allowed?'Sólo dirigidos':'Sin permiso de comentarios'),
            'canal'=>$isMarketing?'Campana de Marketing':'Campana general',
            'integracion_de_avisos_y_sonido'=>$allowed?($baseReady && $ready?'Verificada en archivos':'Pendiente de verificar'): 'No aplica sin permiso',
            'permiso_enviar_al_administrador'=>!empty($row['enviar_administrador']),
        ];
    }
    $inactive=(int)$db->query('SELECT COUNT(*) AS total FROM usuarios u LEFT JOIN roles r ON r.id=u.rol_id WHERE u.estado=1 AND (r.id IS NULL OR r.estado<>1)')->fetch_assoc()['total'];
    $db->close();
    echo json_encode([
        'auditoria'=>'Comentarios de Agenda: configuración de servidor por perfil. Sólo lectura.',
        'archivos'=>$shared,'perfiles'=>$roles,
        'resumen'=>['usuarios_con_permiso'=>$eligible,'usuarios_sin_permiso_de_comentarios'=>$without,
            'usuarios_con_integracion_pendiente'=>$incomplete,'usuarios_activos_con_rol_inactivo_o_inexistente'=>$inactive],
        'limites'=>[
            'No comprueba sesiones abiertas ni reproduce audio en los dispositivos de los usuarios.',
            'Los permisos consultados son los actuales de la base de datos; una sesión abierta puede conservar permisos anteriores.',
            'El sonido está sujeto al bloqueo de reproducción del navegador, volumen y silencio de pestaña.',
            'Las notificaciones de escritorio requieren permiso concedido por cada navegador; no se conceden desde el servidor.',
            'Sólo reciben avisos los destinatarios autorizados. Todos excluye al administrador si el autor no tiene permiso para enviárselos.',
        ],
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,'No se pudo completar la auditoría: '.get_class($e).'. Revisar conexión y tablas de roles/permisos.'.PHP_EOL);
    exit(1);
}
