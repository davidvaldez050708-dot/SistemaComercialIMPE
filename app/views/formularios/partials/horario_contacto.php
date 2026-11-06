<?php
$horarioPublico=!empty($horarioContactoPublico);
$horarioId=$horarioPublico?'registro_publico_contacto':'registro_contacto';
$horarioClase=$horarioPublico?'formulario-publico-field':'formularios-field';
?>
<div class="<?= $horarioClase ?> horario-contacto" data-horario-contacto>
    <label for="<?= $horarioId ?>">¿En qué horario podemos contactarte? <strong>*</strong></label>
    <select id="<?= $horarioId ?>" name="contacto_disponibilidad" class="<?= $horarioPublico?'':'form-select' ?>" required data-native-select data-horario-modo aria-describedby="<?= $horarioId ?>_ayuda">
        <option value="">Selecciona una opción</option>
        <option value="sin_preferencia">Sin preferencia de horario</option>
        <option value="personalizado">Elegir días y horario</option>
    </select>
    <small class="<?= $horarioPublico?'formulario-publico-help':'' ?>" id="<?= $horarioId ?>_ayuda">Usaremos esta preferencia para contactarte; no es una cita confirmada.</small>
    <div class="invalid-feedback">Selecciona tu disponibilidad de contacto.</div>
    <fieldset class="horario-contacto-config" data-horario-config>
        <legend>Días y horario disponibles</legend>
        <span class="horario-contacto-label" id="<?= $horarioId ?>_dias">¿Qué días te podemos contactar?</span>
        <div class="horario-contacto-dias" role="group" aria-labelledby="<?= $horarioId ?>_dias">
            <?php foreach (['lun'=>'Lun','mar'=>'Mar','mie'=>'Mié','jue'=>'Jue','vie'=>'Vie','sab'=>'Sáb','dom'=>'Dom'] as $horarioDia=>$horarioNombre): ?>
            <label><input type="checkbox" name="contacto_dias[]" value="<?= $horarioDia ?>" data-horario-dia> <span><?= $horarioNombre ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="horario-contacto-horas">
            <div class="<?= $horarioClase ?>"><label for="<?= $horarioId ?>_desde">Desde</label><input type="time" step="60" id="<?= $horarioId ?>_desde" name="contacto_desde" class="<?= $horarioPublico?'':'form-control' ?>" data-horario-desde></div>
            <div class="<?= $horarioClase ?>"><label for="<?= $horarioId ?>_hasta">Hasta</label><input type="time" step="60" id="<?= $horarioId ?>_hasta" name="contacto_hasta" class="<?= $horarioPublico?'':'form-control' ?>" data-horario-hasta></div>
        </div>
        <small class="<?= $horarioPublico?'formulario-publico-help':'' ?>">Indica las horas del lugar donde vives. Elige un rango dentro del mismo día.</small>
    </fieldset>
    <p class="horario-contacto-error" role="alert" hidden data-horario-error></p>
</div>
