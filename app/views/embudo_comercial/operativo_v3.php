<?php
/**
 * Gestión comercial V3: pantalla principal del asesor autorizado.
 * Conserva el teléfono del shell, Mis llamadas y movimientos V1 seguros.
 * Califica el interés comercial con evidencia, sin confirmar pagos ni inscripciones.
 */
require_once __DIR__.'/../../services/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
require_once __DIR__.'/../../services/EmbudoComercialV3MovimientosPermitidos.php';
require_once __DIR__.'/../../services/EmbudoComercialV3SeguimientoService.php';
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$sub=EmbudoComercialPoliticaPropuesta::SUBETAPAS;
$base=BASE_URL.'index.php?';
$params=[
    'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo',
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
$puedeRecuperar=$puedeMover && tienePermiso('ventas.recuperacion.gestionar') &&
    EmbudoComercialAcceso::puede('recuperacion');
$puedeDescartar=$puedeMover && tienePermiso('ventas.descartados.gestionar') &&
    EmbudoComercialAcceso::puede('descartados');
$actividadV3=!empty($tablero['actividad_v3']);
$ahoraUtc=gmdate('Y-m-d H:i:s');
$contenedorStage=[
    'NUEVO'=>['bi-person-plus','Sin primera conversación acreditada'],
    'CONTACTADO'=>['bi-telephone-inbound','Primer contacto y calificación'],
    'POTENCIAL'=>['bi-star','Interés y requisitos por acreditar'],
    'SEGUIMIENTO'=>['bi-calendar-check','Próximas gestiones programadas'],
    'PROCESO_INSCRIPCION'=>['bi-file-earmark-check','Trámite y validación institucional']
];
$tarjeta=function(array $p,string $etapa,bool $legacy=false)use(
    $esc,$base,$sub,$buscarTel,$puedeTelefono,$puedeLlamar,$puedeMover,$puedeRecuperar,$puedeDescartar,$actividadV3,$ahoraUtc
){
    $real=(string)($p['etapa_original']??'NUEVO');
    if($real==='')$real='NUEVO';
    $propuestas=$legacy?[]:EmbudoComercialV3MovimientosPermitidos::destinos(
        $real,!empty($p['puede_calificar'])
    );
    if(!$puedeMover)$propuestas=[];
    $pendiente=$p['actividad_pendiente']??null;
    $completada=$p['actividad_completada']??null;
    if($pendiente)$propuestas=array_diff($propuestas,['SEGUIMIENTO']);
    $destinos=implode(',',$propuestas);
    $detalle=$base.http_build_query([
        'controller'=>'embudoComercial','action'=>'detalle',
        'vista'=>'embudo','id'=>(int)$p['prospecto_id']
    ]);
    $historial=$base.http_build_query([
        'controller'=>'telefonia','action'=>'misLlamadas',
        'prospecto_asignacion_id'=>(int)$p['asignacion_id'],
        'q'=>$buscarTel($p['telefono_original']??'')
    ]);
    $st=(string)($p['subetapa']??'');
    $label=$st!==''&&isset($sub[$etapa][$st])
        ?$sub[$etapa][$st]:'';
    // Etapas históricas: presentar etiquetas sin modificar la BD.
    if($real==='INTERESADO')$label=$st!=='' && isset($sub['POTENCIAL'][$st])
        ?$sub['POTENCIAL'][$st]:'Interesado · registro anterior';
    if($real==='PAGO_CONFIRMADO')$label='Pago histórico · verificar';
    $n=(int)($p['intentos_hoy']['intentos']??0);
    $n=max(0,$n);
    $avance=min(3,$n);
    $origen=(string)($p['tipo_origen']??'');
    ?>
    <article class="impe-embudo-card impe-v3o-card"
             data-asignacion-id="<?= (int)$p['asignacion_id'] ?>"
             data-etapa="<?= $esc($real) ?>"
             data-v3-subetapa="<?= $esc($st) ?>"
             data-nombre="<?= $esc($p['nombre_completo']) ?>"
             data-v3-destinos="<?= $esc($destinos) ?>"
             <?= $propuestas?'draggable="true"':'' ?>>
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
        <?php if($label!=='' || $legacy): ?>
        <div class="impe-v3o-sub" title="<?= $esc($legacy?'Registro pendiente de revisar':$label) ?>">
            <i class="bi bi-bookmark-check" aria-hidden="true"></i>
            <span><?= $esc($legacy?'Revisar registro anterior':$label) ?></span>
        </div>
        <?php endif; ?>
        <?php if($etapa==='NUEVO' && !$legacy): ?>
        <span class="impe-v3o-intentos" title="Intentos sin contacto hoy; no generan cambios automáticos"
              aria-label="<?= $n ?> de 3 intentos sin conversación hoy">
            <i class="bi bi-telephone-x" aria-hidden="true"></i><?= $avance ?>/3
        </span>
        <?php endif; ?>
        <?php if(!empty($p['horario_contacto'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-clock" aria-hidden="true"></i>
            Horario solicitado: <?= $esc($p['horario_contacto']) ?></p>
        <?php endif; ?>
        <?php if($pendiente || $completada): ?>
        <p class="impe-v3o-meta">
            <i class="bi <?= $pendiente?'bi-calendar-event':'bi-check-circle' ?>" aria-hidden="true"></i>
            <?= $pendiente?($pendiente<$ahoraUtc?'Actividad vencida:':'Actividad programada:'):'Última actividad completada:' ?>
            <?= $esc(EmbudoComercialPresentacion::fechaUtc($pendiente?:$completada)) ?>
        </p>
        <?php endif; ?>
        <?php if(!empty($p['proxima_accion_at'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-calendar-event" aria-hidden="true"></i>
            <?= $actividadV3?'Agenda anterior:':'Próxima acción:' ?>
            <?= $esc(EmbudoComercialPresentacion::fechaUtc($p['proxima_accion_at'])) ?></p>
        <?php elseif(!empty($p['revision_fecha'])): ?>
        <p class="impe-v3o-meta"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            Revisar: <?= $esc(EmbudoComercialPresentacion::fechaLocal($p['revision_fecha'])) ?></p>
        <?php else: ?>
        <p class="impe-v3o-meta"><i class="bi bi-calendar3" aria-hidden="true"></i>
            Jornada: <?= $esc(EmbudoComercialPresentacion::fechaLocal($p['fecha_trabajo']??'')) ?></p>
        <?php endif; ?>
        <div class="impe-v3o-acciones" aria-label="Acciones comerciales">
            <?php if($puedeLlamar && $st!=='NO_CONTACTAR' &&
                trim((string)($p['telefono_original']??''))!==''): ?>
            <button type="button" class="impe-v3o-accion impe-v3o-accion--llamar"
                    data-embudo-marcar="<?= $esc($p['telefono_original']) ?>"
                    title="Llamar al prospecto" aria-label="Llamar al prospecto">
                <i class="bi bi-telephone" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if($puedeTelefono): ?>
            <a class="impe-v3o-accion" href="<?= $esc($historial) ?>"
               title="Mis llamadas y resultados" aria-label="Mis llamadas y resultados">
                <i class="bi bi-journal-check" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <a class="impe-v3o-accion" href="<?= $esc($detalle) ?>"
               title="Ver ficha e historial" aria-label="Ver ficha e historial">
                <i class="bi bi-person-vcard" aria-hidden="true"></i>
            </a>
            <?php if($puedeMover && $etapa==='POTENCIAL' && $st!=='NO_CONTACTAR'): ?>
            <button type="button" class="impe-v3o-accion" data-v3i-iniciar
                title="Vincular solicitud real de inscripción"
                aria-label="Verificar solicitud institucional de inscripción">
                <i class="bi bi-file-earmark-check" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if($puedeMover && $etapa==='POTENCIAL'): ?>
            <button type="button" class="impe-v3o-accion" data-v3-gestionar
                title="Gestionar subetapa y próxima acción" aria-label="Gestionar potencial">
                <i class="bi bi-calendar2-plus" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if($actividadV3 && $puedeMover && !$legacy &&
                in_array($real,EmbudoComercialV3SeguimientoService::ETAPAS,true)): ?>
            <button type="button" class="impe-v3o-accion" data-v3-actividad
                data-version="<?= (int)($p['actividad_version']??0) ?>"
                data-pendiente="<?= $pendiente?'1':'0' ?>"
                title="Actividades comerciales" aria-label="Actividades comerciales">
                <i class="bi bi-list-check" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if(!$legacy && $real==='NUEVO' && $puedeRecuperar && $n>=3): ?>
            <button type="button" class="impe-v3o-accion" data-v3b-accion="RECUPERACION"
                title="Revisar tres intentos PBX antes de Recuperación"
                aria-label="Revisar ingreso a Recuperación">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if(!$legacy && $puedeDescartar &&
                in_array($real,['NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO'],true)): ?>
            <button type="button" class="impe-v3o-accion" data-v3b-accion="DESCARTAR"
                title="Revisar motivo de descarte con evidencia"
                aria-label="Revisar descarte acreditado">
                <i class="bi bi-person-x" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if($propuestas): ?>
            <button type="button" class="impe-v3o-accion"
                data-v3-mover title="Cambiar etapa con motivo" aria-label="Cambiar etapa con motivo">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
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
<link rel="stylesheet" href="<?= $esc(BASE_URL) ?>public/css/embudo_v3_bandejas.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_v3_bandejas.css'),0,16) ?>">
<div class="impe-embudo impe-embudo--compact impe-embudo--v3-operativo"
    data-v3-bandejas data-dia="<?= $esc($tablero['dia_indicadores']??'') ?>">
    <nav class="impe-embudo-tabs" aria-label="Vistas comerciales">
        <a aria-current="page" href="<?= $esc($base.http_build_query([
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo'
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
    <?php if($error!==''): ?>
    <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><?= $esc($error) ?></p>
    <?php elseif(!$disponible): ?>
    <p class="impe-embudo-alert" role="status">La estructura V3 no está disponible; utiliza el tablero anterior.</p>
    <?php else: ?>
    <form class="impe-embudo-filtros" action="<?= $esc(BASE_URL.'index.php') ?>" method="get" role="search">
        <input type="hidden" name="controller" value="embudoComercial">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="vista" value="embudo">
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
            'controller'=>'embudoComercial','action'=>'index','vista'=>'embudo'
        ])) ?>">Limpiar</a>
    </form>
    <div class="impe-embudo-board-info">
        <span><i class="bi bi-people" aria-hidden="true"></i>
            <?= (int)$tablero['total_activos'] ?> asignaciones vigentes bajo tu responsabilidad</span>
        <small>20 por columna · paginación independiente</small>
    </div>
    <div class="impe-embudo-board" role="region" tabindex="0"
         aria-label="Cinco etapas del embudo de ventas">
        <?php foreach($contenedorStage as $clave=>$conf):
            $col=$tablero['columnas'][$clave]; ?>
        <section class="impe-embudo-lane impe-v3o-columna"
                 data-etapa="<?= $esc($clave) ?>"
                 aria-label="<?= $esc($col['etiqueta']) ?>">
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
        Tres intentos PBX permiten solicitar revisión manual; nunca trasladan
        automáticamente al prospecto. Descartes requieren evidencia acreditada.
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
                    <option value="POTENCIAL">Potenciales</option>
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
            <label id="impe-v3o-calificacion-label" class="impe-v3o-calificacion" hidden>
                <input type="checkbox" id="impe-v3o-calificacion" value="1">
                <span>El prospecto confirmó su intención de inscribirse.</span>
            </label>
            <p id="impe-v3o-dialog-error" role="alert" hidden></p>
            <div class="impe-embudo-dialog-footer">
                <button type="button" data-v3-cerrar>Cancelar</button>
                <button type="submit" id="impe-v3o-enviar">Confirmar</button>
            </div>
        </form>
    </dialog>
    <dialog class="impe-embudo-dialog" id="impe-v3o-potencial-dialog"
            aria-labelledby="impe-v3o-potencial-titulo">
        <form id="impe-v3o-potencial-form" method="dialog">
            <header>
                <h3 id="impe-v3o-potencial-titulo">Gestionar potencial</h3>
                <button type="button" data-v3-gestionar-cerrar aria-label="Cerrar">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>
            <p id="impe-v3o-potencial-datos"></p>
            <label>Situación comercial
                <select id="impe-v3o-potencial-subetapa" required>
                    <option value="">Seleccionar subetapa</option>
                    <?php foreach($sub['POTENCIAL'] as $codigo=>$etiqueta): ?>
                    <option value="<?= $esc($codigo) ?>"><?= $esc($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Próxima acción (opcional)
                <input type="datetime-local" id="impe-v3o-potencial-fecha">
            </label>
            <p class="impe-v3o-ayuda">Una próxima acción no cambia al prospecto de Potenciales a Seguimiento.</p>
            <label>Motivo de gestión
                <textarea id="impe-v3o-potencial-motivo" required minlength="8"
                    maxlength="190" rows="3"
                    placeholder="Describe el avance, pendiente o próximo paso"></textarea>
            </label>
            <p id="impe-v3o-potencial-error" role="alert" hidden></p>
            <div class="impe-embudo-dialog-footer">
                <button type="button" data-v3-gestionar-cerrar>Cancelar</button>
                <button type="submit" id="impe-v3o-potencial-guardar">Guardar gestión</button>
            </div>
        </form>
    </dialog>
    <?php if($actividadV3): ?>
    <dialog class="impe-embudo-dialog" id="impe-v3o-actividad-dialog" aria-labelledby="impe-v3o-actividad-titulo">
        <form method="dialog">
            <header>
                <h3 id="impe-v3o-actividad-titulo">Actividad comercial</h3>
                <button type="button" data-actividad-cerrar aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <div class="impe-v3o-actividad-cuerpo">
                <p data-actividad-prospecto></p>
                <label>Acción
                    <select name="operacion" required>
                        <option value="REGISTRAR">Registrar gestión</option>
                        <option value="PROGRAMAR">Programar actividad</option>
                        <option value="REPROGRAMAR">Reprogramar actividad</option>
                        <option value="COMPLETAR">Marcar como completada</option>
                    </select>
                </label>
                <label data-actividad-subetapa hidden>Subetapa de Seguimiento
                    <select name="subetapa">
                        <option value="">Conservar subetapa</option>
                        <?php foreach($sub['SEGUIMIENTO'] as $codigo=>$etiqueta): ?>
                            <option value="<?= $esc($codigo) ?>"><?= $esc($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label data-actividad-fecha hidden>Fecha y hora de México
                    <input name="fecha" type="datetime-local">
                </label>
                <label>Motivo
                    <textarea name="motivo" minlength="8" maxlength="190" required rows="2"></textarea>
                </label>
                <details class="impe-v3o-actividad-historial">
                    <summary>
                        <span><i class="bi bi-clock-history" aria-hidden="true"></i> Historial de actividades</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <div class="impe-v3o-actividad-historial-panel">
                        <p class="impe-v3o-actividad-historial-estado" data-actividad-historial-estado
                           role="status" aria-live="polite"></p>
                        <div class="impe-v3o-actividad-historial-scroll" role="region"
                             aria-label="Registros anteriores" tabindex="0">
                            <ol data-actividad-historial aria-label="Actividades registradas"></ol>
                        </div>
                        <button class="impe-v3o-actividad-mas" type="button"
                                data-actividad-mas hidden>
                            <i class="bi bi-arrow-down-circle" aria-hidden="true"></i>
                            Ver anteriores
                        </button>
                    </div>
                </details>
                <p data-actividad-error role="alert" hidden></p>
            </div>
            <div class="impe-embudo-dialog-footer">
                <button type="button" data-actividad-cerrar>Cancelar</button>
                <button type="submit">Guardar actividad</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
    <?php if($puedeRecuperar || $puedeDescartar): ?>
        <?php require __DIR__.'/bandejas_v3_modal.php'; ?>
    <?php endif; ?>
    <?php if($puedeMover): ?>
        <?php require __DIR__.'/inscripcion_v3_modal.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if($puedeTelefono || $puedeMover): ?>
    <script src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_v3_operativo.js?v=<?= filemtime(__DIR__.'/../../../public/javascript/embudo_comercial_v3_operativo.js') ?>" defer></script>
    <?php if($puedeMover): ?>
    <script defer src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_v3_inscripcion.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/embudo_comercial_v3_inscripcion.js'),0,16) ?>"></script>
    <?php endif; ?>
    <?php if($puedeRecuperar || $puedeDescartar): ?>
    <script defer src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_v3_bandejas.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/embudo_comercial_v3_bandejas.js'),0,16) ?>"></script>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
