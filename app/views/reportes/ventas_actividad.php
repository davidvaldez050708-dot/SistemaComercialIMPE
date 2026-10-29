<?php
require_once __DIR__.'/../../helpers/AvatarHelper.php';
$d=$actividad;$s=$d['resumen']??VentasActividadMetricas::vacio();
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$time=[VentasActividadMetricas::class,'tiempo'];
$scope=$d['vista']??VentasActividadAccess::alcance(false);
$title='Reporte de actividad telefónica';
$generated=!empty($d['generado']) && $errorActividad==='';
$inputValue=static fn($key,$default='')=>is_string($_GET[$key]??$default)?($_GET[$key]??$default):$default;
$period=$d['periodo']??['clave'=>$inputValue('periodo','semana'),'fecha_desde'=>$inputValue('fecha_desde'),'fecha_hasta'=>$inputValue('fecha_hasta')];
$filter=['controller'=>'reporte','action'=>'ventas','area'=>'asesores','generar'=>1,
    'periodo'=>$period['clave'],'fecha_desde'=>$period['fecha_desde'],'fecha_hasta'=>$period['fecha_hasta'],
    'persona_id'=>$d['persona_id']??0,'direccion'=>$d['direccion']??'','resultado'=>$d['resultado']??''];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/desempeno.css?v=<?= filemtime(ROOT_PATH.'/public/css/desempeno.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/ventas_actividad.css?v=<?= filemtime(ROOT_PATH.'/public/css/ventas_actividad.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/ventas_reporte_horarios.css?v=<?= filemtime(ROOT_PATH.'/public/css/ventas_reporte_horarios.css') ?>">
<section class="performance-module sales-activity-module sales-report-page">
    <header class="dashboard-panel performance-hero">
        <div><span class="performance-eyebrow">ASESORES · ACTIVIDAD TELEFÓNICA</span><h1><?= $e($title) ?></h1><p>Consulta las llamadas, sus resultados y el tiempo confirmado de atención en el periodo seleccionado.</p></div>
        <?php if ($urlActividadPdf!==''): ?><a class="btn btn-system-save" href="<?= $e($urlActividadPdf) ?>" data-sales-report-pdf data-pdf-filename="Actividad_Telefonica.pdf"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Exportar PDF</a><?php endif; ?>
    </header>
    <?php if ($errorActividad!==''): ?><div class="alert alert-danger" role="alert"><?= $e($errorActividad) ?></div><?php endif; ?>
    <section class="dashboard-panel performance-filter-panel">
        <div class="sales-report-filter-heading"><i class="bi bi-sliders" aria-hidden="true"></i><h2 class="panel-title">Filtros del reporte</h2></div>
        <form class="sales-activity-filters" method="get" autocomplete="off" data-sales-activity-form>
            <input type="hidden" name="controller" value="reporte"><input type="hidden" name="action" value="ventas"><input type="hidden" name="area" value="asesores"><input type="hidden" name="generar" value="1">
            <?php if ($scope!=='propio'): ?><div class="performance-filter-field"><label for="salesAdvisor">Asesor</label><select class="form-select" id="salesAdvisor" name="persona_id"><option value="0">Todos los asesores autorizados</option><?php foreach ($d['personas']??[] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($d['persona_id']??0)===(int)$p['id']?'selected':'' ?>><?= $e($p['nombre_completo']) ?><?= !$p['activo']?' · Inactivo':'' ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="performance-filter-field"><label for="salesPeriod">Periodo</label><select class="form-select" id="salesPeriod" name="periodo" data-sales-period><?php foreach (VentasActividadMetricas::PERIODOS as $key=>$label): ?><option value="<?= $key ?>" <?= $period['clave']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="performance-filter-field"><label for="salesDirection">Tipo de llamada</label><select class="form-select" id="salesDirection" name="direccion"><?php foreach ([''=>'Entrantes y salientes','Saliente'=>'Salientes','Entrante'=>'Entrantes'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($d['direccion']??'')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="performance-filter-field"><label for="salesResult">Resultado</label><select class="form-select" id="salesResult" name="resultado"><?php foreach ([''=>'Todos los resultados','PENDIENTE'=>'Sin resultado registrado']+TelefoniaResultadoVentasCatalogo::opcionesFiltro() as $key=>$label): ?><option value="<?= $key ?>" <?= ($d['resultado']??'')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="performance-filter-field" data-sales-custom <?= $period['clave']!=='personalizado'?'hidden':'' ?>><label for="salesFrom">Desde</label><input class="form-control" id="salesFrom" type="date" name="fecha_desde" value="<?= $e($period['fecha_desde']) ?>" max="<?= date('Y-m-d') ?>"></div>
            <div class="performance-filter-field" data-sales-custom <?= $period['clave']!=='personalizado'?'hidden':'' ?>><label for="salesTo">Hasta</label><input class="form-control" id="salesTo" type="date" name="fecha_hasta" value="<?= $e($period['fecha_hasta']) ?>" max="<?= date('Y-m-d') ?>"></div>
            <noscript><button class="btn btn-system-save" type="submit">Consultar reporte</button></noscript>
        </form>
        <p class="page-subtitle mt-3 mb-0" role="status" aria-live="polite" data-sales-activity-status hidden></p>
    </section>
    <?php if ($generated): ?>
        <div class="performance-context-row"><div><span>ALCANCE</span><strong><?= ['propio'=>'Personal','equipo'=>'Mi equipo territorial','global'=>'Nacional'][$scope] ?></strong></div><div><span>PERIODO</span><strong><?= $e($period['fecha_desde'].' al '.$period['fecha_hasta']) ?></strong></div><div><span>ASESORES</span><strong><?= count($d['personas_resumen']) ?></strong></div><div><span>CONSULTADO</span><strong><?= $e($d['fecha_generacion']) ?></strong></div></div>
        <section class="performance-kpi-grid">
            <?php foreach ([['salientes','Llamadas salientes','telephone-outbound','En el periodo y filtros seleccionados'],['entrantes','Llamadas entrantes','telephone-inbound','En el periodo y filtros seleccionados'],['buzones','Buzones de voz','voicemail','Clasificados como buzón por el asesor'],['conversaciones','Conversaciones registradas','chat-dots','En el periodo y filtros seleccionados']] as [$key,$label,$icon,$caption]): ?>
                <article><span class="performance-kpi-icon"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><div><strong><?= $s[$key] ?></strong><span><?= $label ?></span><small><?= $caption ?></small></div></article>
            <?php endforeach; ?>
        </section>
        <section class="performance-kpi-grid">
            <?php foreach ([[VentasActividadMetricas::tiempoConfirmado($s),'Tiempo confirmado del asesor','clock',$s['duraciones_pendientes'].' duraciones pendientes'],[$time($s['promedio_segundos']),'Promedio con duración confirmada','stopwatch','Excluye las duraciones pendientes'],[$s['por_registrar'],'Llamadas por registrar','journal-check','Ya permiten registrar su resultado'],[$s['cumplimiento_registro']===null?'—':$s['cumplimiento_registro'].'%','Cumplimiento del registro','check2-circle','Resultados guardados de llamadas habilitadas']] as [$value,$label,$icon,$caption]): ?>
                <article><span class="performance-kpi-icon is-success"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><div><strong><?= $e($value) ?></strong><span><?= $label ?></span><small><?= $e($caption) ?></small></div></article>
            <?php endforeach; ?>
        </section>
        <p class="sales-activity-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span><?= $s['intentos'] ?> intentos sin llamada confirmada · <?= $s['transferencias_pendientes'] ?> transferencias pendientes de confirmación. Se muestran aparte y no aumentan las llamadas registradas.</span></p>
        <?php if (VentasActividadMetricas::mostrarHorarioIndividual($d)): ?>
        <section class="dashboard-panel sales-activity-panel" data-sales-call-schedule aria-labelledby="salesScheduleTitle">
            <header class="sales-report-section-heading"><div><span class="sales-report-eyebrow">ACTIVIDAD DIARIA</span><h2 class="panel-title" id="salesScheduleTitle">Horario de llamadas</h2><p class="page-subtitle"><?= $e($d['personas_resumen'][0]['nombre_completo']) ?> · Hora de México. Inicio de las llamadas del reporte, con los mismos filtros de tipo y resultado.</p></div></header>
            <?php if (in_array($period['clave'],['hoy','ayer'],true)): $horario=$d['dias'][0]; ?>
                <div class="performance-kpi-grid sales-call-boundaries">
                    <?php foreach (['primera_llamada'=>['Primera llamada','telephone-outbound'],'ultima_llamada'=>['Última llamada','clock-history']] as $key=>[$label,$icon]): ?>
                    <article><span class="performance-kpi-icon"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span><div><strong><?= $e(VentasActividadMetricas::horaInicio($horario[$key])) ?></strong><span><?= $label ?></span><small><?= $e($horario['fecha']) ?><?= !$horario['llamadas']?' · Sin llamadas':'' ?></small></div></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive sales-activity-table sales-report-inset-table" role="region" aria-label="Primera y última llamada por día" tabindex="0"><table class="table users-table table-hover align-middle mb-0 sales-report-numeric-table sales-report-schedule-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Llamadas</th><th scope="col">Primera llamada</th><th scope="col">Última llamada</th></tr></thead><tbody>
                <?php foreach ($d['dias'] as $day): ?><tr><td><?= $e($day['fecha']) ?></td><td><?= $day['llamadas'] ?></td><td><?= $e(VentasActividadMetricas::horaInicio($day['primera_llamada'])) ?></td><td><?= $e(VentasActividadMetricas::horaInicio($day['ultima_llamada'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
            <p class="page-subtitle mt-3 mb-0">Se excluyen intentos y transferencias sin confirmar. Los días sin llamadas se muestran con —.</p>
        </section>
        <?php endif; ?>
        <section class="dashboard-panel sales-activity-panel sales-report-table-panel">
            <header class="table-panel-header sales-report-section-heading"><div><span class="sales-report-eyebrow">RESUMEN INDIVIDUAL</span><h2 class="panel-title" id="salesPeopleTitle"><?= $scope==='propio'?'Mi actividad':'Actividad por asesor' ?></h2><p class="page-subtitle">Resumen individual con los mismos filtros del reporte.</p></div><span class="sales-report-count"><?= count($d['personas_resumen']) ?> <?= count($d['personas_resumen'])===1?'asesor':'asesores' ?></span></header>
            <div class="table-responsive sales-activity-table" role="region" aria-label="Actividad por asesor" tabindex="0"><table class="table users-table table-hover align-middle mb-0 sales-report-numeric-table sales-report-people-table<?= !$d['personas_resumen']?' is-empty':'' ?>"><thead><tr><th scope="col">Asesor</th><th scope="col">Salientes</th><th scope="col">Entrantes</th><th scope="col">Conversaciones</th><th scope="col">Tasa de contacto</th><th scope="col">Tiempo confirmado</th><th scope="col">Por registrar</th></tr></thead><tbody>
                <?php foreach ($d['personas_resumen'] as $p): ?><tr><td><div class="sales-activity-person"><?= renderAvatarUsuario($p['nombre_completo'],'','Asesor',$p['foto_perfil']??'','sm','general','',false) ?><strong><?= $e($p['nombre_completo']) ?></strong></div></td><td><?= $p['salientes'] ?></td><td><?= $p['entrantes'] ?></td><td><?= $p['conversaciones'] ?></td><td><?= $p['tasa_contacto']===null?'—':$p['tasa_contacto'].'%' ?></td><td><?= $e(VentasActividadMetricas::tiempoConfirmado($p)) ?></td><td><?= $p['por_registrar'] ?></td></tr><?php endforeach; ?>
                <?php if (!$d['personas_resumen']): ?><tr><td colspan="7"><div class="empty-table-message">No hay asesores dentro de este alcance.</div></td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="dashboard-panel sales-activity-panel"><header class="sales-report-section-heading"><div><span class="sales-report-eyebrow">TENDENCIA DEL PERIODO</span><h2 class="panel-title" id="salesEvolutionTitle">Evolución por día</h2><p class="page-subtitle">Los días sin actividad también se incluyen en el periodo.</p></div><span class="sales-report-count"><?= count($d['dias']) ?> <?= count($d['dias'])===1?'día':'días' ?></span></header>
            <?php
            $bloque=max(1,(int)ceil(count($d['dias'])/20));$grafica=[];
            foreach (array_chunk($d['dias'],$bloque) as $grupo) {
                $g=['fecha'=>$grupo[0]['fecha'],'salientes'=>0,'entrantes'=>0,'conversaciones'=>0];
                foreach ($grupo as $dia) foreach (['salientes','entrantes','conversaciones'] as $k) $g[$k]+=$dia[$k];
                $grafica[]=$g;
            }
            $maxDia=max(1,...array_column($grafica,'salientes'),...array_column($grafica,'entrantes'),...array_column($grafica,'conversaciones'));
            ?>
            <?php if ($s['llamadas']>0): ?>
                <div class="performance-chart-scroll sales-report-chart" role="region" tabindex="0" aria-label="Gráfica de llamadas por día; desplázate para consultar todas las fechas">
                    <?php if ($bloque>1): ?><p class="page-subtitle">Gráfica agrupada en bloques de hasta <?= $bloque ?> días; cada etiqueta indica el inicio del bloque. La tabla conserva el detalle diario.</p><?php endif; ?>
                    <div class="performance-bar-chart" style="--performance-days: <?= count($grafica) ?>">
                        <?php foreach ($grafica as $day): ?><div class="performance-day"><div class="performance-bars">
                            <?php foreach (['salientes'=>'is-primary','entrantes'=>'is-secondary','conversaciones'=>'is-tertiary'] as $key=>$class): ?><span class="<?= $class ?>" style="height: <?= number_format(100*$day[$key]/$maxDia,2,'.','') ?>%" title="<?= $e($day['fecha'].' · '.$key.': '.$day[$key]) ?>"></span><?php endforeach; ?>
                        </div><strong><?= $e(substr($day['fecha'],5)) ?></strong></div><?php endforeach; ?>
                    </div>
                </div>
                <div class="performance-chart-legend"><span><i class="is-primary"></i> Salientes</span><span><i class="is-secondary"></i> Entrantes</span><span><i class="is-tertiary"></i> Conversaciones registradas</span></div>
            <?php else: ?><p class="performance-chart-empty">Sin llamadas registradas en este periodo y filtros.</p><?php endif; ?>
            <div class="table-responsive sales-activity-table sales-activity-days sales-report-inset-table" role="region" aria-label="Evolución por día" tabindex="0"><table class="table users-table table-hover align-middle mb-0 sales-report-numeric-table sales-report-days-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Salientes</th><th scope="col">Entrantes</th><th scope="col">Conversaciones</th><th scope="col">Tiempo confirmado</th><th scope="col">Duraciones pendientes</th></tr></thead><tbody><?php foreach ($d['dias'] as $day): ?><tr><td><?= $e($day['fecha']) ?></td><td><?= $day['salientes'] ?></td><td><?= $day['entrantes'] ?></td><td><?= $day['conversaciones'] ?></td><td><?= $day['duraciones_confirmadas']?$time($day['segundos']):'—' ?></td><td><?= $day['duraciones_pendientes'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
        <section class="dashboard-panel sales-activity-panel" aria-labelledby="salesResultsTitle"><header class="sales-report-section-heading"><div><span class="sales-report-eyebrow">DISTRIBUCIÓN DE RESULTADOS</span><h2 class="panel-title" id="salesResultsTitle">Resultados de las llamadas</h2><p class="page-subtitle">Una llamada puede tener varios resultados. Las categorías no se suman como llamadas adicionales.</p></div></header><div class="sales-activity-results"><?php foreach ($d['resultados'] as $code=>$n): ?><div><span><?= $e(TelefoniaResultadoVentasCatalogo::opcionesConsulta()[$code]??'Sin resultado registrado') ?></span><strong><?= $n ?></strong></div><?php endforeach; ?><?php if (!$d['resultados']): ?><p class="sales-report-empty-copy">No hay llamadas registradas con estos filtros.</p><?php endif; ?></div></section>
        <?php $total=count($d['llamadas']);$pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($_GET['pagina']??1))); ?>
        <section class="dashboard-panel sales-activity-panel sales-report-table-panel">
            <header class="table-panel-header sales-report-section-heading"><div><span class="sales-report-eyebrow">REGISTROS DEL PERIODO</span><h2 class="panel-title" id="salesDetailTitle">Detalle de llamadas</h2><p class="page-subtitle">Consulta las llamadas que forman este reporte. El PDF incluye el detalle completo del corte.</p></div><span class="sales-report-count"><?= $total ?> <?= $total===1?'registro':'registros' ?></span></header>
            <div class="table-responsive sales-activity-table" role="region" aria-label="Detalle de llamadas" tabindex="0"><table class="table users-table table-hover align-middle mb-0 sales-report-detail-table<?= !$total?' is-empty':'' ?>"><thead><tr><th scope="col">Fecha y hora</th><th scope="col">Asesor</th><th scope="col">Contacto</th><th scope="col">Tipo</th><th scope="col">Resultado</th><th scope="col">Duración del asesor</th><th scope="col">Observaciones</th></tr></thead><tbody>
                <?php foreach (array_slice($d['llamadas'],($page-1)*25,25) as $c): ?><tr><td class="sales-report-date"><?= $e($c['fecha']) ?></td><td><?= $e($c['asesor']) ?></td><td><div class="sales-report-contact"><strong><?= $e($c['registro']['prospecto_nombre']??$c['nombre']??'') ?></strong><span><?= $e($c['numero']) ?></span></div></td><td><span class="sales-report-type"><i class="bi bi-<?= $c['direccion']==='Entrante'?'telephone-inbound':'telephone-outbound' ?>" aria-hidden="true"></i><?= $e($c['direccion']) ?></span></td><td><div class="sales-report-result-tags"><?php $etiquetas=$c['pbx_call_id']===''?['Sin llamada confirmada']:(TelefoniaResultadoVentasCatalogo::etiquetasRegistro($c['registro']??null)?:['Sin resultado registrado']);foreach($etiquetas as $etiqueta): ?><span class="sales-report-result-tag"><?= $e($etiqueta) ?></span><?php endforeach; ?></div><?php if($aclaracion=TelefoniaResultadoVentasCatalogo::aclaracionesRegistro($c['registro']??null)): ?><small class="sales-report-secondary"><?= $e($aclaracion) ?></small><?php endif; ?></td><td class="sales-report-duration"><?= $e($time($c['segundos'])) ?></td><td class="sales-report-observations"><?= $e(mb_strimwidth($c['registro']['observacion']??'',0,240,'…','UTF-8')) ?></td></tr><?php endforeach; ?>
                <?php if (!$total): ?><tr><td colspan="7"><div class="empty-table-message">No hay llamadas en este periodo y filtros.</div></td></tr><?php endif; ?>
            </tbody></table></div>
            <footer class="sales-report-table-footer"><span><?= $total ?> registros · Página <?= $page ?> de <?= $pages ?></span><?php if ($pages>1): ?><nav class="sales-activity-pagination" aria-label="Páginas de llamadas"><?php foreach (['Anterior'=>max(1,$page-1),'Siguiente'=>min($pages,$page+1)] as $label=>$number): ?><?php if($number===$page): ?><span class="btn btn-system-light" aria-disabled="true"><?= $label ?></span><?php else: ?><a class="btn btn-system-light" href="<?= $e(BASE_URL.'index.php?'.http_build_query($filter+['pagina'=>$number])) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?></footer>
        </section>
        <div class="sales-report-pending"><?php $datosPendientes=$d;require __DIR__.'/../telefonia/ventas_pendientes_resumen.php'; ?></div>
        <section class="dashboard-panel sales-activity-panel" aria-labelledby="salesCriteriaTitle"><header class="sales-report-section-heading"><div><span class="sales-report-eyebrow">CRITERIOS DEL REPORTE</span><h2 class="panel-title" id="salesCriteriaTitle">Cómo se calculan estos datos</h2></div></header><ul class="sales-activity-criteria"><li>Una llamada registrada no significa que se haya hablado con una persona. La conversación la clasifica el asesor al guardar su resultado.</li><li>La tasa de contacto divide las conversaciones con conexión confirmada entre las llamadas registradas del mismo corte.</li><li>El tiempo corresponde únicamente al tramo del asesor. Las duraciones pendientes se excluyen del promedio; el total puede ser parcial.</li><li>El cumplimiento de registro considera únicamente llamadas que ya permiten registrar un resultado. Crear o completar un recordatorio no cuenta como llamada.</li></ul></section>
    <?php endif; ?>
</section>
<script defer src="<?= BASE_URL ?>public/javascript/ventas_actividad.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/ventas_actividad.js') ?>"></script>

<script src="<?= BASE_URL ?>public/javascript/reportes_pdf_descarga.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/reportes_pdf_descarga.js') ?>"></script>
