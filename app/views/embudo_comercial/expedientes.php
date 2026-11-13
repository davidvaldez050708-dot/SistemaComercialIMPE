<?php
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
$esc=static fn($valor)=>htmlspecialchars((string)$valor,ENT_QUOTES,'UTF-8');
$url=BASE_URL.'index.php?controller=embudoComercial';
?>
<link rel="stylesheet" href="<?= $esc(BASE_URL.'public/css/embudo_comercial.css') ?>">
<div class="impe-embudo">
    <a href="<?= $esc($url.'&action=index') ?>">Volver al embudo</a>
    <section class="impe-embudo-historial">
        <h2>Expedientes cerrados</h2>
        <?php if($error!==''): ?>
            <p role="alert"><?= $esc($error) ?></p>
        <?php elseif(!$expedientes): ?>
            <p>No hay expedientes cerrados en esta página.</p>
        <?php endif; ?>
        <ul>
            <?php foreach(array_slice($expedientes,0,25) as $fila): ?>
            <li><a href="<?= $esc($url.'&action=detalle&id='.(int)$fila['id']) ?>"><?= $esc($fila['nombre_completo']) ?></a>
                · Solicitud #<?= (int)$fila['formulario_inscripcion_id'] ?>
                · <?= $esc(EmbudoComercialPresentacion::fechaUtc($fila['finalizado_at'])) ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if($pagina>1): ?><a href="<?= $esc($url.'&action=expedientes&pagina='.($pagina-1)) ?>">Anterior</a><?php endif; ?>
        <?php if(count($expedientes)>25 && $pagina<10000): ?><a href="<?= $esc($url.'&action=expedientes&pagina='.($pagina+1)) ?>">Siguiente</a><?php endif; ?>
    </section>
</div>
