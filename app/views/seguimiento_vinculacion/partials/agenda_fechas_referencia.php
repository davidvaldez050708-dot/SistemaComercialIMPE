<?php
// Capa informativa local: sin consultas de BD, permisos nuevos o recordatorios.
$agendaFechasArchivo = __DIR__ . '/../../../data/agenda_fechas_2026_2027.json';
$agendaFechasReferencia = is_readable($agendaFechasArchivo)
    ? json_decode((string)file_get_contents($agendaFechasArchivo),true) : null;
if (!is_array($agendaFechasReferencia)) return;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/agenda_fechas_referencia.css?v=<?= filemtime(ROOT_PATH . '/public/css/agenda_fechas_referencia.css') ?>">
<div class="agenda-fechas-legend" data-agenda-fechas-legend role="group" aria-label="Simbología de fechas del calendario">
    <div class="agenda-fechas-categorias">
        <label class="agenda-fecha-oficial"><input type="checkbox" data-agenda-fecha-categoria="oficial" checked><span aria-hidden="true">●</span> Oficiales</label>
        <label class="agenda-fecha-tradicional"><input type="checkbox" data-agenda-fecha-categoria="tradicional" checked><span aria-hidden="true">◆</span> Tradicionales</label>
        <label class="agenda-fecha-santa"><input type="checkbox" data-agenda-fecha-categoria="santa" checked><span aria-hidden="true">✦</span> Semana Santa</label>
        <label class="agenda-fecha-fsl"><input type="checkbox" data-agenda-fecha-categoria="fsl" checked><span aria-hidden="true">▰</span> Fines de semana largos</label>
    </div>
    <small>Fechas informativas de 2026 y 2027 · Fuente: <a href="https://calendario.mx/" target="_blank" rel="noopener noreferrer">calendario.mx</a></small>
</div>
<script type="application/json" data-agenda-fechas-datos><?= json_encode($agendaFechasReferencia, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>public/javascript/agenda_fechas_referencia.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/agenda_fechas_referencia.js') ?>" defer></script>
