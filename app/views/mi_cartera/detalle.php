<?php
/** Ficha reducida, no reutiliza la ficha administrativa ni sus enlaces. */
require_once __DIR__.'/../../helpers/ProspectoFechaHoraHelper.php';
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$texto=static fn($v)=>$v===null||trim((string)$v)===''?'Sin indicar':(string)$v;
$fecha=static fn($v)=>$v===null||$v===''?'Sin programar':ProspectoFechaHoraHelper::aMexico($v);
$urlCartera=BASE_URL.'index.php?controller=miCartera&action=index';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/mi_cartera.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/mi_cartera.css'),0,16) ?>">
<div class="mi-cartera">
    <a href="<?= $esc($urlCartera) ?>" class="mi-cartera-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a Mi cartera</a>
    <?php if ($error!==''): ?>
    <div class="mi-cartera-notice mi-cartera-notice--error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= $esc($error) ?></div>
    <?php endif; ?>
    <?php if ($prospecto): ?>
    <header class="mi-cartera-hero">
        <div>
            <span class="mi-cartera-eyebrow"><i class="bi bi-person-check" aria-hidden="true"></i> PROSPECTO DE TU CARTERA</span>
            <h2><?= $esc($prospecto['nombre_completo']) ?></h2>
            <p>Ficha de consulta · ID comercial #<?= (int)$prospecto['id'] ?></p>
        </div>
        <div class="mi-cartera-hero-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
    </header>

    <div class="mi-cartera-detalles">
        <section class="mi-cartera-panel">
            <div class="mi-cartera-panel-heading"><div><h3>Datos de contacto</h3><p>Información declarada por la persona.</p></div></div>
            <dl class="mi-cartera-dl">
                <div><dt>Teléfono</dt><dd><?= $esc($texto($prospecto['telefono_original'])) ?></dd></div>
                <div><dt>Correo electrónico</dt><dd><?= $esc($texto($prospecto['correo'])) ?></dd></div>
                <div><dt>País</dt><dd><?= $esc($texto($prospecto['pais_nombre'])) ?></dd></div>
                <div><dt>Estado o provincia</dt><dd><?= $esc($texto($prospecto['estado_provincia'])) ?></dd></div>
                <div><dt>Ciudad</dt><dd><?= $esc($texto($prospecto['ciudad'])) ?></dd></div>
                <div><dt>Verificación</dt><dd><?= $esc(str_replace('_',' ',$texto($prospecto['verificacion_estado']))) ?></dd></div>
            </dl>
        </section>

        <section class="mi-cartera-panel">
            <div class="mi-cartera-panel-heading"><div><h3>Mi asignación</h3><p>Solo la asignación vigente a tu usuario.</p></div></div>
            <dl class="mi-cartera-dl">
                <div><dt>Estado de asignación</dt><dd><?= $esc(str_replace('_',' ',$texto($prospecto['estado_asignacion']))) ?></dd></div>
                <div><dt>Asignado</dt><dd><?= $esc($fecha($prospecto['asignado_at'])) ?></dd></div>
                <div><dt>Fecha de trabajo</dt><dd><?= $esc($texto($prospecto['fecha_trabajo'])) ?></dd></div>
                <div><dt>Próxima acción</dt><dd><?= $esc($fecha($prospecto['proxima_accion_at'])) ?></dd></div>
            </dl>
            <div class="mi-cartera-info"><i class="bi bi-info-circle" aria-hidden="true"></i> El estado de asignación no equivale a un resultado comercial o de llamada.</div>
        </section>

        <section class="mi-cartera-panel">
            <div class="mi-cartera-panel-heading"><div><h3>Programas de interés</h3></div></div>
            <?php if (empty($prospecto['programas'])): ?><p class="mi-cartera-muted">Sin programa clasificado.</p>
            <?php else: ?><div class="mi-cartera-tags">
                <?php foreach ($prospecto['programas'] as $programa): ?>
                <span><?= $esc($texto($programa['nombre_original'])) ?></span>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>

        <section class="mi-cartera-panel">
            <div class="mi-cartera-panel-heading"><div><h3>Procedencia del registro</h3></div></div>
            <?php if (empty($prospecto['origenes'])): ?><p class="mi-cartera-muted">Procedencia no registrada.</p>
            <?php else: ?><div class="mi-cartera-fuentes">
                <?php foreach ($prospecto['origenes'] as $origen): ?>
                <div><strong><?= $esc($texto($origen['aliado_nombre']??$origen['formulario_nombre']??$origen['tipo_origen'])) ?></strong>
                    <span><?= $esc(str_replace('_',' ',$texto($origen['tipo_origen']))) ?></span></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>

        <section class="mi-cartera-panel">
            <div class="mi-cartera-panel-heading"><div><h3>Horario de contacto solicitado</h3><p>Preferencia del prospecto, no cita confirmada.</p></div></div>
            <?php if (empty($prospecto['preferencias'])): ?><p class="mi-cartera-muted">Sin preferencia registrada.</p>
            <?php else: ?><div class="mi-cartera-fuentes">
                <?php foreach ($prospecto['preferencias'] as $pref): ?>
                <div><strong><?= $esc($texto($pref['valor_original'])) ?></strong>
                    <span><?= $esc($texto($pref['zona_horaria'])) ?> · <?= $esc(str_replace('_',' ',$texto($pref['interpretacion_estado']))) ?></span></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
        </section>
    </div>

    <div class="mi-cartera-info mi-cartera-info--footer">
        <i class="bi bi-headset" aria-hidden="true"></i>
        El historial de telefonía se conserva en <a href="<?= $esc(BASE_URL.'index.php?controller=telefonia&action=misLlamadas') ?>">Mis llamadas</a>.
        Esta ficha todavía no registra resultados ni inicia llamadas.
    </div>
    <?php endif; ?>
</div>
