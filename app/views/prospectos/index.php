<?php
$escaparProspecto = static function ($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
};
$registrosProspectos = $listado['registros'] ?? [];
$paginaProspectos = (int)($listado['pagina'] ?? 1);
$paginasProspectos = (int)($listado['paginas'] ?? 1);
$urlProspectos = static function (array $params = []) {
    return BASE_URL.'index.php?'.http_build_query(array_merge(['controller'=>'prospecto','action'=>'index'],$params));
};
$consultaProspectos = ['vista'=>$vistaProspectos,'buscar'=>$buscarProspectos,'aliado_id'=>$aliadoFiltroProspectos,'programa'=>$programaFiltroProspectos];
if ($estadoProspectos) $consultaProspectos['estado_id']=(int)$estadoProspectos['id'];
$hayFiltrosProspectos=$buscarProspectos!=='' || $aliadoFiltroProspectos!=='' || $programaFiltroProspectos!=='';
$limpiarProspectos=$urlProspectos($estadoProspectos?['estado_id'=>(int)$estadoProspectos['id']]:['vista'=>'todos']);
$normalizarEstado = static function ($nombre) {
    return strtr(mb_strtolower((string)$nombre),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
};
$mapaProspectos = static function ($nombre) use ($normalizarEstado) {
    $especiales=['México'=>'estado-de-mexico','Estado de México'=>'estado-de-mexico','Michoacán'=>'michoacán','Michoacán de Ocampo'=>'michoacán','Veracruz de Ignacio de la Llave'=>'veracruz','Coahuila de Zaragoza'=>'coahuila'];
    $slug=$especiales[$nombre]??trim((string)preg_replace('/[^a-z0-9]+/','-',$normalizarEstado($nombre)),'-');
    return is_file(__DIR__.'/../../../public/img/estados/'.$slug.'.png') ? BASE_URL.'public/img/estados/'.rawurlencode($slug).'.png' : '';
};
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/marketing_selectores_uniformes.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/marketing_selectores_uniformes.css'),0,16) ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/aliados.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/aliados.css'),0,16) ?>">
<style>
.prospectos-page{font-family:Manrope,sans-serif;color:#17233e}
.prospectos-page .prospectos-panel{background:#fff;border:1px solid #e1e8f0;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(24,43,72,.04)}
.prospectos-page .prospectos-heading{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:22px}
.prospectos-page .prospectos-icon{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;color:#123b86;background:#e9f6f3;font-size:24px}
.prospectos-page .prospectos-heading-main{display:flex;align-items:center;gap:16px}
.prospectos-page h2{font-size:18px;font-weight:800;margin:0 0 4px}
.prospectos-page p{font-size:13px;color:#617189;margin:0}
.prospectos-page .prospectos-total{border:1px solid #e1e8f0;border-radius:14px;padding:10px 20px;text-align:center;min-width:118px}
.prospectos-page .prospectos-total strong{font-size:24px;display:block}
.prospectos-page .prospectos-total span{font-size:12px;color:#617189}
.prospectos-page .prospectos-table-wrap{width:100%;overflow-x:auto}
.prospectos-page table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}
.prospectos-page .prospectos-table-wrap table,
.prospectos-page .prospectos-table-wrap table th,
.prospectos-page .prospectos-table-wrap table td,
.prospectos-page .prospectos-table-wrap table span,
.prospectos-page .prospectos-table-wrap table a{font-family:'Manrope',sans-serif !important;font-size:12px !important;letter-spacing:normal !important;line-height:1.5 !important}
.prospectos-page .prospectos-table-wrap table th{font-weight:800 !important}
.prospectos-page .prospectos-table-wrap table td{font-weight:600 !important}
.prospectos-page .prospectos-table-wrap table td.prospectos-name{font-weight:700 !important}
.prospectos-page th{background:#eef4fa;color:#173a65;font-weight:800;text-align:left;padding:12px 14px;border-bottom:1px solid #d9e4ef}
.prospectos-page td{padding:12px 14px;border-bottom:1px solid #e9eef5;color:#42516a}
.prospectos-page tbody tr:hover{background:#f7fafc}
.prospectos-page .prospectos-name{font-weight:750;color:#17233e}
.prospectos-page a{color:#174b8a;text-decoration:none}
.prospectos-page .prospectos-empty{text-align:center;padding:35px;color:#617189}
.prospectos-page .prospectos-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:18px;font-size:13px}
.prospectos-page .prospectos-pagination a{border:1px solid #dce5ef;border-radius:9px;padding:8px 12px}
@media(max-width:767px){.prospectos-page .prospectos-panel{padding:16px}}
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/prospectos_fase1.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/prospectos_fase1.css'),0,16) ?>">
<div class="prospectos-page prospectos-fase1" data-prospectos-modulo>
    <?php foreach (array_filter([$mensajeError,$errorTerritorial,$errorProcedencia]) as $errorProspectos): ?>
    <div class="login-alert prospectos-message" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span><?= $escaparProspecto($errorProspectos) ?></span></div>
    <?php endforeach; ?>
    <section class="aliados-summary-grid prospectos-summary" aria-label="Indicadores de Prospectos">
        <?php foreach ([['total','bi-people','Total de prospectos',$resumenProspectos['total']],['estados','bi-map','Estados de aliados con registros',$estadosConRegistros],['aliados','bi-buildings','Procedentes de aliados',$resumenProspectos['aliados']],['generales','bi-ui-checks','Formularios generales',$resumenProspectos['generales']]] as [$indicador,$icono,$etiqueta,$cantidad]): ?>
        <article class="aliados-summary-card" data-prospectos-indicador="<?= $indicador ?>">
            <span class="aliados-summary-icon"><i class="bi <?= $icono ?>" aria-hidden="true"></i></span>
            <div><strong><?= $cantidad===null?'—':(int)$cantidad ?></strong><span><?= $etiqueta ?></span><?php if ($cantidad===null): ?><small>Sin verificar</small><?php endif; ?></div>
        </article>
        <?php endforeach; ?>
    </section>
    <nav class="prospectos-views" aria-label="Vistas de Prospectos">
        <a class="prospectos-view<?= $vistaProspectos==='estados'?' is-active':'' ?>" <?= $vistaProspectos==='estados'?'aria-current="page"':'' ?> href="<?= $escaparProspecto($urlProspectos()) ?>"><i class="bi bi-map" aria-hidden="true"></i> Por estados</a>
        <a class="prospectos-view<?= $vistaProspectos==='todos'?' is-active':'' ?>" <?= $vistaProspectos==='todos'?'aria-current="page"':'' ?> href="<?= $escaparProspecto($urlProspectos(['vista'=>'todos'])) ?>"><i class="bi bi-people" aria-hidden="true"></i> Todos los prospectos</a>
    </nav>
    <?php if (!$mostrarTablaProspectos): ?>
    <section class="dashboard-panel prospectos-state-toolbar">
        <div><h2 class="panel-title">Seleccionar estado</h2><p>Consulta los prospectos por el estado de su aliado de origen. Los registros generales están en Todos los prospectos.</p></div>
        <form method="get" data-prospectos-state-search-form>
            <input type="hidden" name="controller" value="prospecto"><input type="hidden" name="action" value="index">
            <label for="prospectosEstadoBuscar">Buscar estado</label>
            <div class="prospectos-search-controls"><div class="module-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="prospectosEstadoBuscar" name="buscar_estado" type="search" placeholder="Buscar estado…" value="<?= $escaparProspecto($buscarEstadoProspectos) ?>" data-prospectos-state-search></div></div>
        </form>
    </section>
    <?php if ($errorTerritorial===''): ?>
    <p class="prospectos-state-counter" role="status" aria-live="polite" data-prospectos-state-counter></p>
    <section class="data-territorial-cards linkage-territory-grid prospectos-state-grid" aria-label="Estados activos">
        <?php $coincidenciasEstado=0; foreach ($territoriosProspectos as $territorio): $visible=$buscarEstadoProspectos==='' || str_contains($normalizarEstado($territorio['nombre']),$normalizarEstado($buscarEstadoProspectos)); if ($visible) $coincidenciasEstado++; $mapa=$mapaProspectos($territorio['nombre']); ?>
        <a class="dashboard-panel data-territorial-card linkage-territory-card prospectos-state-card" href="<?= $escaparProspecto($urlProspectos(['estado_id'=>(int)$territorio['id']])) ?>" data-prospectos-state="<?= $escaparProspecto($territorio['nombre']) ?>" <?= $visible?'':'hidden' ?>>
            <div class="data-card-header"><div class="data-card-heading"><h3><?= $escaparProspecto($territorio['nombre']) ?></h3></div></div>
            <div class="data-state-image"><?php if ($mapa!==''): ?><img src="<?= $escaparProspecto($mapa) ?>" alt="Mapa de <?= $escaparProspecto($territorio['nombre']) ?>" loading="lazy"><?php else: ?><i class="bi bi-map" aria-label="Mapa no disponible"></i><?php endif; ?></div>
            <dl class="data-card-meta"><div><dt>Prospectos de aliados</dt><dd data-prospectos-state-total><?= (int)$territorio['total'] ?></dd></div></dl>
            <span class="btn btn-system-light">Ver prospectos</span>
        </a>
        <?php endforeach; ?>
    </section>
    <section class="dashboard-panel data-empty-state prospectos-empty-state" data-prospectos-state-empty <?= $coincidenciasEstado>0?'hidden':'' ?>><i class="bi bi-<?= $territoriosProspectos?'search':'map' ?>" aria-hidden="true"></i><h3><?= $territoriosProspectos?'No hay resultados en la búsqueda.':'No hay estados activos disponibles.' ?></h3><p><?= $territoriosProspectos?'Prueba con otro nombre de estado.':'Revisa el catálogo territorial.' ?></p></section>
    <?php endif; ?>
    <?php else: ?>
    <section class="prospectos-panel">
        <div class="prospectos-heading"><div class="prospectos-heading-main"><span class="prospectos-icon"><i class="bi bi-people" aria-hidden="true"></i></span><div><h2><?= $estadoProspectos?'Prospectos de '.$escaparProspecto($estadoProspectos['nombre']):($vistaProspectos==='todos'?'Todos los prospectos':'Estado no encontrado') ?></h2></div></div><?php if ($mensajeError===''): ?><div class="prospectos-total"><strong><?= (int)$listado['total'] ?></strong><span>prospectos</span></div><?php endif; ?></div>
        <?php if ($vistaProspectos==='estados'): ?>
        <p class="prospectos-territory-note">Prospectos procedentes de aliados de este estado. Las columnas de residencia conservan lo declarado en el formulario.</p>
        <div class="prospectos-state-navigation"><a class="btn btn-system-light" href="<?= $escaparProspecto($urlProspectos()) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Estados</a>
            <form method="get" data-prospectos-navegacion><input type="hidden" name="controller" value="prospecto"><input type="hidden" name="action" value="index"><label for="prospectosCambiarEstado">Cambiar de estado</label><select class="form-select" name="estado_id" id="prospectosCambiarEstado" required><option value="">Seleccionar estado</option><?php foreach ($territoriosProspectos as $territorio): ?><option value="<?= (int)$territorio['id'] ?>" <?= $estadoProspectos && (int)$estadoProspectos['id']===(int)$territorio['id']?'selected':'' ?>><?= $escaparProspecto($territorio['nombre']) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-system-light">Ver</button></form>
        </div>
        <?php endif; ?>
        <?php if ($mensajeError===''): ?>
        <form method="get" class="prospectos-list-filters prospectos-fase2-filters" data-prospectos-filtros data-prospectos-navegacion>
            <input type="hidden" name="controller" value="prospecto"><input type="hidden" name="action" value="index"><input type="hidden" name="vista" value="<?= $vistaProspectos ?>"><?php if ($estadoProspectos): ?><input type="hidden" name="estado_id" value="<?= (int)$estadoProspectos['id'] ?>"><?php endif; ?>
            <div><label for="prospectoBuscar">Buscar prospecto</label><div class="module-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" type="search" id="prospectoBuscar" name="buscar" placeholder="Escribe nombre, teléfono o correo…" maxlength="180" value="<?= $escaparProspecto($buscarProspectos) ?>"></div></div>
            <div><label for="prospectoAliado" id="prospectoAliadoLabel">Aliado de origen</label><select class="form-select" id="prospectoAliado" name="aliado_id" data-native-select data-prospectos-searchable="aliado" <?= $errorProcedencia!=='' || $errorAliadosProspectos!==''?'disabled':'' ?>><option value="">Todos los orígenes</option><option value="general" <?= $aliadoFiltroProspectos==='general'?'selected':'' ?>>Registro general</option><?php foreach (($listado['aliados']??[]) as $origenProspecto): ?><option value="<?= (int)$origenProspecto['id'] ?>" <?= $aliadoFiltroProspectos===(string)$origenProspecto['id']?'selected':'' ?>><?= $escaparProspecto($origenProspecto['nombre'].' · ID #'.$origenProspecto['id']) ?></option><?php endforeach; ?></select></div>
            <div><label for="prospectoPrograma" id="prospectoProgramaLabel">Programa educativo</label><select class="form-select" id="prospectoPrograma" name="programa" data-native-select data-prospectos-searchable="programa" <?= $errorProgramasProspectos!=='' || empty($listado['programas'])?'disabled':'' ?>>
                <option value="">Todos los programas</option>
                <?php foreach (($listado['programas']??[]) as $opcionPrograma): ?><option value="<?= $escaparProspecto($opcionPrograma['valor']) ?>" <?= $programaFiltroProspectos===$opcionPrograma['valor']?'selected':'' ?>><?= $escaparProspecto($opcionPrograma['nombre']) ?></option><?php endforeach; ?>
            </select></div>
            <noscript><button type="submit" class="btn btn-system-light"><i class="bi bi-funnel" aria-hidden="true"></i> Aplicar filtros</button></noscript>
        </form>
        <div class="prospectos-filter-summary"><p><strong data-prospectos-filter-total><?= (int)$listado['total'] ?></strong> resultados</p><a class="btn btn-system-light" href="<?= $escaparProspecto($limpiarProspectos) ?>" data-prospectos-limpiar <?= $hayFiltrosProspectos?'':'hidden' ?>><i class="bi bi-x-circle" aria-hidden="true"></i> Limpiar filtros</a><span class="prospectos-searching" role="status" data-prospectos-searching hidden><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Buscando prospectos</span></div>
        <p class="prospectos-message" role="alert" data-prospectos-program-error <?= $errorProgramasProspectos!==''?'':'hidden' ?>><?= $escaparProspecto($errorProgramasProspectos) ?></p>
        <?php if ($errorAliadosProspectos!==''): ?><p class="prospectos-message" role="alert"><?= $escaparProspecto($errorAliadosProspectos) ?></p><?php endif; ?>
        <p class="prospectos-message" role="alert" data-prospectos-search-error hidden></p>
        <span class="visually-hidden" role="status" aria-live="polite" data-prospectos-search-status></span>
        <div data-prospectos-resultados>
        <div class="prospectos-table-wrap" role="region" aria-label="Listado de prospectos" tabindex="0">
            <table>
                <thead><tr><th scope="col">Fecha de registro</th><th scope="col">Nombre completo</th><th scope="col">Correo electrónico</th><th scope="col">Teléfono</th><th scope="col">Tel. secundario</th><th scope="col">Tel. recomendado</th><th scope="col">Aliado de origen</th><th scope="col">Perfil de interés</th><th scope="col">Estado de residencia</th><th scope="col">Municipio de residencia</th><th scope="col">Lugar de trabajo</th><th scope="col">Cargo</th><th scope="col">Fecha de nacimiento</th><th scope="col">Origen</th></tr></thead>
                <tbody>
                <?php foreach ($registrosProspectos as $prospecto): ?>
                    <tr>
                        <td><?= $escaparProspecto($prospecto['created_at']) ?></td>
                        <td class="prospectos-name"><?= $escaparProspecto(trim($prospecto['nombre'] . ' ' . $prospecto['apellido'])) ?></td>
                        <td><?= $escaparProspecto($prospecto['correo']) ?></td><td><?= $escaparProspecto($prospecto['movil']) ?></td><td><?= $escaparProspecto($prospecto['movil_secundario'] ?: '—') ?></td><td><?= $escaparProspecto($prospecto['tel_recomendado'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($errorProcedencia!==''?'Procedencia no disponible':($prospecto['aliado_nombre']??'Registro general')) ?></td>
                        <td><?= $escaparProspecto($prospecto['perfil_interes']) ?></td><td><?= $escaparProspecto($prospecto['estado'] ?: '—') ?></td><td><?= $escaparProspecto($prospecto['municipio'] ?: '—') ?></td><td><?= $escaparProspecto($prospecto['lugar_laboras']) ?></td><td><?= $escaparProspecto($prospecto['cargo_puesto'] ?: '—') ?></td><td><?= $escaparProspecto($prospecto['fecha_nacimiento']) ?></td><td><?= $escaparProspecto($prospecto['origen']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$registrosProspectos): ?><tr><td colspan="14" class="prospectos-empty"><div class="prospectos-empty-state"><i class="bi bi-<?= $buscarProspectos!==''||$aliadoFiltroProspectos!==''?'search':'people' ?>" aria-hidden="true"></i><strong><?= $buscarProspectos!==''?'No se encontraron coincidencias.':($programaFiltroProspectos!==''?'No hay registros para el programa seleccionado.':($aliadoFiltroProspectos!==''?'No hay prospectos para este aliado.':'No hay prospectos registrados.')) ?></strong></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($paginasProspectos > 1): ?>
        <nav class="prospectos-pagination" aria-label="Páginas de prospectos">
            <?php if ($paginaProspectos > 1): ?><a href="<?= $escaparProspecto($urlProspectos(array_merge($consultaProspectos,['pagina'=>$paginaProspectos-1]))) ?>" rel="prev">Anterior</a><?php endif; ?>
            <span>Página <?= $paginaProspectos ?> de <?= $paginasProspectos ?></span>
            <?php if ($paginaProspectos < $paginasProspectos): ?><a href="<?= $escaparProspecto($urlProspectos(array_merge($consultaProspectos,['pagina'=>$paginaProspectos+1]))) ?>" rel="next">Siguiente</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
<script defer src="<?= BASE_URL ?>public/javascript/prospectos_fase1.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/javascript/prospectos_fase1.js'),0,16) ?>"></script>
