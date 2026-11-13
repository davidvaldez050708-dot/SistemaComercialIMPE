<?php
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
$esc=static fn($valor)=>htmlspecialchars((string)$valor,ENT_QUOTES,'UTF-8');
$url=BASE_URL.'index.php?controller=embudoComercial';
?>
<link rel="stylesheet" href="<?= $esc(BASE_URL.'public/css/embudo_comercial.css') ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL.'public/css/seguimiento_grabaciones.css') ?>">
<div class="impe-embudo">
    <a class="impe-embudo-back" href="<?= $esc($url.'&action=index') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al embudo</a>
    <header class="impe-embudo-hero">
        <div><h2>Expedientes cerrados</h2><p>Consulta el historial comercial y las llamadas de prospectos con una inscripción vinculada.</p></div>
        <span class="impe-embudo-hero-icon"><i class="bi bi-folder2-open" aria-hidden="true"></i></span>
    </header>
    <div class="impe-embudo-detalles">
        <section class="impe-embudo-detalles-wide">
            <h3>Inscripciones vinculadas</h3>
            <?php if($error!==''): ?>
                <p role="alert" class="impe-embudo-alert impe-embudo-alert--error"><?= $esc($error) ?></p>
            <?php elseif(!$expedientes): ?>
                <p class="linkage-call-recordings-empty">No hay expedientes cerrados en esta página.</p>
            <?php endif; ?>
            <div class="linkage-call-recordings-list">
            <?php foreach(array_slice($expedientes,0,25) as $fila): ?>
                <article class="linkage-call-recording-card">
                    <span class="linkage-call-recording-card-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                    <div class="linkage-call-recording-body">
                        <div class="linkage-call-recording-top">
                            <div><strong><?= $esc($fila['nombre_completo']) ?></strong>
                                <span>Solicitud #<?= (int)$fila['formulario_inscripcion_id'] ?></span>
                            </div>
                            <time class="linkage-call-recording-duration"><?= $esc(EmbudoComercialPresentacion::fechaUtc($fila['finalizado_at'])) ?></time>
                        </div>
                        <a class="impe-embudo-back" href="<?= $esc($url.'&action=detalle&id='.(int)$fila['id']) ?>">Abrir expediente <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <?php if($pagina>1 || count($expedientes)>25): ?>
            <nav class="impe-embudo-tabs mt-3" aria-label="Páginas de expedientes">
                <?php if($pagina>1): ?><a href="<?= $esc($url.'&action=expedientes&pagina='.($pagina-1)) ?>">Anterior</a><?php endif; ?>
                <?php if(count($expedientes)>25 && $pagina<10000): ?><a href="<?= $esc($url.'&action=expedientes&pagina='.($pagina+1)) ?>">Siguiente</a><?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>
    </div>
</div>
