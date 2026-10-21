<?php foreach (['pais_nombre' => 'País', 'estado_provincia' => 'Estado o provincia', 'ciudad' => 'Ciudad'] as $campo => $etiqueta): ?>
<div class="formulario-publico-field">
    <label for="ubicacion_<?= $campo ?>"><?= $etiqueta ?> <strong>*</strong></label>
    <input type="text" id="ubicacion_<?= $campo ?>" name="<?= $campo ?>" required minlength="2" maxlength="120" data-ubicacion-internacional>
    <div class="invalid-feedback">Ingresa <?= $texto(mb_strtolower($etiqueta)) ?>.</div>
</div>
<?php endforeach; ?>
