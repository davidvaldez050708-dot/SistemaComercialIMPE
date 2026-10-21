<?php
$convenios = is_array($convenios ?? null) ? $convenios : [];
$estados = is_array($estados ?? null) ? $estados : [];
$estructuraDisponible = (bool)($estructuraDisponible ?? false);

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
        return (new DateTime($fecha))->format('d/m/Y H:i');
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

    <section class="dashboard-panel crear-convocatoria-heading">
        <div class="crear-convocatoria-heading-copy">
            <span class="crear-convocatoria-heading-icon" aria-hidden="true">
                <i class="bi bi-megaphone-fill"></i>
            </span>

            <div>
                <h2 class="panel-title">Crear convocatoria</h2>
                <p>
                    Revisa los convenios aliados y genera una convocatoria
                    para el territorio correspondiente.
                </p>
            </div>
        </div>

        <div class="crear-convocatoria-summary">
            <strong><?= count($convenios) ?></strong>
            <span><?= count($convenios) === 1 ? 'convenio aliado' : 'convenios aliados' ?></span>
        </div>
    </section>

    <section class="dashboard-panel crear-convocatoria-toolbar-panel">
        <div class="crear-convocatoria-toolbar-heading">
            <div>
                <h2 class="panel-title">Convenios de aliados</h2>
                <p>
                    Selecciona una notificación para consultar los datos del aliado
                    antes de crear la convocatoria.
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
                <label for="crear_convocatoria_estado_boton">Estado</label>

                <div
                    class="crear-convocatoria-state-dropdown"
                    data-state-dropdown>
                    <input
                        type="hidden"
                        id="crear_convocatoria_estado"
                        value=""
                        data-convenio-state-filter>

                    <button
                        type="button"
                        class="crear-convocatoria-state-trigger"
                        id="crear_convocatoria_estado_boton"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        data-state-trigger>
                        <span data-state-label>Todos los estados</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>

                    <div
                        class="crear-convocatoria-state-menu"
                        role="listbox"
                        aria-label="Seleccionar estado"
                        data-state-menu>
                        <button
                            type="button"
                            class="crear-convocatoria-state-option is-selected"
                            role="option"
                            aria-selected="true"
                            data-state-value="">
                            Todos los estados
                        </button>

                        <?php foreach ($estados as $estado): ?>
                            <button
                                type="button"
                                class="crear-convocatoria-state-option"
                                role="option"
                                aria-selected="false"
                                data-state-value="<?= (int)($estado['id'] ?? 0) ?>">
                                <?= $texto($estado['nombre'] ?? '') ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
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
    <?php elseif (empty($convenios)): ?>
        <section class="dashboard-panel crear-convocatoria-empty">
            <span><i class="bi bi-inbox"></i></span>
            <div>
                <h3>Aún no hay convenios aliados</h3>
                <p>
                    Cuando Vinculación registre un convenio aliado,
                    aparecerá automáticamente en esta bandeja.
                </p>
            </div>
        </section>
    <?php else: ?>
        <div class="crear-convocatoria-results">
            <span data-convenio-results>
                <?= count($convenios) ?>
                <?= count($convenios) === 1 ? 'notificación' : 'notificaciones' ?>
            </span>
        </div>

        <section class="dashboard-panel crear-convocatoria-list-panel">
            <div class="crear-convocatoria-list" data-convenio-list>
                <?php foreach ($convenios as $convenio): ?>
                    <?php
                    $payload = [
                        'registro_programas' => $convenio['registro_programas'] ?? '',
                        'registro_estado' => $convenio['registro_estado'] ?? 'Sin enlace',
                        'origen_tipo' => $convenio['origen_tipo'] ?? 'institucional',
                        'origen_id' => (int)($convenio['origen_id'] ?? $convenio['seguimiento_id']),
                        'seguimiento_id' => (int)($convenio['seguimiento_id'] ?? 0),
                        'estado_id' => (int)($convenio['estado_id'] ?? 0),
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
                    <button
                        type="button"
                        class="crear-convocatoria-notification"
                        data-convenio-notification
                        data-convenio-state="<?= (int)$payload['estado_id'] ?>"
                        data-convenio-origin="<?= $texto($payload['origen_tipo']) ?>"
                        data-convenio-attention="<?= $texto($payload['estado_atencion']) ?>"
                        data-convenio-search-text="<?= $texto($busqueda) ?>"
                        data-convenio='<?= $texto(json_encode(
                            $payload,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES |
                            JSON_INVALID_UTF8_SUBSTITUTE
                        )) ?>'>
                        <span class="crear-convocatoria-notification-icon">
                            <i class="bi bi-building-check"></i>
                        </span>

                        <span class="crear-convocatoria-notification-content">
                            <span class="crear-convocatoria-notification-title">
                                <?= $texto($payload['institucion'] !== '' ? $payload['institucion'] : 'Convenio recibido') ?>
                            </span>

                            <span class="crear-convocatoria-notification-meta">
                                <span>
                                    <i class="bi bi-geo-alt"></i>
                                    <?= $texto($payload['estado']) ?>
                                </span>

                                <span>
                                    <i class="bi bi-check2-circle"></i>
                                    <?= $payload['origen_tipo'] === 'manual' ? 'Relación manual · Sin formalización documental' : 'Formalizado ' . $texto($payload['fecha_recibido']) ?>
                                </span>
                            </span>

                            <span class="crear-convocatoria-notification-file">
                                <i class="bi bi-person-badge"></i>
                                <?= $payload['origen_tipo'] === 'manual' ? 'Contacto:' : 'Analista:' ?> <?= $texto($payload['origen_tipo'] === 'manual' ? $payload['contacto'] : ($payload['analista'] !== '' ? $payload['analista'] : 'Sin asignar')) ?>
                            </span>
                            <span class="crear-convocatoria-notification-file"><?= $texto($payload['registro_programas'] ?: 'Sin programas de Registro') ?></span>
                        </span>

                        <span class="crear-convocatoria-notification-status">
                            <span class="crear-convocatoria-state-badge"><?= $texto($payload['registro_estado']) ?></span>
                            <span
                                class="crear-convocatoria-attention-badge <?= $payload['estado_atencion'] === 'con_convocatoria' ? 'is-done' : 'is-pending' ?>"
                                data-convenio-card-attention>
                                <?= $texto($payload['estado_atencion_etiqueta']) ?>
                            </span>

                            <span class="crear-convocatoria-state-badge">
                                <?= $texto($payload['estado']) ?>
                            </span>

                            <i class="bi bi-chevron-right"></i>
                        </span>
                    </button>
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
</section>

<div
    class="modal fade"
    id="modalConvenioCrearConvocatoria"
    tabindex="-1"
    aria-labelledby="modalConvenioCrearConvocatoriaTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content crear-convocatoria-modal">
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
                </div>

                <div class="crear-convocatoria-modal-notes"><span>Origen</span><p data-convenio-origin-label></p></div>
                <div class="crear-convocatoria-modal-notes">
                    <span>Notas del convenio / formalización</span>
                    <p data-convenio-modal-notes>Sin notas registradas.</p>
                </div>

                <?php require __DIR__ . '/partials/registro_aliado.php'; ?>
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

                <a
                    href="#"
                    class="btn btn-system-save crear-convocatoria-modal-create"
                    data-convenio-create-link>
                    <i class="bi bi-megaphone"></i>
                    Crear convocatoria
                </a>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>public/vendor/qrcode-generator/qrcode.js" defer></script>
