<?php
require_once __DIR__.'/../../services/EmbudoComercialPresentacion.php';
require_once __DIR__.'/../../helpers/EmbudoComercialAcceso.php';
$esc=static fn($x)=>htmlspecialchars((string)($x??''),ENT_QUOTES,'UTF-8');
$def=static fn($x,$f='Sin indicar')=>trim((string)($x??''))!==''?(string)$x:$f;
$urlBase=BASE_URL.'index.php?';
$baseParametros=[
    'controller'=>'embudoComercial','action'=>'index','vista'=>$vista,
    'origen'=>$filtros['origen'],'programa'=>$filtros['programa'],
    'buscar'=>$filtros['buscar'],'vencidos'=>$filtros['vencidos']?'1':'0'
];
if ($supervisor && $filtros['asesor']>0) $baseParametros['asesor']=$filtros['asesor'];
foreach ($filtros['paginas'] as $etapa=>$numero) {
    if ($numero>1) $baseParametros['p_'.strtolower($etapa)]=$numero;
}
$paginaUrl=static function(string $etapa,int $numero) use ($urlBase,$baseParametros): string {
    $q=$baseParametros;
    $k='p_'.strtolower($etapa);
    if ($numero<=1) unset($q[$k]); else $q[$k]=$numero;
    return $urlBase.http_build_query($q);
};
// Enlace de consulta: la PBX guarda en ocasiones diez dígitos nacionales
// mientras que el almacén comercial conserva el número con prefijo +52.
// Cambiar únicamente el texto de búsqueda; no alterar teléfono ni marcación.
$buscarNumeroLlamadas=static function(?string $telefono): string {
    $valor=trim((string)($telefono??''));
    if ($valor==='' || !preg_match('/^\+?[0-9 ().-]+$/D',$valor)) {
        return $valor;
    }
    $digitos=preg_replace('/\D+/', '',$valor)??'';
    return strlen($digitos)===12 && substr($digitos,0,2)==='52'
        ? substr($digitos,2) : $valor;
};
$fecha=static fn($utc)=>EmbudoComercialPresentacion::fechaUtc($utc);
$dia=static fn($fecha)=>EmbudoComercialPresentacion::fechaLocal($fecha);
$mostrarLlamadas=tienePermiso('telefonia.usar') && !$supervisor;
$puedeLlamar=$mostrarLlamadas && tienePermiso('telefonia.salientes');
$puedeMover=!$supervisor && tienePermiso('ventas.embudo.mover') &&
    EmbudoComercialAcceso::puede('embudo');
$puedeRecuperar=$puedeMover && tienePermiso('ventas.recuperacion.gestionar') &&
    EmbudoComercialAcceso::puede('recuperacion');
$puedeDescartar=$puedeMover && tienePermiso('ventas.descartados.gestionar') &&
    EmbudoComercialAcceso::puede('descartados');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/embudo_comercial.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/embudo_comercial.css'),0,16) ?>">
<div class="impe-embudo">
    <header class="impe-embudo-hero">
        <div>
            <span class="impe-embudo-kicker"><i class="bi bi-diagram-3" aria-hidden="true"></i> VENTAS · PROSPECTOS ASIGNADOS</span>
            <h2><?= $esc($vista==='embudo'?'Gestión comercial':($vista==='recuperacion'?'Recuperación de prospectos':'Prospectos descartados')) ?></h2>
            <p><?= $supervisor?'Supervisión de asignaciones comerciales vigentes. Consulta de solo lectura.':'Tus prospectos asignados, organizados para su atención y seguimiento.' ?></p>
        </div>
        <span class="impe-embudo-hero-icon"><i class="bi bi-kanban" aria-hidden="true"></i></span>
    </header>

    <nav class="impe-embudo-tabs" aria-label="Vistas comerciales">
        <?php foreach ([
            'embudo'=>['bi-kanban','Embudo de ventas'],
            'recuperacion'=>['bi-arrow-repeat','Recuperación'],
            'descartados'=>['bi-archive','Descartados']
        ] as $clave=>$datos): ?>
        <?php if (EmbudoComercialAcceso::puede($clave)): ?>
        <a href="<?= $esc($urlBase.http_build_query(['controller'=>'embudoComercial','action'=>'index','vista'=>$clave])) ?>"
           <?= $vista===$clave?'aria-current="page"':'' ?>>
            <i class="bi <?= $esc($datos[0]) ?>" aria-hidden="true"></i><?= $esc($datos[1]) ?>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($error!==''): ?>
    <p class="impe-embudo-alert impe-embudo-alert--error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><?= $esc($error) ?></p>
    <?php elseif (!$disponible): ?>
    <p class="impe-embudo-alert" role="status"><i class="bi bi-shield-lock" aria-hidden="true"></i>
        El tablero está preparado, pero su estructura independiente aún no está instalada. No se modificaron prospectos ni llamadas.
    </p>
    <?php else: ?>
    <form class="impe-embudo-filtros" action="<?= $esc(BASE_URL.'index.php') ?>" method="get" role="search">
        <input type="hidden" name="controller" value="embudoComercial">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="vista" value="<?= $esc($vista) ?>">
        <label>Origen
            <select name="origen">
                <?php foreach ([''=>'Todos','META_ADS'=>'Meta','ALIADO'=>'Aliados'] as $value=>$label): ?>
                <option value="<?= $esc($value) ?>" <?= $filtros['origen']===$value?'selected':'' ?>><?= $esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Programa educativo
            <select name="programa">
                <option value="">Todos los programas</option>
                <?php foreach ($opciones['programas'] as $p): ?>
                <option value="<?= $esc($p['codigo']) ?>" <?= $filtros['programa']===$p['codigo']?'selected':'' ?>><?= $esc($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($supervisor): ?>
        <label>Asesor
            <select name="asesor">
                <option value="">Todos los asesores</option>
                <?php foreach ($opciones['asesores'] as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filtros['asesor']===(int)$u['id']?'selected':'' ?>><?= $esc($u['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Buscar prospecto
            <input name="buscar" type="search" maxlength="120" value="<?= $esc($filtros['buscar']) ?>" placeholder="Nombre, teléfono o correo">
        </label>
        <label class="impe-embudo-check"><input name="vencidos" type="checkbox" value="1" <?= $filtros['vencidos']?'checked':'' ?>> Solo vencidos</label>
        <button type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
        <a class="impe-embudo-reset" href="<?= $esc($urlBase.http_build_query(['controller'=>'embudoComercial','action'=>'index','vista'=>$vista])) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Limpiar</a>
    </form>

    <div class="impe-embudo-board-info">
        <span><i class="bi bi-people" aria-hidden="true"></i> <?= number_format((int)$tablero['total']) ?> asignaciones<?= $supervisor?' bajo supervisión':' propias' ?></span>
        <small>20 tarjetas por columna · <?= !$puedeMover?'Vista de consulta':($vista==='embudo'?'Arrastra una ficha o usa las acciones de la tarjeta':'Usa las acciones de cada prospecto para actualizar su seguimiento') ?></small>
    </div>

    <div class="impe-embudo-board <?= $vista==='embudo'?'':'impe-embudo-board--single' ?>" role="region" tabindex="0"
         aria-label="<?= $esc($vista==='embudo'?'Columnas del embudo comercial':'Lista de '.$vista) ?>">
        <?php foreach ($tablero['columnas'] as $etapa=>$col): ?>
        <section class="impe-embudo-lane impe-embudo-lane--<?= $esc(strtolower($etapa)) ?>" data-etapa="<?= $esc($etapa) ?>" aria-label="<?= $esc($col['etiqueta']) ?>">
            <div class="impe-embudo-lane-head">
                <div>
                    <h3><?= $esc($col['etiqueta']) ?></h3>
                    <small><?= $etapa==='NUEVO'?'Pendientes de primera gestión':($etapa==='PAGO_CONFIRMADO'?'Pendientes de verificar e integrar': 'Prospectos en esta etapa') ?></small>
                </div>
                <span class="impe-embudo-lane-count"><?= number_format((int)$col['total']) ?></span>
            </div>
            <div class="impe-embudo-lane-list">
                <?php if (!$col['registros']): ?>
                <div class="impe-embudo-empty"><i class="bi bi-inbox" aria-hidden="true"></i><span>Sin prospectos en esta etapa</span></div>
                <?php else: ?>
                <?php foreach ($col['registros'] as $p):
                    $detalle=$urlBase.http_build_query([
                        'controller'=>'embudoComercial','action'=>'detalle',
                        'vista'=>$vista,'id'=>(int)$p['prospecto_id']
                    ]);
                    $origen=(string)($p['tipo_origen']??'');
                ?>
                <article class="impe-embudo-card"
                   data-asignacion-id="<?= (int)$p['asignacion_id'] ?>"
                   data-etapa="<?= $esc($etapa) ?>"
                   data-nombre="<?= $esc($p['nombre_completo']) ?>"
                   <?= $puedeMover && $vista==='embudo' && $etapa!=='PAGO_CONFIRMADO'?'draggable="true"':'' ?>>
                    <div class="impe-embudo-card-line">
                        <a class="impe-embudo-persona" href="<?= $esc($detalle) ?>" title="<?= $esc($p['nombre_completo']) ?>"><?= $esc($p['nombre_completo']) ?></a>
                        <span class="impe-embudo-source <?= $origen==='META_ADS'?'is-meta':($origen==='ALIADO'?'is-aliado':'') ?>"><?= $esc($origen==='META_ADS'?'Meta':($origen==='ALIADO'?'Aliado':'Registro')) ?></span>
                    </div>
                    <p class="impe-embudo-programa" title="<?= $esc($p['programas']) ?>"><i class="bi bi-mortarboard" aria-hidden="true"></i><?= $esc($def($p['programas'],'Programa pendiente')) ?></p>
                    <div class="impe-embudo-card-bottom">
                        <?php $fechaTarjeta=$etapa==='RECUPERACION' && !empty($p['revision_fecha'])
                            ?('Revisar: '.$dia($p['revision_fecha']))
                            :($p['proxima_accion_at']?('Próxima acción: '.$fecha($p['proxima_accion_at'])):
                                ('Jornada: '.$dia($p['fecha_trabajo']))); ?>
                        <span class="impe-embudo-next" title="<?= $esc($fechaTarjeta) ?>">
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            <?= $esc($fechaTarjeta) ?>
                        </span>
                        <div class="impe-embudo-card-actions">
                            <?php if ($puedeLlamar && !empty($p['telefono_original'])): ?>
                            <button type="button" data-embudo-marcar="<?= $esc($p['telefono_original']) ?>"
                                title="Abrir marcador para este prospecto"
                                aria-label="Llamar a <?= $esc($p['nombre_completo']) ?>">
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($mostrarLlamadas): ?>
                            <a href="<?= $esc(BASE_URL.'index.php?'.http_build_query([
                                'controller'=>'telefonia','action'=>'misLlamadas',
                                'prospecto_asignacion_id'=>(int)$p['asignacion_id'],
                                'q'=>$buscarNumeroLlamadas((string)$p['telefono_original'])
                            ])) ?>" title="Consultar y registrar resultado en Mis llamadas" aria-label="Registrar resultado de llamadas del prospecto"><i class="bi bi-journal-check" aria-hidden="true"></i></a>
                            <?php endif; ?>
                            <a href="<?= $esc($detalle) ?>" title="Ver ficha" aria-label="Ver ficha de <?= $esc($p['nombre_completo']) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                            <?php if ($puedeMover && $etapa!=='PAGO_CONFIRMADO' && $etapa!=='DESCARTADO'): ?>
                            <button type="button" class="impe-embudo-action-mover"
                                data-mover-tarjeta aria-label="Cambiar etapa de <?= $esc($p['nombre_completo']) ?>"
                                title="Mover prospecto con registro de motivo">
                                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($col['paginas']>1): ?>
            <nav class="impe-embudo-pager" aria-label="Páginas de <?= $esc($col['etiqueta']) ?>">
                <?php if ($col['pagina']>1): ?><a href="<?= $esc($paginaUrl($etapa,$col['pagina']-1)) ?>" aria-label="Página anterior de <?= $esc($col['etiqueta']) ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                <span><?= (int)$col['pagina'] ?> de <?= (int)$col['paginas'] ?></span>
                <?php if ($col['pagina']<$col['paginas']): ?><a href="<?= $esc($paginaUrl($etapa,$col['pagina']+1)) ?>" aria-label="Página siguiente de <?= $esc($col['etiqueta']) ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>
    <?php if ($puedeMover): ?>
    <dialog class="impe-embudo-dialog" id="impe-embudo-dialog"
        aria-labelledby="impe-embudo-dialog-title">
        <form id="impe-embudo-mover-form" method="dialog">
            <header>
                <h3 id="impe-embudo-dialog-title">Cambiar etapa</h3>
                <button type="button" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
            </header>
            <p id="impe-embudo-dialog-subtitulo"></p>
            <label>Nueva etapa
                <select name="etapa_nueva" id="impe-embudo-destino" required>
                    <?php foreach (EmbudoComercialRepository::ETAPAS as $clave=>$nombre):
                        if ($clave==='PAGO_CONFIRMADO') continue;
                        if ($clave==='RECUPERACION' && !$puedeRecuperar) continue;
                        if ($clave==='DESCARTADO' && !$puedeDescartar) continue; ?>
                    <option value="<?= $esc($clave) ?>"><?= $esc($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label id="impe-embudo-motivo-wrap">Motivo / evidencia de gestión
                <textarea name="motivo" id="impe-embudo-motivo" maxlength="190" rows="3"
                    placeholder="Qué ocurrió y por qué se cambia la etapa"></textarea>
            </label>
            <label id="impe-embudo-fecha-wrap" hidden>Próxima acción
                <input type="datetime-local" name="proxima_accion" id="impe-embudo-fecha">
            </label>
            <p id="impe-embudo-mover-error" role="alert" hidden></p>
            <div class="impe-embudo-dialog-footer">
                <button type="button" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" id="impe-embudo-mover-enviar">Confirmar movimiento</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
    <?php if ($puedeMover || $puedeLlamar): ?>
    <script src="<?= $esc(BASE_URL) ?>public/javascript/embudo_comercial_movimientos.js?v=<?= filemtime(__DIR__.'/../../../public/javascript/embudo_comercial_movimientos.js') ?>" defer></script>
    <?php endif; ?>
    <p class="impe-embudo-note"><i class="bi bi-shield-check" aria-hidden="true"></i>
        Las etapas manuales conservan auditoría; las llamadas siguen registrándose en Telefonía.
        No se confirma ningún pago ni se inscribe a nadie desde este tablero.
        Recuperación establece una revisión para mañana, pero no envía mensajes automáticos.
    </p>
    <?php endif; ?>
</div>
