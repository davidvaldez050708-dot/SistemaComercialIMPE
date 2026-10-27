<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$url=static fn($action,$params=[])=>BASE_URL.'index.php?'.http_build_query(['controller'=>'reporte','action'=>$action]+$params);
$duration=static fn($n)=>sprintf('%02d:%02d:%02d',intdiv((int)$n,3600),intdiv((int)$n%3600,60),(int)$n%60);
require_once __DIR__.'/../../helpers/AvatarHelper.php';
?>
<?php foreach(['seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css','seguimiento_entrantes_analista.css','telefonia_ventas_ui.css','supervision_llamadas.css'] as $css): ?>
<link rel="stylesheet" href="<?= $esc(BASE_URL.'public/css/'.$css.'?v='.filemtime(ROOT_PATH.'/public/css/'.$css)) ?>">
<?php endforeach; ?>
<section class="dashboard-panel linkage-incoming-page sales-calls-page supervision-calls" data-supervision-calls
 data-detail-url="<?= $esc($url('supervisionDetalle')) ?>" data-audio-url="<?= $esc($url('supervisionAudio')) ?>">
 <header class="linkage-incoming-heading">
  <div class="linkage-incoming-heading-copy"><span class="linkage-incoming-eyebrow"><i class="bi bi-headset" aria-hidden="true"></i> HISTORIAL DEL EQUIPO</span>
   <h2>Supervisión de llamadas</h2><p>Consulta los resultados y las grabaciones disponibles de <?= $scope==='global'?'todos los analistas y asesores':'tu equipo asignado' ?>.</p>
  </div>
 </header>
 <form method="get" data-supervision-filters class="data-territorial-toolbar linkage-tools-form supervision-filters" aria-label="Filtrar historial">
  <input type="hidden" name="controller" value="reporte"><input type="hidden" name="action" value="supervisionLlamadas">
  <div class="data-filter-field"><label for="supervisionArea">Área</label><select data-native-select class="form-select" id="supervisionArea" name="area"><?php foreach(['asesores'=>'Asesores de ventas','analistas'=>'Analistas de datos'] as $key=>$label): ?><option value="<?= $key ?>" <?= $f['area']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
  <div class="data-filter-field"><label for="supervisionPerson">Persona</label><select data-native-select class="form-select" id="supervisionPerson" name="persona_id"><option value="0">Todo el equipo autorizado</option><?php foreach($data['personas'] as $person): ?><option value="<?= (int)$person['id'] ?>" <?= $f['persona_id']===(int)$person['id']?'selected':'' ?>><?= $esc($person['nombre'].(!$person['activo']?' · Inactivo':'')) ?></option><?php endforeach; ?></select></div>
  <div class="data-filter-field"><label for="supervisionFrom">Desde</label><input class="form-control" id="supervisionFrom" type="date" name="desde" value="<?= $esc($f['desde']) ?>" required></div>
  <div class="data-filter-field"><label for="supervisionUntil">Hasta</label><input class="form-control" id="supervisionUntil" type="date" name="hasta" value="<?= $esc($f['hasta']) ?>" required></div>
  <div class="data-filter-field"><label for="supervisionDirection">Tipo de llamada</label><select data-native-select class="form-select" id="supervisionDirection" name="direccion"><?php foreach([''=>'Entrantes y salientes','Entrante'=>'Entrantes','Saliente'=>'Salientes'] as $key=>$label): ?><option value="<?= $key ?>" <?= $f['direccion']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
  <div class="data-filter-field"><label for="supervisionResult">Resultado</label><select data-native-select class="form-select" id="supervisionResult" name="resultado"><option value="">Todos los resultados</option><?php foreach($data['resultados'] as $key=>$label): ?><option value="<?= $esc($key) ?>" <?= $f['resultado']===$key?'selected':'' ?>><?= $esc($label) ?></option><?php endforeach; ?></select></div>
  <div class="data-filter-field supervision-search-field"><label for="supervisionSearch">Buscar llamada</label><div class="module-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="supervisionSearch" name="buscar" type="search" maxlength="100" placeholder="Persona, nombre o teléfono…" value="<?= $esc($f['buscar']) ?>"></div></div>
  <noscript><button class="btn btn-system-save" type="submit">Aplicar filtros</button></noscript>
 </form>
 <div class="linkage-call-section-heading linkage-incoming-list-head"><div><strong>Historial de llamadas</strong><p>La duración corresponde a la persona indicada. Las duraciones observadas se identifican por separado.</p></div><span class="linkage-call-recordings-count"><?= $data['total'] ?></span></div>
 <p class="supervision-notice" role="status" data-supervision-notice hidden></p>
 <div class="linkage-call-history-list linkage-incoming-history" role="list">
 <?php foreach($data['llamadas'] as $c): ?>
  <article class="linkage-call-history-card linkage-incoming-card" role="listitem" data-call-key="<?= $esc($c['key']) ?>">
   <div class="linkage-call-recording-card-icon"><i class="bi <?= $c['direccion']==='Entrante'?'bi-telephone-inbound':'bi-telephone-outbound' ?>" aria-hidden="true"></i></div>
   <div class="linkage-call-recording-body">
    <div class="linkage-call-recording-top"><div class="linkage-call-history-identity"><div class="supervision-person"><?= renderAvatarUsuario($c['persona'],'',$f['area']==='asesores'?'Asesor de Ventas':'Analista de Datos',$c['foto_perfil']??'','sm','general','',false) ?><strong><?= $esc($c['persona']) ?></strong></div>
     <span><?= $esc($c['nombre']?:'Llamada '.mb_strtolower($c['direccion'])) ?></span><span><?= $esc($c['numero']) ?><?= $c['extension']!==''?' · Ext. '.$esc($c['extension']):'' ?></span></div>
     <time class="linkage-call-history-date"><?= $esc(date('d/m/Y · H:i',strtotime($c['fecha']))) ?></time></div>
    <?php /* IMPE_SUPERVISION_TARJETAS_PBX_ESTADO_V1 */
    $estadoPbx = trim((string)($c['estado_pbx'] ?? ''));
    if ($estadoPbx === '') $estadoPbx = !empty($c['conectada']) ? 'Conectada' : 'Sin conexión confirmada';
    $resultadoGuardado = !empty($c['resultado_guardado']);
    $textoResultado = $resultadoGuardado
        ? (string)($c['resultado_texto'] ?? 'Resultado registrado')
        : (string)($c['resultado_estado'] ?? 'Sin resultado registrado');
    ?>
    <div class="linkage-call-result-group"><span class="linkage-call-result-badge <?= !empty($c['conectada'])?'is-contact':'' ?>"><?= $esc($c['direccion']) ?> · <?= $esc($estadoPbx) ?></span><span class="linkage-call-result-badge is-result"><?= $esc($textoResultado) ?></span></div>
    <div class="linkage-call-history-footer"><div class="supervision-actions linkage-call-result-group">
     <?php if($resultadoGuardado): ?><button type="button" class="linkage-incoming-open" data-supervision-detail><i class="bi bi-card-checklist" aria-hidden="true"></i> Consultar resultado</button><?php endif; ?>
     <?php if($puedeAudio && !empty($c['audio_consultable'])): ?><button type="button" class="linkage-call-audio-state is-available linkage-call-recording-toggle" data-supervision-audio aria-expanded="false"><i class="bi bi-record-circle" aria-hidden="true"></i> <span>Consultar grabaciones</span><i class="bi bi-chevron-down" aria-hidden="true"></i></button><?php elseif($puedeAudio): ?><span class="linkage-call-audio-state">Sin grabación confirmada</span><?php endif; ?>
    </div><span class="linkage-call-recording-duration"><i class="bi bi-clock" aria-hidden="true"></i> <?= $c['segundos']===null?'Duración pendiente':$esc($duration($c['segundos']).($c['observada']?' · Observada':'')) ?></span></div>
    <div class="linkage-incoming-audio-panel" data-supervision-player hidden></div>
   </div>
  </article>
 <?php endforeach; ?>
 <?php if(!$data['llamadas']): ?><div class="supervision-empty"><i class="bi bi-telephone" aria-hidden="true"></i><h3>No hay llamadas para estos filtros</h3><p>Selecciona otra persona o amplía el periodo.</p></div><?php endif; ?>
 </div>
 <?php if($data['paginas']>1): ?><nav class="supervision-pagination" aria-label="Páginas del historial"><?php if($data['pagina']>1): ?><a class="btn btn-system-light" href="<?= $esc($url('supervisionLlamadas',array_merge($f,['pagina'=>$data['pagina']-1]))) ?>">Anterior</a><?php endif; ?><span>Página <?= $data['pagina'] ?> de <?= $data['paginas'] ?></span><?php if($data['pagina']<$data['paginas']): ?><a class="btn btn-system-light" href="<?= $esc($url('supervisionLlamadas',array_merge($f,['pagina'=>$data['pagina']+1]))) ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
 <dialog class="supervision-detail" aria-labelledby="supervisionDetailTitle"><header><h3 id="supervisionDetailTitle">Resultado de la llamada</h3><button type="button" class="btn btn-system-light" data-supervision-close aria-label="Cerrar detalle"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header><dl data-supervision-fields></dl><p>Consulta de supervisión. El resultado lo registra la persona que atendió la llamada.</p></dialog>
</section>
<script src="<?= $esc(BASE_URL.'public/javascript/telefonia_segmentos_player.js?v='.filemtime(ROOT_PATH.'/public/javascript/telefonia_segmentos_player.js')) ?>"></script>
<script src="<?= $esc(BASE_URL.'public/javascript/supervision_llamadas.js?v='.filemtime(ROOT_PATH.'/public/javascript/supervision_llamadas.js')) ?>"></script>
