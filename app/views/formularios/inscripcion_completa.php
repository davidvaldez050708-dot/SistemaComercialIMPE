<?php
require_once __DIR__ . '/../../services/FormularioInscripcionValidacion.php';
$texto = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$publico = !empty($inscripcionPublica);
$programa = $programaInscripcion;
$slug = $programa['slug'];
$legales = FormularioInscripcionValidacion::legales($slug);
$perfiles = FormularioInscripcionValidacion::perfiles($slug);
$secciones = require __DIR__ . '/../../services/FormularioInscripcionCampos.php';
$hoy = (new DateTimeImmutable('today', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
$urlPublica = BASE_URL . 'ya-estas-inscrito/?programa=' . rawurlencode($slug);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_inscripcion_completa.css?v=<?= filemtime(ROOT_PATH.'/public/css/formularios_inscripcion_completa.css') ?>">
<div class="formularios-page formularios-detail-page formularios-inscripcion-theme inscripcion-completa">
<?php if (!$publico): ?>
<div class="formularios-back-row"><a class="data-back-link" href="<?= BASE_URL ?>index.php?controller=formulario&amp;action=inscripcion"><i class="bi bi-arrow-left"></i> Volver a programas</a></div>
<section class="dashboard-panel formularios-detail-hero">
<div class="formularios-detail-heading"><span class="formularios-detail-icon is-registro"><i class="bi bi-file-earmark-check"></i></span><div><span class="formularios-kicker">FORMULARIO DE INSCRIPCIÓN</span><h2><?= $texto($programa['nombre']) ?></h2><p>Captura y revisa la información antes de guardar la inscripción.</p></div></div>
</section>
<section class="dashboard-panel inscripcion-enlace"><div><strong>Enlace público del programa</strong><p>Comparte esta liga para que la persona capture su inscripción.</p><input readonly aria-label="Enlace público" value="<?= $texto($urlPublica) ?>" data-inscripcion-enlace></div><button type="button" class="btn btn-system-cancel" data-inscripcion-copiar><i class="bi bi-copy"></i> Copiar enlace</button><a class="btn btn-system-cancel" href="<?= $texto($urlPublica) ?>" target="_blank" rel="noopener">Abrir enlace <i class="bi bi-box-arrow-up-right"></i></a></section>
<?php endif; ?>
<section class="dashboard-panel formularios-registration-panel">
<div class="formularios-registration-heading"><div><span class="formularios-kicker">DATOS DE INSCRIPCIÓN</span><h3>Información de la persona que se inscribe</h3><p>Los campos con <strong>*</strong> son obligatorios. Coteja nombre, fecha de nacimiento y CURP con la constancia oficial.</p></div><span class="formularios-registration-badge"><i class="bi bi-shield-check"></i> Validación de datos</span></div>
<?php if ($legales['terminos'] === '' || !$perfiles): ?><div class="inscripcion-nota" role="status"><i class="bi bi-info-circle"></i><span>Formulario en preparación: falta confirmar <?= !$perfiles ? 'los perfiles y ' : '' ?>los términos de este programa. Puedes revisar los campos; todavía no se reciben inscripciones.</span></div><?php endif; ?>
<?php if (!$inscripcionRecepcionLista): ?><div class="inscripcion-nota" role="status"><i class="bi bi-info-circle"></i><span>La recepción de inscripciones aún no está habilitada. Puedes revisar el formulario; no se enviará información hasta completar su preparación.</span></div><?php endif; ?>
<form class="formularios-registration-form" action="<?= $texto($inscripcionAction) ?>" method="post" enctype="multipart/form-data" novalidate data-inscripcion-completa data-municipios-url="<?= $texto($inscripcionMunicipiosUrl) ?>">
<input type="hidden" name="csrf_inscripcion" value="<?= $texto($_SESSION['csrf_inscripcion_completa']) ?>"><input type="hidden" name="solicitud_token" value="<?= $texto($inscripcionToken) ?>"><input type="hidden" name="programa_slug" value="<?= $texto($slug) ?>">
<?php foreach ($secciones as $seccion): ?>
<section class="formularios-registration-section"><div class="formularios-registration-section-title"><span><i class="bi <?= $texto($seccion['icono']) ?>"></i></span><div><h4><?= $texto($seccion['titulo']) ?></h4><p><?= $texto($seccion['titulo'] === 'Información laboral y perfil' ? 'El programa queda asociado a este formulario.' : $seccion['descripcion']) ?></p></div></div>
<div class="formularios-registration-grid">
<?php foreach ($seccion['campos'] as [$nombre,$etiqueta,$tipo,$placeholder,$max,$autocomplete,$requerido]): ?>
<div class="formularios-field"><label for="inscripcion_<?= $texto($nombre) ?>"><?= $texto($etiqueta) ?> <?= $requerido ? '<strong>*</strong>' : '' ?></label>
<?php if (in_array($nombre, ['entidad_federativa','municipio','genero','perfil_evaluacion'],true)): ?>
<select class="form-select" name="<?= $texto($nombre) ?>" id="inscripcion_<?= $texto($nombre) ?>" <?= $requerido?'required':'' ?> <?= $nombre==='municipio'?'disabled':'' ?>>
<option value="">Selecciona una opción</option>
<?php if ($nombre==='genero'): ?><option value="H">Hombre</option><option value="M">Mujer</option>
<?php elseif ($nombre==='entidad_federativa'): foreach ($estados as $estado): ?><option value="<?= (int)$estado['id'] ?>"><?= $texto($estado['nombre']) ?></option><?php endforeach; ?>
<?php elseif ($nombre==='perfil_evaluacion'): foreach ($perfiles as $perfil): ?><option value="<?= $texto($perfil) ?>" <?= count($perfiles)===1?'selected':'' ?>><?= $texto($perfil) ?></option><?php endforeach; endif; ?>
</select>
<?php else: ?><input class="form-control" type="<?= $texto($tipo) ?>" name="<?= $texto($nombre) ?>" id="inscripcion_<?= $texto($nombre) ?>" maxlength="<?= (int)$max ?>" autocomplete="<?= $texto($autocomplete) ?>" placeholder="<?= $texto($placeholder) ?>" <?= $requerido?'required':'' ?> <?= $tipo==='date'?'min="1900-01-01" max="'.$hoy.'"':'' ?> <?= in_array($nombre,['movil','movil_confirmacion','codigo_postal'],true)?'inputmode="numeric"':'' ?>><?php endif; ?>
<?php if ($nombre==='curp'): ?><small>Compárala con tu constancia. <a target="_blank" rel="noopener noreferrer" href="https://www.gob.mx/curp/">Consultar CURP oficial</a></small><?php endif; ?>
<div class="invalid-feedback" id="error_<?= $texto($nombre) ?>"></div></div>
<?php endforeach; ?></div></section>
<?php endforeach; ?>
<section class="formularios-registration-section"><div class="formularios-registration-section-title"><span><i class="bi bi-receipt"></i></span><div><h4>Inscripción y comprobante de pago</h4><p>El comprobante se recibe para revisión; subirlo no acredita que el pago fue validado.</p></div></div><div class="formularios-registration-grid"><div class="formularios-field"><label for="inscripcion_fecha_inscripcion">Fecha de inscripción <strong>*</strong></label><input class="form-control" type="date" id="inscripcion_fecha_inscripcion" name="fecha_inscripcion" required max="<?= $hoy ?>"><div class="invalid-feedback" id="error_fecha_inscripcion"></div></div><div class="formularios-field"><label for="inscripcion_comprobante">Comprobante de pago <strong>*</strong></label><input class="form-control" type="file" id="inscripcion_comprobante" name="comprobante" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><small>PDF, JPG o PNG. Máximo 8 MB. Evita incluir datos de tarjeta que no sean necesarios.</small><div class="invalid-feedback" id="error_comprobante"></div></div></div></section>
<section class="formularios-registration-section"><div class="formularios-registration-section-title"><span><i class="bi bi-shield-check"></i></span><div><h4>Revisión y consentimiento</h4><p>Revisa la información y los documentos antes de enviar.</p></div></div>
<label class="inscripcion-consentimiento"><input type="checkbox" name="confirmacion_identidad" value="1" required> Cotejé mi nombre, apellidos, fecha de nacimiento y CURP con mi constancia oficial.</label><div class="invalid-feedback" id="error_confirmacion_identidad"></div>
<?php foreach (['terminos'=>'Términos y condiciones','privacidad'=>'Aviso de privacidad'] as $clave=>$titulo): ?><details class="inscripcion-documento"><summary><?= $texto($titulo) ?></summary><div><?= $legales[$clave] !== '' ? nl2br($texto($legales[$clave])) : 'Pendiente de confirmar el documento correspondiente a este programa.' ?></div></details><label class="inscripcion-consentimiento"><input type="checkbox" name="acepta_<?= $clave ?>" value="1" required <?= $legales[$clave]===''?'disabled':'' ?>> Leí y acepto <?= $texto(mb_strtolower($titulo,'UTF-8')) ?>.</label><div class="invalid-feedback" id="error_acepta_<?= $clave ?>"></div><?php endforeach; ?>
</section><div class="formularios-registration-actions"><button class="btn btn-system-save" type="submit" <?= $legales['terminos']===''||!$perfiles||!$inscripcionRecepcionLista?'disabled':'' ?>><i class="bi bi-check2-circle"></i> Guardar inscripción</button></div><div role="status" aria-live="polite" data-inscripcion-estado></div>
</form></section></div>
<script src="<?= BASE_URL ?>public/javascript/formularios_inscripcion_completa.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/formularios_inscripcion_completa.js') ?>"></script>
