<nav class="central-tabs" aria-label="Secciones de Prospectos">
<?php foreach (['todos'=>['bi-people','Todos los prospectos'],'aliados'=>['bi-buildings','Aliados'],'meta'=>['bi-megaphone','Campañas'],'asignaciones'=>['bi-person-check','Asignaciones']] as $clave=>[$icono,$nombre]): ?>
<a href="<?= $esc(BASE_URL.'index.php?'.http_build_query(['controller'=>'prospecto','action'=>'index','seccion'=>$clave])) ?>" class="<?= $seccionProspectos===$clave?'is-active':'' ?>" <?= $seccionProspectos===$clave?'aria-current="page"':'' ?>><i class="bi <?= $icono ?>" aria-hidden="true"></i><?= $esc($nombre) ?></a>
<?php endforeach; ?>
</nav>
