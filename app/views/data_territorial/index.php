<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$territorios = $territorios ?? [];
$territoriosTarjetas = $territoriosTarjetas ?? $territorios;
$territoriosInterfaz = $territoriosDisponibles ?? $territorios;
$territoriosActualizacionMasiva = $territoriosActualizacionMasiva ?? [];
$totalTerritoriosDisponibles = $totalTerritoriosDisponibles ?? count($territoriosInterfaz);
$totalTerritorios = $totalTerritorios ?? count($territorios);
$paginaTerritorios = $paginaTerritorios ?? 1;
$limiteTerritorios = $limiteTerritorios ?? 12;
$totalPaginasTerritorios = $totalPaginasTerritorios ?? 1;
$inicioTerritorios = $inicioTerritorios ?? 0;
$filtroInformacion = $filtroInformacion ?? 'todos';
$estadoSeleccionado = $estadoSeleccionado ?? null;
$secretarias = $secretarias ?? [];
$indicadores = $indicadores ?? [];
$municipios = $municipios ?? [];
$priorizacionMunicipal = $priorizacionMunicipal ?? [
    'disponible' => false,
    'total_municipios_con_poblacion' => 0,
    'total_municipios_sin_poblacion' => 0,
    'poblacion_municipal_registrada' => 0,
    'conteos' => ['ALTA' => 0, 'MEDIA' => 0, 'BAJA' => 0],
    'recomendados' => [],
    'por_municipio' => []
];
$actividadEconomicaOficial = $actividadEconomicaOficial ?? [
    'total_establecimientos' => 0,
    'sectores' => []
];
$comparacionEconomicaNacional = $comparacionEconomicaNacional ?? [
    'disponible' => false,
    'sectores' => []
];
$poderAdquisitivoOficial = $poderAdquisitivoOficial ?? [
    'disponible' => false,
    'referencia_nacional' => null
];
$rezagoEducativoOficial = $rezagoEducativoOficial ?? [
    'disponible' => false,
    'referencia_nacional' => null,
    'historico' => []
];
$fuentes = $fuentes ?? [];
$buscarTerritorio = $buscarTerritorio ?? '';
$buscarMunicipio = $buscarMunicipio ?? '';
$paginaMunicipios = $paginaMunicipios ?? 1;
$limiteMunicipios = $limiteMunicipios ?? 10;
$totalMunicipios = $totalMunicipios ?? 0;
$municipiosCargados = $municipiosCargados ?? 0;
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';

$puedeEditarGeneral =
    tienePermiso('data_territorial.editar') ||
    tienePermiso('territorios.actualizar_ficha');
$puedeActualizarInformacionOficial =
    tienePermiso('data_territorial.actualizar_oficial');
$puedeGestionarSecretarias =
    tienePermiso('data_territorial.gestionar_secretarias');
$puedeGestionarMunicipios =
    tienePermiso('data_territorial.gestionar_municipios');
$puedeGestionarIndicadores =
    tienePermiso('data_territorial.gestionar_indicadores');
$esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
$estadosActualizacionMasiva = $puedeActualizarInformacionOficial
    ? array_map(
        function ($territorio) {
            return [
                'id' => (int)($territorio['id'] ?? 0),
                'nombre' => (string)($territorio['nombre'] ?? '')
            ];
        },
        $territoriosActualizacionMasiva
    )
    : [];
$totalEstadosActualizacionMasiva = count($estadosActualizacionMasiva);

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = function ($valor) use ($texto) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return nl2br($texto($valor));
};

$numero = function ($valor) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return number_format((float)$valor, 0, '.', ',');
};

$porcentajeSeguro = function ($valor) {
    if (!is_numeric($valor)) {
        return 0.0;
    }

    return max(0.0, min(100.0, (float)$valor));
};

$valorIndicador = function ($valor, $unidad = '') use ($texto) {
    if ($valor === null || $valor === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    $numeroFormateado = number_format((float)$valor, 2, '.', ',');
    $numeroFormateado = rtrim(rtrim($numeroFormateado, '0'), '.');
    $unidadLimpia = trim((string)$unidad);

    return $numeroFormateado
        . ($unidadLimpia !== '' ? ' ' . $texto($unidadLimpia) : '');
};

$numeroDecimal = function ($valor, $decimales = 2) {
    return number_format((float)$valor, (int)$decimales, '.', ',');
};

$fecha = function ($valorFecha) use ($texto) {
    if (!$valorFecha) {
        return '<span class="detail-muted">No registrado</span>';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    $meses = [
        '01' => 'ene',
        '02' => 'feb',
        '03' => 'mar',
        '04' => 'abr',
        '05' => 'may',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'ago',
        '09' => 'sep',
        '10' => 'oct',
        '11' => 'nov',
        '12' => 'dic'
    ];

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y') . ' · ' .
        $fechaObjeto->format('H:i');
};

$fechaCorta = function ($valorFecha) use ($texto) {
    if (!$valorFecha) {
        return '<span class="detail-muted">Sin información</span>';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    $meses = [
        '01' => 'ene',
        '02' => 'feb',
        '03' => 'mar',
        '04' => 'abr',
        '05' => 'may',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'ago',
        '09' => 'sep',
        '10' => 'oct',
        '11' => 'nov',
        '12' => 'dic'
    ];

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y');
};

$fechaTerritorial = function ($valorFecha) use ($texto) {
    if (!$valorFecha) {
        return '<span class="detail-muted">Sin información</span>';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    $meses = [
        '01' => 'ene',
        '02' => 'feb',
        '03' => 'mar',
        '04' => 'abr',
        '05' => 'may',
        '06' => 'jun',
        '07' => 'jul',
        '08' => 'ago',
        '09' => 'sep',
        '10' => 'oct',
        '11' => 'nov',
        '12' => 'dic'
    ];

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y') . ' · ' .
        $fechaObjeto->format('H:i');
};

$fechaInput = function ($valorFecha) {
    if (!$valorFecha) {
        return '';
    }

    try {
        return (new DateTime($valorFecha))->format('Y-m-d\TH:i');
    } catch (Exception $error) {
        return '';
    }
};

$fuenteHtml = function ($fuente, $mostrarVacio = false) use ($texto, $fecha) {
    if (empty($fuente)) {
        return $mostrarVacio
            ? '<p class="data-source-empty">Fuente no registrada.</p>'
            : '';
    }

    $tipos = [
        'AUTOMATICA' => 'Automática',
        'IMPORTACION' => 'Importada',
        'MANUAL' => 'Verificada manualmente'
    ];
    $tipo = $tipos[$fuente['tipo_actualizacion'] ?? ''] ?? '';

    $html = '<div class="data-source-box">';
    $html .= '<span>Fuente: <strong>' . $texto($fuente['fuente'] ?? '') . '</strong></span>';

    if (!empty($fuente['periodo'])) {
        $html .= '<span>Periodo: <strong>' . $texto($fuente['periodo']) . '</strong></span>';
    }

    if ($tipo !== '') {
        $html .= '<span class="data-source-badge">' . $texto($tipo) . '</span>';
    }

    if (!empty($fuente['fecha_consulta'])) {
        $html .= '<span>Última consulta: <strong>' . $fecha($fuente['fecha_consulta']) . '</strong></span>';
    }

    if (!empty($fuente['verificado_por'])) {
        $html .= '<span>Verificado por: <strong>' . $texto($fuente['verificado_por']) . '</strong></span>';
    }

    return $html . '</div>';
};

$tieneInformacion = function ($territorio) {
    return (int)($territorio['tiene_informacion_territorial'] ?? 0) === 1;
};

$badgeInformacion = function ($territorio) use ($tieneInformacion) {
    return $tieneInformacion($territorio)
        ? '<span class="data-info-badge data-info-badge-complete">Con información</span>'
        : '<span class="data-info-badge data-info-badge-empty">Sin información</span>';
};

$aliasTerritorio = function ($territorio) use ($texto) {
    $alias = trim((string)($territorio['nombre_corto'] ?? ''));
    $nombre = trim((string)($territorio['nombre'] ?? ''));

    if ($alias === '' || strtolower($alias) === strtolower($nombre)) {
        return '';
    }

    return '<p>' . $texto($alias) . '</p>';
};

$avanceMunicipios = function ($territorio) {
    $cargados = (int)($territorio['municipios_cargados'] ?? 0);

    if (($territorio['total_municipios'] ?? null) !== null) {
        return $cargados . ' de ' . (int)$territorio['total_municipios'] . ' cargados';
    }

    return $cargados . ' cargados';
};

$avanceSecretarias = function ($territorio) {
    $cargadas = (int)($territorio['secretarias_cargadas'] ?? 0);

    if (($territorio['total_secretarias'] ?? null) !== null) {
        return $cargadas . ' de ' . (int)$territorio['total_secretarias'] . ' cargadas';
    }

    return $cargadas . ' cargadas';
};

$redesSociales = function ($valor) use ($texto) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    if (filter_var($valor, FILTER_VALIDATE_URL)) {
        return '<a class="data-external-link" href="' .
            $texto($valor) .
            '" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i> Abrir red social</a>';
    }

    return $texto($valor);
};

$fotoTitularUrl = $estadoSeleccionado
    ? obtenerUrlArchivoPublico(
        $estadoSeleccionado['foto_titular'] ?? '',
        ['public/uploads/territorios/titulares', 'public/uploads/usuarios']
    )
    : '';
$mapaEstadoUrl = $estadoSeleccionado
    ? obtenerUrlArchivoPublico(
        $estadoSeleccionado['mapa_estado'] ?? '',
        ['public/uploads/territorios/mapas']
    )
    : '';
$totalMunicipiosEsperado = $estadoSeleccionado
    ? $estadoSeleccionado['total_municipios']
    : null;
$textoAvanceMunicipios = $estadoSeleccionado && $totalMunicipiosEsperado !== null
    ? (int)$municipiosCargados . ' de ' . (int)$totalMunicipiosEsperado . ' cargados'
    : (int)$municipiosCargados . ' cargados';
$textoAvanceSecretarias = $estadoSeleccionado
    ? $avanceSecretarias($estadoSeleccionado)
    : '';
$hayFiltrosTerritorios =
    trim($buscarTerritorio) !== '' ||
    $filtroInformacion !== 'todos';
$primerTerritorioMostrado = $totalTerritorios > 0 ? $inicioTerritorios + 1 : 0;
$ultimoTerritorioMostrado = min($inicioTerritorios + $limiteTerritorios, $totalTerritorios);
$urlPaginaTerritorio = function ($pagina) use ($buscarTerritorio, $filtroInformacion) {
    return BASE_URL . 'index.php?' . http_build_query([
        'controller' => 'dataTerritorial',
        'action' => 'index',
        'buscar' => $buscarTerritorio,
        'filtro_informacion' => $filtroInformacion,
        'pagina_territorios' => $pagina
    ]);
};

?>

<section class="data-territorial-module">
    <?php if (!$estadoSeleccionado): ?>
        <section class="dashboard-panel data-territorial-selector">
            <div class="data-selector-heading">
                <div class="data-territorial-selector-copy">
                    <h2 class="panel-title mb-1">
                        Seleccionar territorio
                    </h2>
                    <p>
                        Busca o selecciona un Estado para consultar su ficha territorial.
                    </p>
                </div>

                <?php if ($puedeActualizarInformacionOficial && $totalEstadosActualizacionMasiva > 0): ?>
                    <div class="data-territorial-selector-actions">
                        <button
                            type="button"
                            class="btn btn-system-light data-mass-update-button"
                            data-bs-toggle="modal"
                            data-bs-target="#modalActualizarInformacionEstados">
                            <i class="bi bi-arrow-repeat me-2"></i>
                            Actualizar información oficial
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <form
                class="data-territorial-toolbar"
                action="<?= BASE_URL ?>index.php"
                method="GET">
                <input type="hidden" name="controller" value="dataTerritorial">
                <input type="hidden" name="action" value="index">
                <input type="hidden" name="pagina_territorios" value="1" data-territory-page-input>

                <div class="data-filter-field">
                    <label for="buscar_territorio">Buscar territorio</label>
                    <div class="module-search">
                        <i class="bi bi-search"></i>
                        <input
                            type="search"
                            class="form-control"
                            id="buscar_territorio"
                            name="buscar"
                            value="<?= $texto($buscarTerritorio) ?>"
                            placeholder="Buscar territorio..."
                            aria-label="Buscar territorio">
                    </div>
                </div>

                <div class="data-filter-field">
                    <label for="filtro_informacion">Estado de información</label>
                    <select
                        class="form-select"
                        id="filtro_informacion"
                        name="filtro_informacion"
                        aria-label="Filtrar por estado de información">
                        <option value="todos" <?= $filtroInformacion === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="sin" <?= $filtroInformacion === 'sin' ? 'selected' : '' ?>>Sin información</option>
                        <option value="con" <?= $filtroInformacion === 'con' ? 'selected' : '' ?>>Con información</option>
                    </select>
                </div>

                <div class="data-filter-field">
                    <label for="selector_estado">Territorio</label>
                    <select
                        class="form-select data-territorial-state-select"
                        id="selector_estado"
                        name="estado_id"
                        aria-label="Seleccionar Estado">
                        <option value="">Seleccionar Estado</option>

                        <?php foreach ($territoriosInterfaz as $territorio): ?>
                            <option value="<?= (int)$territorio['id'] ?>">
                                <?= $texto($territorio['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($totalTerritoriosDisponibles === 0): ?>

        <section class="dashboard-panel data-empty-state">
            <span><i class="bi bi-map"></i></span>
            <strong>No tienes territorios asignados actualmente.</strong>
            <p>No hay información registrada para consultar.</p>
        </section>

    <?php elseif (!$estadoSeleccionado): ?>

        <?php if ($hayFiltrosTerritorios): ?>
            <div class="data-filter-summary">
                <span>
                    Mostrando resultados filtrados.
                </span>
                <a href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index">
                    Limpiar filtros
                </a>
            </div>
        <?php endif; ?>

        <section class="data-territorial-cards" data-territory-cards>
            <?php foreach ($territoriosInterfaz as $territorio): ?>
                 <?php
                        $slugEstado = strtolower($territorio['nombre'] ?? '');
                        $slugEstado = iconv('UTF-8','ASCII//TRANSLIT',$slugEstado);

                        $slugEstado = preg_replace('/[^a-z0-9]+/','-',$slugEstado);

                        $slugEstado = trim($slugEstado, '-');

                        $nombreEstado = trim($territorio['nombre'] ?? '');

                        if ($nombreEstado === 'Ciudad de México') {
                        $slugEstado = 'ciudad-de-mexico';
                        }

                        if ($nombreEstado === 'Estado de México') {
                        $slugEstado = 'estado-de-mexico';
                        }

                        $imagenEstado = BASE_URL. 'public/img/estados/'. $slugEstado. '.png';
                        ?>
                <article
                    class="dashboard-panel data-territorial-card"
                    role="link"
                    tabindex="0"
                    data-territory-card
                    data-territory-name="<?= $texto($territorio['nombre'] ?? '') ?>"
                    data-territory-alias="<?= $texto($territorio['nombre_corto'] ?? '') ?>"
                    data-territory-info="<?= $tieneInformacion($territorio) ? 'con' : 'sin' ?>"
                    data-card-url="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index&estado_id=<?= (int)$territorio['id'] ?>">
                   <div class="data-card-header">

    <div class="data-state-image">
        <img
            src="<?= htmlspecialchars($imagenEstado) ?>"
            alt="Mapa de <?= $texto($territorio['nombre']) ?>">
    </div>

    <div class="data-card-header-info">

        <div class="data-card-heading">
            <h3><?= $texto($territorio['nombre']) ?></h3>
            <?= $aliasTerritorio($territorio) ?>
        </div>

        <?= $badgeInformacion($territorio) ?>

    </div>

</div>

                    <dl class="data-card-meta">
                        <div>
                            <dt>Municipios</dt>
                            <dd><?= $texto($avanceMunicipios($territorio)) ?></dd>
                        </div>
                        <div>
                            <dt>Última actualización</dt>
                            <dd><?= $fechaCorta($territorio['fecha_actualizacion'] ?? null) ?></dd>
                        </div>
                    </dl>

                    <a
                        class="btn btn-system-light"
                        href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index&estado_id=<?= (int)$territorio['id'] ?>">
                        Ver ficha
                    </a>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="dashboard-panel data-empty-state d-none" data-territory-empty>
            <span><i class="bi bi-search"></i></span>
            <strong>No se encontraron territorios.</strong>
            <p>Prueba con otro nombre o cambia los filtros.</p>
        </section>

        <div class="data-pagination data-territory-pagination" data-territory-pagination>
            <span data-territory-counter>
                Mostrando <?= (int)$primerTerritorioMostrado ?> a <?= (int)$ultimoTerritorioMostrado ?>
                de <?= (int)$totalTerritorios ?> territorios
            </span>

            <div data-territory-pages></div>
        </div>

    <?php else: ?>

        <section class="data-state-header">
            <div class="data-territorial-detail-nav">
                <a
                    class="data-territorial-detail-back"
                    href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index">
                    <i class="bi bi-arrow-left"></i>
                    Volver a territorios
                </a>

                <div class="data-territorial-detail-switch">
                    <label
                        class="data-territorial-detail-switch-label"
                        for="selector_estado_detalle">
                        Cambiar territorio
                    </label>
                    <select
                        class="form-select data-territorial-detail-select"
                        id="selector_estado_detalle"
                        aria-label="Cambiar territorio"
                        data-territory-detail-select
                        data-current-state="<?= (int)$estadoSeleccionado['id'] ?>">
                        <?php foreach ($territoriosInterfaz as $territorio): ?>
                            <option
                                value="<?= (int)$territorio['id'] ?>"
                                <?= (int)$estadoSeleccionado['id'] === (int)$territorio['id'] ? 'selected' : '' ?>>
                                <?= $texto($territorio['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dashboard-panel data-state-summary">
                <div class="data-state-main">
                    <div class="data-state-title">
                        <span>INFORMACIÓN TERRITORIAL</span>
                        <h2><?= $texto($estadoSeleccionado['nombre']) ?></h2>
                        <?= $aliasTerritorio($estadoSeleccionado) ?>
                        <?= $badgeInformacion($estadoSeleccionado) ?>
                    </div>

                    <div class="data-state-profile">
                        <?php if ($fotoTitularUrl !== ''): ?>
                            <button
                                type="button"
                                class="data-territorial-holder-avatar data-state-photo"
                                data-profile-photo
                                data-photo-url="<?= $texto($fotoTitularUrl) ?>"
                                data-photo-name="<?= $texto($estadoSeleccionado['titular_gobierno'] ?: $estadoSeleccionado['nombre']) ?>"
                                data-photo-role="<?= $texto($estadoSeleccionado['cargo_titular'] ?: 'Titular del gobierno') ?>"
                                aria-label="Ver foto de <?= $texto($estadoSeleccionado['titular_gobierno'] ?: $estadoSeleccionado['nombre']) ?>">
                                <img
                                    src="<?= $texto($fotoTitularUrl) ?>"
                                alt="Foto de <?= $texto($estadoSeleccionado['titular_gobierno'] ?: $estadoSeleccionado['nombre']) ?>">
                            </button>
                        <?php else: ?>
                            <div class="data-territorial-holder-avatar" aria-hidden="true">
                                <i class="bi bi-person"></i>
                            </div>
                        <?php endif; ?>

                        <div>
                            <strong>
                                <?= trim((string)$estadoSeleccionado['titular_gobierno']) !== ''
                                    ? $texto($estadoSeleccionado['titular_gobierno'])
                                    : 'Titular no registrado' ?>
                            </strong>
                            <span>
                                <?= trim((string)$estadoSeleccionado['cargo_titular']) !== ''
                                    ? $texto($estadoSeleccionado['cargo_titular'])
                                    : 'Gobernador(a)' ?>
                            </span>
                        </div>
                    </div>

                    <dl class="data-state-facts">
                        <div><dt>Partido político</dt><dd><?= $valor($estadoSeleccionado['partido_politico']) ?></dd></div>
                        <div><dt>Población</dt><dd><?= $numero($estadoSeleccionado['poblacion']) ?></dd></div>
                        <div><dt>Municipios</dt><dd><?= $texto($textoAvanceMunicipios) ?></dd></div>
                        <div><dt>Secretarías</dt><dd><?= $texto($textoAvanceSecretarias) ?></dd></div>
                        <div><dt>Periodo de gobierno</dt><dd><?= $valor($estadoSeleccionado['periodo_gobierno']) ?></dd></div>
                        <div><dt>Capital</dt><dd><?= $valor($estadoSeleccionado['capital']) ?></dd></div>
                        <div><dt>Teléfono de contacto</dt><dd><?= $valor($estadoSeleccionado['telefono']) ?></dd></div>
                        <div><dt>Redes sociales</dt><dd><?= $redesSociales($estadoSeleccionado['redes_sociales']) ?></dd></div>
                        <div class="data-state-fact-wide">
                            <dt>Última actualización</dt>
                            <dd><?= $fechaTerritorial($estadoSeleccionado['fecha_actualizacion']) ?></dd>
                        </div>
                    </dl>
                </div>

                <div class="data-state-map">
                    <?php if ($mapaEstadoUrl !== ''): ?>
                        <button
                            type="button"
                            data-profile-photo
                            data-photo-url="<?= $texto($mapaEstadoUrl) ?>"
                            data-photo-name="Mapa de <?= $texto($estadoSeleccionado['nombre']) ?>"
                            data-photo-role="Mapa estatal"
                            aria-label="Ver mapa de <?= $texto($estadoSeleccionado['nombre']) ?>">
                            <img
                                src="<?= $texto($mapaEstadoUrl) ?>"
                                alt="Mapa de <?= $texto($estadoSeleccionado['nombre']) ?>">
                        </button>
                    <?php else: ?>
                        <div>
                            <i class="bi bi-map"></i>
                            <span>Mapa no disponible</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($puedeEditarGeneral): ?>
                        <button
                            type="button"
                            class="btn btn-system-light"
                            title="Actualizar información general del territorio"
                            data-bs-toggle="modal"
                            data-bs-target="#modalFichaGeneral">
                            <i class="bi bi-pencil me-2"></i>
                            Editar ficha territorial
                        </button>
                    <?php endif; ?>

                    <?php if ($puedeActualizarInformacionOficial): ?>
                        <button
                            type="button"
                            class="btn btn-system-light data-official-update-button"
                            data-bs-toggle="modal"
                            data-bs-target="#modalActualizarInformacionOficial">
                            <i class="bi bi-arrow-clockwise me-2"></i>
                            Actualizar información oficial
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <nav class="data-section-nav" aria-label="Navegación de información territorial">
            <a class="active" href="#resumen">Resumen</a>
            <a href="#secretarias">Secretarías</a>
            <a href="#economia">Economía</a>
            <a href="#educacion">Educación</a>
            <a href="#municipios">Municipios</a>
        </nav>

        <section class="dashboard-panel data-section" id="resumen">
            <div class="data-section-header">
                <div>
                    <span>RESUMEN</span>
                    <h3>Resumen general del Estado</h3>
                </div>
            </div>
            <?= $fuenteHtml($fuentes['GENERAL'] ?? null, true) ?>
        </section>

        <section class="dashboard-panel data-section" id="secretarias">
            <div class="data-section-header">
                <div>
                    <span>SECRETARÍAS DEL ESTADO</span>
                    <h3>Dependencias estatales registradas.</h3>
                </div>

                <?php if ($puedeGestionarSecretarias): ?>
                    <button
                        type="button"
                        class="btn btn-system-save"
                        data-secretaria-create
                        data-bs-toggle="modal"
                        data-bs-target="#modalSecretaria">
                        <i class="bi bi-plus-circle me-2"></i>
                        Agregar secretaría
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($secretarias)): ?>
                <div class="table-responsive">
                    <table class="table users-table data-table align-middle">
                        <thead>
                            <tr>
                                <th>Secretaría</th>
                                <th>Titular</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Sitio web</th>
                                <?php if ($puedeGestionarSecretarias): ?>
                                    <th class="text-end">Acciones</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($secretarias as $secretaria): ?>
                                <tr>
                                    <td>
                                        <strong><?= $texto($secretaria['nombre']) ?></strong>
                                        <?= (int)$secretaria['estado'] === 1
                                            ? ''
                                            : '<span class="status-pill status-pill-inactive ms-2">Inactiva</span>' ?>
                                    </td>
                                    <td><?= $valor($secretaria['titular']) ?></td>
                                    <td><?= $valor($secretaria['correo']) ?></td>
                                    <td><?= $valor($secretaria['telefono']) ?></td>
                                    <td><?= $valor($secretaria['sitio_web']) ?></td>

                                    <?php if ($puedeGestionarSecretarias): ?>
                                        <td class="text-end">
                                            <div class="table-actions">
                                                <button
                                                    type="button"
                                                    class="table-action-button"
                                                    aria-label="Editar secretaría"
                                                    data-secretaria-edit
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalSecretaria"
                                                    data-id="<?= (int)$secretaria['id'] ?>"
                                                    data-nombre="<?= $texto($secretaria['nombre']) ?>"
                                                    data-titular="<?= $texto($secretaria['titular']) ?>"
                                                    data-cargo-titular="<?= $texto($secretaria['cargo_titular']) ?>"
                                                    data-correo="<?= $texto($secretaria['correo']) ?>"
                                                    data-telefono="<?= $texto($secretaria['telefono']) ?>"
                                                    data-sitio-web="<?= $texto($secretaria['sitio_web']) ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <form
                                                    action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=cambiarEstadoSecretaria"
                                                    method="POST">
                                                    <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                                                    <input type="hidden" name="id" value="<?= (int)$secretaria['id'] ?>">
                                                    <input type="hidden" name="estado" value="<?= (int)$secretaria['estado'] === 1 ? 0 : 1 ?>">
                                                    <button
                                                        type="submit"
                                                        class="table-action-button"
                                                        aria-label="<?= (int)$secretaria['estado'] === 1 ? 'Desactivar secretaría' : 'Activar secretaría' ?>">
                                                        <i class="bi <?= (int)$secretaria['estado'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="data-empty-text">No hay secretarías registradas para este territorio.</p>
            <?php endif; ?>

            <?= $fuenteHtml($fuentes['SECRETARIAS'] ?? null) ?>
        </section>

        <section class="dashboard-panel data-section" id="economia">
            <div class="data-section-header">
                <div>
                    <span>ECONOMÍA</span>
                    <h3>Actividad económica y poder adquisitivo</h3>
                </div>

                <?php if (tienePermiso('data_territorial.editar')): ?>
                    <button
                        type="button"
                        class="btn btn-system-light"
                        data-bs-toggle="modal"
                        data-bs-target="#modalEconomia">
                        <i class="bi bi-pencil me-2"></i>
                        Editar contexto económico
                    </button>
                <?php endif; ?>
            </div>

            <?php
            $sectoresEconomicos = $actividadEconomicaOficial['sectores'] ?? [];
            $totalEstablecimientosEconomicos =
                (int)($actividadEconomicaOficial['total_establecimientos'] ?? 0);
            $fuenteActividadEconomica = $fuentes['ACTIVIDAD_ECONOMICA'] ?? null;
            $sectoresPresenciaNacional = [];

            if (
                ($comparacionEconomicaNacional['disponible'] ?? false) === true &&
                !empty($comparacionEconomicaNacional['sectores'])
            ) {
                $sectoresPresenciaNacional = array_slice(
                    array_values(
                        array_filter(
                            $comparacionEconomicaNacional['sectores'],
                            function ($sector) {
                                return (float)($sector['diferencia_puntos'] ?? 0) > 0;
                            }
                        )
                    ),
                    0,
                    3
                );
            }
            ?>

            <div class="data-economy-layout">
                <article class="data-economy-card">
                    <h4>Actividad económica</h4>

                    <div class="data-economy-manual">
                        <span>Contexto económico del territorio</span>
                        <p>
                            <?= trim((string)$estadoSeleccionado['actividad_economica']) !== ''
                                ? $valor($estadoSeleccionado['actividad_economica'])
                                : '<span class="detail-muted">Sin contexto complementario registrado.</span>' ?>
                        </p>
                    </div>

                    <div class="data-economic-official">
                        <div class="data-economic-official-header">
                            <div>
                                <h5>Distribución de establecimientos por sector</h5>
                                <p>
                                    Distribución de los establecimientos registrados en DENUE según sector
                                    de actividad económica.
                                </p>
                            </div>
                        </div>

                        <?php if (!empty($sectoresEconomicos)): ?>
                            <div class="data-economic-summary" aria-label="Resumen de actividad económica oficial">
                                <div>
                                    <span>Establecimientos registrados</span>
                                    <strong><?= number_format($totalEstablecimientosEconomicos, 0, '.', ',') ?></strong>
                                </div>
                                <div>
                                    <span>Sectores identificados</span>
                                    <strong><?= count($sectoresEconomicos) ?></strong>
                                </div>
                            </div>

                            <div class="data-economic-list-heading">
                                <span>Principales sectores</span>
                                <small>Se muestran inicialmente los 5 sectores con más establecimientos.</small>
                            </div>

                            <div class="data-economic-sector-list">
                                <?php foreach ($sectoresEconomicos as $indiceSector => $sector): ?>
                                    <?php
                                    $porcentajeSector = $porcentajeSeguro($sector['porcentaje'] ?? 0);
                                    $porcentajeAncho = number_format($porcentajeSector, 2, '.', '');
                                    $sectorExtra = $indiceSector >= 5;
                                    ?>
                                    <div class="data-economic-sector-row <?= $sectorExtra ? 'data-economic-sector-extra d-none' : '' ?>">
                                        <div class="data-economic-sector-main">
                                            <span class="data-economic-sector-key">
                                                <?= $texto($sector['clave_sector'] ?? '') ?>
                                            </span>
                                            <strong><?= $texto($sector['nombre_sector'] ?? '') ?></strong>
                                        </div>
                                        <div class="data-economic-sector-metric">
                                            <span><?= number_format((int)($sector['establecimientos'] ?? 0), 0, '.', ',') ?></span>
                                            <small>establecimientos</small>
                                        </div>
                                        <div class="data-economic-sector-percent">
                                            <span><?= number_format($porcentajeSector, 2, '.', ',') ?> %</span>
                                            <div
                                                class="data-economic-progress"
                                                aria-hidden="true">
                                                <div style="width: <?= $porcentajeAncho ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (count($sectoresEconomicos) > 5): ?>
                                <button
                                    type="button"
                                    class="data-economic-toggle"
                                    data-economic-toggle
                                    aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                    Ver todos los sectores
                                </button>
                            <?php endif; ?>

                            <p class="data-economic-note">
                                Los porcentajes corresponden a la proporción de establecimientos registrados en DENUE
                                y no representan aportación al PIB.
                            </p>

                            <?= $fuenteHtml($fuenteActividadEconomica) ?>
                        <?php else: ?>
                            <p class="data-empty-text">
                                Aún no hay información económica oficial registrada.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($sectoresPresenciaNacional)): ?>
                        <div class="data-economic-national">
                            <div class="data-economic-national-heading">
                                <div>
                                    <h5>
                                        Sectores con mayor presencia respecto al país
                                        <i
                                            class="bi bi-info-circle"
                                            title="Compara la proporción de establecimientos de cada sector en el Estado con su participación a nivel nacional."></i>
                                    </h5>
                                    <p>
                                        Sectores cuya proporción de establecimientos en este Estado supera
                                        en mayor medida su participación dentro de la distribución nacional.
                                    </p>
                                </div>
                            </div>

                            <div class="data-economic-national-list">
                                <?php foreach ($sectoresPresenciaNacional as $sectorComparado): ?>
                                    <article class="data-economic-national-card">
                                        <strong><?= $texto($sectorComparado['nombre_sector'] ?? '') ?></strong>

                                        <div class="data-economic-national-values">
                                            <div>
                                                <span>Estado</span>
                                                <b><?= $numeroDecimal($sectorComparado['porcentaje_estatal'] ?? 0) ?> %</b>
                                            </div>
                                            <div>
                                                <span>Nacional</span>
                                                <b><?= $numeroDecimal($sectorComparado['porcentaje_nacional'] ?? 0) ?> %</b>
                                            </div>
                                        </div>

                                        <div class="data-economic-national-meta">
                                            <span>
                                                +<?= $numeroDecimal($sectorComparado['diferencia_puntos'] ?? 0) ?> pts.
                                            </span>
                                            <small>
                                                <?= $numeroDecimal($sectorComparado['indice_relativo'] ?? 0) ?>× respecto al país
                                            </small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <p class="data-economic-national-note">
                                Referencia calculada con la distribución de establecimientos DENUE de los
                                32 Estados disponibles en el sistema.
                            </p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="data-economy-card data-economy-card-compact data-purchasing-card">
                    <h4>Poder adquisitivo</h4>

                    <div class="data-economy-manual data-purchasing-context">
                        <span class="data-economy-context-title">Contexto sobre poder adquisitivo</span>
                        <p>
                            <?= trim((string)$estadoSeleccionado['poder_adquisitivo']) !== ''
                                ? $valor($estadoSeleccionado['poder_adquisitivo'])
                                : '<span class="detail-muted">Sin contexto complementario registrado.</span>' ?>
                        </p>
                    </div>

                    <?php if (($poderAdquisitivoOficial['disponible'] ?? false) === true): ?>
                        <?php
                            $trimestrePoder = (int)($poderAdquisitivoOficial['trimestre'] ?? 0);
                            $anioPoder = (int)($poderAdquisitivoOficial['anio'] ?? 0);
                            $nombresTrimestre = [
                                1 => 'Primer trimestre',
                                2 => 'Segundo trimestre',
                                3 => 'Tercer trimestre',
                                4 => 'Cuarto trimestre'
                            ];
                            $periodoPoder = ($nombresTrimestre[$trimestrePoder] ?? 'Trimestre') .
                                ($anioPoder > 0 ? ' de ' . $anioPoder : '');
                            $referenciaPoder = $poderAdquisitivoOficial['referencia_nacional'] ?? null;
                            $diferenciaIngreso = $poderAdquisitivoOficial['diferencia_ingreso_nacional'] ?? null;
                            $diferenciaPobreza = $poderAdquisitivoOficial['diferencia_pobreza_nacional'] ?? null;
                        ?>

                        <div class="data-purchasing-official">
                            <div class="data-purchasing-heading">
                                <div>
                                    <h5>Indicadores oficiales</h5>
                                    <p>Seguimiento del ingreso laboral y su relación con el costo de la canasta alimentaria.</p>
                                </div>
                                <span><?= $texto($periodoPoder) ?></span>
                            </div>

                            <div class="data-purchasing-metrics">
                                <article class="data-purchasing-metric">
                                    <div class="data-purchasing-metric-title">
                                        <i class="bi bi-cash-stack" aria-hidden="true"></i>
                                        <span>Ingreso laboral real per cápita</span>
                                    </div>
                                    <strong>$<?= $numeroDecimal($poderAdquisitivoOficial['ingreso_laboral_real_per_capita'] ?? 0) ?></strong>

                                    <?php if (is_array($referenciaPoder)): ?>
                                        <div class="data-purchasing-reference">
                                            <span>
                                                Referencia nacional
                                                <b>$<?= $numeroDecimal($referenciaPoder['ingreso_laboral_real_per_capita'] ?? 0) ?></b>
                                            </span>
                                            <?php if ($diferenciaIngreso !== null): ?>
                                                <small>
                                                    <?= (float)$diferenciaIngreso >= 0 ? '+' : '-' ?>$<?= $numeroDecimal(abs((float)$diferenciaIngreso)) ?> respecto al país
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>

                                <article class="data-purchasing-metric">
                                    <div class="data-purchasing-metric-title">
                                        <i class="bi bi-basket2" aria-hidden="true"></i>
                                        <span>Pobreza laboral</span>
                                    </div>
                                    <strong><?= $numeroDecimal($poderAdquisitivoOficial['pobreza_laboral'] ?? 0) ?> %</strong>

                                    <?php if (is_array($referenciaPoder)): ?>
                                        <div class="data-purchasing-reference">
                                            <span>
                                                Referencia nacional
                                                <b><?= $numeroDecimal($referenciaPoder['pobreza_laboral'] ?? 0) ?> %</b>
                                            </span>
                                            <?php if ($diferenciaPobreza !== null): ?>
                                                <small>
                                                    <?= (float)$diferenciaPobreza >= 0 ? '+' : '-' ?><?= $numeroDecimal(abs((float)$diferenciaPobreza)) ?> pts. respecto al país
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            </div>

                            <p class="data-purchasing-note">
                                El ingreso laboral real per cápita está expresado en pesos del primer trimestre de 2020,
                                deflactados con el INPC. La pobreza laboral representa a la población cuyo ingreso laboral
                                per cápita es inferior al costo de la canasta alimentaria.
                            </p>

                            <?= $fuenteHtml($fuentes['PODER_ADQUISITIVO'] ?? null) ?>
                        </div>
                    <?php else: ?>
                        <p class="data-empty-text data-purchasing-empty">
                            Aún no hay indicadores oficiales de poder adquisitivo registrados para este territorio.
                        </p>
                    <?php endif; ?>
                </article>
            </div>
        </section>

        <section class="dashboard-panel data-section" id="educacion">
            <div class="data-section-header">
                <div>
                    <span>INDICADORES EDUCATIVOS</span>
                    <h3>Educación</h3>
                </div>

            </div>

            <div class="data-education-official">
                <div class="data-education-official-heading">
                    <div>
                        <span>REZAGO EDUCATIVO</span>
                        <h4>Rezago educativo oficial</h4>
                        <p>
                            Población que presenta rezago educativo de acuerdo con la medición oficial de
                            Pobreza Multidimensional de INEGI.
                        </p>
                    </div>

                    <?php if (($rezagoEducativoOficial['disponible'] ?? false) === true): ?>
                        <span class="data-education-period">
                            <?= (int)($rezagoEducativoOficial['anio'] ?? 0) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (($rezagoEducativoOficial['disponible'] ?? false) === true): ?>
                    <?php
                        $referenciaRezago = $rezagoEducativoOficial['referencia_nacional'] ?? null;
                        $diferenciaRezago = $rezagoEducativoOficial['diferencia_nacional'] ?? null;
                        $historicoRezago = $rezagoEducativoOficial['historico'] ?? [];
                    ?>

                    <div class="data-education-metrics">
                        <article class="data-education-metric data-education-metric-primary">
                            <span>Porcentaje de la población</span>
                            <strong><?= $numeroDecimal($rezagoEducativoOficial['porcentaje'] ?? 0) ?> %</strong>

                            <?php if (is_array($referenciaRezago)): ?>
                                <div class="data-education-reference">
                                    <span>
                                        Referencia nacional
                                        <b><?= $numeroDecimal($referenciaRezago['porcentaje'] ?? 0) ?> %</b>
                                    </span>
                                    <?php if ($diferenciaRezago !== null): ?>
                                        <small>
                                            <?= (float)$diferenciaRezago >= 0 ? '+' : '-' ?><?= $numeroDecimal(abs((float)$diferenciaRezago)) ?> pts. respecto al país
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>

                        <article class="data-education-metric">
                            <span>Personas con rezago educativo</span>
                            <strong><?= $numero($rezagoEducativoOficial['cantidad_personas'] ?? 0) ?></strong>
                            <small>Estimación de personas para el periodo mostrado.</small>
                        </article>
                    </div>

                    <?php if (!empty($historicoRezago)): ?>
                        <div class="data-education-history">
                            <div class="data-education-history-heading">
                                <strong>Evolución registrada</strong>
                                <span>Porcentaje de población con rezago educativo</span>
                            </div>
                            <div class="data-education-history-list">
                                <?php foreach ($historicoRezago as $periodoRezago): ?>
                                    <div>
                                        <span><?= (int)($periodoRezago['anio'] ?? 0) ?></span>
                                        <strong><?= $numeroDecimal($periodoRezago['porcentaje'] ?? 0) ?> %</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="data-education-note">
                        La comparación nacional utiliza el dato de México del mismo periodo. Los valores históricos
                        se conservan para identificar cambios entre mediciones sin reemplazar los años anteriores.
                    </p>

                    <?= $fuenteHtml($fuentes['EDUCACION'] ?? null) ?>
                <?php else: ?>
                    <p class="data-empty-text data-education-empty">
                        Aún no hay información oficial de rezago educativo importada para este territorio.
                    </p>
                <?php endif; ?>
            </div>

            <div class="data-education-complementary">
                <div class="data-education-complementary-heading">
                    <div>
                        <span>INFORMACIÓN COMPLEMENTARIA</span>
                        <h4>Indicadores educativos complementarios</h4>
                        <p>Información adicional registrada por el equipo de análisis a partir de fuentes verificadas.</p>
                    </div>

                    <?php if ($puedeGestionarIndicadores): ?>
                        <button
                            type="button"
                            class="btn btn-system-save data-education-add-indicator"
                            data-indicador-create
                            data-bs-toggle="modal"
                            data-bs-target="#modalIndicador">
                            <i class="bi bi-plus-circle me-2"></i>
                            Agregar indicador educativo
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($indicadores)): ?>
                    <div class="table-responsive">
                        <table class="table users-table data-table align-middle">
                            <thead>
                                <tr>
                                    <th>Indicador educativo</th>
                                    <th>Valor</th>
                                    <th>Cantidad aproximada</th>
                                    <th>Fuente</th>
                                    <th>Periodo</th>
                                    <?php if ($puedeGestionarIndicadores): ?>
                                        <th class="text-end">Acciones</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($indicadores as $indicador): ?>
                                    <?php
                                        $valorPrincipalIndicador = $indicador['valor'] ?? $indicador['porcentaje'] ?? null;
                                        $unidadIndicador = $indicador['unidad'] ?? (
                                            ($indicador['porcentaje'] ?? null) !== null ? '%' : ''
                                        );
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= $texto($indicador['situacion']) ?></strong>
                                        </td>
                                        <td><?= $valorIndicador($valorPrincipalIndicador, $unidadIndicador) ?></td>
                                        <td><?= $indicador['cantidad_aproximada'] !== null ? $numero($indicador['cantidad_aproximada']) : '<span class="detail-muted">No registrada</span>' ?></td>
                                        <td><?= $valor($indicador['fuente']) ?></td>
                                        <td><?= $valor($indicador['periodo']) ?></td>

                                        <?php if ($puedeGestionarIndicadores): ?>
                                            <td class="text-end">
                                                <div class="table-actions">
                                                    <button
                                                        type="button"
                                                        class="table-action-button"
                                                        aria-label="Editar indicador"
                                                        data-indicador-edit
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalIndicador"
                                                        data-id="<?= (int)$indicador['id'] ?>"
                                                        data-situacion="<?= $texto($indicador['situacion']) ?>"
                                                        data-valor="<?= $texto($valorPrincipalIndicador) ?>"
                                                        data-unidad="<?= $texto($unidadIndicador) ?>"
                                                        data-cantidad-aproximada="<?= $texto($indicador['cantidad_aproximada']) ?>"
                                                        data-fuente="<?= $texto($indicador['fuente']) ?>"
                                                        data-periodo="<?= $texto($indicador['periodo']) ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="table-action-button table-action-danger"
                                                        aria-label="Eliminar indicador educativo"
                                                        data-indicador-delete
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalEliminarIndicador"
                                                        data-id="<?= (int)$indicador['id'] ?>"
                                                        data-nombre="<?= $texto($indicador['situacion']) ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="data-empty-text">No hay indicadores educativos complementarios registrados.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="dashboard-panel data-section" id="municipios">
            <div class="data-section-header">
                <div>
                    <span>MUNICIPIOS</span>
                    <h3>
                        Municipios
                        <small>
                            <?= $texto($textoAvanceMunicipios) ?>
                        </small>
                    </h3>
                </div>

            </div>

            <?php if (($priorizacionMunicipal['disponible'] ?? false) === true): ?>
                <?php
                    $conteosPrioridad = $priorizacionMunicipal['conteos'] ?? [];
                    $municipiosRecomendados = $priorizacionMunicipal['recomendados'] ?? [];
                    $municipiosSinPoblacion = (int)($priorizacionMunicipal['total_municipios_sin_poblacion'] ?? 0);
                ?>
                <section class="data-municipality-priority" aria-labelledby="prioridadMunicipalTitulo">
                    <div class="data-municipality-priority-header">
                        <div>
                            <span>PRIORIZACIÓN SUGERIDA</span>
                            <h4 id="prioridadMunicipalTitulo">Municipios prioritarios para vinculación</h4>
                            <p>
                                La priorización combina el Índice de Oportunidad Municipal con la posición de cada
                                municipio dentro de su propio Estado.
                            </p>
                        </div>
                        <div class="data-municipality-priority-help">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span>
                                <strong>Priorización orientativa</strong><br>
                                ATACAR: mayor prioridad · OFRECER: prioridad media · OBSERVAR: seguimiento<br>
                                Educación y economía corresponden al contexto estatal.
                            </span>
                        </div>
                    </div>

                    <div class="data-municipality-priority-summary" aria-label="Resumen de prioridad municipal">
                        <div>
                            <span>Atacar</span>
                            <strong><?= (int)($conteosPrioridad['ALTA'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Ofrecer</span>
                            <strong><?= (int)($conteosPrioridad['MEDIA'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Observar</span>
                            <strong><?= (int)($conteosPrioridad['BAJA'] ?? 0) ?></strong>
                        </div>
                        <div>
                            <span>Con población disponible</span>
                            <strong><?= (int)($priorizacionMunicipal['total_municipios_con_poblacion'] ?? 0) ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($municipiosRecomendados)): ?>
                        <div class="data-municipality-priority-list">
                            <?php foreach ($municipiosRecomendados as $indicePrioridad => $municipioPrioritario): ?>
                                <?php
                                    $clasePrioridad = strtolower((string)($municipioPrioritario['prioridad'] ?? 'MEDIA'));
                                    $accionPrioridad = strtoupper((string)($municipioPrioritario['accion'] ?? 'OFRECER'));
                                    $puntajeMunicipio = (int)($municipioPrioritario['puntaje'] ?? 0);
                                    $coberturaMunicipio = (int)($municipioPrioritario['cobertura_datos'] ?? 0);
                                    $rankingMunicipio = (int)($municipioPrioritario['ranking'] ?? 0);
                                    $totalRankingMunicipio = (int)($municipioPrioritario['total_ranking'] ?? 0);
                                ?>
                                <article class="data-municipality-priority-card">
                                    <div class="data-municipality-priority-rank" aria-hidden="true">
                                        <?= (int)$indicePrioridad + 1 ?>
                                    </div>
                                    <div class="data-municipality-priority-main">
                                        <div class="data-municipality-priority-title">
                                            <strong><?= $texto($municipioPrioritario['nombre'] ?? '') ?></strong>
                                            <span class="data-municipality-priority-badge data-municipality-priority-badge-<?= $texto($clasePrioridad) ?>">
                                                <?= $texto($accionPrioridad) ?>
                                            </span>
                                        </div>
                                        <div class="data-municipality-priority-metrics">
                                            <span>
                                                <i class="bi bi-people" aria-hidden="true"></i>
                                                <?= number_format((int)($municipioPrioritario['poblacion'] ?? 0), 0, '.', ',') ?> habitantes
                                            </span>
                                            <span>
                                                <?= (int)$puntajeMunicipio ?>/100
                                                <?php if ($rankingMunicipio > 0 && $totalRankingMunicipio > 0): ?>
                                                    · ranking <?= (int)$rankingMunicipio ?> de <?= (int)$totalRankingMunicipio ?>
                                                <?php endif; ?>
                                                · cobertura de datos <?= (int)$coberturaMunicipio ?> %
                                            </span>
                                        </div>
                                        <p><?= $texto($municipioPrioritario['motivo'] ?? '') ?></p>
                                    </div>
                                    <button
                                        type="button"
                                        class="data-municipality-priority-action"
                                        data-priority-municipality="<?= $texto($municipioPrioritario['nombre'] ?? '') ?>"
                                        aria-label="Ver <?= $texto($municipioPrioritario['nombre'] ?? '') ?> en la tabla de municipios">
                                        Ver en tabla
                                        <i class="bi bi-arrow-down" aria-hidden="true"></i>
                                    </button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <p class="data-municipality-priority-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Índice calculado con información municipal, contexto estatal y ranking relativo dentro del territorio.
                    </p>

                    <?php if ($municipiosSinPoblacion > 0): ?>
                        <p class="data-municipality-priority-note">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <?= $municipiosSinPoblacion ?> municipio<?= $municipiosSinPoblacion === 1 ? '' : 's' ?> sin población disponible no recibe<?= $municipiosSinPoblacion === 1 ? '' : 'n' ?> puntos por alcance poblacional.
                        </p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="data-municipality-priority data-municipality-priority-empty">
                    <i class="bi bi-bar-chart" aria-hidden="true"></i>
                    <div>
                        <strong>Priorización municipal no disponible</strong>
                        <p>Se requiere población municipal oficial para generar la sugerencia de alcance.</p>
                    </div>
                </section>
            <?php endif; ?>

            <form
                class="data-municipality-toolbar"
                id="municipiosFiltrosForm"
                action="<?= BASE_URL ?>index.php"
                method="GET">
                <input type="hidden" name="controller" value="dataTerritorial">
                <input type="hidden" name="action" value="index">
                <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                <input type="hidden" name="pagina_municipios" value="<?= (int)$paginaMunicipios ?>">

                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        name="buscar_municipio"
                        value="<?= $texto($buscarMunicipio) ?>"
                        placeholder="Buscar municipio..."
                        aria-label="Buscar municipio">
                </div>

                <select
                    class="form-select"
                    name="limite_municipios"
                    aria-label="Registros por página">
                    <?php foreach ([10, 15, 20] as $limite): ?>
                        <option value="<?= $limite ?>" <?= (int)$limiteMunicipios === $limite ? 'selected' : '' ?>>
                            <?= $limite ?> registros
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div id="municipiosTablaContenido">
                <?php require __DIR__ . '/municipios_tabla.php'; ?>
            </div>

            <?= $fuenteHtml($fuentes['MUNICIPIOS'] ?? null) ?>
        </section>

        <?php if ($puedeEditarGeneral): ?>
            <div
                class="modal fade"
                id="modalFichaGeneral"
                tabindex="-1"
                aria-labelledby="modalFichaGeneralTitulo"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-ficha">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalFichaGeneralTitulo">
                                    Editar ficha territorial
                                </h5>
                                <p class="system-form-modal-subtitle">
                                    Actualiza la información general del Estado
                                </p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>

                        <form
                            action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarFichaGeneral"
                            method="POST"
                            enctype="multipart/form-data">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">

                            <div class="modal-body">
                                <div class="system-form-grid">
                                    <div class="data-form-section-title system-form-grid-full">
                                        Información general
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_capital">Capital</label>
                                        <input class="form-control" id="ficha_capital" name="capital" value="<?= $texto($estadoSeleccionado['capital']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_titular">Titular de gobierno</label>
                                        <input class="form-control" id="ficha_titular" name="titular_gobierno" value="<?= $texto($estadoSeleccionado['titular_gobierno']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_cargo">Cargo</label>
                                        <input class="form-control" id="ficha_cargo" name="cargo_titular" value="<?= $texto($estadoSeleccionado['cargo_titular']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_partido">Partido político</label>
                                        <input class="form-control" id="ficha_partido" name="partido_politico" value="<?= $texto($estadoSeleccionado['partido_politico']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_poblacion">Población</label>
                                        <input class="form-control" type="number" min="0" id="ficha_poblacion" name="poblacion" value="<?= $texto($estadoSeleccionado['poblacion']) ?>" readonly>
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_total_municipios">Total municipios</label>
                                        <input class="form-control" type="number" min="0" id="ficha_total_municipios" name="total_municipios" value="<?= $texto($estadoSeleccionado['total_municipios']) ?>" readonly>
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_total_secretarias">Total secretarías</label>
                                        <input class="form-control" type="number" min="0" id="ficha_total_secretarias" name="total_secretarias" value="<?= $texto($estadoSeleccionado['total_secretarias']) ?>" readonly>
                                        <div class="form-text">
                                            Población y municipios provienen de información oficial. El total de secretarías se calcula a partir de las dependencias registradas.
                                        </div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_periodo">Periodo de gobierno</label>
                                        <input class="form-control" id="ficha_periodo" name="periodo_gobierno" value="<?= $texto($estadoSeleccionado['periodo_gobierno']) ?>">
                                    </div>
                                    <div>
                                        <label class="form-label" for="ficha_telefono">Teléfono de contacto</label>
                                        <input class="form-control" id="ficha_telefono" name="telefono" value="<?= $texto($estadoSeleccionado['telefono']) ?>">
                                        <div class="form-text">
                                            Dato de contacto territorial registrado para el Estado.
                                        </div>
                                    </div>

                                    <div class="data-form-section-title system-form-grid-full">
                                        Recursos visuales
                                    </div>
                                    <div class="data-photo-field">
                                        <label class="form-label" for="ficha_foto">Fotografía del titular</label>
                                        <div class="data-photo-control">
                                            <div class="data-photo-preview data-photo-preview-round" id="fichaFotoPreview">
                                                <?php if ($fotoTitularUrl !== ''): ?>
                                                    <img src="<?= $texto($fotoTitularUrl) ?>" alt="Fotografía actual del titular">
                                                <?php else: ?>
                                                    <i class="bi bi-person"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p>JPG, PNG o WEBP · máximo 2 MB</p>
                                                <label class="btn btn-system-light data-photo-button" for="ficha_foto">
                                                    Seleccionar fotografía
                                                </label>
                                                <input class="data-photo-input" type="file" id="ficha_foto" name="foto_titular" accept="image/jpeg,image/png,image/webp" data-preview-target="fichaFotoPreview" data-preview-icon="bi-person">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="data-photo-field">
                                        <label class="form-label" for="ficha_mapa">Mapa estatal</label>
                                        <div class="data-photo-control">
                                            <div class="data-photo-preview data-photo-preview-map" id="fichaMapaPreview">
                                                <?php if ($mapaEstadoUrl !== ''): ?>
                                                    <img src="<?= $texto($mapaEstadoUrl) ?>" alt="Mapa actual del Estado">
                                                <?php else: ?>
                                                    <i class="bi bi-map"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p>JPG, PNG o WEBP · máximo 2 MB</p>
                                                <label class="btn btn-system-light data-photo-button" for="ficha_mapa">
                                                    Seleccionar mapa
                                                </label>
                                                <input class="data-photo-input" type="file" id="ficha_mapa" name="mapa_estado" accept="image/jpeg,image/png,image/webp" data-preview-target="fichaMapaPreview" data-preview-icon="bi-map">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="data-form-section-title system-form-grid-full">
                                        Información complementaria
                                    </div>
                                    <div class="system-form-grid-full">
                                        <label class="form-label" for="ficha_redes">Redes sociales</label>
                                        <textarea class="form-control data-compact-textarea" id="ficha_redes" name="redes_sociales" rows="2"><?= $texto($estadoSeleccionado['redes_sociales']) ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-save">
                                    <i class="bi bi-check2-circle me-2"></i>
                                    Guardar ficha
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (tienePermiso('data_territorial.editar')): ?>
            <div class="modal fade" id="modalEconomia" tabindex="-1" aria-labelledby="modalEconomiaTitulo" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-economia">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalEconomiaTitulo">Editar contexto económico</h5>
                                <p class="system-form-modal-subtitle">
                                    Agrega información complementaria sobre las condiciones económicas del territorio.
                                </p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <form action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarEconomia" method="POST">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                            <div class="modal-body">
                                <div class="data-context-note">
                                    <i class="bi bi-info-circle"></i>
                                    <span>
                                        Esta información es complementaria y se registra manualmente. Los datos
                                        oficiales obtenidos de INEGI y DENUE no se modifican desde este formulario.
                                    </span>
                                </div>
                                <div class="data-form-block">
                                    <label class="form-label" for="economia_actividad">Contexto económico del territorio</label>
                                    <textarea class="form-control" id="economia_actividad" name="actividad_economica" rows="4"><?= $texto($estadoSeleccionado['actividad_economica']) ?></textarea>
                                    <div class="form-text">
                                        Agrega observaciones que ayuden a interpretar la actividad económica del Estado
                                        y que no estén representadas directamente en los datos oficiales.
                                    </div>
                                </div>
                                <div class="data-form-block">
                                    <label class="form-label" for="economia_poder">Contexto sobre poder adquisitivo</label>
                                    <textarea class="form-control" id="economia_poder" name="poder_adquisitivo" rows="4"><?= $texto($estadoSeleccionado['poder_adquisitivo']) ?></textarea>
                                    <div class="form-text">
                                        Registra observaciones complementarias sobre las condiciones económicas
                                        y la capacidad adquisitiva del territorio.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-save">Guardar contexto</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($puedeGestionarSecretarias): ?>
            <div class="modal fade" id="modalSecretaria" tabindex="-1" aria-labelledby="modalSecretariaTitulo" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-secretaria">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalSecretariaTitulo">Registrar secretaría</h5>
                                <p class="system-form-modal-subtitle" id="modalSecretariaSubtitulo">Registra una dependencia estatal y su información institucional.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <form id="formSecretaria" action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=guardarSecretaria" method="POST">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                            <input type="hidden" name="id" id="secretaria_id">
                            <div class="modal-body">
                                <div class="system-form-grid">
                                    <div class="data-form-section-title system-form-grid-full">
                                        DEPENDENCIA
                                    </div>
                                    <div class="system-form-grid-full">
                                        <label class="form-label" for="secretaria_nombre">Nombre de la secretaría *</label>
                                        <input
                                            class="form-control"
                                            id="secretaria_nombre"
                                            name="nombre"
                                            placeholder="Ej. Secretaría de Educación"
                                            required>
                                    </div>
                                    <div class="system-form-grid-full">
                                        <label class="form-label" for="secretaria_sitio">Sitio web oficial</label>
                                        <input
                                            class="form-control"
                                            type="url"
                                            id="secretaria_sitio"
                                            name="sitio_web"
                                            placeholder="Ej. https://www.ejemplo.gob.mx">
                                    </div>
                                    <div class="data-form-section-title system-form-grid-full">
                                        TITULAR Y CONTACTO
                                    </div>
                                    <div>
                                        <label class="form-label" for="secretaria_titular">Titular</label>
                                        <input
                                            class="form-control"
                                            id="secretaria_titular"
                                            name="titular"
                                            placeholder="Nombre del titular">
                                    </div>
                                    <div>
                                        <label class="form-label" for="secretaria_cargo">Cargo del titular</label>
                                        <input
                                            class="form-control"
                                            id="secretaria_cargo"
                                            name="cargo_titular"
                                            placeholder="Ej. Secretario(a) de Educación">
                                    </div>
                                    <div>
                                        <label class="form-label" for="secretaria_correo">Correo institucional</label>
                                        <input
                                            class="form-control"
                                            type="email"
                                            id="secretaria_correo"
                                            name="correo"
                                            placeholder="Ej. contacto@dependencia.gob.mx">
                                    </div>
                                    <div>
                                        <label class="form-label" for="secretaria_telefono">Teléfono</label>
                                        <input
                                            class="form-control"
                                            id="secretaria_telefono"
                                            name="telefono"
                                            placeholder="Ej. 686 000 0000">
                                    </div>
                                    <div class="form-text system-form-grid-full">
                                        Registra preferentemente información institucional publicada por la dependencia.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-save" id="botonGuardarSecretaria">Guardar secretaría</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($puedeGestionarIndicadores): ?>
            <div class="modal fade" id="modalIndicador" tabindex="-1" aria-labelledby="modalIndicadorTitulo" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-indicador">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalIndicadorTitulo">Indicador educativo complementario</h5>
                                <p class="system-form-modal-subtitle" id="modalIndicadorSubtitulo">Registra información educativa adicional obtenida de fuentes verificadas.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <form id="formIndicador" action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=guardarIndicador" method="POST">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                            <input type="hidden" name="id" id="indicador_id">
                            <div class="modal-body">
                                <div class="data-indicator-complementary-note mb-3">
                                    <i class="bi bi-info-circle"></i>
                                    <span>Este registro es complementario y no modifica el rezago educativo oficial importado desde INEGI.</span>
                                </div>
                                <div class="system-form-grid">
                                    <div class="system-form-grid-full">
                                        <label class="form-label" for="indicador_situacion">Indicador educativo complementario *</label>
                                        <input class="form-control" id="indicador_situacion" name="situacion" placeholder="Ej. Abandono escolar - Media Superior" required>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicador_valor">Valor</label>
                                        <input class="form-control" type="number" min="0" step="0.01" id="indicador_valor" name="valor" placeholder="Ej. 5.3">
                                        <div class="form-text">Registra el valor principal del indicador cuando aplique.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicador_unidad">Unidad</label>
                                        <input class="form-control" id="indicador_unidad" name="unidad" list="indicadorUnidadesSugeridas" maxlength="30" placeholder="Ej. %, años, tasa">
                                        <datalist id="indicadorUnidadesSugeridas">
                                            <option value="%"></option>
                                            <option value="personas"></option>
                                            <option value="años"></option>
                                            <option value="promedio"></option>
                                            <option value="tasa"></option>
                                            <option value="índice"></option>
                                            <option value="puntos"></option>
                                        </datalist>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicador_cantidad">Cantidad aproximada</label>
                                        <input class="form-control" type="number" min="0" id="indicador_cantidad" name="cantidad_aproximada" placeholder="Ej. 12500">
                                        <div class="form-text">Opcional. Úsala cuando exista una cantidad de personas o alumnos asociada.</div>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicador_fuente">Fuente *</label>
                                        <input class="form-control" id="indicador_fuente" name="fuente" placeholder="Ej. SEP" required>
                                    </div>
                                    <div>
                                        <label class="form-label" for="indicador_periodo">Periodo *</label>
                                        <input class="form-control" id="indicador_periodo" name="periodo" placeholder="Ej. 2025 o Ciclo 2025-2026" required>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-save" id="botonGuardarIndicador">Guardar indicador</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($puedeGestionarIndicadores): ?>
            <div class="modal fade" id="modalEliminarIndicador" tabindex="-1" aria-labelledby="modalEliminarIndicadorTitulo" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalEliminarIndicadorTitulo">Eliminar indicador educativo</h5>
                                <p class="system-form-modal-subtitle">Retira un indicador complementario de la ficha territorial.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <form action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=eliminarIndicador" method="POST" id="formEliminarIndicador">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                            <input type="hidden" name="id" id="indicador_eliminar_id">
                            <div class="modal-body">
                                <p class="confirm-text">
                                    ¿Eliminar el indicador <strong id="indicador_eliminar_nombre">seleccionado</strong>? Dejará de mostrarse en la ficha territorial, pero el registro se conservará internamente como inactivo.
                                </p>
                                <div class="data-indicator-complementary-note mt-3">
                                    <i class="bi bi-info-circle"></i>
                                    <span>Esta acción no modifica el rezago educativo oficial importado desde INEGI.</span>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-danger">Eliminar indicador</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($puedeGestionarMunicipios): ?>
            <div class="modal fade" id="modalMunicipio" tabindex="-1" aria-labelledby="modalMunicipioTitulo" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-municipio">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalMunicipioTitulo">Editar municipio</h5>
                                <p class="system-form-modal-subtitle" id="modalMunicipioSubtitulo">Actualiza la información complementaria del municipio.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <form id="formMunicipio" action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarMunicipio" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                            <input type="hidden" name="id" id="municipio_id">
                            <div class="modal-body">
                                <div class="system-form-grid">
                                    <div class="system-form-grid-full data-form-section-title">
                                        DATOS OFICIALES
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_nombre">Nombre *</label>
                                        <input class="form-control" id="municipio_nombre" name="nombre" required readonly>
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_clave">Clave INEGI</label>
                                        <input class="form-control" id="municipio_clave" name="clave_inegi" readonly>
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_poblacion">Población</label>
                                        <input class="form-control" type="number" min="0" id="municipio_poblacion" name="poblacion" readonly>
                                    </div>
                                    <div class="system-form-grid-full data-context-note">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <span>
                                            Nombre, clave INEGI y población provienen de la información oficial
                                            y se actualizan desde INEGI.
                                        </span>
                                    </div>
                                    <div class="system-form-grid-full data-form-section-title">
                                        INFORMACIÓN COMPLEMENTARIA
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_presidente">Presidente municipal</label>
                                        <input class="form-control" id="municipio_presidente" name="presidente_municipal">
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_partido">Partido político</label>
                                        <input class="form-control" id="municipio_partido" name="partido_politico">
                                    </div>
                                    <div>
                                        <label class="form-label" for="municipio_redes">Redes sociales</label>
                                        <textarea class="form-control data-compact-textarea" id="municipio_redes" name="redes_sociales" rows="2"></textarea>
                                        <div class="form-text">Puedes registrar uno o varios enlaces de redes sociales.</div>
                                    </div>
                                    <div class="system-form-grid-full">
                                        <label class="form-label" for="municipio_fotografia">Fotografía del presidente municipal</label>
                                        <div class="data-photo-control">
                                            <div class="data-photo-preview" id="municipioFotoPreview">
                                                <i class="bi bi-image"></i>
                                            </div>
                                            <div>
                                                <p>Retrato del presidente municipal · JPG, PNG o WEBP · máximo 2 MB · opcional</p>
                                                <div class="data-photo-actions">
                                                    <label class="btn btn-system-light data-photo-button" for="municipio_fotografia" id="municipioFotoLabel">
                                                        Seleccionar fotografía
                                                    </label>
                                                    <div class="photo-remove-control d-none" id="municipio_quitar_foto_panel">
                                                <input class="form-check-input" type="checkbox" id="municipio_quitar_foto" name="quitar_fotografia" value="1">
                                                        <label class="form-check-label" for="municipio_quitar_foto">Quitar fotografía</label>
                                                    </div>
                                                </div>
                                                <input class="data-photo-input" type="file" id="municipio_fotografia" name="fotografia" accept="image/jpeg,image/png,image/webp" data-preview-target="municipioFotoPreview" data-preview-icon="bi-image">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-system-save" id="botonGuardarMunicipio">Guardar cambios</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($puedeActualizarInformacionOficial): ?>
            <div
                class="modal fade"
                id="modalActualizarInformacionOficial"
                tabindex="-1"
                aria-labelledby="modalActualizarInformacionOficialTitulo"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-oficial">
                    <div class="modal-content system-form-modal">
                        <div class="modal-header system-form-modal-header">
                            <div>
                                <h5 class="system-form-modal-title" id="modalActualizarInformacionOficialTitulo">
                                    Actualizar información oficial
                                </h5>
                                <p class="system-form-modal-subtitle">
                                    Selecciona la información oficial que deseas actualizar.
                                </p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>

                        <div class="modal-body">
                            <div data-official-initial="individual">
                                <p class="data-official-confirm-text">
                                    Selecciona la información oficial que deseas actualizar para:
                                    <strong><?= $texto($estadoSeleccionado['nombre']) ?></strong>.
                                </p>

                                <div class="data-official-option-list" data-official-options="individual">
                                    <label class="data-official-option-card">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="poblacion"
                                            data-official-option>
                                        <span>
                                            <strong>Población estatal</strong>
                                            <small>Fuente: INEGI - Banco de Indicadores</small>
                                            <em>Actualiza el dato oficial de población disponible para el Estado.</em>
                                        </span>
                                    </label>

                                    <label class="data-official-option-card">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="actividad"
                                            data-official-option>
                                        <span>
                                            <strong>Actividad económica</strong>
                                            <small>Fuente: INEGI - DENUE</small>
                                            <em>Actualiza la distribución de establecimientos registrados por sector económico.</em>
                                        </span>
                                    </label>
                                    <label class="data-official-option-card">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="municipios"
                                            data-official-option>
                                        <span>
                                            <strong>Municipios</strong>
                                            <small>Fuente: INEGI - Catálogo Único de Claves Geoestadísticas</small>
                                            <em>Actualiza nombre, clave INEGI y población de los municipios del Estado.</em>
                                        </span>
                                    </label>

                                </div>

                                <div class="data-official-note">
                                    Los datos existentes de las opciones seleccionadas serán actualizados con la información oficial más reciente disponible.
                                </div>

                                <div
                                    class="data-official-note data-official-note-muted d-none"
                                    data-denue-note="individual">
                                    Los porcentajes corresponden a la proporción de establecimientos registrados en DENUE
                                    por sector y no representan aportación al PIB.
                                </div>

                                <div
                                    class="data-official-note data-official-note-muted d-none"
                                    data-municipios-note="individual">
                                    La actualización municipal conserva presidente municipal, partido político, redes sociales y fotografía.
                                </div>
                            </div>

                            <div class="data-mass-update-progress d-none" data-official-progress="individual">
                                <p class="data-mass-update-eyebrow">Actualizando información oficial</p>
                                <strong data-official-progress-counter="individual">0 de 0 operaciones</strong>
                                <div
                                    class="progress data-mass-progress"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="0">
                                    <div class="progress-bar" data-official-progress-bar="individual" style="width: 0%"></div>
                                </div>
                                <div class="data-mass-update-current">
                                    <span>Estado actual</span>
                                    <strong data-official-progress-state="individual">En espera</strong>
                                </div>
                                <div class="data-mass-update-current">
                                    <span>Información</span>
                                    <strong data-official-progress-type="individual">En espera</strong>
                                </div>
                            </div>

                            <div class="data-mass-update-result d-none" data-official-result="individual">
                                <p class="data-mass-result-title" data-official-result-title="individual">
                                    Actualización finalizada
                                </p>
                                <div class="data-mass-result-summary" data-official-result-summary="individual"></div>
                                <div class="data-mass-error-list d-none" data-official-error-list="individual"></div>
                            </div>

                            <div
                                class="data-official-modal-message d-none"
                                id="mensajeActualizarInformacionOficial"
                                role="alert"></div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">
                                Cancelar
                            </button>
                            <button
                                type="button"
                                class="btn btn-system-save data-official-primary-button"
                                id="botonActualizarInformacionOficial"
                                data-estado-id="<?= (int)$estadoSeleccionado['id'] ?>"
                                disabled>
                                <span class="data-official-button-label" data-official-start-label="individual">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    Actualizar información
                                </span>
                                <span class="data-official-button-loading d-none">
                                    <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                    Actualizando...
                                </span>
                            </button>
                            <button
                                type="button"
                                class="btn btn-system-save data-official-primary-button d-none"
                                data-official-finish="individual">
                                Finalizar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($puedeActualizarInformacionOficial && !$estadoSeleccionado && $totalEstadosActualizacionMasiva > 0): ?>
        <div
            class="modal fade"
            id="modalActualizarInformacionEstados"
            tabindex="-1"
            aria-labelledby="modalActualizarInformacionEstadosTitulo"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered system-form-dialog data-territorial-modal-masiva">
                <div class="modal-content system-form-modal">
                    <div class="modal-header system-form-modal-header">
                        <div>
                            <h5 class="system-form-modal-title" id="modalActualizarInformacionEstadosTitulo">
                                Actualizar información oficial
                            </h5>
                            <p class="system-form-modal-subtitle" id="modalActualizarInformacionEstadosSubtitulo">
                                Selecciona la información que deseas consultar y actualizar para los Estados registrados.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn-close"
                            data-mass-update-close
                            data-bs-dismiss="modal"
                            aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="data-mass-update-initial" data-mass-update-initial>
                            <p class="data-official-confirm-text">
                                Selecciona la información oficial que deseas actualizar.
                            </p>

                            <div class="data-official-option-list" data-official-options="mass">
                                <label class="data-official-option-card">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="poblacion"
                                        data-official-option>
                                    <span>
                                        <strong>Población estatal</strong>
                                        <small>Fuente: INEGI - Banco de Indicadores</small>
                                        <em>Actualiza el dato oficial de población disponible para cada Estado.</em>
                                    </span>
                                </label>

                                <label class="data-official-option-card">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="actividad"
                                        data-official-option>
                                    <span>
                                        <strong>Actividad económica</strong>
                                        <small>Fuente: INEGI - DENUE</small>
                                        <em>Actualiza la distribución de establecimientos registrados por sector económico.</em>
                                    </span>
                                </label>

                                <label class="data-official-option-card">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="municipios"
                                        data-official-option>
                                    <span>
                                        <strong>Municipios</strong>
                                        <small>Fuente: INEGI - Catálogo Único de Claves Geoestadísticas</small>
                                        <em>Actualiza nombre, clave INEGI y población municipal en los 32 Estados.</em>
                                    </span>
                                </label>

                                <label class="data-official-option-card">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="poder_adquisitivo"
                                        data-official-option>
                                    <span>
                                        <strong>Poder adquisitivo</strong>
                                        <small>Fuente: INEGI - Pobreza Laboral (PL)</small>
                                        <em>Importa ingreso laboral real per cápita y pobreza laboral desde el XLSX oficial.</em>
                                    </span>
                                </label>

                                <label class="data-official-option-card">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        value="rezago_educativo"
                                        data-official-option>
                                    <span>
                                        <strong>Rezago educativo</strong>
                                        <small>Fuente: INEGI - Pobreza Multidimensional</small>
                                        <em>Importa porcentaje y población con rezago educativo desde el XLSX oficial.</em>
                                    </span>
                                </label>
                            </div>

                            <div class="data-power-import d-none" data-power-import>
                                <div class="data-power-import-heading">
                                    <div>
                                        <strong>Archivo oficial de Pobreza Laboral</strong>
                                        <span>Descarga el tabulado XLSX publicado por INEGI y súbelo sin modificarlo.</span>
                                    </div>
                                    <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>
                                </div>

                                <label class="data-power-import-file" for="archivoPoderAdquisitivo">
                                    <span>Seleccionar archivo XLSX</span>
                                    <input
                                        type="file"
                                        id="archivoPoderAdquisitivo"
                                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        data-power-import-file>
                                </label>
                                <small class="data-power-import-help">
                                    El sistema validará Cuadro 5, Cuadro 9, el periodo y las 32 entidades antes de importar.
                                    <a href="https://www.inegi.org.mx/desarrollosocial/pl/" target="_blank" rel="noopener noreferrer">Abrir Pobreza Laboral en INEGI</a>
                                </small>

                                <div class="data-power-import-status d-none" data-power-import-status role="status"></div>

                                <div class="data-power-import-preview d-none" data-power-import-preview>
                                    <div>
                                        <span>Archivo</span>
                                        <strong data-power-preview-file>—</strong>
                                    </div>
                                    <div>
                                        <span>Periodo detectado</span>
                                        <strong data-power-preview-period>—</strong>
                                    </div>
                                    <div>
                                        <span>Territorios</span>
                                        <strong data-power-preview-territories>—</strong>
                                    </div>
                                    <div>
                                        <span>Indicadores</span>
                                        <strong>Ingreso laboral real y pobreza laboral</strong>
                                    </div>
                                    <p class="d-none" data-power-preview-existing></p>
                                </div>
                            </div>

                            <div class="data-power-import d-none" data-education-import>
                                <div class="data-power-import-heading">
                                    <div>
                                        <strong>Archivo oficial de Pobreza Multidimensional</strong>
                                        <span>Selecciona el tabulado por entidad federativa publicado por INEGI, sin modificarlo.</span>
                                    </div>
                                    <i class="bi bi-mortarboard" aria-hidden="true"></i>
                                </div>

                                <label class="data-power-import-file" for="archivoRezagoEducativo">
                                    <span>Seleccionar archivo XLSX</span>
                                    <input
                                        type="file"
                                        id="archivoRezagoEducativo"
                                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        data-education-import-file>
                                </label>
                                <small class="data-power-import-help">
                                    El sistema localizará dinámicamente la fila “Rezago educativo”, los años disponibles y las 32 entidades + México.
                                    <a href="https://www.inegi.org.mx/desarrollosocial/pm/" target="_blank" rel="noopener noreferrer">Abrir Pobreza Multidimensional en INEGI</a>
                                </small>

                                <div class="data-power-import-status d-none" data-education-import-status role="status"></div>

                                <div class="data-power-import-preview d-none" data-education-import-preview>
                                    <div>
                                        <span>Archivo</span>
                                        <strong data-education-preview-file>—</strong>
                                    </div>
                                    <div>
                                        <span>Periodos detectados</span>
                                        <strong data-education-preview-periods>—</strong>
                                    </div>
                                    <div>
                                        <span>Último periodo</span>
                                        <strong data-education-preview-latest>—</strong>
                                    </div>
                                    <div>
                                        <span>Territorios</span>
                                        <strong>32 Estados + referencia nacional</strong>
                                    </div>
                                    <p data-education-preview-summary></p>
                                </div>
                            </div>

                            <div class="data-mass-update-grid data-official-scope-grid">
                                <div>
                                    <span>Estados a procesar</span>
                                    <strong><?= (int)$totalEstadosActualizacionMasiva ?></strong>
                                </div>
                                <div>
                                    <span>Opciones disponibles</span>
                                    <strong>5</strong>
                                </div>
                            </div>

                            <div class="data-official-note">
                                Los datos existentes de las opciones seleccionadas serán actualizados con la información oficial más reciente disponible.
                            </div>

                            <div
                                class="data-official-note data-official-note-muted d-none"
                                data-denue-note="mass">
                                Los porcentajes corresponden a la proporción de establecimientos registrados en DENUE
                                por sector y no representan aportación al PIB.
                            </div>

                            <div
                                class="data-official-note data-official-note-muted d-none"
                                data-municipios-note="mass">
                                Municipios actualiza únicamente nombre, clave INEGI y población. Los datos capturados sobre autoridades municipales se conservan.
                            </div>
                        </div>

                        <div class="data-mass-update-progress d-none" data-mass-update-progress>
                            <p class="data-mass-update-eyebrow">Actualizando información oficial</p>
                            <strong data-mass-update-counter>
                                0 de 0 operaciones
                            </strong>
                            <div class="progress data-mass-progress" role="progressbar" aria-label="Progreso de actualización" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" data-mass-update-bar style="width: 0%"></div>
                            </div>
                            <div class="data-mass-update-current">
                                <span>Estado actual</span>
                                <strong data-mass-update-current>En espera</strong>
                            </div>
                            <div class="data-mass-update-current">
                                <span>Información</span>
                                <strong data-mass-update-type>En espera</strong>
                            </div>
                        </div>

                        <div class="data-mass-update-result d-none" data-mass-update-result>
                            <p class="data-mass-result-title" data-mass-result-title>
                                Actualización finalizada
                            </p>
                            <div class="data-mass-result-summary" data-mass-result-summary></div>
                            <div class="data-mass-error-list d-none" data-mass-error-list></div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-system-cancel"
                            data-mass-update-cancel
                            data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            class="btn btn-system-save data-official-primary-button"
                            id="botonActualizarInformacionEstados"
                            disabled>
                            <span data-mass-update-start-label>
                                <i class="bi bi-arrow-repeat me-2"></i>
                                Actualizar información
                            </span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-system-save data-official-primary-button d-none"
                            data-mass-update-finish>
                            Finalizar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if ($mensajeExito !== ''): ?>
        <div class="toast system-toast" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="3200">
            <div class="toast-body">
                <i class="bi bi-check2-circle"></i>
                <span><?= $texto($mensajeExito) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($mensajeError !== ''): ?>
        <div class="toast system-toast system-toast-error" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
            <div class="toast-body">
                <i class="bi bi-exclamation-circle"></i>
                <span><?= $texto($mensajeError) ?></span>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const baseUrl = '<?= BASE_URL ?>index.php';
    const estadosActualizacionMasiva = <?= json_encode(
        $estadosActualizacionMasiva,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;
    const selectorEstado = document.querySelector('.data-territorial-state-select');
    const selectorCambioTerritorio = document.querySelector('[data-territory-detail-select]');
    const municipiosForm = document.getElementById('municipiosFiltrosForm');
    const municipiosContenido = document.getElementById('municipiosTablaContenido');
    const botonActualizarInformacionOficial = document.getElementById('botonActualizarInformacionOficial');
    const mensajeActualizarOficial = document.getElementById('mensajeActualizarInformacionOficial');
    const modalActualizarOficialElemento = document.getElementById('modalActualizarInformacionOficial');
    const modalActualizacionMasivaElemento = document.getElementById('modalActualizarInformacionEstados');
    const botonActualizacionMasiva = document.getElementById('botonActualizarInformacionEstados');
    const botonAbrirActualizacionMasiva = document.querySelector('.data-mass-update-button');
    const bloqueImportacionPoder = document.querySelector('[data-power-import]');
    const archivoImportacionPoder = document.querySelector('[data-power-import-file]');
    const estadoImportacionPoder = document.querySelector('[data-power-import-status]');
    const previewImportacionPoder = document.querySelector('[data-power-import-preview]');
    let tokenImportacionPoder = '';
    let importacionPoderValidando = false;
    const bloqueImportacionEducacion = document.querySelector('[data-education-import]');
    const archivoImportacionEducacion = document.querySelector('[data-education-import-file]');
    const estadoImportacionEducacion = document.querySelector('[data-education-import-status]');
    const previewImportacionEducacion = document.querySelector('[data-education-import-preview]');
    let tokenImportacionEducacion = '';
    let importacionEducacionValidando = false;
    let temporizadorMunicipios = null;
    let consultaMunicipios = null;
    let actualizacionOficialEnCurso = false;
    let actualizacionMasivaEnCurso = false;
    let temporizadorTerritorios = null;
    let firmaFiltrosTerritoriales = '';

    const formularioTerritorios = document.querySelector('.data-territorial-toolbar');
    const tarjetasTerritorio = Array.from(document.querySelectorAll('[data-territory-card]'));
    const contenedorTarjetasTerritorio = document.querySelector('[data-territory-cards]');
    const estadoVacioTerritorios = document.querySelector('[data-territory-empty]');
    const paginacionTerritorios = document.querySelector('[data-territory-pagination]');
    const contadorTerritorios = document.querySelector('[data-territory-counter]');
    const paginasTerritorios = document.querySelector('[data-territory-pages]');
    const limiteTerritorios = <?= (int)$limiteTerritorios ?>;
    let paginaTerritoriosActual = 1;

    const normalizarTextoTerritorial = function (texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    };

    const actualizarUrlFiltrosTerritoriales = function (buscar, filtro, pagina) {
        const parametros = new URLSearchParams();
        parametros.set('controller', 'dataTerritorial');
        parametros.set('action', 'index');

        if (buscar !== '') {
            parametros.set('buscar', buscar);
        }

        if (filtro !== 'todos') {
            parametros.set('filtro_informacion', filtro);
        }

        if (pagina > 1) {
            parametros.set('pagina_territorios', String(pagina));
        }

        const nuevaUrl = baseUrl + '?' + parametros.toString();

        try {
            if (new URL(nuevaUrl, window.location.href).origin === window.location.origin) {
                window.history.replaceState(null, '', nuevaUrl);
            }
        } catch (error) {
            return;
        }
    };

    const aplicarFiltrosTerritoriales = function (paginaSolicitada) {
        if (!formularioTerritorios || tarjetasTerritorio.length === 0) {
            return;
        }

        const busqueda = formularioTerritorios.querySelector('[name="buscar"]');
        const filtro = formularioTerritorios.querySelector('[name="filtro_informacion"]');
        const paginaInput = formularioTerritorios.querySelector('[name="pagina_territorios"]');
        const textoBusqueda = normalizarTextoTerritorial(busqueda?.value || '');
        const filtroInformacion = filtro?.value || 'todos';

        paginaTerritoriosActual = Math.max(1, parseInt(paginaSolicitada || paginaTerritoriosActual, 10) || 1);

        const tarjetasFiltradas = tarjetasTerritorio.filter(function (tarjeta) {
            const textoTarjeta = normalizarTextoTerritorial(
                (tarjeta.dataset.territoryName || '') + ' ' + (tarjeta.dataset.territoryAlias || '')
            );
            const coincideTexto = textoBusqueda === '' || textoTarjeta.includes(textoBusqueda);
            const coincideInformacion =
                filtroInformacion === 'todos' ||
                tarjeta.dataset.territoryInfo === filtroInformacion;

            return coincideTexto && coincideInformacion;
        });
        const total = tarjetasFiltradas.length;
        const totalPaginas = Math.max(1, Math.ceil(total / limiteTerritorios));

        if (paginaTerritoriosActual > totalPaginas) {
            paginaTerritoriosActual = 1;
        }

        const inicio = (paginaTerritoriosActual - 1) * limiteTerritorios;
        const fin = inicio + limiteTerritorios;
        const visibles = new Set(tarjetasFiltradas.slice(inicio, fin));

        tarjetasTerritorio.forEach(function (tarjeta) {
            tarjeta.classList.toggle('d-none', !visibles.has(tarjeta));
        });

        contenedorTarjetasTerritorio?.classList.toggle('d-none', total === 0);
        estadoVacioTerritorios?.classList.toggle('d-none', total !== 0);
        paginacionTerritorios?.classList.toggle('d-none', total === 0);

        if (contadorTerritorios && total > 0) {
            contadorTerritorios.textContent =
                'Mostrando ' +
                (inicio + 1) +
                ' a ' +
                Math.min(fin, total) +
                ' de ' +
                total +
                ' territorios';
        }

        if (paginasTerritorios) {
            paginasTerritorios.innerHTML = '';

            if (totalPaginas > 1) {
                for (let pagina = 1; pagina <= totalPaginas; pagina += 1) {
                    const enlace = document.createElement('a');
                    enlace.href = '#';
                    enlace.dataset.territoryPage = String(pagina);
                    enlace.textContent = String(pagina);
                    enlace.classList.toggle('active', pagina === paginaTerritoriosActual);
                    paginasTerritorios.appendChild(enlace);
                }
            }
        }

        if (paginaInput) {
            paginaInput.value = String(paginaTerritoriosActual);
        }

        actualizarUrlFiltrosTerritoriales(
            busqueda?.value.trim() || '',
            filtroInformacion,
            paginaTerritoriosActual
        );
        firmaFiltrosTerritoriales = (busqueda?.value || '') + '|' + filtroInformacion;
    };

    const programarFiltrosTerritoriales = function () {
        window.clearTimeout(temporizadorTerritorios);
        temporizadorTerritorios = window.setTimeout(function () {
            paginaTerritoriosActual = 1;
            aplicarFiltrosTerritoriales(1);
        }, 250);
    };

    const firmaActualFiltrosTerritoriales = function () {
        if (!formularioTerritorios) {
            return '';
        }

        const busqueda = formularioTerritorios.querySelector('[name="buscar"]');
        const filtro = formularioTerritorios.querySelector('[name="filtro_informacion"]');

        return (busqueda?.value || '') + '|' + (filtro?.value || 'todos');
    };

    if (selectorEstado) {
        selectorEstado.addEventListener('change', function () {
            if (selectorEstado.value === '') {
                return;
            }

            window.location.href =
                baseUrl +
                '?controller=dataTerritorial&action=index&estado_id=' +
                encodeURIComponent(selectorEstado.value);
        });
    }

    if (selectorCambioTerritorio) {
        selectorCambioTerritorio.addEventListener('change', function () {
            const estadoDestino = selectorCambioTerritorio.value;
            const estadoActual = selectorCambioTerritorio.dataset.currentState || '';

            if (estadoDestino === '' || estadoDestino === estadoActual) {
                return;
            }

            window.location.href =
                baseUrl +
                '?controller=dataTerritorial&action=index&estado_id=' +
                encodeURIComponent(estadoDestino);
        });
    }

    if (formularioTerritorios) {
        const busquedaTerritorio = formularioTerritorios.querySelector('[name="buscar"]');
        const filtroInformacion = formularioTerritorios.querySelector('[name="filtro_informacion"]');

        formularioTerritorios.addEventListener('submit', function (event) {
            event.preventDefault();
            paginaTerritoriosActual = 1;
            aplicarFiltrosTerritoriales(1);
        });

        if (busquedaTerritorio) {
            busquedaTerritorio.addEventListener('input', function () {
                if (busquedaTerritorio.value === '') {
                    paginaTerritoriosActual = 1;
                    aplicarFiltrosTerritoriales(1);
                    return;
                }

                programarFiltrosTerritoriales();
            });
            busquedaTerritorio.addEventListener('search', programarFiltrosTerritoriales);
            busquedaTerritorio.addEventListener('change', programarFiltrosTerritoriales);
            busquedaTerritorio.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    busquedaTerritorio.value = '';
                    programarFiltrosTerritoriales();
                }
            });
        }

        if (filtroInformacion) {
            filtroInformacion.addEventListener('change', function () {
                paginaTerritoriosActual = 1;
                aplicarFiltrosTerritoriales(1);
            });
        }

        paginasTerritorios?.addEventListener('click', function (event) {
            const enlace = event.target.closest('[data-territory-page]');

            if (!enlace) {
                return;
            }

            event.preventDefault();
            aplicarFiltrosTerritoriales(parseInt(enlace.dataset.territoryPage || '1', 10));
        });

        aplicarFiltrosTerritoriales(<?= (int)$paginaTerritorios ?>);
        firmaFiltrosTerritoriales = firmaActualFiltrosTerritoriales();

        window.setInterval(function () {
            const firmaActual = firmaActualFiltrosTerritoriales();

            if (firmaActual !== firmaFiltrosTerritoriales) {
                firmaFiltrosTerritoriales = firmaActual;
                paginaTerritoriosActual = 1;
                aplicarFiltrosTerritoriales(1);
            }
        }, 300);
    }

    /* Navegación interna de la ficha territorial: una sección visible a la vez. */
    const navegacionSecciones = document.querySelector('.data-section-nav');
    const enlacesSeccion = Array.from(document.querySelectorAll('.data-section-nav a'));
    const resumenPrincipal = document.querySelector('.data-state-summary');
    const seccionesTerritorialesValidas = [
        'resumen',
        'secretarias',
        'economia',
        'educacion',
        'municipios'
    ];
    const panelesTerritoriales = {
        resumen: [
            resumenPrincipal,
            document.getElementById('resumen')
        ],
        secretarias: [document.getElementById('secretarias')],
        economia: [document.getElementById('economia')],
        educacion: [document.getElementById('educacion')],
        municipios: [document.getElementById('municipios')]
    };

    if (navegacionSecciones && resumenPrincipal) {
        navegacionSecciones.insertAdjacentElement('afterend', resumenPrincipal);
    }

    const todosLosPanelesTerritoriales = [];
    Object.values(panelesTerritoriales)
        .flat()
        .filter(Boolean)
        .forEach(function (panel) {
            if (!todosLosPanelesTerritoriales.includes(panel)) {
                todosLosPanelesTerritoriales.push(panel);
            }
            panel.classList.add('data-territorial-panel-content');
        });

    const obtenerSeccionDesdeHash = function () {
        const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''))
            .trim()
            .toLowerCase();

        return seccionesTerritorialesValidas.includes(hash)
            ? hash
            : 'resumen';
    };

    const mantenerTabVisible = function (enlace) {
        if (!navegacionSecciones || !enlace) {
            return;
        }

        const izquierda = enlace.offsetLeft;
        const derecha = izquierda + enlace.offsetWidth;
        const visibleIzquierda = navegacionSecciones.scrollLeft;
        const visibleDerecha = visibleIzquierda + navegacionSecciones.clientWidth;

        if (izquierda >= visibleIzquierda && derecha <= visibleDerecha) {
            return;
        }

        const posicion = izquierda - (navegacionSecciones.clientWidth - enlace.offsetWidth) / 2;
        navegacionSecciones.scrollTo({
            left: Math.max(0, posicion),
            behavior: 'smooth'
        });
    };

    const acomodarVistaSeccion = function () {
        if (!navegacionSecciones) {
            return;
        }

        const rect = navegacionSecciones.getBoundingClientRect();
        const posicionNatural = window.scrollY + rect.top - 78;

        if (window.scrollY > posicionNatural + 40) {
            window.scrollTo({
                top: Math.max(0, posicionNatural),
                behavior: 'smooth'
            });
        }
    };

    const activarSeccionTerritorial = function (seccion, opciones = {}) {
        const seccionActiva = seccionesTerritorialesValidas.includes(seccion)
            ? seccion
            : 'resumen';
        const panelesActivos = panelesTerritoriales[seccionActiva].filter(Boolean);

        todosLosPanelesTerritoriales.forEach(function (panel) {
            const activo = panelesActivos.includes(panel);
            panel.hidden = !activo;
            panel.setAttribute('aria-hidden', activo ? 'false' : 'true');
            panel.classList.remove('data-territorial-panel-enter');

            if (activo && opciones.animar !== false) {
                void panel.offsetWidth;
                panel.classList.add('data-territorial-panel-enter');
            }
        });

        let enlaceActivo = null;
        enlacesSeccion.forEach(function (enlace) {
            const href = enlace.getAttribute('href') || '';
            const activo = href === '#' + seccionActiva;
            enlace.classList.toggle('active', activo);
            enlace.setAttribute('aria-selected', activo ? 'true' : 'false');
            enlace.setAttribute('tabindex', activo ? '0' : '-1');

            if (activo) {
                enlaceActivo = enlace;
            }
        });

        if (opciones.actualizarUrl === true) {
            const nuevaUrl = window.location.pathname + window.location.search + '#' + seccionActiva;
            const hashActual = window.location.hash.replace(/^#/, '');

            if (hashActual !== seccionActiva) {
                if (opciones.reemplazarHistorial === true) {
                    window.history.replaceState(null, '', nuevaUrl);
                } else {
                    window.history.pushState(null, '', nuevaUrl);
                }
            }
        }

        window.requestAnimationFrame(function () {
            mantenerTabVisible(enlaceActivo);
        });

        if (opciones.acomodarVista === true) {
            acomodarVistaSeccion();
        }
    };

    enlacesSeccion.forEach(function (enlace, indice) {
        enlace.setAttribute('role', 'tab');

        enlace.addEventListener('click', function (event) {
            event.preventDefault();
            const seccion = enlace.getAttribute('href').replace(/^#/, '');
            activarSeccionTerritorial(seccion, {
                actualizarUrl: true,
                animar: true,
                acomodarVista: true
            });
        });

        enlace.addEventListener('keydown', function (event) {
            let indiceDestino = null;

            if (event.key === 'ArrowRight') {
                indiceDestino = (indice + 1) % enlacesSeccion.length;
            }
            if (event.key === 'ArrowLeft') {
                indiceDestino = (indice - 1 + enlacesSeccion.length) % enlacesSeccion.length;
            }
            if (event.key === 'Home') {
                indiceDestino = 0;
            }
            if (event.key === 'End') {
                indiceDestino = enlacesSeccion.length - 1;
            }
            if (indiceDestino === null) {
                return;
            }

            event.preventDefault();
            const destino = enlacesSeccion[indiceDestino];
            destino.focus();
            const seccion = destino.getAttribute('href').replace(/^#/, '');
            activarSeccionTerritorial(seccion, {
                actualizarUrl: true,
                animar: true,
                acomodarVista: true
            });
        });
    });

    if (enlacesSeccion.length > 0 && todosLosPanelesTerritoriales.length > 0) {
        const seccionInicial = obtenerSeccionDesdeHash();
        activarSeccionTerritorial(seccionInicial, {
            actualizarUrl: false,
            animar: false,
            acomodarVista: false
        });

        if ('scrollRestoration' in window.history) {
            window.history.scrollRestoration = 'manual';
        }

        const colocarFichaAlInicio = function () {
            window.scrollTo(0, 0);
        };

        colocarFichaAlInicio();
        window.requestAnimationFrame(function () {
            colocarFichaAlInicio();
            window.requestAnimationFrame(function () {
                colocarFichaAlInicio();
            });
        });
        window.addEventListener('load', colocarFichaAlInicio, { once: true });
    }

    window.addEventListener('popstate', function () {
        activarSeccionTerritorial(obtenerSeccionDesdeHash(), {
            actualizarUrl: false,
            animar: true,
            acomodarVista: true
        });
    });

    window.addEventListener('hashchange', function () {
        activarSeccionTerritorial(obtenerSeccionDesdeHash(), {
            actualizarUrl: false,
            animar: true,
            acomodarVista: true
        });
    });

    document.querySelectorAll('.data-territorial-card[data-card-url]').forEach(function (tarjeta) {
        const abrirTarjeta = function () {
            const url = tarjeta.dataset.cardUrl || '';

            if (url !== '') {
                window.location.href = url;
            }
        };

        tarjeta.addEventListener('click', function (event) {
            if (!event.target.closest('a, button, input, select, textarea')) {
                abrirTarjeta();
            }
        });

        tarjeta.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                abrirTarjeta();
            }
        });
    });

    const cargarMunicipios = async function () {
        if (!municipiosForm || !municipiosContenido) {
            return;
        }

        if (consultaMunicipios) {
            consultaMunicipios.abort();
        }

        const controlador = new AbortController();
        consultaMunicipios = controlador;
        const parametros = new URLSearchParams(new FormData(municipiosForm));
        parametros.set('controller', 'dataTerritorial');
        parametros.set('action', 'municipiosTabla');

        municipiosContenido.classList.add('table-loading');

        try {
            const respuesta = await fetch(baseUrl + '?' + parametros.toString(), {
                headers: { 'X-Requested-With': 'fetch' },
                signal: controlador.signal
            });

            if (!respuesta.ok) {
                throw new Error('No fue posible consultar municipios.');
            }

            municipiosContenido.innerHTML = await respuesta.text();
            const parametrosPagina = new URLSearchParams(new FormData(municipiosForm));
            window.history.replaceState(null, '', baseUrl + '?' + parametrosPagina.toString() + '#municipios');
        } catch (error) {
            if (error.name !== 'AbortError') {
                municipiosContenido.innerHTML =
                    '<div class="empty-table-message">No fue posible actualizar los municipios.</div>';
            }
        } finally {
            if (consultaMunicipios === controlador) {
                municipiosContenido.classList.remove('table-loading');
            }
        }
    };

    if (municipiosForm) {
        const busqueda = municipiosForm.querySelector('[name="buscar_municipio"]');
        const limite = municipiosForm.querySelector('[name="limite_municipios"]');
        const pagina = municipiosForm.querySelector('[name="pagina_municipios"]');

        municipiosForm.addEventListener('submit', function (event) {
            event.preventDefault();
            cargarMunicipios();
        });

        if (busqueda) {
            busqueda.addEventListener('input', function () {
                if (pagina) {
                    pagina.value = '1';
                }
                window.clearTimeout(temporizadorMunicipios);
                temporizadorMunicipios = window.setTimeout(cargarMunicipios, 300);
            });
        }

        if (limite) {
            limite.addEventListener('change', function () {
                if (pagina) {
                    pagina.value = '1';
                }
                cargarMunicipios();
            });
        }

        municipiosContenido?.addEventListener('click', function (event) {
            const enlace = event.target.closest('[data-municipios-pagina]');

            if (!enlace || !pagina) {
                return;
            }

            event.preventDefault();
            pagina.value = enlace.dataset.municipiosPagina || '1';
            cargarMunicipios();
        });
    }

    document.querySelectorAll('[data-priority-municipality]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            if (!municipiosForm) {
                return;
            }

            const busqueda = municipiosForm.querySelector('[name="buscar_municipio"]');
            const pagina = municipiosForm.querySelector('[name="pagina_municipios"]');
            const municipio = boton.dataset.priorityMunicipality || '';

            if (!busqueda || municipio === '') {
                return;
            }

            busqueda.value = municipio;
            if (pagina) {
                pagina.value = '1';
            }

            cargarMunicipios();

            window.setTimeout(function () {
                municipiosForm.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                busqueda.focus({ preventScroll: true });
            }, 120);
        });
    });

    const mostrarToastSistema = function (mensaje, esError) {
        const contenedor = document.querySelector('.toast-container');

        if (!contenedor) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'toast system-toast' + (esError ? ' system-toast-error' : '');
        toast.setAttribute('role', esError ? 'alert' : 'status');
        toast.setAttribute('aria-live', esError ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.setAttribute('data-bs-delay', esError ? '4200' : '3200');
        toast.innerHTML =
            '<div class="toast-body">' +
                '<i class="bi ' + (esError ? 'bi-exclamation-circle' : 'bi-check2-circle') + '"></i>' +
                '<span></span>' +
            '</div>';
        toast.querySelector('span').textContent = mensaje;
        contenedor.appendChild(toast);

        const instanciaToast = new bootstrap.Toast(toast);
        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });
        instanciaToast.show();
    };

    const tiposActualizacionOficial = {
        poblacion: {
            nombre: 'Población estatal',
            action: 'actualizarPoblacionOficial',
            mensajeError: 'No fue posible obtener información oficial.'
        },
        actividad: {
            nombre: 'Actividad económica',
            action: 'actualizarActividadEconomicaOficial',
            mensajeError: 'No fue posible obtener información económica.',
            pausaPosterior: 400
        },
        municipios: {
            nombre: 'Municipios',
            action: 'actualizarMunicipiosOficiales',
            mensajeError: 'No fue posible obtener la información municipal de INEGI.',
            pausaPosterior: 250
        },
        poder_adquisitivo: {
            nombre: 'Poder adquisitivo',
            action: 'importarPoderAdquisitivo',
            mensajeError: 'No fue posible importar los indicadores de poder adquisitivo.',
            tipoOperacion: 'importacion'
        },
        rezago_educativo: {
            nombre: 'Rezago educativo',
            action: 'importarRezagoEducativo',
            mensajeError: 'No fue posible importar los indicadores oficiales de rezago educativo.',
            tipoOperacion: 'importacion'
        }
    };

    const esperar = function (milisegundos) {
        return new Promise(function (resolver) {
            window.setTimeout(resolver, milisegundos);
        });
    };

    const obtenerTiposSeleccionados = function (alcance) {
        return Array.from(
            document.querySelectorAll('[data-official-options="' + alcance + '"] [data-official-option]:checked')
        )
            .map(function (opcion) {
                return opcion.value;
            })
            .filter(function (tipo) {
                return Object.prototype.hasOwnProperty.call(tiposActualizacionOficial, tipo);
            });
    };

    const actualizarEstadoOpcionesOficiales = function (alcance, boton, bloqueado) {
        const seleccionados = obtenerTiposSeleccionados(alcance);
        const notaDenue = document.querySelector('[data-denue-note="' + alcance + '"]');
        const notaMunicipios = document.querySelector('[data-municipios-note="' + alcance + '"]');
        const requiereArchivoPoder = alcance === 'mass' && seleccionados.includes('poder_adquisitivo');
        const requiereArchivoEducacion = alcance === 'mass' && seleccionados.includes('rezago_educativo');

        document
            .querySelectorAll('[data-official-options="' + alcance + '"] .data-official-option-card')
            .forEach(function (tarjeta) {
                const checkbox = tarjeta.querySelector('[data-official-option]');
                tarjeta.classList.toggle('is-selected', !!checkbox?.checked);
            });

        if (notaDenue) {
            notaDenue.classList.toggle('d-none', !seleccionados.includes('actividad'));
        }

        if (notaMunicipios) {
            notaMunicipios.classList.toggle('d-none', !seleccionados.includes('municipios'));
        }

        if (alcance === 'mass' && bloqueImportacionPoder) {
            bloqueImportacionPoder.classList.toggle('d-none', !requiereArchivoPoder);
        }

        if (alcance === 'mass' && bloqueImportacionEducacion) {
            bloqueImportacionEducacion.classList.toggle('d-none', !requiereArchivoEducacion);
        }

        if (boton) {
            const archivoPoderListo = !requiereArchivoPoder || (tokenImportacionPoder !== '' && !importacionPoderValidando);
            const archivoEducacionListo = !requiereArchivoEducacion || (tokenImportacionEducacion !== '' && !importacionEducacionValidando);
            boton.disabled =
                bloqueado ||
                seleccionados.length === 0 ||
                !archivoPoderListo ||
                !archivoEducacionListo;
        }
    };

    const limpiarMensajeActualizacionOficial = function () {
        if (!mensajeActualizarOficial) {
            return;
        }

        mensajeActualizarOficial.textContent = '';
        mensajeActualizarOficial.classList.add('d-none');
        mensajeActualizarOficial.classList.remove(
            'data-official-modal-message-error',
            'data-official-modal-message-success'
        );
    };

    const mostrarMensajeActualizacionOficial = function (mensaje, esError) {
        if (!mensajeActualizarOficial) {
            return;
        }

        mensajeActualizarOficial.textContent = mensaje;
        mensajeActualizarOficial.classList.remove(
            'd-none',
            'data-official-modal-message-error',
            'data-official-modal-message-success'
        );
        mensajeActualizarOficial.classList.add(
            esError
                ? 'data-official-modal-message-error'
                : 'data-official-modal-message-success'
        );
    };

    const obtenerElementosActualizacionIndividual = function () {
        return {
            inicial: document.querySelector('[data-official-initial="individual"]'),
            progreso: document.querySelector('[data-official-progress="individual"]'),
            resultado: document.querySelector('[data-official-result="individual"]'),
            contador: document.querySelector('[data-official-progress-counter="individual"]'),
            barra: document.querySelector('[data-official-progress-bar="individual"]'),
            barraContenedor: document.querySelector('[data-official-progress="individual"] .data-mass-progress'),
            estado: document.querySelector('[data-official-progress-state="individual"]'),
            tipo: document.querySelector('[data-official-progress-type="individual"]'),
            tituloResultado: document.querySelector('[data-official-result-title="individual"]'),
            resumenResultado: document.querySelector('[data-official-result-summary="individual"]'),
            errores: document.querySelector('[data-official-error-list="individual"]'),
            finalizar: document.querySelector('[data-official-finish="individual"]')
        };
    };

    const establecerVistaActualizacionIndividual = function (modo) {
        const elementos = obtenerElementosActualizacionIndividual();

        elementos.inicial?.classList.toggle('d-none', modo !== 'inicial');
        elementos.progreso?.classList.toggle('d-none', modo !== 'progreso');
        elementos.resultado?.classList.toggle('d-none', modo !== 'resultado');
        botonActualizarInformacionOficial?.classList.toggle('d-none', modo !== 'inicial');
        elementos.finalizar?.classList.toggle('d-none', modo !== 'resultado');
    };

    const establecerBloqueoActualizacionIndividual = function (bloqueado) {
        actualizacionOficialEnCurso = bloqueado;
        document
            .querySelectorAll('[data-official-options="individual"] [data-official-option]')
            .forEach(function (opcion) {
                opcion.disabled = bloqueado;
            });

        modalActualizarOficialElemento
            ?.querySelectorAll('[data-bs-dismiss="modal"], .btn-close')
            .forEach(function (control) {
                control.disabled = bloqueado;
            });

        const etiqueta = botonActualizarInformacionOficial?.querySelector('.data-official-button-label');
        const carga = botonActualizarInformacionOficial?.querySelector('.data-official-button-loading');
        etiqueta?.classList.toggle('d-none', bloqueado);
        carga?.classList.toggle('d-none', !bloqueado);
        actualizarEstadoOpcionesOficiales('individual', botonActualizarInformacionOficial, bloqueado);
    };

    const actualizarProgresoIndividual = function (procesados, total, estadoActual, tipoActual) {
        const elementos = obtenerElementosActualizacionIndividual();
        const porcentaje = total > 0 ? Math.round((procesados / total) * 100) : 0;

        if (elementos.contador) {
            elementos.contador.textContent = procesados + ' de ' + total + ' operaciones';
        }

        if (elementos.estado) {
            elementos.estado.textContent = estadoActual || 'En espera';
        }

        if (elementos.tipo) {
            elementos.tipo.textContent = tipoActual || 'En espera';
        }

        if (elementos.barra) {
            elementos.barra.style.width = porcentaje + '%';
        }

        if (elementos.barraContenedor) {
            elementos.barraContenedor.setAttribute('aria-valuenow', String(porcentaje));
        }
    };

    const mostrarResultadoIndividual = function (resultados, errores, totalEstados) {
        const elementos = obtenerElementosActualizacionIndividual();
        const totalErrores = errores.length;

        if (elementos.tituloResultado) {
            elementos.tituloResultado.textContent = totalErrores === 0
                ? 'Actualización finalizada'
                : 'Actualización finalizada con incidencias';
        }

        if (elementos.resumenResultado) {
            elementos.resumenResultado.innerHTML = '';
            Object.keys(resultados).forEach(function (tipo) {
                const resumen = document.createElement('p');
                resumen.textContent =
                    tiposActualizacionOficial[tipo].nombre + ': ' +
                    resultados[tipo].exitosos + ' de ' + totalEstados + ' actualizados';
                elementos.resumenResultado.appendChild(resumen);
            });

            if (totalErrores > 0) {
                const incidencias = document.createElement('p');
                incidencias.textContent = totalErrores + ' incidencia' + (totalErrores === 1 ? '' : 's');
                elementos.resumenResultado.appendChild(incidencias);
            }
        }

        if (elementos.errores) {
            elementos.errores.innerHTML = '';
            elementos.errores.classList.toggle('d-none', totalErrores === 0);
            errores.forEach(function (error) {
                const item = document.createElement('div');
                const estado = document.createElement('strong');
                const tipo = document.createElement('small');
                const mensaje = document.createElement('span');

                estado.textContent = error.estado;
                tipo.textContent = error.tipo;
                mensaje.textContent = error.mensaje;
                item.appendChild(estado);
                item.appendChild(tipo);
                item.appendChild(mensaje);
                elementos.errores.appendChild(item);
            });
        }

        actualizarProgresoIndividual(
            Object.values(resultados).reduce(function (total, resultado) {
                return total + resultado.exitosos + resultado.errores;
            }, 0),
            Object.keys(resultados).length * totalEstados,
            'Finalizado',
            'Finalizado'
        );
        establecerVistaActualizacionIndividual('resultado');
    };

    const actualizarEstadoOperacion = async function (estado, tipo) {
        const configuracion = tiposActualizacionOficial[tipo];
        const datos = new URLSearchParams();
        datos.set('estado_id', String(estado.id || ''));

        const respuesta = await fetch(
            baseUrl + '?controller=dataTerritorial&action=' + configuracion.action,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'fetch'
                },
                body: datos.toString()
            }
        );
        const resultado = await respuesta.json();

        if (!respuesta.ok || resultado.ok !== true) {
            throw new Error(resultado.mensaje || configuracion.mensajeError);
        }

        return resultado;
    };

    const trimestreTexto = function (trimestre, anio) {
        const nombres = {
            1: 'Primer trimestre',
            2: 'Segundo trimestre',
            3: 'Tercer trimestre',
            4: 'Cuarto trimestre'
        };

        return (nombres[Number(trimestre)] || 'Trimestre') + (anio ? ' de ' + anio : '');
    };

    const limpiarPreviewPoderAdquisitivo = function () {
        tokenImportacionPoder = '';
        importacionPoderValidando = false;

        if (estadoImportacionPoder) {
            estadoImportacionPoder.textContent = '';
            estadoImportacionPoder.className = 'data-power-import-status d-none';
        }

        if (previewImportacionPoder) {
            previewImportacionPoder.classList.add('d-none');
        }

        document.querySelector('[data-power-preview-existing]')?.classList.add('d-none');
    };

    const mostrarEstadoImportacionPoder = function (mensaje, tipo) {
        if (!estadoImportacionPoder) {
            return;
        }

        estadoImportacionPoder.textContent = mensaje;
        estadoImportacionPoder.className = 'data-power-import-status';
        estadoImportacionPoder.classList.add('is-' + tipo);
    };

    const validarArchivoPoderAdquisitivo = async function (archivo) {
        limpiarPreviewPoderAdquisitivo();

        if (!archivo) {
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
            return;
        }

        if (!archivo.name.toLowerCase().endsWith('.xlsx')) {
            mostrarEstadoImportacionPoder('El archivo debe estar en formato XLSX.', 'error');
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
            return;
        }

        importacionPoderValidando = true;
        mostrarEstadoImportacionPoder('Validando estructura y último periodo del archivo...', 'loading');
        actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
        const formulario = new FormData();
        formulario.append('archivo_poder_adquisitivo', archivo);

        try {
            const respuesta = await fetch(
                baseUrl + '?controller=dataTerritorial&action=previsualizarPoderAdquisitivo',
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formulario
                }
            );
            const resultado = await respuesta.json();

            if (!respuesta.ok || resultado.ok !== true) {
                throw new Error(resultado.mensaje || 'No fue posible validar el archivo.');
            }

            const datos = resultado.datos || {};
            tokenImportacionPoder = String(datos.token || '');
            mostrarEstadoImportacionPoder('Archivo validado correctamente. Revisa la vista previa antes de continuar.', 'success');

            const archivoPreview = document.querySelector('[data-power-preview-file]');
            const periodoPreview = document.querySelector('[data-power-preview-period]');
            const territoriosPreview = document.querySelector('[data-power-preview-territories]');
            const existentePreview = document.querySelector('[data-power-preview-existing]');

            if (archivoPreview) {
                archivoPreview.textContent = datos.archivo || archivo.name;
            }

            if (periodoPreview) {
                periodoPreview.textContent = trimestreTexto(datos.periodo?.trimestre, datos.periodo?.anio);
            }

            if (territoriosPreview) {
                territoriosPreview.textContent = '32 Estados + referencia nacional';
            }

            if (existentePreview) {
                existentePreview.classList.toggle('d-none', !datos.periodo_existente);
                existentePreview.textContent = datos.periodo_existente
                    ? 'Este periodo ya existe. Al continuar se actualizarán sus registros sin crear duplicados.'
                    : '';
            }

            previewImportacionPoder?.classList.remove('d-none');
        } catch (error) {
            tokenImportacionPoder = '';
            mostrarEstadoImportacionPoder(
                error.message || 'No fue posible validar el archivo XLSX.',
                'error'
            );
        } finally {
            importacionPoderValidando = false;
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
        }
    };

    const importarArchivoPoderAdquisitivo = async function () {
        if (tokenImportacionPoder === '') {
            throw new Error('Selecciona y valida el archivo XLSX de Pobreza Laboral.');
        }

        const datos = new URLSearchParams();
        datos.set('token', tokenImportacionPoder);
        const respuesta = await fetch(
            baseUrl + '?controller=dataTerritorial&action=importarPoderAdquisitivo',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'fetch'
                },
                body: datos.toString()
            }
        );
        const resultado = await respuesta.json();

        if (!respuesta.ok || resultado.ok !== true) {
            throw new Error(resultado.mensaje || tiposActualizacionOficial.poder_adquisitivo.mensajeError);
        }

        return resultado;
    };

    const limpiarPreviewRezagoEducativo = function () {
        tokenImportacionEducacion = '';
        importacionEducacionValidando = false;

        if (estadoImportacionEducacion) {
            estadoImportacionEducacion.textContent = '';
            estadoImportacionEducacion.className = 'data-power-import-status d-none';
        }

        if (previewImportacionEducacion) {
            previewImportacionEducacion.classList.add('d-none');
        }
    };

    const mostrarEstadoImportacionEducacion = function (mensaje, tipo) {
        if (!estadoImportacionEducacion) {
            return;
        }

        estadoImportacionEducacion.textContent = mensaje;
        estadoImportacionEducacion.className = 'data-power-import-status';
        estadoImportacionEducacion.classList.add('is-' + tipo);
    };

    const validarArchivoRezagoEducativo = async function (archivo) {
        limpiarPreviewRezagoEducativo();

        if (!archivo) {
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
            return;
        }

        if (!archivo.name.toLowerCase().endsWith('.xlsx')) {
            mostrarEstadoImportacionEducacion('El archivo debe estar en formato XLSX.', 'error');
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
            return;
        }

        importacionEducacionValidando = true;
        mostrarEstadoImportacionEducacion('Validando territorios, periodos y rezago educativo...', 'loading');
        actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
        const formulario = new FormData();
        formulario.append('archivo_rezago_educativo', archivo);

        try {
            const respuesta = await fetch(
                baseUrl + '?controller=dataTerritorial&action=previsualizarRezagoEducativo',
                {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'fetch'
                    },
                    body: formulario
                }
            );
            const resultado = await respuesta.json();

            if (!respuesta.ok || resultado.ok !== true) {
                throw new Error(resultado.mensaje || 'No fue posible validar el archivo.');
            }

            const datos = resultado.datos || {};
            tokenImportacionEducacion = String(datos.token || '');
            mostrarEstadoImportacionEducacion('Archivo validado correctamente. Revisa la vista previa antes de continuar.', 'success');

            const archivoPreview = document.querySelector('[data-education-preview-file]');
            const periodosPreview = document.querySelector('[data-education-preview-periods]');
            const ultimoPreview = document.querySelector('[data-education-preview-latest]');
            const resumenPreview = document.querySelector('[data-education-preview-summary]');
            const periodos = Array.isArray(datos.periodos) ? datos.periodos : [];
            const nuevos = Array.isArray(datos.periodos_nuevos) ? datos.periodos_nuevos : [];
            const existentes = Array.isArray(datos.periodos_existentes) ? datos.periodos_existentes : [];

            if (archivoPreview) {
                archivoPreview.textContent = datos.archivo || archivo.name;
            }

            if (periodosPreview) {
                periodosPreview.textContent = periodos.length > 0
                    ? periodos.join(', ')
                    : 'No identificados';
            }

            if (ultimoPreview) {
                ultimoPreview.textContent = datos.ultimo_periodo || '—';
            }

            if (resumenPreview) {
                const partes = [];

                if (nuevos.length > 0) {
                    partes.push('Nuevos: ' + nuevos.join(', ') + '.');
                }

                if (existentes.length > 0) {
                    partes.push('Ya registrados: ' + existentes.join(', ') + '.');
                }

                partes.push((datos.total_registros || 0) + ' registros serán procesados.');
                partes.push('Los periodos existentes se actualizarán sin crear duplicados.');
                resumenPreview.textContent = partes.join(' ');
            }

            previewImportacionEducacion?.classList.remove('d-none');
        } catch (error) {
            tokenImportacionEducacion = '';
            mostrarEstadoImportacionEducacion(
                error.message || 'No fue posible validar el archivo XLSX.',
                'error'
            );
        } finally {
            importacionEducacionValidando = false;
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, actualizacionMasivaEnCurso);
        }
    };

    const importarArchivoRezagoEducativo = async function () {
        if (tokenImportacionEducacion === '') {
            throw new Error('Selecciona y valida el archivo XLSX de Pobreza Multidimensional.');
        }

        const datos = new URLSearchParams();
        datos.set('token', tokenImportacionEducacion);
        const respuesta = await fetch(
            baseUrl + '?controller=dataTerritorial&action=importarRezagoEducativo',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'fetch'
                },
                body: datos.toString()
            }
        );
        const resultado = await respuesta.json();

        if (!respuesta.ok || resultado.ok !== true) {
            throw new Error(resultado.mensaje || tiposActualizacionOficial.rezago_educativo.mensajeError);
        }

        return resultado;
    };

    const obtenerElementosActualizacionMasiva = function () {
        return {
            inicial: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-initial]'),
            progreso: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-progress]'),
            resultado: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-result]'),
            contador: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-counter]'),
            barra: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-bar]'),
            barraContenedor: modalActualizacionMasivaElemento?.querySelector('.data-mass-progress'),
            actual: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-current]'),
            tipo: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-type]'),
            tituloResultado: modalActualizacionMasivaElemento?.querySelector('[data-mass-result-title]'),
            resumenResultado: modalActualizacionMasivaElemento?.querySelector('[data-mass-result-summary]'),
            errores: modalActualizacionMasivaElemento?.querySelector('[data-mass-error-list]'),
            cancelar: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-cancel]'),
            cerrar: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-close]'),
            finalizar: modalActualizacionMasivaElemento?.querySelector('[data-mass-update-finish]')
        };
    };

    const establecerVistaActualizacionMasiva = function (modo) {
        const elementos = obtenerElementosActualizacionMasiva();

        elementos.inicial?.classList.toggle('d-none', modo !== 'inicial');
        elementos.progreso?.classList.toggle('d-none', modo !== 'progreso');
        elementos.resultado?.classList.toggle('d-none', modo !== 'resultado');
        botonActualizacionMasiva?.classList.toggle('d-none', modo !== 'inicial');
        elementos.cancelar?.classList.toggle('d-none', modo === 'resultado');
        elementos.finalizar?.classList.toggle('d-none', modo !== 'resultado');
    };

    const establecerBloqueoActualizacionMasiva = function (bloqueado) {
        const elementos = obtenerElementosActualizacionMasiva();
        actualizacionMasivaEnCurso = bloqueado;

        actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, bloqueado);

        document
            .querySelectorAll('[data-official-options="mass"] [data-official-option]')
            .forEach(function (opcion) {
                opcion.disabled = bloqueado;
            });

        if (archivoImportacionPoder) {
            archivoImportacionPoder.disabled = bloqueado;
        }

        if (archivoImportacionEducacion) {
            archivoImportacionEducacion.disabled = bloqueado;
        }

        if (botonAbrirActualizacionMasiva) {
            botonAbrirActualizacionMasiva.disabled = bloqueado;
        }

        if (elementos.cancelar) {
            elementos.cancelar.disabled = bloqueado;
        }

        if (elementos.cerrar) {
            elementos.cerrar.disabled = bloqueado;
        }
    };

    const actualizarProgresoMasivo = function (procesados, total, estadoActual, tipoActual) {
        const elementos = obtenerElementosActualizacionMasiva();
        const porcentaje = total > 0 ? Math.round((procesados / total) * 100) : 0;

        if (elementos.contador) {
            elementos.contador.textContent = procesados + ' de ' + total + ' operaciones';
        }

        if (elementos.actual) {
            elementos.actual.textContent = estadoActual || 'En espera';
        }

        if (elementos.tipo) {
            elementos.tipo.textContent = tipoActual || 'En espera';
        }

        if (elementos.barra) {
            elementos.barra.style.width = porcentaje + '%';
        }

        if (elementos.barraContenedor) {
            elementos.barraContenedor.setAttribute('aria-valuenow', String(porcentaje));
        }
    };

    const crearResultadoInicial = function (tiposSeleccionados) {
        return tiposSeleccionados.reduce(function (resultado, tipo) {
            resultado[tipo] = {
                exitosos: 0,
                errores: 0
            };
            return resultado;
        }, {});
    };

    const mostrarResultadoMasivo = function (resultados, errores, totalEstados, totalOperaciones) {
        const elementos = obtenerElementosActualizacionMasiva();
        const totalErrores = errores.length;

        if (elementos.tituloResultado) {
            elementos.tituloResultado.textContent = totalErrores === 0
                ? 'Actualización finalizada'
                : 'Actualización finalizada con incidencias';
        }

        if (elementos.resumenResultado) {
            elementos.resumenResultado.innerHTML = '';
            Object.keys(resultados).forEach(function (tipo) {
                const actualizado = document.createElement('p');

                if (tipo === 'poder_adquisitivo') {
                    actualizado.textContent = resultados[tipo].errores > 0
                        ? 'Poder adquisitivo: no fue posible completar la importación'
                        : 'Poder adquisitivo: 32 de 32 Estados y referencia nacional actualizados';
                } else if (tipo === 'rezago_educativo') {
                    actualizado.textContent = resultados[tipo].errores > 0
                        ? 'Rezago educativo: no fue posible completar la importación'
                        : 'Rezago educativo: 32 de 32 Estados, referencia nacional e histórico actualizados';
                } else {
                    actualizado.textContent =
                        tiposActualizacionOficial[tipo].nombre + ': ' +
                        resultados[tipo].exitosos + ' de ' + totalEstados + ' actualizados';
                }

                elementos.resumenResultado.appendChild(actualizado);
            });

            if (totalErrores > 0) {
                const conError = document.createElement('p');
                conError.textContent = totalErrores + ' incidencia' + (totalErrores === 1 ? '' : 's');
                elementos.resumenResultado.appendChild(conError);
            }
        }

        if (elementos.errores) {
            elementos.errores.innerHTML = '';
            elementos.errores.classList.toggle('d-none', totalErrores === 0);

            errores.forEach(function (error) {
                const item = document.createElement('div');
                const estado = document.createElement('strong');
                const tipo = document.createElement('small');
                const mensaje = document.createElement('span');

                estado.textContent = error.estado;
                tipo.textContent = error.tipo;
                mensaje.textContent = error.mensaje;
                item.appendChild(estado);
                item.appendChild(tipo);
                item.appendChild(mensaje);
                elementos.errores.appendChild(item);
            });
        }

        actualizarProgresoMasivo(
            totalOperaciones,
            totalOperaciones,
            'Finalizado',
            'Finalizado'
        );
        establecerVistaActualizacionMasiva('resultado');
    };

    if (modalActualizarOficialElemento) {
        modalActualizarOficialElemento.addEventListener('hide.bs.modal', function (event) {
            if (actualizacionOficialEnCurso) {
                event.preventDefault();
            }
        });

        modalActualizarOficialElemento.addEventListener('show.bs.modal', function () {
            if (actualizacionOficialEnCurso) {
                return;
            }

            establecerVistaActualizacionIndividual('inicial');
            establecerBloqueoActualizacionIndividual(false);
            limpiarMensajeActualizacionOficial();
            actualizarProgresoIndividual(0, 0, 'En espera', 'En espera');
        });
    }

    document
        .querySelectorAll('[data-official-options="individual"] [data-official-option]')
        .forEach(function (opcion) {
            opcion.addEventListener('change', function () {
                actualizarEstadoOpcionesOficiales(
                    'individual',
                    botonActualizarInformacionOficial,
                    actualizacionOficialEnCurso
                );
            });
        });

    if (botonActualizarInformacionOficial) {
        actualizarEstadoOpcionesOficiales('individual', botonActualizarInformacionOficial, false);

        botonActualizarInformacionOficial.addEventListener('click', async function () {
            const tiposSeleccionados = obtenerTiposSeleccionados('individual');

            if (
                actualizacionOficialEnCurso ||
                tiposSeleccionados.length === 0 ||
                !botonActualizarInformacionOficial.dataset.estadoId
            ) {
                return;
            }

            const estado = {
                id: botonActualizarInformacionOficial.dataset.estadoId,
                nombre: '<?= $estadoSeleccionado ? $texto($estadoSeleccionado['nombre']) : '' ?>'
            };
            const totalOperaciones = tiposSeleccionados.length;
            const resultados = crearResultadoInicial(tiposSeleccionados);
            const errores = [];
            let procesados = 0;

            limpiarMensajeActualizacionOficial();
            establecerBloqueoActualizacionIndividual(true);
            establecerVistaActualizacionIndividual('progreso');

            for (const tipo of tiposSeleccionados) {
                const configuracion = tiposActualizacionOficial[tipo];
                actualizarProgresoIndividual(
                    procesados,
                    totalOperaciones,
                    estado.nombre,
                    configuracion.nombre
                );

                try {
                    await actualizarEstadoOperacion(estado, tipo);
                    resultados[tipo].exitosos += 1;
                } catch (error) {
                    resultados[tipo].errores += 1;
                    errores.push({
                        estado: estado.nombre || 'Estado sin nombre',
                        tipo: configuracion.nombre,
                        mensaje: error.message || configuracion.mensajeError
                    });
                }

                procesados += 1;
                actualizarProgresoIndividual(
                    procesados,
                    totalOperaciones,
                    estado.nombre,
                    configuracion.nombre
                );

                if (configuracion.pausaPosterior) {
                    await esperar(configuracion.pausaPosterior);
                }
            }

            establecerBloqueoActualizacionIndividual(false);
            mostrarResultadoIndividual(resultados, errores, 1);
        });
    }

    document.querySelectorAll('[data-economic-toggle]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const expandido = boton.getAttribute('aria-expanded') === 'true';
            const mostrarTodos = !expandido;

            document.querySelectorAll('.data-economic-sector-extra').forEach(function (fila) {
                fila.classList.toggle('d-none', !mostrarTodos);
            });

            boton.setAttribute('aria-expanded', mostrarTodos ? 'true' : 'false');
            boton.innerHTML = mostrarTodos
                ? '<i class="bi bi-chevron-up"></i> Mostrar solo principales'
                : '<i class="bi bi-chevron-down"></i> Ver todos los sectores';
        });
    });

    if (modalActualizacionMasivaElemento) {
        modalActualizacionMasivaElemento.addEventListener('hide.bs.modal', function (event) {
            if (actualizacionMasivaEnCurso) {
                event.preventDefault();
            }
        });

        modalActualizacionMasivaElemento.addEventListener('show.bs.modal', function () {
            if (actualizacionMasivaEnCurso) {
                return;
            }

            establecerVistaActualizacionMasiva('inicial');
            limpiarPreviewPoderAdquisitivo();
            limpiarPreviewRezagoEducativo();

            document
                .querySelectorAll('[data-official-options="mass"] [data-official-option]')
                .forEach(function (opcion) {
                    opcion.checked = false;
                });

            if (archivoImportacionPoder) {
                archivoImportacionPoder.value = '';
            }

            if (archivoImportacionEducacion) {
                archivoImportacionEducacion.value = '';
            }

            establecerBloqueoActualizacionMasiva(false);
            actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, false);
            actualizarProgresoMasivo(0, 0, 'En espera', 'En espera');
        });
    }

    archivoImportacionPoder?.addEventListener('change', function () {
        validarArchivoPoderAdquisitivo(archivoImportacionPoder.files?.[0] || null);
    });

    archivoImportacionEducacion?.addEventListener('change', function () {
        validarArchivoRezagoEducativo(archivoImportacionEducacion.files?.[0] || null);
    });

    document
        .querySelectorAll('[data-official-options="mass"] [data-official-option]')
        .forEach(function (opcion) {
            opcion.addEventListener('change', function () {
                actualizarEstadoOpcionesOficiales(
                    'mass',
                    botonActualizacionMasiva,
                    actualizacionMasivaEnCurso
                );
            });
        });

    if (botonActualizacionMasiva) {
        actualizarEstadoOpcionesOficiales('mass', botonActualizacionMasiva, false);

        botonActualizacionMasiva.addEventListener('click', async function () {
            const tiposSeleccionados = obtenerTiposSeleccionados('mass');

            if (
                actualizacionMasivaEnCurso ||
                estadosActualizacionMasiva.length === 0 ||
                tiposSeleccionados.length === 0
            ) {
                return;
            }

            establecerBloqueoActualizacionMasiva(true);
            establecerVistaActualizacionMasiva('progreso');

            const resultados = crearResultadoInicial(tiposSeleccionados);
            const errores = [];
            const tiposPorEstado = tiposSeleccionados.filter(function (tipo) {
                return tipo !== 'poder_adquisitivo' && tipo !== 'rezago_educativo';
            });
            const incluyePoderAdquisitivo = tiposSeleccionados.includes('poder_adquisitivo');
            const incluyeRezagoEducativo = tiposSeleccionados.includes('rezago_educativo');
            const totalOperaciones =
                (estadosActualizacionMasiva.length * tiposPorEstado.length) +
                (incluyePoderAdquisitivo ? 1 : 0) +
                (incluyeRezagoEducativo ? 1 : 0);
            let procesados = 0;

            for (const estado of estadosActualizacionMasiva) {
                for (const tipo of tiposPorEstado) {
                    const configuracion = tiposActualizacionOficial[tipo];
                    actualizarProgresoMasivo(
                        procesados,
                        totalOperaciones,
                        estado.nombre,
                        configuracion.nombre
                    );

                    try {
                        await actualizarEstadoOperacion(estado, tipo);
                        resultados[tipo].exitosos += 1;
                    } catch (error) {
                        resultados[tipo].errores += 1;
                        errores.push({
                            estado: estado.nombre || 'Estado sin nombre',
                            tipo: configuracion.nombre,
                            mensaje: error.message || configuracion.mensajeError
                        });
                    }

                    procesados += 1;
                    actualizarProgresoMasivo(
                        procesados,
                        totalOperaciones,
                        estado.nombre,
                        configuracion.nombre
                    );

                    if (configuracion.pausaPosterior) {
                        await esperar(configuracion.pausaPosterior);
                    }
                }
            }

            if (incluyePoderAdquisitivo) {
                const configuracion = tiposActualizacionOficial.poder_adquisitivo;
                actualizarProgresoMasivo(
                    procesados,
                    totalOperaciones,
                    '32 Estados + México',
                    configuracion.nombre
                );

                try {
                    const importacion = await importarArchivoPoderAdquisitivo();
                    resultados.poder_adquisitivo.exitosos = Number(importacion.datos?.estados_importados || 32);
                    tokenImportacionPoder = '';
                } catch (error) {
                    resultados.poder_adquisitivo.errores = 1;
                    errores.push({
                        estado: 'Todos los Estados',
                        tipo: configuracion.nombre,
                        mensaje: error.message || configuracion.mensajeError
                    });
                }

                procesados += 1;
                actualizarProgresoMasivo(
                    procesados,
                    totalOperaciones,
                    '32 Estados + México',
                    configuracion.nombre
                );
            }

            if (incluyeRezagoEducativo) {
                const configuracion = tiposActualizacionOficial.rezago_educativo;
                actualizarProgresoMasivo(
                    procesados,
                    totalOperaciones,
                    '32 Estados + México',
                    configuracion.nombre
                );

                try {
                    const importacion = await importarArchivoRezagoEducativo();
                    resultados.rezago_educativo.exitosos = Number(importacion.datos?.estados_importados || 32);
                    tokenImportacionEducacion = '';
                } catch (error) {
                    resultados.rezago_educativo.errores = 1;
                    errores.push({
                        estado: 'Todos los Estados',
                        tipo: configuracion.nombre,
                        mensaje: error.message || configuracion.mensajeError
                    });
                }

                procesados += 1;
                actualizarProgresoMasivo(
                    procesados,
                    totalOperaciones,
                    '32 Estados + México',
                    configuracion.nombre
                );
            }

            establecerBloqueoActualizacionMasiva(false);
            mostrarResultadoMasivo(
                resultados,
                errores,
                estadosActualizacionMasiva.length,
                totalOperaciones
            );
        });
    }

    document.querySelector('[data-mass-update-finish]')?.addEventListener('click', function () {
        window.location.reload();
    });

    document.querySelector('[data-official-finish="individual"]')?.addEventListener('click', function () {
        window.location.reload();
    });

    const prepararFormulario = function (formulario, accion, campos) {
        if (!formulario) {
            return;
        }

        formulario.action = accion;
        formulario.reset();

        Object.keys(campos || {}).forEach(function (id) {
            const campo = document.getElementById(id);

            if (campo) {
                campo.value = campos[id] || '';
            }
        });
    };

    const establecerTexto = function (id, texto) {
        const elemento = document.getElementById(id);

        if (elemento) {
            elemento.textContent = texto;
        }
    };

    const establecerPreviewImagen = function (idPreview, url, icono) {
        const preview = document.getElementById(idPreview);

        if (!preview) {
            return;
        }

        if (url) {
            preview.innerHTML = '';
            const imagen = document.createElement('img');
            imagen.src = url;
            imagen.alt = 'Vista previa de la imagen';
            preview.appendChild(imagen);
            return;
        }

        preview.innerHTML = '<i class="bi ' + icono + '"></i>';
    };

    document.querySelectorAll('.data-photo-input').forEach(function (input) {
        input.addEventListener('change', function () {
            const archivo = input.files && input.files[0] ? input.files[0] : null;
            const previewId = input.dataset.previewTarget || '';
            const icono = input.dataset.previewIcon || 'bi-image';

            if (!archivo || !previewId) {
                establecerPreviewImagen(previewId, '', icono);
                return;
            }

            const lector = new FileReader();

            lector.onload = function (evento) {
                establecerPreviewImagen(previewId, evento.target.result, icono);
            };

            lector.readAsDataURL(archivo);
        });
    });

    document.addEventListener('click', function (event) {
        const secretariaCrear = event.target.closest('[data-secretaria-create]');
        const secretariaEditar = event.target.closest('[data-secretaria-edit]');
        const indicadorCrear = event.target.closest('[data-indicador-create]');
        const indicadorEditar = event.target.closest('[data-indicador-edit]');
        const indicadorEliminar = event.target.closest('[data-indicador-delete]');
        const municipioEditar = event.target.closest('[data-municipio-edit]');

        if (secretariaCrear) {
            establecerTexto('modalSecretariaTitulo', 'Registrar secretaría');
            establecerTexto(
                'modalSecretariaSubtitulo',
                'Registra una dependencia estatal y su información institucional.'
            );
            establecerTexto('botonGuardarSecretaria', 'Guardar secretaría');
            prepararFormulario(
                document.getElementById('formSecretaria'),
                '<?= BASE_URL ?>index.php?controller=dataTerritorial&action=guardarSecretaria',
                { secretaria_id: '' }
            );
        }

        if (secretariaEditar) {
            establecerTexto('modalSecretariaTitulo', 'Editar secretaría');
            establecerTexto(
                'modalSecretariaSubtitulo',
                'Actualiza la información institucional de la dependencia.'
            );
            establecerTexto('botonGuardarSecretaria', 'Guardar cambios');
            prepararFormulario(
                document.getElementById('formSecretaria'),
                '<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarSecretaria',
                {
                    secretaria_id: secretariaEditar.dataset.id,
                    secretaria_nombre: secretariaEditar.dataset.nombre,
                    secretaria_titular: secretariaEditar.dataset.titular,
                    secretaria_cargo: secretariaEditar.dataset.cargoTitular,
                    secretaria_correo: secretariaEditar.dataset.correo,
                    secretaria_telefono: secretariaEditar.dataset.telefono,
                    secretaria_sitio: secretariaEditar.dataset.sitioWeb
                }
            );
        }

        if (indicadorCrear) {
            establecerTexto('modalIndicadorTitulo', 'Indicador educativo complementario');
            establecerTexto(
                'modalIndicadorSubtitulo',
                'Registra información educativa adicional obtenida de fuentes verificadas.'
            );
            establecerTexto('botonGuardarIndicador', 'Guardar indicador');
            prepararFormulario(
                document.getElementById('formIndicador'),
                '<?= BASE_URL ?>index.php?controller=dataTerritorial&action=guardarIndicador',
                { indicador_id: '' }
            );
        }

        if (indicadorEditar) {
            establecerTexto('modalIndicadorTitulo', 'Editar indicador educativo');
            establecerTexto(
                'modalIndicadorSubtitulo',
                'Actualiza la información complementaria. La fecha de consulta se renovará automáticamente al guardar.'
            );
            establecerTexto('botonGuardarIndicador', 'Guardar cambios');
            prepararFormulario(
                document.getElementById('formIndicador'),
                '<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarIndicador',
                {
                    indicador_id: indicadorEditar.dataset.id,
                    indicador_situacion: indicadorEditar.dataset.situacion,
                    indicador_valor: indicadorEditar.dataset.valor,
                    indicador_unidad: indicadorEditar.dataset.unidad,
                    indicador_cantidad: indicadorEditar.dataset.cantidadAproximada,
                    indicador_fuente: indicadorEditar.dataset.fuente,
                    indicador_periodo: indicadorEditar.dataset.periodo
                }
            );
        }

        if (indicadorEliminar) {
            const idEliminar = document.getElementById('indicador_eliminar_id');
            const nombreEliminar = document.getElementById('indicador_eliminar_nombre');

            if (idEliminar) {
                idEliminar.value = indicadorEliminar.dataset.id || '';
            }

            if (nombreEliminar) {
                nombreEliminar.textContent = indicadorEliminar.dataset.nombre || 'seleccionado';
            }
        }

        if (municipioEditar) {
            establecerTexto('modalMunicipioTitulo', 'Editar municipio');
            establecerTexto(
                'modalMunicipioSubtitulo',
                'Actualiza la información complementaria del municipio.'
            );
            establecerTexto('botonGuardarMunicipio', 'Guardar cambios');
            establecerTexto('municipioFotoLabel', 'Cambiar fotografía del presidente');
            prepararFormulario(
                document.getElementById('formMunicipio'),
                '<?= BASE_URL ?>index.php?controller=dataTerritorial&action=actualizarMunicipio',
                {
                    municipio_id: municipioEditar.dataset.id,
                    municipio_nombre: municipioEditar.dataset.nombre,
                    municipio_clave: municipioEditar.dataset.claveInegi,
                    municipio_poblacion: municipioEditar.dataset.poblacion,
                    municipio_presidente: municipioEditar.dataset.presidenteMunicipal,
                    municipio_partido: municipioEditar.dataset.partidoPolitico,
                    municipio_redes: municipioEditar.dataset.redesSociales
                }
            );
            establecerPreviewImagen(
                'municipioFotoPreview',
                municipioEditar.dataset.fotografiaUrl || '',
                'bi-image'
            );

            const panelFoto = document.getElementById('municipio_quitar_foto_panel');
            const checkFoto = document.getElementById('municipio_quitar_foto');
            const inputFoto = document.getElementById('municipio_fotografia');

            if (panelFoto) {
                panelFoto.classList.toggle('d-none', (municipioEditar.dataset.fotografia || '') === '');
            }

            if (checkFoto) {
                checkFoto.checked = false;
                checkFoto.disabled = false;
            }

            if (inputFoto) {
                inputFoto.value = '';
            }
        }
    });

    const fotoMunicipio = document.getElementById('municipio_fotografia');
    const quitarFotoMunicipio = document.getElementById('municipio_quitar_foto');

    if (fotoMunicipio && quitarFotoMunicipio) {
        fotoMunicipio.addEventListener('change', function () {
            const tieneArchivo = fotoMunicipio.files.length > 0;

            if (tieneArchivo) {
                quitarFotoMunicipio.checked = false;
            }

            quitarFotoMunicipio.disabled = tieneArchivo;
        });

        quitarFotoMunicipio.addEventListener('change', function () {
            if (quitarFotoMunicipio.checked) {
                establecerPreviewImagen('municipioFotoPreview', '', 'bi-image');
            }
        });
    }

    document.querySelectorAll('.system-toast').forEach(function (toast) {
        new bootstrap.Toast(toast).show();
    });
});
</script>
