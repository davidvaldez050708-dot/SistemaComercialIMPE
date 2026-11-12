<?php
/**
 * Gestión comercial V3: pantalla de TRABAJO del asesor, acceso voluntario.
 * Conserva el teléfono del shell, Mis llamadas y movimientos V1 seguros.
 * Nunca confirma Potenciales/pagos/Inscritos ni modifica resultados PBX.
 */
require_once __DIR__.'/../../services/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
require_once __DIR__.'/../../services/EmbudoComercialV3MovimientosPermitidos.php';
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$sub=EmbudoComercialPoliticaPropuesta::SUBETAPAS;
$base=BASE_URL.'index.php?';
$params=[
    'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo',
    'operativo_v3'=>'1',
    'origen'=>$filtros['origen'],'programa'=>$filtros['programa'],
    'buscar'=>$filtros['buscar'],'vencidos'=>$filtros['vencidos']?'1':'0'
];
foreach($paginasV3 as $clave=>$n){
    if($n>1)$params['p3_'.strtolower($clave)]=$n;
}
$paginar=static function($etapa,$num)use($base,$params){
    $p=$params;$key='p3_'.strtolower($etapa);
    if($num<=1)unset($p[$key]);else $p[$key]=$num;
    return $base.http_build_query($p);
};
$buscarTel=static function($tel){
    $v=trim((string)($tel??''));
    if($v==='' || !preg_match('/^\+?[0-9 ().-]+$/D',$v))return $v;
    $digits=preg_replace('/\D+/','',$v)??'';
    return strlen($digits)===12 && substr($digits,0,2)==='52'
        ? substr($digits,2):$v;
};
$puedeTelefono=tienePermiso('telefonia.usar');
$puedeLlamar=$puedeTelefono&&tienePermiso('telefonia.salientes');
$puedeMover=tienePermiso('ventas.embudo.mover');
$contenedorStage=[
    'NUEVO'=>['bi-person-plus','Sin primera conversación acreditada'],
    'CONTACTADO'=>['bi-telephone-inbound','Primer contacto y calificación'],
    'POTENCIAL'=>['bi-star','Interés y requisitos por acreditar'],
    'SEGUIMIENTO'=>['bi-calendar-check','Próximas gestiones programadas'],
    'PROCESO_INSCRIPCION'=>['bi-file-earmark-check','Trámite institucional acreditado']
];
$tarjeta=function(array $p,string $etapa,bool $legacy=false)use(
    $esc,$base,$sub,$buscarTel,$puedeTelefono,$puedeLlamar,$puedeMover
){
    $real=(string)($p['etapa_original']??'NUEVO');
    if($real==='')$real='NUEVO';
    $propuestas=$legacy?[]:EmbudoComercialV3MovimientosPermitidos::destinos($real);
    if(!$puedeMover)$propuestas=[];
    $destinos=implode(',',$propuestas);
    $detalle=$base.http_build_query([
        'controller'=>'embudoComercial','action'=>'detalle',
        'vista'=>'embudo','operativo_v3'=>'1','id'=>(int)$p['prospecto_id']
    ]);
    $historial=$base.http_build_query([
        'controller'=>'telefonia','action'=>'misLlamadas',
        'prospecto_asignacion_id'=>(int)$p['asignacion_id'],
        'q'=>$buscarTel($p['telefono_original']??'')
    ]);
    $st=(string)($p['subetapa']??'');
    $label=$st!==''&&isset($sub[$etapa][$st])
        ?$sub[$etapa][$st]:'Subetapa pendiente';
    $n=(int)($p['intentos_hoy']['intentos']??0);
    $n=max(0,$n);
    $avance=min(3,$n);
    $origen=(string)($p['tipo_origen']??'');
    ?>
    <article class="impe-embudo-card impe-v3o-card"
             data-asignacion-id="<?= (int)$p['asignacion_id'] ?>"
             data-etapa="<?= $esc($real) ?>"
             data-nombre="<?= $esc($p['nombre_completo']) ?>"
             data-v3-destinos="<?= $esc($destinos) ?>">
        <div class="impe-embudo-card-line">
            <a href="<?= $esc($detalle) ?>" class="impe-embudo-persona"
               title="Ver ficha comercial"><?= $esc($p['nombre_completo']) ?></a>
            <span class="impe-embudo-source <?= $origen==='META_ADS'?'is-meta':($origen==='ALIADO'?'is-aliado':'') ?>">
                <?= $origen==='META_ADS'?'Meta':($origen==='ALIADO'?'Aliado':'Registro') ?>
            </span>
        </div>
        <p class="impe-embudo-programa" title="<?= $esc($p['programas']??'') ?>">
            <i class="bi bi-mortarboard" aria-hidden="true"></i>
            <?= $esc(trim((string)($p['programas']??''))?:'Programa pendiente') ?>
        </p>
        <div class="impe-v3o-sub">
            <i class="bi bi-bookmark-check" aria-hidden="true"></i>
            <span><?= $esc($legacy?'Requiere conciliación comercial':$label) ?></span>
        </div>
        <?php if($etapa==='NUEVO' && !$legacy): ?>
        <div class="impe-v3o-metrica">
            <div class="impe-v3o-metrica-titulo"><span>Intentos sin contacto hoy</span><strong><?= $n ?>/3</strong></div>
            <div class="impe-v3o-barra" role="progressbar" aria-label="Intentos PBX verificados"
                 aria-valuemin="0" aria-valuemax="3" aria-valuenow="<?= $avance ?>">
                <span style="width:<?= (int)round($avance*100/3) ?>%"></span>
            </div>
            <small>Indicador PBX, no activa cambios de etapa</small>
        </div>
        <?php elseif($n>0): ?>
        <p class="impe-v3o-metrica-breve"><i class="bi bi-telephone-x" aria-hidden="true"></i>
            <?= $n ?> intento<?= $n===1?'':'s' ?> sin conversación hoy</p>
        <?php endif; ?>
        <?php if(!empty($p['horario_contacto'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-clock" aria-hidden="true"></i>
            Horario solicitado: <?= $esc($p['horario_contacto']) ?></p>
        <?php endif; ?>
        <?php if(!empty($p['proxima_accion_at'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-calendar-event" aria-hidden="true"></i>
            Próxima acción: <?= $esc(EmbudoComercialPresentacion::fechaUtc($p['proxima_accion_at'])) ?></p>
        <?php elseif(!empty($p['revision_fecha'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            Revisar: <?= $esc(EmbudoComercialPresentacion::fechaLocal($p['revision_fecha'])) ?></p>
        <?php else: ?>
        <p class="impe-v3o-meta"><i class="bi bi-calendar3" aria-hidden="true"></i>
            Jornada: <?= $esc(EmbudoComercialPresentacion::fechaLocal($p['fecha_trabajo']??'')) ?></p>
        <?php endif; ?>
        <div class="impe-v3o-acciones">
            <?php if($puedeLlamar && trim((string)($p['telefono_original']??''))!==''): ?>
            <button type="button" class="impe-v3o-accion impe-v3o-accion--llamar"
                    data-embudo-marcar="<?= $esc($p['telefono_original']) ?>"
                    title="Abrir teléfono persistente para este prospecto">
                <i class="bi bi-telephone" aria-hidden="true"></i><span>Llamar</span>
            </button>
            <?php endif; ?>
            <?php if($puedeTelefono): ?>
            <a class="impe-v3o-accion" href="<?= $esc($historial) ?>"
               title="Consultar llamadas y registrar resultados">
                <i class="bi bi-journal-check" aria-hidden="true"></i><span>Mis llamadas</span>
            </a>
            <?php endif; ?>
            <a class="impe-v3o-accion" href="<?= $esc($detalle) ?>" title="Ver ficha e historial">
                <i class="bi bi-person-vcard" aria-hidden="true"></i><span>Ficha</span>
            </a>
            <?php if($propuestas): ?>
            <button type="button" class="impe-v3o-accion"
                data-v3-mover title="Cambiar etapa con registro de motivo">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Mover</span>
            </button>
            <?php endif; ?>
        </div>
    </article>
    <?php
};
?>
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_comercial_compacto_v1.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial_compacto_v1.css'),0,16) ?>">
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_v3_operativo.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_v3_operativo.css'),0,16) ?>">
<div class="impe-embudo impe-embudo--compact impe-embudo--v3-operativo">
    <nav class="impe-embudo-tabs" aria-label="Vistas comerciales">
        <a aria-current="page" href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo','operativo_v3'=>'1'
        ])) ?>"><i class="bi bi-kanban" aria-hidden="true"></i> Embudo de ventas</a>
        <?php if(EmbudoComercialAcceso::puede('recuperacion')): ?>
        <a href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'recuperacion'
        ])) ?>"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recuperación</a>
        <?php endif; ?>
        <?php if(EmbudoComercialAcceso::puede('descartados')): ?>
        <a href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'descartados'
        ])) ?>"><i class="bi bi-archive" aria-hidden="true"></i> Descartados</a>
        <?php endif; ?>
    </nav>
    <div class="impe-v3o-encabezado" role="status">
        <div>
            <span class="impe-v3o-etiqueta"><i class="bi bi-shield-check" aria-hidden="true"></i>
                Gestión comercial · Vista operativa V3</span>
            <p>Trabaja tus prospectos asignados con el teléfono y registro actuales.
               Las etapas que requieren validación institucional permanecen protegidas.</p>
        </div>
        <a href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo'
        ])) ?>" class="impe-v3o-volver"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
           Volver al tablero anterior</a>
    </div>
    <?php if($error!==''): ?>
    <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><?= $esc($error) ?></p>
    <?php elseif(!$disponible): ?>
    <p class="impe-embudo-alert" role="status">La estructura V3 no está disponible; utiliza el tablero anterior.</p>
    <?php else: ?>
    <form class="impe-embudo-filtros" action="<?= $esc(BASE_URL.'index.php') ?>" method="get" role="search">
        <input type="hidden" name="controller" value="embudoComercial">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="vista" value="embudo">
        <input type="hidden" name="operativo_v3" value="1">
        <label>Origen
            <select name="origen">
                <?php foreach([''=>'Todos','META_ADS'=>'Meta','ALIADO'=>'Aliados'] as $v=>$name): ?>
                <option value="<?= $esc($v) ?>" <?= $filtros['origen']===$v?'selected':'' ?>><?= $esc($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Programa educativo
            <select name="programa">
                <option value="">Todos los programas</option>
                <?php foreach($opciones['programas'] as $o): ?>
                <option value="<?= $esc($o['codigo']) ?>" <?= $filtros['programa']===$o['codigo']?'selected':'' ?>><?= $esc($o['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Buscar prospecto
            <input type="search" name="buscar" maxlength="120"
                   value="<?= $esc($filtros['buscar']) ?>" placeholder="Nombre, teléfono o correo">
        </label>
        <label class="impe-embudo-check">
            <input type="checkbox" name="vencidos" value="1" <?= $filtros['vencidos']?'checked':'' ?>> Solo vencidos
        </label>
        <button type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
        <a class="impe-embudo-reset" href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo','operativo_v3'=>'1'
        ])) ?>">Limpiar</a>
    </form>
    <div class="impe-embudo-board-info">
        <span><i class="bi bi-people" aria-hidden="true"></i>
            <?= (int)$tablero['total_activos'] ?> asignaciones vigentes bajo tu responsabilidad</span>
        <small>Hasta 20 tarjetas por columna · Usa Mis llamadas para registrar el resultado V4</small>
    </div>
    <div class="impe-embudo-board" role="region" tabindex="0"
         aria-label="Cinco etapas del embudo de ventas">
        <?php foreach($contenedorStage as $clave=>$conf):
            $col=$tablero['columnas'][$clave]; ?>
        <section class="impe-embudo-lane impe-v3o-columna" aria-label="<?= $esc($col['etiqueta']) ?>">
            <div class="impe-embudo-lane-head">
                <div>
                    <h3><i class="bi <?= $esc($conf[0]) ?>" aria-hidden="true"></i>
                        <?= $esc($col['etiqueta']) ?></h3>
                    <small><?= $esc($conf[1]) ?></small>
                </div>
                <span class="impe-embudo-lane-count"><?= (int)$col['total'] ?></span>
            </div>
            <div class="impe-embudo-lane-list">
                <?php if(!$col['registros']): ?>
                    <div class="impe-embudo-empty"><i class="bi bi-inbox" aria-hidden="true"></i><span>Sin prospectos</span></div>
                <?php else: ?>
                    <?php foreach($col['registros'] as $p):$tarjeta($p,$clave);endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if($col['paginas']>1): ?>
            <nav class="impe-v3o-paginacion" aria-label="Páginas de <?= $esc($col['etiqueta']) ?>">
                <?php if($col['pagina']>1): ?>
                <a href="<?= $esc($paginar($clave,$col['pagina']-1)) ?>" title="Anterior">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <?php endif; ?>
                <span><?= (int)$col['pagina'] ?> / <?= (int)$col['paginas'] ?></span>
                <?php if($col['pagina']<$col['paginas']): ?>
                <a href="<?= $esc($paginar($clave,$col['pagina']+1)) ?>" title="Siguiente">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>
    <?php $legacy=$tablero['columnas']['REVISION_LEGACY'];if($legacy['total']>0): ?>
    <section class="impe-v3o-legado" aria-label="Prospectos pendientes de clasificación">
        <div class="impe-v3o-legado-cabecera">
            <div><h3><i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                Clasificación pendiente <span><?= (int)$legacy['total'] ?></span></h3>
                <p>Conservan su etapa histórica. Puedes llamar y consultar sus resultados, sin ascenderlos automáticamente.</p>
            </div>
        </div>
        <div class="impe-v3o-legado-tarjetas">
            <?php foreach($legacy['registros'] as $p):$tarjeta($p,'REVISION_LEGACY',true);endforeach; ?>
        </div>
        <?php if($legacy['paginas']>1): ?>
        <nav class="impe-v3o-paginacion" aria-label="Páginas de clasificación pendiente">
            <?php if($legacy['pagina']>1): ?>
            <a href="<?= $esc($paginar('REVISION_LEGACY',$legacy['pagina']-1)) ?>">Anterior</a>
            <?php endif; ?>
            <span><?= (int)$legacy['pagina'] ?> / <?= (int)$legacy['paginas'] ?></span>
            <?php if($legacy['pagina']<$legacy['paginas']): ?>
            <a href="<?= $esc($paginar('REVISION_LEGACY',$legacy['pagina']+1)) ?>">Siguiente</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <p class="impe-embudo-note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        Recuperación: <?= (int)$tablero['externos']['RECUPERACION'] ?> ·
        Descartados: <?= (int)$tablero['externos']['DESCARTADO'] ?>.
        El contador de PBX es informativo; la regla de recuperación al tercer intento
        aún no está activa. Los resultados se guardan en Telefonía como siempre.
    </p>
    <?php if($puedeMover): ?>
    <dialog class="impe-embudo-dialog" id="impe-v3o-dialog"
            aria-labelledby="impe-v3o-dialog-titulo">
        <form id="impe-v3o-mover-form" method="dialog">
            <header>
                <h3 id="impe-v3o-dialog-titulo">Actualizar etapa comercial</h3>
                <button type="button" data-v3-cerrar aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
            </header>
            <p id="impe-v3o-dialog-datos"></p>
            <label>Nueva etapa
                <select name="etapa_nueva" id="impe-v3o-destino" required>
                    <option value="">Seleccionar</option>
                    <option value="CONTACTADO">Contactados</option>
                    <option value="SEGUIMIENTO">Seguimiento</option>
                </select>
            </label>
            <label>Motivo de gestión
                <textarea id="impe-v3o-motivo" name="motivo" required minlength="8"
                    maxlength="190" rows="3" placeholder="Qué ocurrió y por qué se mueve"></textarea>
            </label>
            <label id="impe-v3o-fecha-label" hidden>Próxima acción
                <input type="datetime-local" id="impe-v3o-fecha" name="proxima_accion">
            </label>
            <p id="impe-v3o-dialog-error" role="alert" hidden></p>
            <div class="impe-embudo-dialog-footer">
                <button type="button" data-v3-cerrar>Cancelar</button>
                <button type="submit" id="impe-v3o-enviar">Confirmar</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
    <?php if($puedeTelefono || $puedeMover): ?>
    <script src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_v3_operativo.js?v=<?= filemtime(__DIR__.'/../../../public/javascript/embudo_comercial_v3_operativo.js') ?>" defer></script>
    <?php endif; ?>
    <?php endif; ?>
</div>
