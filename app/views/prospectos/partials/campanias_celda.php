<?php
// Se consulta por lote en el repositorio; los nombres originales se escapan aquí.
$campaniasCelda=$r['campanias']??[];
if (!$campaniasCelda): ?>
<span class="central-campaign-none"><?= $seccionProspectos==='meta'?'—':'Campaña: —' ?></span>
<?php else:
    $campaniaTexto=static fn($c)=>$c['nombre']!==null&&$c['nombre']!==''?$c['nombre']:'—';
    if ($seccionProspectos==='meta'): ?>
<span class="central-campaign-name"><?= $esc($campaniaTexto($campaniasCelda[0])) ?></span>
<?php endif;
    if (count($campaniasCelda)>1||$seccionProspectos!=='meta'): ?>
<details class="central-campaign-details"><summary><?= $seccionProspectos==='meta'?'Ver todas ('.count($campaniasCelda).')':'Campañas ('.count($campaniasCelda).')' ?></summary>
<ul><?php foreach ($campaniasCelda as $c): ?><li><?= $esc($campaniaTexto($c)) ?><small><?= $c['id']!==null&&$c['id']!==''?'ID '.$esc($c['id']):'Sin ID informado' ?></small></li><?php endforeach; ?></ul></details>
<?php elseif (!empty($campaniasCelda[0]['id'])): ?><small class="central-campaign-id">ID <?= $esc($campaniasCelda[0]['id']) ?></small><?php endif; endif; ?>
