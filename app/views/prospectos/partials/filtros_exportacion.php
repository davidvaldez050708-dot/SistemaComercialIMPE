<?php
$fechaFiltro = static fn(string $campo): string => htmlspecialchars(is_string($_GET[$campo]??null)?$_GET[$campo]:'',ENT_QUOTES,'UTF-8');
?>
<div><label>Periodo
<select name="periodo" class="form-select">
<?php foreach ([''=>'Todas las fechas o rango','hoy'=>'Hoy','ayer'=>'Ayer'] as $valor=>$nombre): ?>
<option value="<?= $valor ?>" <?= ($_GET['periodo']??'')===$valor?'selected':'' ?>><?= $nombre ?></option>
<?php endforeach; ?>
</select></label></div>
<div><label>Desde <input type="date" name="desde" class="form-control" value="<?= $fechaFiltro('desde') ?>"></label></div>
<div><label>Hasta <input type="date" name="hasta" class="form-control" value="<?= $fechaFiltro('hasta') ?>"></label></div>
<div><button type="submit" class="btn btn-system-light">Aplicar fechas</button>
<button type="submit" name="exportar" value="xlsx" class="btn btn-system-light"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportar Excel</button></div>
