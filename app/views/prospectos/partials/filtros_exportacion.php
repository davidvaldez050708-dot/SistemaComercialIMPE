<style>
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
    font: 600 .875rem Manrope, sans-serif;
}
.prospectos-fechas .prospectos-periodo select,
.prospectos-fechas .prospectos-rango input {
    width: auto;
    min-height: 38px;
    padding: 6px 10px;
    border: 1px solid #d5e0f1;
    border-radius: 9px;
    background: #f8fafc;
    color: #173865;
    font: 600 .875rem Manrope, sans-serif;
}
.prospectos-fechas .central-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 38px;
    padding: 6px 11px;
    border: 1px solid #d5e0f1;
    border-radius: 9px;
    background: #f2f6fc;
    color: #173865;
    font: 700 .875rem Manrope, sans-serif;
    cursor: pointer;
}
.prospectos-fechas .central-link:hover { background: #e8effb; }
.prospectos-fechas .prospectos-exportar { margin-inline-start: auto; }
.prospectos-fechas summary { list-style: none; }
.prospectos-fechas summary::-webkit-details-marker { display: none; }
.prospectos-fechas .prospectos-rango {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 8px;
    margin-block-start: 8px;
}
.prospectos-fechas .prospectos-rango label { display: grid; gap: 4px; margin: 0; font-size: .8125rem; }
.prospectos-fechas details[open] { flex-basis: 100%; order: 1; }
@media (max-width: 480px) {
    .prospectos-fechas .prospectos-exportar { margin-inline-start: 0; }
}
</style>
<?php
$fechaFiltro = static fn(string $campo): string => htmlspecialchars(is_string($_GET[$campo]??null)?$_GET[$campo]:'',ENT_QUOTES,'UTF-8');
$rangoActivo = empty($_GET['periodo']) && ($fechaFiltro('desde')!=='' || $fechaFiltro('hasta')!=='');
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
        <summary class="central-link"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= $rangoActivo ? 'Rango aplicado' : 'Rango de fechas' ?></summary>
        <div class="prospectos-rango">
            <label>Desde <input type="date" name="desde" value="<?= $fechaFiltro('desde') ?>"></label>
            <label>Hasta <input type="date" name="hasta" value="<?= $fechaFiltro('hasta') ?>"></label>
        </div>
    </details>
    <button type="submit" class="central-link">Aplicar fechas</button>
    <button type="submit" name="exportar" value="xlsx" class="central-link prospectos-exportar"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportar Excel</button>
</div>
<script>
(() => {
    const barra = document.currentScript.previousElementSibling;
    barra.querySelectorAll('input[type=date]').forEach(campo => {
        campo.addEventListener('change', () => { barra.querySelector('select[name=periodo]').value = ''; });
    });
})();
</script>
