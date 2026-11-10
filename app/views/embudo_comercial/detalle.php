<?php
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
require_once __DIR__.'/../../services/TelefoniaResultadoVentasService.php';
$esc=static fn($x)=>htmlspecialchars((string)($x??''),ENT_QUOTES,'UTF-8');
$def=static fn($x)=>trim((string)($x??''))!==''?(string)$x:'Sin indicar';
$fecha=static fn($x)=>EmbudoComercialPresentacion::fechaUtc($x);
$dia=static fn($x)=>EmbudoComercialPresentacion::fechaLocal($x);
/*
 * Traducción exclusiva de la vista: la bitácora conserva el motivo PBX
 * íntegro para auditoría y los movimientos manuales quedan intactos.
 * Es un catálogo de presentación, no altera Telefonía ni decisiones de etapa.
 */
$presentarMotivo=static function($valor): array {
    $original=(string)($valor??'');
    if (!preg_match('/^Resultado PBX verificado: ([A-Z0-9_]{3,36}) · ref ([A-Za-z0-9_-]{6,100})$/Du',
        $original,$m)) {
        return ['descripcion'=>$original,'referencia_tecnica'=>null];
    }
    // Catálogo V4 y etiquetas históricas: los resultados del asesor
    // se presentan legibles sin alterar el código PBX en la bitácora.
    $etiquetas=TelefoniaResultadoVentasService::opciones();
    $etiquetas['CONVERSACION_PERSONA']='Se habló con el prospecto';
    $etiquetas['REQUIERE_SEGUIMIENTO']='En seguimiento';
    $etiqueta=$etiquetas[$m[1]]??'Resultado telefónico no identificado';
    return [
        'descripcion'=>'Llamada comercial — '.$etiqueta,
        'referencia_tecnica'=>$original
    ];
};
$volver=BASE_URL.'index.php?'.http_build_query([
    'controller'=>'embudoComercial','action'=>'index','vista'=>$vista
]);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<style>
.impe-embudo .impe-embudo-timeline-tecnico{margin:0 0 8px;color:#7187a5;font:600 10px/1.5 Manrope,sans-serif}
.impe-embudo .impe-embudo-timeline-tecnico summary{display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:#607b9d;list-style:none;width:fit-content}
.impe-embudo .impe-embudo-timeline-tecnico summary::-webkit-details-marker{display:none}
.impe-embudo .impe-embudo-timeline-tecnico summary::before{content:"▸";font-size:11px}
.impe-embudo .impe-embudo-timeline-tecnico[open] summary::before{content:"▾"}
.impe-embudo .impe-embudo-timeline-tecnico summary:focus-visible{outline:2px solid #9dbdf3;outline-offset:3px;border-radius:3px}
.impe-embudo .impe-embudo-timeline-tecnico code{display:block;max-width:100%;margin-top:6px;padding:7px 9px;color:#506b8e;background:#eff4fc;border:1px solid #dce7f4;border-radius:7px;font:500 10px/1.5 ui-monospace,monospace;overflow-wrap:anywhere;white-space:pre-wrap}
</style>
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
                <div><dt>Jornada de trabajo</dt><dd><?= $esc($dia($prospecto['fecha_trabajo'])) ?></dd></div>
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
        <section class="impe-embudo-detalles-wide impe-embudo-historial" aria-labelledby="impe-embudo-historial-titulo">
            <div class="impe-embudo-historial-head">
                <div>
                    <h3 id="impe-embudo-historial-titulo">Historial de movimientos</h3>
                    <p>Registro de cambios de etapa de esta asignación comercial.</p>
                </div>
                <?php if (!empty($prospecto['historial_movimientos'])): ?>
                <span class="impe-embudo-historial-total"><?= count($prospecto['historial_movimientos']) ?> movimientos<?= !empty($prospecto['historial_truncado'])?' recientes':'' ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($prospecto['historial_movimientos'])): ?>
            <p class="impe-embudo-muted">Todavía no se han registrado cambios de etapa.</p>
            <?php else: ?>
            <ol class="impe-embudo-timeline">
                <?php foreach ($prospecto['historial_movimientos'] as $mov):
                    $etapaAnterior=EmbudoComercialRepository::ETAPAS[$mov['etapa_anterior']]??str_replace('_',' ',$mov['etapa_anterior']);
                    $etapaNueva=EmbudoComercialRepository::ETAPAS[$mov['etapa_nueva']]??str_replace('_',' ',$mov['etapa_nueva']);
                    $motivoPresentado=$presentarMotivo($mov['motivo']??'');
                    $autor=trim((string)($mov['autor']??''));
                    if ($autor==='') $autor='Usuario #'.(int)$mov['usuario_id'];
                ?>
                <li class="impe-embudo-timeline-item">
                    <div class="impe-embudo-timeline-main">
                        <strong><?= $esc($etapaAnterior) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i> <?= $esc($etapaNueva) ?></strong>
                        <time datetime="<?= $esc(str_replace(' ','T',(string)$mov['creado_at']).'Z') ?>"><?= $esc($fecha($mov['creado_at'])) ?></time>
                    </div>
                    <p class="impe-embudo-timeline-motivo"><?= $esc($motivoPresentado['descripcion']) ?></p>
                    <?php if ($motivoPresentado['referencia_tecnica']!==null): ?>
                    <details class="impe-embudo-timeline-tecnico">
                        <summary>Ver referencia técnica</summary>
                        <code><?= $esc($motivoPresentado['referencia_tecnica']) ?></code>
                    </details>
                    <?php endif; ?>
                    <span class="impe-embudo-timeline-autor"><i class="bi bi-person" aria-hidden="true"></i> <?= $esc($autor) ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php if (!empty($prospecto['historial_truncado'])): ?>
            <p class="impe-embudo-historial-aviso">Se muestran los últimos 50 movimientos. Los anteriores permanecen registrados en la base de datos.</p>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <p class="impe-embudo-note"><i class="bi bi-info-circle"></i>
        Los resultados y grabaciones se conservan en Telefonía. La relación con el embudo requiere una llamada PBX finalizada, un resultado registrado por el asesor y la asignación explícita al prospecto; no se infiere ninguna conversación.
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
