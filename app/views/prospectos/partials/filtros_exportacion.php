<style>
:is(.central-filters, .prospectos-list-filters) {
    row-gap: 10px;
}
:is(.central-filters, .prospectos-list-filters) > div > label,
:is(.central-filters, .prospectos-list-filters) > div > label ~ label {
    font: 700 12px/1.4 Manrope, sans-serif !important;
    color: #617189;
    margin-bottom: 6px;
}
:is(.central-filters, .prospectos-list-filters) :is(input, select, .uniform-select-trigger, .uniform-select-option, .uniform-select-label) {
    font-family: Manrope, sans-serif !important;
    font-size: 13px !important;
    font-weight: 500;
}
:is(.central-filters, .prospectos-list-filters) :is(input, select, .uniform-select-trigger) {
    height: 42px;
    min-height: 42px;
}

.central-filters > .prospectos-fechas,
.prospectos-list-filters > .prospectos-fechas {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    min-width: 0;
}
.prospectos-fechas .prospectos-periodo {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #465873;
    font: 700 12px/1.4 Manrope, sans-serif !important;
}
.prospectos-fechas .prospectos-periodo select,
.prospectos-fechas .prospectos-rango input {
    width: auto;
    height: 36px;
    min-height: 36px;
    padding: 6px 10px;
    border: 1px solid #d5e0f1;
    border-radius: 9px;
    background: #f8fafc;
    color: #173865;
    font: 500 13px/1.4 Manrope, sans-serif !important;
}
.prospectos-fechas .central-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 36px;
    padding: 6px 11px;
    border: 1px solid #d5e0f1;
    border-radius: 9px;
    background: #f2f6fc;
    color: #173865;
    font: 800 12px/1.4 Manrope, sans-serif !important;
    cursor: pointer;
}
.prospectos-fechas .central-link:hover { background: #e8effb; }
.prospectos-fechas .prospectos-exportar {
    margin-inline-start: auto;
    background: #223a84;
    border-color: #223a84;
    color: #fff;
}
.prospectos-fechas .prospectos-exportar:hover { background: #192d70; }
.prospectos-fechas summary { list-style: none; }
.prospectos-fechas summary::-webkit-details-marker { display: none; }
.prospectos-fechas .prospectos-rango {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 8px;
    margin-block-start: 8px;
}
.prospectos-fechas .prospectos-rango label { display: grid; gap: 4px; margin: 0; font-size: 12px !important; }
.prospectos-fechas details { position: relative; }
.prospectos-fechas details[open] > summary { background: #e8effb; }
.prospectos-fechas .prospectos-rango {
    position: absolute;
    z-index: 10;
    inset-inline-start: 0;
    width: max-content;
    max-width: calc(100vw - 40px);
    padding: 12px;
    background: #fff;
    border: 1px solid #d5e0f1;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(24,43,72,.12);
}
.prospectos-fechas .prospectos-limpiar { background: #fff; text-decoration: none; }
.prospectos-page:has(.prospectos-fechas) [data-prospectos-limpiar] { display: none; }
@media (max-width: 650px) {
    .prospectos-fechas details { position: static; }
    .prospectos-fechas { position: relative; }
    .prospectos-fechas .prospectos-rango { inset-inline-start: 0; }
}
@media (max-width: 480px) {
    .prospectos-fechas .prospectos-exportar { margin-inline-start: 0; }
}
</style>
<?php
$fechaFiltro = static fn(string $campo): string => htmlspecialchars(is_string($_GET[$campo]??null)?$_GET[$campo]:'',ENT_QUOTES,'UTF-8');
$limpiarFiltros = ['controller'=>'prospecto','action'=>'index','seccion'=>$seccionProspectos];
if ($seccionProspectos==='aliados') {
    $limpiarFiltros['vista']=$vistaProspectos;
    if (!empty($estadoProspectos)) $limpiarFiltros['estado_id']=(int)$estadoProspectos['id'];
}
$urlLimpiarFiltros=BASE_URL.'index.php?'.http_build_query($limpiarFiltros);
?>
<div class="prospectos-fechas">
    <label class="prospectos-periodo">Periodo
        <select name="periodo">
            <?php foreach ([''=>'Todas las fechas','hoy'=>'Hoy','ayer'=>'Ayer'] as $valor=>$nombre): ?>
                <option value="<?= $valor ?>" <?= ($_GET['periodo']??'')===$valor?'selected':'' ?>><?= $nombre ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <details>
        <summary class="central-link"><i class="bi bi-calendar3" aria-hidden="true"></i> Rango de fechas</summary>
        <div class="prospectos-rango">
            <label>Desde <input type="date" name="desde" value="<?= $fechaFiltro('desde') ?>"></label>
            <label>Hasta <input type="date" name="hasta" value="<?= $fechaFiltro('hasta') ?>"></label>
            <button type="submit" class="central-link" data-aplicar-rango>Aplicar</button>
        </div>
    </details>
    <a class="central-link prospectos-limpiar" href="<?= htmlspecialchars($urlLimpiarFiltros,ENT_QUOTES,'UTF-8') ?>"><i class="bi bi-x-circle" aria-hidden="true"></i> Limpiar filtros</a>
    <noscript><button type="submit" class="central-link">Aplicar periodo</button></noscript>
    <button type="submit" name="exportar" value="xlsx" class="central-link prospectos-exportar"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportar Excel</button>
</div>
<script>
(() => {
    const barra = document.currentScript.previousElementSibling;
    const rango = barra.querySelector('details');
    const periodo = barra.querySelector('[name=periodo]');
    const formulario = barra.closest('form');
    const aplicar = barra.querySelector('[data-aplicar-rango]');
    aplicar.addEventListener('click', () => {
        periodo.value = '';
        rango.open = false;
    });
    periodo.addEventListener('change', () => {
        barra.querySelectorAll('input[type=date]').forEach(campo => { campo.value = ''; });
        rango.open = false;
        if (formulario.hasAttribute('data-prospectos-filtros') && formulario.requestSubmit) formulario.requestSubmit();
    });
    document.addEventListener('click', evento => {
        if (rango.open && !rango.contains(evento.target)) rango.open = false;
    });
    rango.addEventListener('keydown', evento => {
        if (evento.key === 'Escape' && rango.open) {
            rango.open = false;
            rango.querySelector('summary').focus();
        }
    });
    barra.querySelectorAll('input[type=date]').forEach(campo => {
        campo.addEventListener('change', () => { barra.querySelector('select[name=periodo]').value = ''; });
    });
})();
</script>
