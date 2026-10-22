<?php
$convenios = is_array($convenios ?? null) ? $convenios : [];
$estados = is_array($estados ?? null) ? $estados : [];
$estructuraDisponible = (bool)($estructuraDisponible ?? false);
$conteosEstado = [];
foreach ($convenios as $convenioConteo) {
    $idConteo = (int)($convenioConteo['estado_id'] ?? 0);
    if ($idConteo > 0) { $conteosEstado[$idConteo] = ($conteosEstado[$idConteo] ?? 0) + 1; }
}
$estadoSeleccionadoId = (int)($_GET['estado_id'] ?? 0);
$estadoSeleccionado = null;
foreach ($estados as $estadoDisponible) {
    if ((int)$estadoDisponible['id'] === $estadoSeleccionadoId) {
        $estadoSeleccionado = $estadoDisponible;
        break;
    }
}
$conveniosVisibles = $estadoSeleccionado
    ? array_values(array_filter($convenios, static fn($c) => (int)($c['estado_id'] ?? 0) === $estadoSeleccionadoId))
    : [];
usort($conveniosVisibles, static function ($a, $b) {
    return strnatcasecmp((string)($a['municipio_nombre'] ?? ''), (string)($b['municipio_nombre'] ?? ''))
        ?: strnatcasecmp((string)($a['nombre_entidad'] ?? ''), (string)($b['nombre_entidad'] ?? ''));
});
$nombreMunicipioAnterior = null;
$mapaConvenios = static function ($nombreEstado) {
    require_once __DIR__ . '/../../helpers/MapaEstadoHelper.php';
    return impeRutaMapaEstado((string)$nombreEstado);
};

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$fechaHora = static function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    try {
        $valor=new DateTime($fecha);
        return $valor->format($valor->format('H:i:s')==='00:00:00'?'d/m/Y':'d/m/Y H:i');
    } catch (Exception $error) {
        return $fecha;
    }
};

$fecha = static function ($valor) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return '—';
    }

    try {
        return (new DateTime($valor))->format('d/m/Y');
    } catch (Exception $error) {
        return $valor;
    }
};
?>

<section
    class="crear-convocatoria-module"
    data-crear-convocatoria-module
    data-convocatoria-base-url="<?= $texto(BASE_URL . 'index.php?controller=convocatoria&action=index') ?>">

    <?php if (!$estadoSeleccionado): ?>
        <section class="dashboard-panel data-territorial-selector aliados-territory-selector">
            <div class="data-selector-heading"><div class="data-territorial-selector-copy">
                <h2 class="panel-title mb-1">Seleccionar territorio</h2>
                <p>Elige un estado para consultar sus convenios y crear publicaciones por programa.</p>
            </div></div>
            <div class="data-territorial-toolbar aliados-territory-toolbar">
                <div class="data-filter-field aliados-territory-search-field">
                    <label for="buscarTerritorioConvenio">Buscar territorio</label>
                    <div class="module-search"><i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" class="form-control" id="buscarTerritorioConvenio" placeholder="Buscar territorio..." autocomplete="off" data-convenios-territorio-buscar>
                    </div>
                </div>
                <div class="data-filter-field">
                    <label for="conveniosEstadoActividad">Estado de convenios</label>
                    <select class="form-select" id="conveniosEstadoActividad" data-convenios-territorio-tipo>
                        <option value="">Todos</option><option value="con">Con convenios</option><option value="sin">Sin convenios</option>
                    </select>
                </div>
                <div class="data-filter-field">
                    <label for="conveniosTerritorioSelect">Territorio</label>
                    <select class="form-select" id="conveniosTerritorioSelect" data-convenios-territorio-seleccionar>
                        <option value="">Seleccionar Estado</option>
                        <?php foreach ($estados as $estadoOpcion): ?>
                            <option value="<?= (int)$estadoOpcion['id'] ?>"><?= $texto($estadoOpcion['nombre'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>
        <span class="visually-hidden" aria-live="polite" data-convenios-territorio-contador><?= count($estados) ?> territorios disponibles</span>
        <section class="data-territorial-cards linkage-territory-grid aliados-territory-grid" data-convenios-territorios aria-label="Estados con convenios">
            <?php foreach ($estados as $estadoTarjeta): ?>
                <?php
                $idTarjeta = (int)$estadoTarjeta['id'];
                $cantidadEstado = (int)($conteosEstado[$idTarjeta] ?? 0);
                $imagenLocal = $mapaConvenios($estadoTarjeta['nombre'] ?? '');
                $enlaceEstado = BASE_URL . 'index.php?controller=crearConvocatoria&action=index&estado_id=' . $idTarjeta;
                ?>
                <article class="dashboard-panel data-territorial-card linkage-territory-card aliados-territory-card convenio-territorio-card"
                    data-convenio-territorio-card data-nombre="<?= $texto($estadoTarjeta['nombre'] ?? '') ?>"
                    data-tiene="<?= $cantidadEstado ? '1' : '0' ?>">
                    <div class="data-card-header">
                        <div class="data-state-image">
                            <?php if ($imagenLocal !== ''): ?><img src="<?= $texto(BASE_URL . $imagenLocal) ?>" alt="Mapa de <?= $texto($estadoTarjeta['nombre'] ?? '') ?>" loading="lazy"><?php else: ?><i class="bi bi-geo-alt" aria-hidden="true"></i><?php endif; ?>
                        </div>
                        <div class="data-card-title"><h3><?= $texto($estadoTarjeta['nombre'] ?? '') ?></h3>
                            <span class="data-info-badge <?= $cantidadEstado ? 'data-info-badge-complete' : 'data-info-badge-empty' ?>"><?= $cantidadEstado ? 'Con convenios' : 'Sin convenios' ?></span>
                        </div>
                    </div>
                    <dl class="data-card-meta"><div><dt>Aliados</dt><dd><?= $cantidadEstado ?> <?= $cantidadEstado === 1 ? 'convenio' : 'convenios' ?></dd></div></dl>
                    <a class="btn btn-system-light" href="<?= $texto($enlaceEstado) ?>">Ver convenios</a>
                </article>
            <?php endforeach; ?>
        </section>
        <div class="aliados-empty-state d-none" data-convenios-territorio-vacio><strong>No se encontraron territorios</strong><p>Prueba con otro estado o criterio.</p></div>
    <?php else: ?>
        <div class="linkage-state-nav aliados-state-nav">
            <a class="linkage-back-link" href="<?= $texto(BASE_URL) ?>index.php?controller=crearConvocatoria&action=index"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a territorios</a>
            <div class="linkage-state-identity"><strong><?= $texto($estadoSeleccionado['nombre'] ?? '') ?></strong><span>Convenios de aliados</span></div>
        </div>
    <section class="dashboard-panel crear-convocatoria-toolbar-panel">
        <div class="crear-convocatoria-toolbar-heading">
            <div>
                <h2 class="panel-title">Convenios de aliados</h2>
                <p>
                    Consulta las instituciones de este territorio y crea una convocatoria por programa.
                </p>
            </div>
        </div>

        <div class="crear-convocatoria-toolbar">
            <div class="data-filter-field crear-convocatoria-search">
                <label for="crear_convocatoria_buscar">Buscar convenio</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="crear_convocatoria_buscar"
                        placeholder="Buscar por institución, estado o analista..."
                        autocomplete="off"
                        data-convenio-search>
                </div>
            </div>

            <div class="data-filter-field">
                <label for="convenioMunicipio">Municipio</label>
                <select class="form-select" id="convenioMunicipio" data-convenio-municipio-filter>
                    <option value="">Todos</option>
                    <?php
                    $municipiosFiltro = [];
                    foreach ($conveniosVisibles as $convenioMunicipio) {
                        $idMunicipio = (int)($convenioMunicipio['municipio_id'] ?? 0);
                        $nombreMunicipio = (string)($convenioMunicipio['municipio_nombre'] ?? '');
                        if ($idMunicipio > 0 && $nombreMunicipio !== '') $municipiosFiltro[$idMunicipio] = $nombreMunicipio;
                    }
                    asort($municipiosFiltro, SORT_NATURAL | SORT_FLAG_CASE);
                    ?>
                    <?php foreach ($municipiosFiltro as $idMunicipio => $nombreMunicipio): ?>
                        <option value="<?= (int)$idMunicipio ?>"><?= $texto($nombreMunicipio) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="data-filter-field"><label for="convenioOrigen">Origen del aliado</label><select id="convenioOrigen" class="form-select" data-convenio-origin-filter><option value="">Todos</option><option value="manual">Manual</option><option value="institucional">Vinculación</option></select></div>
            <div class="crear-convocatoria-filter-actions">
                <button
                    type="button"
                    class="crear-convocatoria-clear-filters d-none"
                    data-convenio-clear-filters>
                    Limpiar filtros
                </button>
            </div>
        </div>
    </section>

    <?php if (!$estructuraDisponible): ?>
        <section class="dashboard-panel crear-convocatoria-empty">
            <span><i class="bi bi-database-exclamation"></i></span>
            <div>
                <h3>Falta habilitar la recepción de convenios</h3>
                <p>
                    La estructura de convenios formalizados no está disponible.
                    Verifica las migraciones del flujo de Vinculación/Aliados.
                </p>
            </div>
        </section>
    <?php elseif (empty($conveniosVisibles)): ?>
        <section class="dashboard-panel crear-convocatoria-empty">
            <span><i class="bi bi-inbox"></i></span>
            <div>
                <h3>Aún no hay convenios aliados</h3>
                <p>
                    Cuando exista un aliado de este estado, aparecerá en este directorio.
                </p>
            </div>
        </section>
    <?php else: ?>
        <div class="crear-convocatoria-results">
            <span data-convenio-results>
                <?= count($conveniosVisibles) ?>
                <?= count($conveniosVisibles) === 1 ? 'convenio' : 'convenios' ?>
            </span>
        </div>

        <section class="dashboard-panel crear-convocatoria-list-panel convenios-directorio-panel">
            <div class="convenios-group-list" data-convenio-list>
                <?php foreach ($conveniosVisibles as $convenio): ?>
                    <?php
                    $payload = [
                        'registro_programas_items' => $convenio['registro_programas_items'] ?? [],
                        'registro_programas' => $convenio['registro_programas'] ?? '',
                        'registro_estado' => $convenio['registro_estado'] ?? 'Sin enlace',
                        'origen_tipo' => $convenio['origen_tipo'] ?? 'institucional',
                        'origen_id' => (int)($convenio['origen_id'] ?? $convenio['seguimiento_id']),
                        'seguimiento_id' => (int)($convenio['seguimiento_id'] ?? 0),
                        'estado_id' => (int)($convenio['estado_id'] ?? 0),
                        'municipio_id' => (int)($convenio['municipio_id'] ?? 0),
                        'estado' => (string)($convenio['estado_nombre'] ?? ''),
                        'municipio' => (string)($convenio['municipio_nombre'] ?? ''),
                        'institucion' => (string)($convenio['nombre_entidad'] ?? ''),
                        'tipo_entidad' => (string)($convenio['tipo_entidad'] ?? ''),
                        'fecha_recibido' => $fechaHora($convenio['convenio_formalizado_at'] ?? ''),
                        'fecha_documento' => $fecha($convenio['convenio_fecha'] ?? ''),
                        'archivo' => '',
                        'notas' => (string)($convenio['convenio_notas'] ?? ''),
                        'contacto' => (string)($convenio['contacto_nombre'] ?? ''),
                        'cargo_contacto' => (string)($convenio['contacto_cargo'] ?? ''),
                        'correo_contacto' => (string)($convenio['correo_contacto'] ?? ''),
                        'analista' => trim(
                            (string)($convenio['analista_nombre'] ?? '') . ' ' .
                            (string)($convenio['analista_apellidos'] ?? '')
                        ),
                        'estado_atencion' =>
                            (int)($convenio['tiene_convocatoria'] ?? 0) === 1
                                ? 'con_convocatoria'
                                : 'pendiente',
                        'estado_atencion_etiqueta' =>
                            (int)($convenio['tiene_convocatoria'] ?? 0) === 1
                                ? 'Con convocatoria'
                                : 'Pendiente'
                    ];

                    $busqueda = mb_strtolower(
                        implode(
                            ' ',
                            [
                                $payload['institucion'],
                                $payload['estado'],
                                $payload['municipio'],
                                $payload['analista'],
                                $payload['contacto'],
                                $payload['correo_contacto']
                            ]
                        ),
                        'UTF-8'
                    );
                    ?>
                    <?php if ($nombreMunicipioAnterior !== $payload['municipio']): ?>
                        <?php $nombreMunicipioAnterior = $payload['municipio']; ?>
                        <div class="convenios-municipio-header" data-convenio-municipio-header>
                            <strong><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= $texto($payload['municipio'] ?: 'Sin municipio') ?></strong>
                        </div>
                    <?php endif; ?>
                    <article class="convenios-fila convenios-list-row"
                        aria-label="Convenio de <?= $texto($payload['institucion']) ?>"
                        data-convenio-notification
                        data-convenio-state="<?= (int)$payload['estado_id'] ?>"
                        data-convenio-municipio="<?= (int)$payload['municipio_id'] ?>"
                        data-convenio-origin="<?= $texto($payload['origen_tipo']) ?>"
                        data-convenio-attention="<?= $texto($payload['estado_atencion']) ?>"
                        data-convenio-search-text="<?= $texto($busqueda) ?>"
                        data-convenio='<?= $texto(json_encode(
                            $payload,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES |
                            JSON_INVALID_UTF8_SUBSTITUTE
                        )) ?>'>
                        <div class="convenios-list-cell convenios-cell-ally"><span class="convenios-cell-label">Aliado</span><strong class="convenios-nombre"><?= $texto($payload['institucion']) ?></strong>
                            <small><?= $texto($payload['municipio']) ?></small>
                            <span class="crear-convocatoria-attention-badge <?= $payload['estado_atencion'] === 'con_convocatoria' ? 'is-done' : 'is-pending' ?>" data-convenio-card-attention><?= $texto($payload['estado_atencion_etiqueta']) ?></span></div>
                        <div class="convenios-list-cell"><span class="convenios-cell-label">Contacto</span><strong><?= $texto($payload['contacto'] ?: 'Sin contacto') ?></strong>
                            <small><?= $texto($payload['correo_contacto'] ?: 'Sin correo') ?></small></div>
                        <div class="convenios-list-cell"><span class="convenios-cell-label">Programas ofrecidos</span><span class="convenios-programas"><?= $texto($payload['registro_programas'] ?: 'Sin programas de Registro') ?></span></div>
                        <div class="convenios-list-cell"><span class="convenios-cell-label">Origen</span><span class="convenios-etiqueta"><?= $payload['origen_tipo'] === 'manual' ? 'Relación manual' : 'Vinculación' ?></span>
                            <small><?= $payload['origen_tipo'] === 'manual' ? 'Sin formalización documental' : 'Formalizado ' . $texto($payload['fecha_recibido']) ?></small></div>
                        <div class="convenios-list-cell convenios-cell-status"><span class="convenios-cell-label">Registro</span><span class="crear-convocatoria-state-badge"><?= $texto($payload['registro_estado']) ?></span></div>
                        <div class="convenios-list-cell convenios-cell-actions"><span class="convenios-cell-label">Acciones</span>
                            <button type="button" class="btn btn-system-save convenios-action-button" data-convenio-open aria-label="Ver detalle de <?= $texto($payload['institucion']) ?>">Ver detalle</button></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div
                class="crear-convocatoria-empty-filter d-none"
                data-convenio-empty-filter>
                <i class="bi bi-search"></i>
                <strong>No se encontraron convenios</strong>
                <span>Prueba con otro texto o selecciona otro estado.</span>
            </div>
        </section>
    <?php endif; ?>
    <?php endif; // Estado seleccionado ?>
</section>

<div
    class="modal fade"
    id="modalConvenioCrearConvocatoria"
    tabindex="-1"
    aria-labelledby="modalConvenioCrearConvocatoriaTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg convenios-detail-dialog">
        <div class="modal-content crear-convocatoria-modal convenios-detail-modal">
            <div class="modal-header">
                <div>
                    <span class="crear-convocatoria-modal-kicker">
                        CONVENIO · ALIADO
                    </span>
                    <h2
                        class="modal-title"
                        id="modalConvenioCrearConvocatoriaTitulo"
                        data-convenio-modal-title>
                        Información del convenio
                    </h2>

                    <span
                        class="crear-convocatoria-modal-attention is-pending"
                        data-convenio-modal-attention>
                        Pendiente
                    </span>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar">
                </button>
            </div>

            <div class="modal-body">
                <div class="crear-convocatoria-modal-state">
                    <span class="crear-convocatoria-modal-state-icon">
                        <i class="bi bi-geo-alt"></i>
                    </span>
                    <div>
                        <small>Territorio de origen</small>
                        <strong data-convenio-modal-state>—</strong>
                    </div>
                </div>

                <div class="crear-convocatoria-modal-grid">
                    <div>
                        <span>Institución</span>
                        <strong data-convenio-modal-institution>—</strong>
                    </div>

                    <div>
                        <span>Municipio</span>
                        <strong data-convenio-modal-municipality>—</strong>
                    </div>

                    <div>
                        <span>Fecha de formalización</span>
                        <strong data-convenio-modal-received>—</strong>
                    </div>

                    <div>
                        <span data-convenio-fecha-label>Fecha del documento</span>
                        <strong data-convenio-modal-document-date>—</strong>
                    </div>

                    <div>
                        <span>Responsable de vinculación</span>
                        <strong data-convenio-modal-analyst>—</strong>
                    </div>

                    <div>
                        <span>Contacto del aliado</span>
                        <strong data-convenio-modal-contact>—</strong>
                    </div>
                    <div>
                        <span>Origen del aliado</span>
                        <strong data-convenio-origin-label>—</strong>
                        <small class="text-muted" data-convenio-origin-description></small>
                    </div>
                    <div class="convenios-detail-programs">
                        <span>Programas autorizados</span>
                        <div class="d-flex flex-wrap gap-1 mt-1" data-convenio-modal-programas></div>
                    </div>
                </div>

                <div class="convenios-detail-section">
                    <span>Notas del convenio</span>
                    <p class="text-muted" data-convenio-modal-notes>Sin notas registradas.</p>
                </div>
                <div class="crear-convocatoria-modal-help">
                    <i class="bi bi-info-circle"></i>
                    <span>
                        La convocatoria se abrirá directamente en el estado
                        correspondiente al aliado seleccionado.
                    </span>
                </div>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-system-light"
                    data-bs-dismiss="modal">
                    Cerrar
                </button>

                <?php if (!empty($puedeCrearDesdeConvenio)): ?>
                <a
                    href="#"
                    class="btn btn-system-save crear-convocatoria-modal-create"
                    data-convenio-create-link>
                    <i class="bi bi-megaphone"></i>
                    Crear convocatoria
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


