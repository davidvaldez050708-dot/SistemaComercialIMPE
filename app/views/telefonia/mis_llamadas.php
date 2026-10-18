<?php
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$items = $misLlamadas['filas'] ?? [];
$filtros = $misLlamadas['filtros'] ?? [];
?>
<style><?php echo file_get_contents(ROOT_PATH . '/public/css/seguimiento_entrantes_analista.css'); ?></style>
<section class="dashboard-panel linkage-incoming-page" data-my-calls>
    <header class="linkage-incoming-heading">
        <div class="linkage-incoming-heading-copy">
            <span class="linkage-incoming-eyebrow"><i class="bi bi-telephone-history" aria-hidden="true"></i> TELEFONÍA · HISTORIAL PERSONAL</span>
            <h2>Mis llamadas</h2>
            <p>Consulta tus llamadas atribuidas por la centralita. Cada fila representa un tramo telefónico verificado.</p>
        </div>
    </header>
    <div class="linkage-call-summary linkage-incoming-stats">
        <div><span>Tramos registrados</span><strong><?= (int)($misLlamadas['total'] ?? 0) ?></strong></div>
        <div><span>Con conexión</span><strong><?= (int)($misLlamadas['conectadas'] ?? 0) ?></strong></div>
        <div><span>Entrantes</span><strong><?= (int)($misLlamadas['entrantes'] ?? 0) ?></strong></div>
        <div><span>Salientes</span><strong><?= (int)($misLlamadas['salientes'] ?? 0) ?></strong></div>
    </div>
    <form method="get" action="<?= BASE_URL ?>index.php" class="linkage-incoming-heading" style="align-items:end;padding:14px;border:1px solid #dfe7f3;border-radius:12px;background:#f8fafd;margin-bottom:20px">
        <input type="hidden" name="controller" value="telefonia">
        <input type="hidden" name="action" value="misLlamadas">
        <label class="form-label">Desde
            <input class="form-control" type="date" name="desde" required value="<?= $esc($filtros['desde'] ?? '') ?>">
        </label>
        <label class="form-label">Hasta
            <input class="form-control" type="date" name="hasta" required value="<?= $esc($filtros['hasta'] ?? '') ?>">
        </label>
        <label class="form-label">Tipo
            <select class="form-select" name="direccion" data-native-select>
                <option value="">Todas</option>
                <?php foreach (['Entrante','Saliente'] as $tipo): ?>
                <option value="<?= $esc($tipo) ?>" <?= ($filtros['direccion'] ?? '') === $tipo ? 'selected' : '' ?>><?= $esc($tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-system-light" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
    </form>
    <div class="linkage-call-section-heading"><div><strong>Historial de actividad</strong><p>Las transferencias pueden contener tramos de diferentes extensiones.</p></div><span class="linkage-call-recordings-count"><?= count($items) ?></span></div>
    <div class="linkage-call-history-list linkage-incoming-history" role="list">
        <?php foreach ($items as $fila): ?>
        <article class="linkage-call-history-card linkage-incoming-card" role="listitem">
            <div class="linkage-call-recording-body">
            <div class="linkage-call-recording-top">
                <div class="linkage-call-history-identity">
                    <strong><i class="bi bi-<?= ($fila['direccion'] ?? '') === 'Saliente' ? 'telephone-outbound' : 'telephone-inbound' ?>" aria-hidden="true"></i> <?= $esc($fila['direccion'] ?? '') ?></strong>
                    <span><?= $esc(($fila['numero'] ?? '') ?: 'Número no disponible') ?> · Ext. <?= $esc($fila['extension'] ?? '') ?></span>
                </div>
                <span class="linkage-call-history-date"><?= $esc($fila['fecha'] ?? '') ?></span>
            </div>
            <div class="linkage-call-history-footer">
                <div class="linkage-call-result-group">
                    <span class="linkage-call-result-badge <?= !empty($fila['conectada']) ? 'is-contact' : 'is-no-contact' ?>"><?= !empty($fila['conectada']) ? 'Conectada' : 'Sin confirmación de conexión' ?></span>
                    <?php if (!empty($fila['resultado'])): ?><span class="linkage-call-result-badge"><?= $esc($fila['resultado']) ?></span><?php endif; ?>
                    <?php if (!empty($fila['resultado_devolucion'])): ?><span class="linkage-call-result-badge is-contact">Devolución · <?= $esc(str_replace('_', ' ', $fila['resultado_devolucion'])) ?></span><?php endif; ?>
                    <span class="linkage-call-recording-duration"><?= sprintf('%02d:%02d', intdiv(max(0,(int)($fila['segundos'] ?? 0)),60), max(0,(int)($fila['segundos'] ?? 0))%60) ?></span>
                </div>
            </div>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$items): ?><div class="linkage-incoming-empty-panel">No hay tramos telefónicos verificables para el período seleccionado.</div><?php endif; ?>
    </div>
    <?php if (($misLlamadas['paginas'] ?? 1) > 1): ?>
    <nav class="linkage-incoming-pagination" aria-label="Páginas del historial personal">
        <span class="linkage-incoming-pagination-range">Página <?= (int)$misLlamadas['pagina'] ?> de <?= (int)$misLlamadas['paginas'] ?> · <?= (int)$misLlamadas['total'] ?> tramos</span>
        <div class="linkage-incoming-pagination-pages">
            <?php foreach ([-1, 1] as $salto):
                $destino = (int)$misLlamadas['pagina'] + $salto;
                if ($destino < 1 || $destino > (int)$misLlamadas['paginas']) continue;
                $query = http_build_query(['controller'=>'telefonia','action'=>'misLlamadas',
                    'desde'=>$filtros['desde'] ?? '', 'hasta'=>$filtros['hasta'] ?? '',
                    'direccion'=>$filtros['direccion'] ?? '', 'pagina'=>$destino]);
            ?>
            <a class="linkage-incoming-pagination-arrow" href="<?= BASE_URL ?>index.php?<?= $esc($query) ?>"><?= $salto < 0 ? 'Anterior' : 'Siguiente' ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php endif; ?>
    <p class="text-muted" style="font-size:12px;margin-top:16px"><?= $esc($misLlamadas['limitacion'] ?? '') ?></p>
</section>
