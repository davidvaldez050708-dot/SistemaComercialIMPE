<?php
/**
 * PREVISUALIZACION V3 - SOLO LECTURA, para supervisores previamente autorizados.
 * No contiene telefonía, formulario de resultado ni movimiento de tarjetas.
 */
require_once __DIR__.'/../../services/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$subetapas=EmbudoComercialPoliticaPropuesta::SUBETAPAS;
$base=BASE_URL.'index.php?';
$fParams=[
    'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo',
    'previsualizacion_v3'=>'1','origen'=>$filtros['origen'],
    'programa'=>$filtros['programa'],'buscar'=>$filtros['buscar'],
    'vencidos'=>$filtros['vencidos']?'1':'0'
];
if($filtros['asesor']>0)$fParams['asesor']=(string)$filtros['asesor'];
?>
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial_compacto_v1.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial_compacto_v1.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_v3_indicadores.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_v3_indicadores.css'),0,16) ?>">
<div class="impe-embudo impe-embudo--compact impe-embudo--v3-preview">
    <div class="impe-embudo-alert" role="status">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <strong>Vista previa de las cinco etapas · Solo lectura.</strong>
        No cambia la etapa real de ningún prospecto, no activa los tres intentos
        y no permite mover tarjetas ni generar inscripciones.
        <a href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo'
        ])) ?>">Volver al embudo actual</a>
    </div>
    <?php if($error!==''): ?>
    <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><?= $esc($error) ?></p>
    <?php elseif(!$disponible): ?>
    <p class="impe-embudo-alert" role="status">El módulo comercial aún no tiene estructura completa.</p>
    <?php else: ?>
    <form class="impe-embudo-filtros" method="get" action="<?= $esc(BASE_URL.'index.php') ?>" role="search">
        <input type="hidden" name="controller" value="embudoComercial">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="vista" value="embudo">
        <input type="hidden" name="previsualizacion_v3" value="1">
        <label>Origen
            <select name="origen">
                <?php foreach([''=>'Todos','META_ADS'=>'Meta','ALIADO'=>'Aliados'] as $value=>$label): ?>
                <option value="<?= $esc($value) ?>" <?= $filtros['origen']===$value?'selected':'' ?>><?= $esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Programa educativo
            <select name="programa">
                <option value="">Todos los programas</option>
                <?php foreach($opciones['programas'] as $pr): ?>
                <option value="<?= $esc($pr['codigo']) ?>" <?= $filtros['programa']===$pr['codigo']?'selected':'' ?>><?= $esc($pr['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Asesor
            <select name="asesor">
                <option value="">Todos los asesores</option>
                <?php foreach($opciones['asesores'] as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filtros['asesor']===(int)$u['id']?'selected':'' ?>><?= $esc($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Buscar prospecto
            <input type="search" name="buscar" value="<?= $esc($filtros['buscar']) ?>" maxlength="120" placeholder="Nombre, teléfono o correo">
        </label>
        <label class="impe-embudo-check">
            <input type="checkbox" name="vencidos" value="1" <?= $filtros['vencidos']?'checked':'' ?>> Solo vencidos
        </label>
        <button type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
        <a class="impe-embudo-reset" href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo',
            'previsualizacion_v3'=>'1'
        ])) ?>">Limpiar</a>
    </form>

    <div class="impe-embudo-board-info">
        <span><i class="bi bi-people" aria-hidden="true"></i>
            <?= number_format((int)$tablero['total_activos']) ?> asignaciones del embudo o pendientes de clasificación
        </span>
        <small>Vista diagnóstica · <?= $esc(EmbudoComercialPresentacion::fechaLocal($tablero['dia_indicadores']??'')) ?> · hasta 20 tarjetas por grupo</small>
    </div>
    <div class="impe-embudo-board" role="region" tabindex="0"
         aria-label="Previsualización de las cinco etapas principales">
        <?php foreach (['NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO','PROCESO_INSCRIPCION'] as $etapa):
            $col=$tablero['columnas'][$etapa]; ?>
        <section class="impe-embudo-lane impe-embudo-lane--<?= $esc(strtolower($etapa)) ?>" aria-label="<?= $esc($col['etiqueta']) ?>">
            <div class="impe-embudo-lane-head">
                <div>
                    <h3><?= $esc($col['etiqueta']) ?></h3>
                    <small><?= $etapa==='NUEVO'?'Sin conversación acreditada':
                        ($etapa==='POTENCIAL'?'Calificación requerida':
                        ($etapa==='PROCESO_INSCRIPCION'?'Trámite institucional iniciado':
                        'Situación comercial vigente')) ?></small>
                </div>
                <span class="impe-embudo-lane-count"><?= number_format((int)$col['total']) ?></span>
            </div>
            <div class="impe-embudo-lane-list">
                <?php if(!$col['registros']): ?>
                <div class="impe-embudo-empty"><i class="bi bi-inbox" aria-hidden="true"></i><span>Sin prospectos</span></div>
                <?php else: foreach($col['registros'] as $p):
                    $sub=(string)($p['subetapa']??'');
                    $subLabel=$sub!=='' && isset($subetapas[$etapa][$sub])
                        ?$subetapas[$etapa][$sub]:'Subetapa pendiente'; ?>
                <article class="impe-embudo-card" aria-label="<?= $esc($p['nombre_completo']) ?>">
                    <div class="impe-embudo-card-line">
                        <strong class="impe-embudo-persona"><?= $esc($p['nombre_completo']) ?></strong>
                    </div>
                    <p class="impe-embudo-programa">
                        <i class="bi bi-mortarboard" aria-hidden="true"></i>
                        <?= $esc(trim((string)($p['programas']??''))?:'Programa pendiente') ?>
                    </p>
                    <p class="impe-v3-subetapa">
                        <i class="bi bi-bookmark-check" aria-hidden="true"></i>
                        <span><?= $esc($subLabel) ?></span>
                    </p>
                    <?php $conteo=$p['intentos_hoy']??null;
                          $n=$conteo!==null?(int)$conteo['intentos']:null;
                          $mostrados=$n===null?0:min(3,max(0,$n)); ?>
                    <?php if($etapa==='NUEVO'): ?>
                    <div class="impe-v3-intentos" aria-label="Intentos sin contacto verificados de la jornada">
                        <div class="impe-v3-intentos-linea">
                            <span>Sin contacto hoy</span>
                            <span class="impe-v3-intentos-valor"><?= $n===null?'No disponible':$esc((string)$n.'/3') ?></span>
                        </div>
                        <?php if($n!==null): ?>
                        <div class="impe-v3-intentos-barra" role="progressbar"
                             aria-label="Intentos sin contacto"
                             aria-valuemin="0" aria-valuemax="3"
                             aria-valuenow="<?= $mostrados ?>">
                            <span class="impe-v3-intentos-avance" style="width:<?= (int)round($mostrados/3*100) ?>%"></span>
                        </div>
                        <span class="impe-v3-intentos-ayuda">Solo llamadas salientes PBX confirmadas y con resultado registrado.</span>
                        <?php endif; ?>
                    </div>
                    <?php elseif($n!==null && $n>0): ?>
                    <div class="impe-v3-intentos">
                        <div class="impe-v3-intentos-linea">
                            <span>Intentos sin conversación hoy</span>
                            <span class="impe-v3-intentos-valor"><?= (int)$n ?></span>
                        </div>
                        <span class="impe-v3-intentos-ayuda">Este indicador no modifica ni reduce la etapa comercial.</span>
                    </div>
                    <?php endif; ?>
                    <?php if(!empty($p['proxima_accion_at'])): ?>
                    <p class="impe-v3-fecha">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <span>Próxima acción: <?= $esc(EmbudoComercialPresentacion::fechaUtc($p['proxima_accion_at'])) ?></span>
                    </p>
                    <?php endif; ?>
                    <?php if(!empty($p['revision_fecha'])): ?>
                    <p class="impe-v3-fecha">
                        <i class="bi bi-calendar2-check" aria-hidden="true"></i>
                        <span>Revisión: <?= $esc(EmbudoComercialPresentacion::fechaLocal($p['revision_fecha'])) ?></span>
                    </p>
                    <?php endif; ?>
                </article>
                <?php endforeach; endif; ?>
                <?php if($col['truncado']): ?>
                <div class="impe-embudo-alert" role="status">Hay más registros. Esta vista previa muestra únicamente 20; los totales incluyen todos.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <?php $legacy=$tablero['columnas']['REVISION_LEGACY']; ?>
    <?php if($legacy['total']>0): ?>
    <section class="impe-embudo-alert" aria-label="Prospectos antiguos pendientes de conciliación">
        <h3>Clasificación pendiente: <?= (int)$legacy['total'] ?></h3>
        <p>Estos registros mantienen su etapa original en la base de datos.
           <strong>INTERESADO</strong> no se convierte automáticamente en Potenciales y
           <strong>PAGO_CONFIRMADO</strong> no acredita por sí solo un trámite institucional.</p>
        <?php foreach($legacy['registros'] as $p): ?>
        <p>
            <strong><?= $esc($p['nombre_completo']) ?></strong>
            · Etapa original: <?= $esc($p['etapa_original']) ?>
            · Pendiente de validación comercial
        </p>
        <?php endforeach; ?>
        <?php if($legacy['truncado']): ?>
        <p>Existen registros adicionales pendientes; consulte la bitácora autorizada.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <p class="impe-embudo-note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        Recuperación: <?= (int)$tablero['externos']['RECUPERACION'] ?>
        · Descartados: <?= (int)$tablero['externos']['DESCARTADO'] ?>.
        Estas bandejas siguen separadas del embudo de cinco columnas.
        La vista previa nunca escribe datos, marca llamadas ni confirma pagos.
    </p>
    <?php endif; ?>
</div>
