<?php
require_once __DIR__ . '/../../helpers/AvatarHelper.php';
$tableroDireccion = is_array($tableroDireccion ?? null) ? $tableroDireccion : [];
$permisos = $tableroDireccion['permisos'] ?? [];
$resumen = $tableroDireccion['resumen'] ?? [];
$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static fn($controller, $action = 'index', $params = []) => BASE_URL . 'index.php?' . http_build_query(['controller'=>$controller,'action'=>$action] + $params);
$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
$saludo = (int)$now->format('G') < 12 ? 'Buenos días' : ((int)$now->format('G') < 19 ? 'Buenas tardes' : 'Buenas noches');
$meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
$fecha = $dias[(int)$now->format('N')] . ', ' . $now->format('j') . ' de ' . $meses[(int)$now->format('n')] . ' de ' . $now->format('Y');
$fechaCorta = static function($value) use ($now): string {
    if (!$value) return 'Sin fecha';
    try { $d = new DateTimeImmutable((string)$value); return ($d->format('Y-m-d')===$now->format('Y-m-d')?'Hoy':$d->format('d/m')) . ' · ' . $d->format('H:i'); }
    catch (Throwable $error) { return 'Fecha por confirmar'; }
};
$atenciones = $tableroDireccion['atenciones'] ?? null;
$totalAtencion = $resumen['atenciones'] ?? null;
$hayAtencion = $totalAtencion !== null && $totalAtencion > 0;
$metricas = [];
if (isset($resumen['equipo'])) {
    $metricas[] = [$resumen['equipo'][4], 'Analistas activos', 'Equipo nacional de vinculación', 'bi-people', $url('usuario')];
    $metricas[] = [$resumen['equipo'][3], 'Asesores activos', 'Equipo nacional de llamadas', 'bi-headset', $url('usuario')];
}
foreach ([
    'territorios'=>['Estados disponibles','Consulta nacional de territorios y equipos','bi-map',$url('territorio')],
    'seguimientos'=>['Seguimientos en proceso','Excluye descartados y convenios formalizados','bi-kanban',$url('seguimientoVinculacion')],
    'convenios'=>['Convenios formalizados','Relaciones institucionales activas','bi-file-earmark-check',$url('crearConvocatoria')],
    'convocatorias'=>['Convocatorias vigentes','Publicaciones activas dentro de sus fechas','bi-megaphone',$url('convocatoria')]
] as $key=>$meta) if (isset($resumen[$key])) $metricas[] = array_merge([$resumen[$key]],$meta);
$accesos = [
    'reportes'=>['Reportes','Genera y exporta información de las áreas autorizadas.','bi-file-earmark-bar-graph',$url('reporte')],
    'desempeno'=>['Desempeño del equipo','Consulta los indicadores y rankings por área.','bi-trophy',$url('desempeno')],
    'llamadas'=>['Supervisión de llamadas','Consulta historiales, resultados y grabaciones.','bi-telephone',$url('reporte','supervisionLlamadas')],
    'aliados'=>['Aliados','Supervisa la red institucional nacional.','bi-buildings',$url('aliado')],
    'prospectos'=>['Prospectos','Consulta registros por aliado y programa.','bi-person-lines-fill',$url('prospecto')]
];
?>
<?php foreach (['dashboard_cuenta_clave.css','dashboard_director_operaciones.css'] as $css): ?>
<link rel="stylesheet" href="<?= $esc(BASE_URL.'public/css/'.$css.'?v='.filemtime(ROOT_PATH.'/public/css/'.$css)) ?>">
<?php endforeach; ?>
<section class="kam-dashboard director-dashboard" data-director-dashboard>
    <section class="kam-welcome">
        <div class="kam-welcome-copy">
            <span class="kam-eyebrow">DIRECCIÓN DE OPERACIONES · ALCANCE NACIONAL</span>
            <h2><?= $esc($saludo . ', ' . (trim((string)($_SESSION['nombre']??'')) ?: 'Dirección')) ?></h2>
            <p><?= $esc($fecha) ?></p>
            <p class="kam-welcome-tagline">Una vista de las prioridades, el equipo y la actividad de toda la operación.</p>
        </div>
        <div class="kam-welcome-status-zone"><div class="kam-welcome-status <?= $hayAtencion?'is-attention':($totalAtencion===0?'is-clear':'') ?>">
            <span class="kam-welcome-status-icon" aria-hidden="true"><i class="bi <?= $hayAtencion?'bi-exclamation-circle':($totalAtencion===0?'bi-check-circle':'bi-globe-americas') ?>"></i></span>
            <div><strong><?= $hayAtencion?$esc($totalAtencion.' casos requieren supervisión'):($totalAtencion===0?'Vinculación sin casos prioritarios':'Supervisión nacional') ?></strong>
            <span><?= $hayAtencion?'Revisa las prioridades de vinculación de todos los territorios.':'La información y los accesos dependen de tus permisos habilitados.' ?></span></div>
        </div></div>
    </section>
    <?php foreach ($tableroDireccion['avisos']??[] as $aviso): ?><p class="do-notice" role="status"><?= $esc($aviso) ?></p><?php endforeach; ?>
    <?php if ($metricas): ?>
    <section class="kam-kpi-section" aria-label="Resumen nacional">
        <div class="kam-kpi-section-heading"><span class="kam-eyebrow">RESUMEN OPERATIVO</span><div><h2>La operación en una mirada</h2><p>Datos actuales de las áreas habilitadas para tu rol.</p></div></div>
        <div class="kam-kpi-grid do-kpi-grid"><?php foreach ($metricas as [$valor,$label,$ayuda,$icono,$destino]): ?>
            <a class="kam-metric-card do-metric-link" href="<?= $esc($destino) ?>"><span class="kam-metric-icon" aria-hidden="true"><i class="bi <?= $esc($icono) ?>"></i></span><div><strong><?= (int)$valor ?></strong><span><?= $esc($label) ?></span><small><?= $esc($ayuda) ?></small></div></a>
        <?php endforeach; ?></div>
    </section>
    <?php endif; ?>
    <?php if (array_filter($accesos, static fn($v,$k)=>!empty($permisos[$k]), ARRAY_FILTER_USE_BOTH)): ?>
    <nav class="do-access-grid" aria-label="Accesos de supervisión"><?php foreach ($accesos as $key=>[$title,$help,$icon,$link]): if (empty($permisos[$key])) continue; ?>
        <a class="do-access" href="<?= $esc($link) ?>"><span class="kam-metric-icon"><i class="bi <?= $esc($icon) ?>" aria-hidden="true"></i></span><div><strong><?= $esc($title) ?></strong><span><?= $esc($help) ?></span></div><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    <?php endforeach; ?></nav>
    <?php endif; ?>
    <?php if (!empty($permisos['seguimientos']) || !empty($permisos['agenda'])): ?>
    <div class="kam-primary-grid <?= empty($permisos['seguimientos'])||empty($permisos['agenda'])?'is-single':'' ?>">
        <?php if (!empty($permisos['seguimientos'])): ?>
        <section class="dashboard-panel kam-attention-panel">
            <div class="kam-panel-heading"><div><span class="kam-section-kicker">VINCULACIÓN NACIONAL</span><h2 class="panel-title mb-1">Casos que requieren supervisión</h2><p class="page-subtitle mb-0">Revisa los seguimientos que necesitan atención.</p></div><?php if ($totalAtencion!==null): ?><span class="kam-panel-count <?= $hayAtencion?'is-attention':'' ?>"><?= (int)$totalAtencion ?> casos</span><?php endif; ?></div>
            <?php if ($atenciones): ?><div class="kam-attention-list"><?php foreach ($atenciones as $item): $tipo=in_array($item['tipo_atencion'],['atrasado','hoy','espera','reunion'],true)?$item['tipo_atencion']:'seguimiento'; ?>
                <a class="kam-attention-item" href="<?= $esc($url('seguimientoVinculacion','estado',['estado_id'=>(int)$item['estado_id'],'abrir_seguimiento'=>(int)$item['id']])) ?>">
                    <span class="kam-attention-indicator is-<?= $esc($tipo) ?>"></span><div class="kam-attention-main"><div class="kam-attention-title"><strong><?= $esc($item['nombre_entidad']) ?></strong><span class="kam-priority-pill is-<?= $esc($tipo) ?>"><?= $esc($item['motivo_atencion']) ?></span></div><div class="kam-attention-meta"><span><i class="bi bi-person" aria-hidden="true"></i> <?= $esc($item['analista_nombre']) ?></span><span><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= $esc($item['estado_nombre']) ?></span><?php if ($item['fecha_referencia']): ?><span><i class="bi bi-clock" aria-hidden="true"></i> <?= $esc($fechaCorta($item['fecha_referencia'])) ?></span><?php endif; ?></div></div><i class="bi bi-chevron-right kam-row-arrow" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?></div><?php else: ?><div class="do-empty"><i class="bi <?= $atenciones===null?'bi-info-circle':'bi-check-circle' ?>" aria-hidden="true"></i><strong><?= $atenciones===null?'Información de prioridades no disponible':'No hay casos prioritarios en vinculación' ?></strong><span><?= $atenciones===null?'Puedes consultar el módulo de Vinculación.':'Puedes consultar la cartera nacional completa.' ?></span></div><?php endif; ?>
            <div class="kam-panel-footer-action"><a href="<?= $esc($url('seguimientoVinculacion')) ?>">Ver vinculación <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
        </section>
        <?php endif; ?>
        <?php if (!empty($permisos['agenda'])): ?>
        <section class="dashboard-panel kam-agenda-panel">
            <div class="kam-panel-heading"><div><span class="kam-section-kicker">PRÓXIMOS 7 DÍAS</span><h2 class="panel-title mb-1">Agenda nacional</h2><p class="page-subtitle mb-0">Próximas reuniones del equipo, por fecha.</p></div><a class="kam-panel-link" href="<?= $esc($url('agendaReunion')) ?>">Ver agenda <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
            <?php if (!empty($tableroDireccion['agenda'])): ?><div class="do-agenda-list"><?php foreach ($tableroDireccion['agenda'] as $item): ?>
                <a class="do-agenda-item" href="<?= $esc($url('agendaReunion','index',['mes'=>substr($item['fecha_propuesta'],0,7),'reunion_id'=>(int)$item['id']])) ?>"><span class="do-agenda-date"><?= $esc($fechaCorta($item['fecha_propuesta'])) ?></span><strong><?= $esc($item['nombre_entidad']) ?></strong><span><?= $esc($item['analista_nombre'].' · '.$item['estado_nombre']) ?></span><small><?= $esc(['SOLICITADA'=>'Pendiente de confirmación','CONFIRMADA'=>'Confirmada','CORREO_ENVIADO'=>'Confirmación enviada'][$item['estado']]??'Programada') ?></small></a>
            <?php endforeach; ?></div><?php else: ?><div class="do-empty"><i class="bi bi-calendar-check" aria-hidden="true"></i><strong><?= ($tableroDireccion['agenda']??null)===null?'Agenda no disponible':'No hay próximas reuniones' ?></strong><span>Consulta el calendario para revisar otros periodos.</span></div><?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($permisos['ventas'])): $ventas=$tableroDireccion['ventas']??null; ?>
    <section class="dashboard-panel kam-team-panel">
        <div class="kam-panel-heading"><div><span class="kam-section-kicker">ASESORES · HOY</span><h2 class="panel-title mb-1">Actividad telefónica del equipo</h2><p class="page-subtitle mb-0">Llamadas y resultados de los asesores registrados hoy.</p></div><a class="kam-panel-link" href="<?= $esc($url('reporte','ventas',['periodo'=>'hoy','generar'=>1])) ?>">Ver reporte <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
        <?php if ($ventas!==null): $phone=$ventas['resumen']; ?>
        <div class="do-phone-grid"><?php foreach ([['llamadas','Llamadas registradas'],['conectadas','Conexiones confirmadas'],['conversaciones','Conversaciones registradas'],['por_registrar','Resultados pendientes']] as [$key,$label]): ?><div><strong><?= (int)$phone[$key] ?></strong><span><?= $esc($label) ?></span></div><?php endforeach; ?></div>
        <p class="do-section-help">El tiempo confirmado corresponde sólo a la conversación del asesor: <?= $esc(VentasActividadMetricas::tiempoConfirmado($phone)) ?>. Los intentos sin confirmar se muestran por separado en el reporte.</p>
        <?php else: ?><div class="do-empty"><i class="bi bi-info-circle" aria-hidden="true"></i><strong>Actividad telefónica no disponible</strong><span>Consulta el reporte o vuelve a cargar el Inicio.</span></div><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if (!empty($permisos['territorios'])): $states=$tableroDireccion['cobertura']??null; ?>
    <section class="dashboard-panel kam-coverage-panel">
        <div class="kam-panel-heading"><div><span class="kam-section-kicker">COBERTURA NACIONAL</span><h2 class="panel-title mb-1">Territorios y equipo asignado</h2><p class="page-subtitle mb-0">Incluye todos los estados activos, aunque todavía no tengan asignaciones.</p></div><a class="kam-panel-link" href="<?= $esc($url('territorio')) ?>">Ver territorios <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
        <?php if ($states): ?>
        <?php $renderStates=static function(array $rows) use ($esc) { ?><div class="do-coverage-grid"><?php foreach ($rows as $state): ?><article class="do-state"><span class="kam-coverage-icon"><i class="bi bi-map" aria-hidden="true"></i></span><div><strong><?= $esc($state['nombre']) ?></strong><span><?= (int)$state['cuentas'] ?> Cuenta Clave · <?= (int)$state['analistas'] ?> analistas · <?= (int)$state['asesores'] ?> asesores</span><?php if ($state['seguimientos']!==null): ?><small><?= (int)$state['seguimientos'] ?> seguimientos en proceso</small><?php endif; ?></div></article><?php endforeach; ?></div><?php }; $renderStates(array_slice($states,0,8)); ?>
        <?php if (count($states)>8): ?><details class="do-more-states"><summary>Ver los <?= count($states) ?> estados</summary><?php $renderStates(array_slice($states,8)); ?></details><?php endif; ?>
        <?php else: ?><div class="do-empty"><i class="bi bi-map" aria-hidden="true"></i><strong><?= $states===null?'Cobertura no disponible':'No hay estados activos' ?></strong></div><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if (!array_filter($permisos) && empty($tableroDireccion['avisos'])): ?><section class="dashboard-panel do-empty"><i class="bi bi-lock" aria-hidden="true"></i><strong>Aún no tienes módulos habilitados</strong><span>Los indicadores y accesos aparecerán cuando el administrador habilite los permisos de consulta.</span></section><?php endif; ?>
    <p class="do-updated">Consultado: <?= $esc($tableroDireccion['generado_at']??$now->format('Y-m-d H:i:s')) ?> · Actualiza el Inicio para consultar cambios recientes.</p>
</section>
