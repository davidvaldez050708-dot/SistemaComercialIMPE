<?php
require_once __DIR__.'/../../helpers/ProspectoFechaHoraHelper.php';
$esc=static fn($x)=>htmlspecialchars((string)($x??''),ENT_QUOTES,'UTF-8');
$def=static fn($x)=>trim((string)($x??''))!==''?(string)$x:'Sin indicar';
$fecha=static fn($x)=>$x?ProspectoFechaHoraHelper::aMexico($x):'Sin programar';
$volver=BASE_URL.'index.php?'.http_build_query([
    'controller'=>'embudoComercial','action'=>'index','vista'=>$vista
]);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<div class="impe-embudo">
    <a class="impe-embudo-back" href="<?= $esc($volver) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a <?= $esc($vista==='embudo'?'Embudo':($vista==='recuperacion'?'Recuperación':'Descartados')) ?></a>
    <?php if ($error!==''): ?>
    <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><?= $esc($error) ?></p>
    <?php elseif ($prospecto): ?>
    <header class="impe-embudo-hero">
        <div><span class="impe-embudo-kicker">FICHA COMERCIAL · SOLO LECTURA</span>
            <h2><?= $esc($prospecto['nombre_completo']) ?></h2>
            <p>ID comercial #<?= (int)$prospecto['id'] ?> · <?= $esc(EmbudoComercialRepository::ETAPAS[$prospecto['etapa']]??'Etapa pendiente') ?></p>
        </div>
        <span class="impe-embudo-hero-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
    </header>
    <div class="impe-embudo-detalles">
        <section>
            <h3>Datos de contacto</h3>
            <dl>
                <div><dt>Teléfono</dt><dd><?= $esc($def($prospecto['telefono_original'])) ?></dd></div>
                <div><dt>Correo</dt><dd><?= $esc($def($prospecto['correo'])) ?></dd></div>
                <div><dt>País</dt><dd><?= $esc($def($prospecto['pais_nombre'])) ?></dd></div>
                <div><dt>Estado / provincia</dt><dd><?= $esc($def($prospecto['estado_provincia'])) ?></dd></div>
                <div><dt>Ciudad</dt><dd><?= $esc($def($prospecto['ciudad'])) ?></dd></div>
            </dl>
        </section>
        <section>
            <h3>Asignación y próxima atención</h3>
            <dl>
                <div><dt>Asesor asignado</dt><dd>ID #<?= (int)$prospecto['asesor_id'] ?></dd></div>
                <div><dt>Fecha de asignación</dt><dd><?= $esc($fecha($prospecto['asignado_at'])) ?></dd></div>
                <div><dt>Jornada de trabajo</dt><dd><?= $esc($def($prospecto['fecha_trabajo'])) ?></dd></div>
                <div><dt>Próxima acción</dt><dd><?= $esc($fecha($prospecto['proxima_accion_at'])) ?></dd></div>
            </dl>
        </section>
        <section>
            <h3>Programas de interés</h3>
            <?php if (!$prospecto['programas']): ?><p class="impe-embudo-muted">Sin programa clasificado.</p><?php else: ?>
            <ul><?php foreach ($prospecto['programas'] as $p): ?><li><?= $esc($p['nombre_original']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section>
            <h3>Procedencia e historial de registro</h3>
            <?php if (!$prospecto['origenes']): ?><p class="impe-embudo-muted">Sin procedencia.</p><?php else: ?>
            <ul><?php foreach ($prospecto['origenes'] as $o): ?><li><?= $esc($def($o['aliado_nombre']?:($o['formulario_nombre']?:$o['tipo_origen']))) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="impe-embudo-detalles-wide">
            <h3>Horario de contacto declarado</h3>
            <?php if (!$prospecto['preferencias']): ?><p class="impe-embudo-muted">Sin horario declarado.</p><?php else: ?>
            <ul><?php foreach ($prospecto['preferencias'] as $p): ?><li><?= $esc($def($p['valor_original'])) ?> · <?= $esc($def($p['zona_horaria'])) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    </div>
    <p class="impe-embudo-note"><i class="bi bi-info-circle"></i>
        Los resultados y grabaciones se conservan en Telefonía. Esta ficha no los vincula automáticamente ni registra conversaciones por ahora.
        <?php if (!$supervisor && tienePermiso('telefonia.usar')): ?>
        <a href="<?= $esc(BASE_URL.'index.php?'.http_build_query([
              'controller'=>'telefonia','action'=>'misLlamadas',
              'prospecto_asignacion_id'=>(int)$prospecto['asignacion_id'],
              'q'=>(string)$prospecto['telefono_original']
           ])) ?>">Consultar llamadas del prospecto</a>.
        <?php endif; ?>
    </p>
    <?php endif; ?>
</div>
