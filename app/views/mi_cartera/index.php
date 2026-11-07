<?php
/** Bandeja de asignaciones propias: los filtros se aplicaron en el servidor. */
require_once __DIR__.'/../../helpers/ProspectoFechaHoraHelper.php';
$esc=static fn($x)=>htmlspecialchars((string)($x??''),ENT_QUOTES,'UTF-8');
$datos=$cartera??[];
$resumen=$datos['resumen']??['total'=>0,'atencion'=>0,'sin_fecha'=>0];
$filtros=$datos['filtros']??['vista'=>'todos','buscar'=>'','pagina'=>1];
$lista=$datos['registros']??[];
$vista=(string)($filtros['vista']??'todos');
$buscar=(string)($filtros['buscar']??'');
$base=BASE_URL.'index.php?';
$url=static fn($v,$pagina=1)=>$base.http_build_query([
    'controller'=>'miCartera','action'=>'index','vista'=>$v,
    'buscar'=>$buscar,'pagina'=>$pagina
]);
$texto=static fn($v)=>$v===null||$v===''?'Sin indicar':(string)$v;
$fecha=static fn($v)=>($v===null||$v==='')?'Sin programar':ProspectoFechaHoraHelper::aMexico($v);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/mi_cartera.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/mi_cartera.css'),0,16) ?>">
<div class="mi-cartera">
    <header class="mi-cartera-hero">
        <div>
            <span class="mi-cartera-eyebrow"><i class="bi bi-person-check" aria-hidden="true"></i> TU ESPACIO COMERCIAL</span>
            <h2>Mi cartera de prospectos</h2>
            <p>Aquí aparecen únicamente las personas que te han sido asignadas. El almacén general no forma parte de tu acceso.</p>
        </div>
        <div class="mi-cartera-hero-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
    </header>

    <?php if ($error!==''): ?>
    <div class="mi-cartera-notice mi-cartera-notice--error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?= $esc($error) ?></div>
    <?php endif; ?>
    <?php if (!$disponible && $error===''): ?>
    <div class="mi-cartera-notice" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>La cartera aún no está habilitada. No se han modificado tus asignaciones ni tus llamadas.</div>
    <?php endif; ?>

    <div class="mi-cartera-stats">
        <div class="mi-cartera-stat"><div class="mi-cartera-stat-icon"><i class="bi bi-people"></i></div><div><strong><?= (int)$resumen['total'] ?></strong><span>Asignaciones vigentes</span></div></div>
        <div class="mi-cartera-stat"><div class="mi-cartera-stat-icon"><i class="bi bi-calendar-event"></i></div><div><strong><?= (int)$resumen['atencion'] ?></strong><span>Hoy o vencidos</span></div></div>
        <div class="mi-cartera-stat"><div class="mi-cartera-stat-icon"><i class="bi bi-calendar-minus"></i></div><div><strong><?= (int)$resumen['sin_fecha'] ?></strong><span>Sin próxima fecha</span></div></div>
    </div>

    <section class="mi-cartera-panel">
        <div class="mi-cartera-panel-heading">
            <div><h3>Mis asignaciones</h3><p>Los datos se actualizan según las asignaciones vigentes a tu cuenta.</p></div>
            <span class="mi-cartera-count"><?= (int)($datos['total']??0) ?> resultados</span>
        </div>

        <nav class="mi-cartera-tabs" aria-label="Filtrar Mi cartera">
            <?php foreach ([
                'todos'=>'Todos',
                'hoy'=>'Hoy y vencidos',
                'proximos'=>'Próximos',
                'sin_fecha'=>'Sin programar'
            ] as $clave=>$etiqueta): ?>
            <a href="<?= $esc($url($clave)) ?>" <?= $vista===$clave?'aria-current="page"':'' ?>><?= $esc($etiqueta) ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="mi-cartera-busqueda" role="search">
            <input type="hidden" name="controller" value="miCartera">
            <input type="hidden" name="action" value="index">
            <input type="hidden" name="vista" value="<?= $esc($vista) ?>">
            <label for="miCarteraBuscar">Buscar entre mis prospectos</label>
            <div class="mi-cartera-busqueda-campos">
                <input id="miCarteraBuscar" type="search" name="buscar" maxlength="120"
                       placeholder="Nombre, teléfono o correo" value="<?= $esc($buscar) ?>">
                <button type="submit"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                <?php if ($buscar!==''): ?><a href="<?= $esc($url($vista)) ?>" class="mi-cartera-clear">Limpiar</a><?php endif; ?>
            </div>
        </form>

        <?php if (!$lista): ?>
        <div class="mi-cartera-empty">
            <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
            <h3><?= $vista!=='todos'||$buscar!==''?'No hay resultados con estos filtros':'Todavía no tienes prospectos asignados' ?></h3>
            <p><?= $vista!=='todos'||$buscar!==''?'Prueba con otro filtro o vuelve a Todos.':'Los prospectos aparecerán aquí cuando exista una asignación vigente a tu usuario. No se muestran registros de otros asesores.' ?></p>
        </div>
        <?php else: ?>
        <div class="mi-cartera-lista">
            <?php foreach ($lista as $r):
                $id=(int)$r['prospecto_id'];
                $detalle=$base.http_build_query(['controller'=>'miCartera','action'=>'detalle','id'=>$id]);
            ?>
            <article class="mi-cartera-fila">
                <div class="mi-cartera-fila-persona">
                    <span class="mi-cartera-fila-avatar"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <div>
                        <strong><?= $esc($r['nombre_completo']) ?></strong>
                        <small>Prospecto #<?= $id ?> · <?= $esc($texto($r['procedencia']??null)) ?></small>
                        <small class="mi-cartera-fila-programas"><?= $esc($texto($r['programas']??null)) ?></small>
                    </div>
                </div>
                <div class="mi-cartera-fila-col">
                    <span>Asignación</span>
                    <strong class="mi-cartera-pill"><?= $esc(str_replace('_',' ',(string)($r['estado_asignacion']??'ASIGNADO'))) ?></strong>
                </div>
                <div class="mi-cartera-fila-col">
                    <span>Próxima acción</span>
                    <strong><?= $esc($r['proxima_accion_at']?$fecha($r['proxima_accion_at']):($r['fecha_trabajo']??'Sin programar')) ?></strong>
                </div>
                <a class="mi-cartera-ver" href="<?= $esc($detalle) ?>" aria-label="Consultar ficha de <?= $esc($r['nombre_completo']) ?>">Ver ficha <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (($datos['paginas']??1)>1): ?>
        <nav class="mi-cartera-pagination" aria-label="Páginas de Mi cartera">
            <?php if ($datos['pagina']>1): ?><a href="<?= $esc($url($vista,$datos['pagina']-1)) ?>">Anterior</a><?php endif; ?>
            <span>Página <?= (int)$datos['pagina'] ?> de <?= (int)$datos['paginas'] ?></span>
            <?php if ($datos['pagina']<$datos['paginas']): ?><a href="<?= $esc($url($vista,$datos['pagina']+1)) ?>">Siguiente</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
    <p class="mi-cartera-footnote"><i class="bi bi-shield-check" aria-hidden="true"></i> Mi cartera es de consulta en esta primera entrega. La gestión de resultados y el reparto automático se incorporarán sin alterar el funcionamiento de Mis llamadas.</p>
</div>
