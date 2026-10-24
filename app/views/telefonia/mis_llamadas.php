<?php
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$items = $misLlamadas['filas'] ?? [];
$filtros = $misLlamadas['filtros'] ?? [];
$pagina = max(1, (int)($misLlamadas['pagina'] ?? 1));
$paginas = max(1, (int)($misLlamadas['paginas'] ?? 1));
$total = (int)($misLlamadas['total'] ?? 0);
$porPagina = max(1, (int)($misLlamadas['por_pagina'] ?? 20));
$primero = $total === 0 ? 0 : (($pagina - 1) * $porPagina + 1);
$ultimo = min($total, ($pagina - 1) * $porPagina + count($items));
$irAPagina = static function (int $destino) use ($filtros): string {
    return BASE_URL . 'index.php?' . http_build_query([
        'controller' => 'telefonia', 'action' => 'misLlamadas',
        'vista' => 'historial', 'desde' => $filtros['desde'] ?? '',
        'hasta' => $filtros['hasta'] ?? '', 'direccion' => $filtros['direccion'] ?? '',
        'pagina' => $destino
    ]) . '#misLlamadasHistorial';
};
$paginasVisibles = [];
foreach ([1, $pagina - 2, $pagina - 1, $pagina, $pagina + 1, $pagina + 2, $paginas] as $n) {
    if ($n >= 1 && $n <= $paginas) $paginasVisibles[$n] = $n;
}
ksort($paginasVisibles, SORT_NUMERIC);
?>
<style><?php foreach (['seguimiento_llamadas_expediente.css','seguimiento_entrantes_analista.css'] as $css) echo file_get_contents(ROOT_PATH.'/public/css/'.$css); ?></style>
<section class="dashboard-panel linkage-incoming-page impe-my-calls" data-my-calls>
    <header class="linkage-incoming-heading">
        <div class="linkage-incoming-heading-copy">
            <span class="linkage-incoming-eyebrow"><i class="bi bi-telephone" aria-hidden="true"></i> TELEFONÍA · HISTORIAL PERSONAL</span>
            <h2>Mis llamadas</h2>
            <p>Consulta los tramos telefónicos atribuidos a tu usuario. Para grabaciones, transferencias y resultados, abre el detalle en su bandeja operativa.</p>
        </div>
    </header>
    <div class="linkage-call-summary linkage-incoming-stats" aria-label="Resumen de actividad telefónica">
        <div><span>Tramos registrados</span><strong><?= $total ?></strong></div>
        <div><span>Con conexión</span><strong><?= (int)($misLlamadas['conectadas'] ?? 0) ?></strong></div>
        <div><span>Entrantes</span><strong><?= (int)($misLlamadas['entrantes'] ?? 0) ?></strong></div>
        <div><span>Salientes</span><strong><?= (int)($misLlamadas['salientes'] ?? 0) ?></strong></div>
    </div>
    <form method="get" action="<?= BASE_URL ?>index.php" class="impe-my-calls-filters">
        <input type="hidden" name="controller" value="telefonia">
        <input type="hidden" name="action" value="misLlamadas">
        <input type="hidden" name="vista" value="historial">
        <div class="impe-my-calls-filter-field">
            <label for="misLlamadasDesde">Desde</label>
            <input id="misLlamadasDesde" type="date" name="desde" required class="form-control"
                value="<?= $esc($filtros['desde'] ?? '') ?>">
        </div>
        <div class="impe-my-calls-filter-field">
            <label for="misLlamadasHasta">Hasta</label>
            <input id="misLlamadasHasta" type="date" name="hasta" required class="form-control"
                value="<?= $esc($filtros['hasta'] ?? '') ?>">
        </div>
        <div class="impe-my-calls-filter-field">
            <label for="misLlamadasTipo">Tipo</label>
            <select id="misLlamadasTipo" name="direccion" class="form-select" data-native-select>
                <option value="">Todas</option>
                <?php foreach (['Entrante','Saliente'] as $tipo): ?>
                    <option value="<?= $esc($tipo) ?>" <?= ($filtros['direccion'] ?? '') === $tipo ? 'selected' : '' ?>><?= $esc($tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="impe-my-calls-filter-action">
            <button class="btn btn-system-light" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
        </div>
    </form>
    <div class="linkage-call-section-heading linkage-incoming-list-head" id="misLlamadasHistorial">
        <div>
            <strong>Historial de actividad</strong>
            <p>Una comunicación transferida puede tener varios tramos sin ser varias llamadas externas.</p>
        </div>
        <span class="linkage-call-recordings-count" aria-label="<?= $total ?> registros"><?= $total ?></span>
    </div>
    <div class="linkage-call-history-list linkage-incoming-history" role="list">
        <?php foreach ($items as $fila):
            $saliente = ($fila['direccion'] ?? '') === 'Saliente';
            $duracion = max(0, (int)($fila['segundos'] ?? 0));
            $fechaBruta = (string)($fila['fecha'] ?? '');
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fechaBruta);
            $fechaVisual = $fecha && $fecha->format('Y-m-d H:i:s') === $fechaBruta
                ? $fecha->format('d/m/Y · H:i') : $fechaBruta;
            $pbxId = trim((string)($fila['pbx_call_id'] ?? ''));
            $detalleUrl = '';
            $detalleTexto = '';
            if (!empty($puedeGestionAnalista) && $saliente
                && (int)($fila['intento_devolucion'] ?? 0) > 0) {
                $detalleUrl = BASE_URL . 'index.php?controller=telefonia&action=misLlamadas'
                    . '&vista=devoluciones&detalle_intento=' . (int)$fila['intento_devolucion'];
                $detalleTexto = 'Ver devolución';
            } elseif (!empty($puedeGestionAnalista) && !$saliente && $pbxId !== '') {
                $detalleUrl = BASE_URL . 'index.php?controller=telefonia&action=misLlamadas'
                    . '&vista=recibidas&detalle=1&pbx_call_id=' . rawurlencode($pbxId);
                $detalleTexto = 'Ver llamada';
            } elseif (!empty($puedeRecepcionMarketing)) {
                $detalleUrl = BASE_URL . 'index.php?controller=telefonia&action=misLlamadas'
                    . '&vista=recepcion&buscar=' . rawurlencode((string)($fila['numero'] ?? ''));
                $detalleTexto = 'Consultar en recepción';
            }
        ?>
            <article class="linkage-call-history-card linkage-incoming-card" role="listitem">
                <span class="linkage-call-recording-card-icon" aria-hidden="true">
                    <i class="bi <?= $saliente ? 'bi-telephone-outbound' : 'bi-telephone-inbound' ?>"></i>
                </span>
                <div class="linkage-call-recording-body">
                    <div class="linkage-call-recording-top">
                        <div class="linkage-call-history-identity">
                            <strong>Llamada <?= $saliente ? 'saliente' : 'entrante' ?></strong>
                            <span><?= $saliente ? 'Destino' : 'Origen' ?>: <?= $esc(($fila['numero'] ?? '') ?: 'Número no disponible') ?></span>
                            <span>Ext. <?= $esc($fila['extension'] ?? '') ?><?= !empty($fila['proceso']) ? ' · '.$esc($fila['proceso']) : '' ?></span>
                        </div>
                        <span class="linkage-call-history-date"><?= $esc($fechaVisual) ?></span>
                    </div>
                    <div class="linkage-call-history-footer">
                        <div class="linkage-call-result-group">
                            <span class="linkage-call-result-badge <?= !empty($fila['conectada']) ? 'is-contact' : 'is-no-contact' ?>"><?= !empty($fila['conectada']) ? 'Conectada' : 'Sin conexión confirmada' ?></span>
                            <?php if (!empty($fila['resultado'])): ?><span class="linkage-call-result-badge"><?= $esc(str_replace('_',' ',$fila['resultado'])) ?></span><?php endif; ?>
                            <?php if (!empty($fila['transferida'])): ?><span class="linkage-call-result-badge">Transferida</span><?php endif; ?>
                            <?php if (!empty($fila['resultado_devolucion'])): ?><span class="linkage-call-result-badge">Devolución · <?= $esc(str_replace('_',' ',$fila['resultado_devolucion'])) ?></span><?php endif; ?>
                        </div>
                        <div class="linkage-call-history-meta">
                            <?php if ($detalleUrl !== ''): ?>
                                <a class="linkage-incoming-open impe-call-detail-link" href="<?= $esc($detalleUrl) ?>">
                                    <i class="bi bi-journal-text" aria-hidden="true"></i>
                                    <?= $esc($detalleTexto) ?>
                                </a>
                            <?php endif; ?>
                            <span class="linkage-call-recording-duration" title="<?= $duracion === 0 ? 'Zadarma no confirmó segundos de conversación en este tramo; 00:00 no demuestra una llamada de cero segundos.' : 'Duración del tramo informada por Zadarma' ?>"><i class="bi bi-clock" aria-hidden="true"></i> <?= sprintf('%02d:%02d', intdiv($duracion,60), $duracion%60) ?><?= $duracion === 0 && !empty($fila['conectada']) ? ' · Sin confirmar' : '' ?></span>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$items): ?><div class="linkage-incoming-empty-panel">No hay llamadas atribuidas a tu usuario dentro del intervalo seleccionado.</div><?php endif; ?>
    </div>
    <?php if ($paginas > 1): ?>
    <nav class="linkage-incoming-pagination" aria-label="Páginas de Mis llamadas">
        <span class="linkage-incoming-pagination-range">Mostrando <?= $primero ?>–<?= $ultimo ?> de <?= $total ?> tramos</span>
        <div class="linkage-incoming-pagination-pages">
            <?php if ($pagina > 1): ?>
                <a class="linkage-incoming-pagination-arrow" href="<?= $esc($irAPagina($pagina-1)) ?>" rel="prev"><i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior</a>
            <?php else: ?>
                <span class="linkage-incoming-pagination-arrow is-disabled" aria-disabled="true"><i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior</span>
            <?php endif; ?>
            <?php $anteriorVisible = 0; foreach ($paginasVisibles as $numero): ?>
                <?php if ($anteriorVisible && $numero - $anteriorVisible > 1): ?><span class="linkage-incoming-pagination-gap" aria-hidden="true">…</span><?php endif; ?>
                <?php if ($numero === $pagina): ?>
                    <span class="linkage-incoming-pagination-number is-current" aria-current="page"><?= $numero ?></span>
                <?php else: ?>
                    <a class="linkage-incoming-pagination-number" href="<?= $esc($irAPagina($numero)) ?>" aria-label="Ir a página <?= $numero ?>"><?= $numero ?></a>
                <?php endif; ?>
            <?php $anteriorVisible = $numero; endforeach; ?>
            <?php if ($pagina < $paginas): ?>
                <a class="linkage-incoming-pagination-arrow" href="<?= $esc($irAPagina($pagina+1)) ?>" rel="next">Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            <?php else: ?>
                <span class="linkage-incoming-pagination-arrow is-disabled" aria-disabled="true">Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
    <p class="impe-my-calls-note"><i class="bi bi-shield-check" aria-hidden="true"></i> Este historial muestra la actividad telefónica que se pudo atribuir a tu usuario. Una transferencia puede incluir varios participantes. Consulta «Recibidas y resultados», «Por devolver» o «Recepción» para el historial operativo y sus grabaciones; pueden existir diferencias entre sus totales.</p>
</section>
