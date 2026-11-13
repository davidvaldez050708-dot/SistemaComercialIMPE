<?php
/**
 * Bandejas extraordinarias V3: prospectos propios, revisión exclusivamente manual.
 * Sin marcación directa ni reactivación de descartados desde esta vista.
 */
require_once __DIR__.'/../../helpers/EmbudoComercialAcceso.php';
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$recuperacion=$vista==='recuperacion';
$col=$tablero['columnas'][$recuperacion?'RECUPERACION':'DESCARTADO'];
$puedeTelefono=tienePermiso('telefonia.usar');
$base=BASE_URL.'index.php?';
$params=['controller'=>'embudoComercial','action'=>'index','vista'=>$vista,
    'origen'=>$filtros['origen'],'programa'=>$filtros['programa'],
    'buscar'=>$filtros['buscar'],'vencidos'=>$filtros['vencidos']?'1':'0'];
$paginar=static function(int $pagina)use($base,$params,$recuperacion){
    $p=$params;
    $campo=$recuperacion?'p_recuperacion':'p_descartado';
    if($pagina>1)$p[$campo]=$pagina;
    return $base.http_build_query($p);
};
?>
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial_compacto_v1.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial_compacto_v1.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_v3_bandejas.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_v3_bandejas.css'),0,16) ?>">
<div class="impe-embudo impe-embudo--compact impe-embudo--v3-bandejas" data-v3-bandejas>
    <nav class="impe-embudo-tabs" aria-label="Vistas comerciales">
        <a href="<?= $esc($base.http_build_query(['controller'=>'embudoComercial',
            'action'=>'index','vista'=>'embudo'])) ?>">
            <i class="bi bi-kanban" aria-hidden="true"></i> Embudo de ventas</a>
        <?php if(EmbudoComercialAcceso::puede('recuperacion')): ?>
        <a href="<?= $esc($base.http_build_query(['controller'=>'embudoComercial',
            'action'=>'index','vista'=>'recuperacion'])) ?>"
            <?= $recuperacion?'aria-current="page"':'' ?>>
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recuperación</a>
        <?php endif; ?>
        <?php if(EmbudoComercialAcceso::puede('descartados')): ?>
        <a href="<?= $esc($base.http_build_query(['controller'=>'embudoComercial',
            'action'=>'index','vista'=>'descartados'])) ?>"
            <?= !$recuperacion?'aria-current="page"':'' ?>>
            <i class="bi bi-archive" aria-hidden="true"></i> Descartados</a>
        <?php endif; ?>
    </nav>
    <?php if($error!==''): ?>
        <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><?= $esc($error) ?></p>
    <?php elseif(!$disponible): ?>
        <p class="impe-embudo-alert" role="status">La estructura comercial no está disponible.</p>
    <?php else: ?>
    <form class="impe-embudo-filtros" action="<?= $esc(BASE_URL.'index.php') ?>" method="get" role="search">
        <input type="hidden" name="controller" value="embudoComercial">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="vista" value="<?= $esc($vista) ?>">
        <label>Origen
            <select name="origen">
                <?php foreach([''=>'Todos','META_ADS'=>'Meta','ALIADO'=>'Aliados'] as $v=>$nombre): ?>
                    <option value="<?= $esc($v) ?>" <?= $filtros['origen']===$v?'selected':'' ?>><?= $esc($nombre) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Programa educativo
            <select name="programa">
                <option value="">Todos</option>
                <?php foreach($opciones['programas'] as $o): ?>
                    <option value="<?= $esc($o['codigo']) ?>" <?= $filtros['programa']===$o['codigo']?'selected':'' ?>>
                        <?= $esc($o['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Buscar prospecto
            <input type="search" name="buscar" maxlength="120"
                   value="<?= $esc($filtros['buscar']) ?>" placeholder="Nombre, teléfono o correo">
        </label>
        <label class="impe-embudo-check">
            <input type="checkbox" name="vencidos" value="1"
                   <?= $filtros['vencidos']?'checked':'' ?>> Solo vencidos
        </label>
        <button type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
        <a class="impe-embudo-reset" href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>$vista])) ?>">Limpiar</a>
    </form>
    <div class="impe-embudo-board-info">
        <span><i class="bi bi-people" aria-hidden="true"></i>
            <?= (int)$col['total'] ?> prospectos asignados</span>
        <small>20 por página · sin llamadas ni mensajes automáticos</small>
    </div>
    <div class="impe-embudo-board impe-embudo-board--single">
        <section class="impe-embudo-lane" aria-label="<?= $esc($col['etiqueta']) ?>">
            <div class="impe-embudo-lane-head">
                <div>
                    <h3><?= $esc($col['etiqueta']) ?></h3>
                    <small><?= $recuperacion?'Pendientes de decisión acreditada':'Historial conservado; sin reactivación directa' ?></small>
                </div>
                <span class="impe-embudo-lane-count"><?= (int)$col['total'] ?></span>
            </div>
            <div class="impe-embudo-lane-list">
                <?php if(!$col['registros']): ?>
                <div class="impe-embudo-empty"><i class="bi bi-inbox" aria-hidden="true"></i>
                    <span>No hay prospectos en esta bandeja</span></div>
                <?php endif; ?>
                <?php foreach($col['registros'] as $p):
                    $legacy=(string)($p['etapa_original']??'')==='EN_CONTACTO';
                    $noContactar=(string)($p['subetapa']??'')==='NO_CONTACTAR';
                    $telefono=trim((string)($p['telefono_original']??''));
                    $misLlamadas=$base.http_build_query([
                        'controller'=>'telefonia','action'=>'misLlamadas',
                        'prospecto_asignacion_id'=>(int)$p['asignacion_id'],
                        'q'=>$telefono
                    ]);
                    $detalle=$base.http_build_query([
                        'controller'=>'embudoComercial','action'=>'detalle',
                        'vista'=>$vista,'id'=>(int)$p['prospecto_id']]);
                ?>
                <article class="impe-embudo-card impe-v3b-card"
                    data-asignacion-id="<?= (int)$p['asignacion_id'] ?>"
                    data-etapa="<?= $esc((string)($p['etapa_original']??($recuperacion?'RECUPERACION':'DESCARTADO'))) ?>"
                    data-nombre="<?= $esc($p['nombre_completo']) ?>"
                    data-no-contactar="<?= $noContactar?'1':'0' ?>">
                    <div class="impe-embudo-card-line">
                        <a class="impe-embudo-persona" href="<?= $esc($detalle) ?>">
                            <?= $esc($p['nombre_completo']) ?></a>
                        <?php if($legacy): ?>
                            <span class="impe-v3b-badge">Registro histórico · revisión</span>
                        <?php endif; ?>
                        <?php if($noContactar): ?>
                            <span class="impe-v3b-badge impe-v3b-badge--stop">
                                <i class="bi bi-shield-lock" aria-hidden="true"></i> No contactar
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="impe-embudo-programa"><i class="bi bi-mortarboard" aria-hidden="true"></i>
                        <?= $esc(trim((string)($p['programas']??''))?:'Programa pendiente') ?></p>
                    <?php if($recuperacion && !empty($p['horario_contacto'])): ?>
                    <p class="impe-v3b-horario"><i class="bi bi-clock" aria-hidden="true"></i>
                        Horario solicitado: <?= $esc($p['horario_contacto']) ?></p>
                    <?php endif; ?>
                    <div class="impe-embudo-card-bottom">
                        <span class="impe-embudo-next">
                            <i class="bi bi-calendar3" aria-hidden="true"></i>
                            <?php if($recuperacion && !empty($p['revision_fecha'])): ?>
                                Revisar: <?= $esc($p['revision_fecha']) ?>
                            <?php else: ?>
                                Fecha de asignación: <?= $esc(substr((string)($p['fecha_trabajo']??''),0,10)) ?>
                            <?php endif; ?>
                        </span>
                        <div class="impe-embudo-card-actions impe-v3b-acciones">
                            <?php if($puedeTelefono): ?>
                                <a href="<?= $esc($misLlamadas) ?>"
                                    title="Mis llamadas y resultados de esta asignación"
                                    aria-label="Mis llamadas y resultados">
                                    <i class="bi bi-journal-check" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <a href="<?= $esc($detalle) ?>" title="Ficha e historial"
                               aria-label="Ver ficha e historial">
                                <i class="bi bi-person-vcard" aria-hidden="true"></i></a>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if($col['paginas']>1): ?>
            <nav class="impe-v3b-paginacion" aria-label="Paginación de bandeja">
                <?php if($col['pagina']>1): ?>
                    <a href="<?= $esc($paginar($col['pagina']-1)) ?>">Anterior</a>
                <?php endif; ?>
                <span><?= (int)$col['pagina'] ?> / <?= (int)$col['paginas'] ?></span>
                <?php if($col['pagina']<$col['paginas']): ?>
                    <a href="<?= $esc($paginar($col['pagina']+1)) ?>">Siguiente</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
<script defer src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_v3_bandejas.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/embudo_comercial_v3_bandejas.js'),0,16) ?>"></script>
