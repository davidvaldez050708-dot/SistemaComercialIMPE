<?php

$aliados = $aliados ?? [];
$aliadosBase = $aliadosBase ?? [];
$estadosAliados = $estadosAliados ?? [];
$municipiosAliados = $municipiosAliados ?? [];
$analistasAliados = $analistasAliados ?? [];
$resumenAliados = $resumenAliados ?? [
    'total' => 0,
    'con_correo' => 0,
    'con_whatsapp' => 0,
    'convocatorias_vigentes' => 0
];
$filtros = $filtros ?? [];
$estructuraAliadosDisponible = $estructuraAliadosDisponible ?? false;
$puedeCompartirCorreo = $puedeCompartirCorreo ?? false;
$puedeVerHistorial = $puedeVerHistorial ?? false;

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fecha = static function ($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '—';
    }

    try {
        return (new DateTime($valor))->format('d/m/Y');
    } catch (Throwable $error) {
        return '—';
    }
};

$fechaHora = static function ($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return 'Sin envíos';
    }

    try {
        return (new DateTime($valor))->format('d/m/Y · H:i');
    } catch (Throwable $error) {
        return 'Sin envíos';
    }
};

?>

<section
    class="aliados-module"
    data-aliados-module
    data-base-url="<?= $texto(BASE_URL) ?>">

    <?php if (!$estructuraAliadosDisponible): ?>
        <div class="alert alert-warning aliados-structure-alert" role="alert">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
                <strong>Falta preparar la estructura de Aliados.</strong>
                <span>Aplica la migración 2026_10_02_aliados_cuenta_clave.sql antes de utilizar este módulo.</span>
            </div>
        </div>
    <?php endif; ?>

    <section class="dashboard-panel aliados-hero">
        <div>
            <span class="aliados-eyebrow">RELACIÓN INSTITUCIONAL</span>
            <h2>Aliados con convenio formalizado</h2>
            <p>
                Consulta las instituciones que ya concluyeron su vinculación y comparte
                convocatorias vigentes dentro de su territorio.
            </p>
        </div>

        <div class="aliados-hero-badge">
            <i class="bi bi-patch-check"></i>
            <div>
                <strong>Fuente única</strong>
                <span>Convenio formalizado</span>
            </div>
        </div>
    </section>

    <section class="aliados-summary-grid">
        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-buildings"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['total'] ?></strong>
                <span>Aliados activos</span>
                <small>Instituciones formalizadas</small>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-envelope-check"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['con_correo'] ?></strong>
                <span>Con correo disponible</span>
                <small>Listos para difusión</small>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-whatsapp"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['con_whatsapp'] ?></strong>
                <span>Con WhatsApp registrado</span>
                <small>Canal pendiente de API</small>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-megaphone"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['convocatorias_vigentes'] ?></strong>
                <span>Convocatorias vigentes</span>
                <small>Aplicables a tus territorios</small>
            </div>
        </article>
    </section>

    <section class="dashboard-panel aliados-filter-panel">
        <div class="aliados-section-heading">
            <div>
                <span class="aliados-eyebrow">DIRECTORIO DE ALIADOS</span>
                <h3>Instituciones formalizadas</h3>
                <p>Filtra por territorio, Analista de origen o nombre de institución.</p>
            </div>

            <span class="aliados-result-count">
                <?= count($aliados) ?> resultado<?= count($aliados) === 1 ? '' : 's' ?>
            </span>
        </div>

        <form method="GET" class="aliados-filter-grid">
            <input type="hidden" name="controller" value="aliado">
            <input type="hidden" name="action" value="index">

            <div class="aliados-filter-search">
                <label class="form-label" for="aliados_buscar">Buscar aliado</label>
                <div class="aliados-input-icon">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="aliados_buscar"
                        name="buscar"
                        value="<?= $texto($filtros['buscar'] ?? '') ?>"
                        placeholder="Institución, contacto o correo">
                </div>
            </div>

            <div>
                <label class="form-label" for="aliados_estado">Estado</label>
                <select class="form-select" id="aliados_estado" name="estado_id">
                    <option value="0">Todos</option>
                    <?php foreach ($estadosAliados as $estado): ?>
                        <option
                            value="<?= (int)$estado['id'] ?>"
                            <?= (int)($filtros['estado_id'] ?? 0) === (int)$estado['id'] ? 'selected' : '' ?>>
                            <?= $texto($estado['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="form-label" for="aliados_municipio">Municipio</label>
                <select class="form-select" id="aliados_municipio" name="municipio_id">
                    <option value="0">Todos</option>
                    <?php foreach ($municipiosAliados as $municipio): ?>
                        <option
                            value="<?= (int)$municipio['id'] ?>"
                            data-estado-id="<?= (int)$municipio['estado_id'] ?>"
                            <?= (int)($filtros['municipio_id'] ?? 0) === (int)$municipio['id'] ? 'selected' : '' ?>>
                            <?= $texto($municipio['nombre']) ?>
                            <?= $municipio['estado_nombre'] !== '' ? ' · ' . $texto($municipio['estado_nombre']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="form-label" for="aliados_analista">Analista de origen</label>
                <select class="form-select" id="aliados_analista" name="analista_id">
                    <option value="0">Todos</option>
                    <?php foreach ($analistasAliados as $analista): ?>
                        <option
                            value="<?= (int)$analista['id'] ?>"
                            <?= (int)($filtros['analista_id'] ?? 0) === (int)$analista['id'] ? 'selected' : '' ?>>
                            <?= $texto($analista['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="aliados-filter-actions">
                <a
                    class="btn aliados-btn-secondary"
                    href="<?= BASE_URL ?>index.php?controller=aliado&action=index">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    Limpiar
                </a>
                <button class="btn aliados-btn-primary" type="submit">
                    <i class="bi bi-funnel"></i>
                    Aplicar filtros
                </button>
            </div>
        </form>
    </section>

    <section class="dashboard-panel aliados-list-panel">
        <?php if (empty($aliados)): ?>
            <div class="aliados-empty-state">
                <span><i class="bi bi-buildings"></i></span>
                <strong>No hay aliados para mostrar</strong>
                <p>
                    <?php if (!empty(array_filter($filtros))): ?>
                        Ajusta los filtros para ampliar la búsqueda.
                    <?php else: ?>
                        Las instituciones aparecerán aquí automáticamente cuando su convenio quede formalizado.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table aliados-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Aliado</th>
                            <th>Contacto</th>
                            <th>Analista de origen</th>
                            <th>Formalización</th>
                            <th>Última difusión</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aliados as $aliado): ?>
                            <?php
                            $ubicacion = trim((string)($aliado['municipio_nombre'] ?? ''));
                            if ($ubicacion !== '') {
                                $ubicacion .= ', ';
                            }
                            $ubicacion .= (string)($aliado['estado_nombre'] ?? '');

                            $correo = trim((string)($aliado['correo_contacto'] ?? ''));
                            $whatsapp = trim((string)($aliado['whatsapp_contacto'] ?? ''));
                            $puedeEnviarAliado =
                                $puedeCompartirCorreo &&
                                $correo !== '' &&
                                filter_var($correo, FILTER_VALIDATE_EMAIL);
                            ?>
                            <tr>
                                <td>
                                    <div class="aliados-institution-cell">
                                        <span class="aliados-institution-icon">
                                            <i class="bi bi-building-check"></i>
                                        </span>
                                        <div>
                                            <strong><?= $texto($aliado['nombre_entidad'] ?? 'Institución') ?></strong>
                                            <span><?= $texto($ubicacion !== '' ? $ubicacion : 'Ubicación no disponible') ?></span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="aliados-contact-cell">
                                        <strong><?= $texto($aliado['contacto_nombre'] ?: 'Contacto no registrado') ?></strong>
                                        <?php if (!empty($aliado['contacto_cargo'])): ?>
                                            <span><?= $texto($aliado['contacto_cargo']) ?></span>
                                        <?php endif; ?>

                                        <div class="aliados-channel-row">
                                            <span class="aliados-channel-pill <?= $correo !== '' ? 'is-ready' : 'is-missing' ?>">
                                                <i class="bi bi-envelope"></i>
                                                <?= $correo !== '' ? $texto($correo) : 'Sin correo' ?>
                                            </span>

                                            <?php if ($whatsapp !== ''): ?>
                                                <span class="aliados-channel-pill is-pending">
                                                    <i class="bi bi-whatsapp"></i>
                                                    <?= $texto($whatsapp) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="aliados-analyst-cell">
                                        <strong><?= $texto($aliado['analista_nombre'] ?? '—') ?></strong>
                                        <span>Originó la vinculación</span>
                                    </div>
                                </td>

                                <td>
                                    <div class="aliados-date-cell">
                                        <strong><?= $texto($fecha($aliado['convenio_fecha'] ?? '')) ?></strong>
                                        <span>Convenio formalizado</span>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($aliado['ultimo_envio_id'])): ?>
                                        <div class="aliados-last-send">
                                            <strong><?= $texto($aliado['ultima_convocatoria_titulo'] ?? 'Convocatoria') ?></strong>
                                            <span>
                                                <?= $texto($aliado['ultimo_envio_canal'] ?? 'CORREO') ?> ·
                                                <?= $texto($fechaHora($aliado['ultimo_envio_at'] ?? '')) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="aliados-no-send">Sin difusiones registradas</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <div class="aliados-actions">
                                        <?php if ($puedeVerHistorial): ?>
                                            <button
                                                type="button"
                                                class="aliados-icon-button"
                                                data-aliado-history="<?= (int)$aliado['seguimiento_id'] ?>"
                                                aria-label="Ver historial"
                                                title="Ver historial">
                                                <i class="bi bi-clock-history"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (tienePermiso('seguimientos_vinculacion.ver')): ?>
                                            <a
                                                class="aliados-icon-button"
                                                href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=<?= (int)$aliado['seguimiento_id'] ?>"
                                                aria-label="Abrir expediente"
                                                title="Abrir expediente">
                                                <i class="bi bi-folder2-open"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($puedeCompartirCorreo): ?>
                                            <button
                                                type="button"
                                                class="btn aliados-share-button"
                                                data-aliado-share="<?= (int)$aliado['seguimiento_id'] ?>"
                                                <?= $puedeEnviarAliado ? '' : 'disabled' ?>
                                                title="<?= $puedeEnviarAliado ? 'Compartir convocatoria' : 'El aliado no tiene un correo válido' ?>">
                                                <i class="bi bi-send"></i>
                                                Compartir
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<div
    class="modal fade"
    id="modalAliadoCompartir"
    tabindex="-1"
    aria-labelledby="modalAliadoCompartirTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content aliados-modal">
            <div class="modal-header">
                <div>
                    <span class="aliados-eyebrow">COMPARTIR CONVOCATORIA</span>
                    <h2 class="modal-title" id="modalAliadoCompartirTitulo">
                        Enviar a un aliado
                    </h2>
                    <p data-aliado-share-context>
                        Selecciona una convocatoria vigente para la institución.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar">
                </button>
            </div>

            <div class="modal-body">
                <div class="aliados-channel-selector">
                    <button type="button" class="aliados-channel-option is-active" disabled>
                        <i class="bi bi-envelope-check"></i>
                        <span>
                            <strong>Correo</strong>
                            <small>Disponible</small>
                        </span>
                    </button>

                    <button type="button" class="aliados-channel-option is-disabled" disabled>
                        <i class="bi bi-whatsapp"></i>
                        <span>
                            <strong>WhatsApp Business</strong>
                            <small>Pendiente de API</small>
                        </span>
                    </button>
                </div>

                <div class="aliados-registration-note">
                    <i class="bi bi-info-circle"></i>
                    <span>
                        El formulario y enlace de registro se incorporarán en una siguiente etapa desde el propio sistema.
                    </span>
                </div>

                <form data-aliado-share-form>
                    <input type="hidden" name="seguimiento_id" value="">
                    <input type="hidden" name="confirmar_reenvio" value="0">

                    <div class="mb-3">
                        <label class="form-label" for="aliado_convocatoria_id">
                            Convocatoria vigente
                        </label>
                        <select
                            class="form-select"
                            id="aliado_convocatoria_id"
                            name="convocatoria_id"
                            required>
                            <option value="">Selecciona una convocatoria</option>
                        </select>
                    </div>

                    <div class="aliados-convocatoria-preview d-none" data-aliado-convocatoria-preview>
                        <img src="" alt="" data-aliado-convocatoria-image>
                        <div>
                            <span class="aliados-preview-label">CONVOCATORIA SELECCIONADA</span>
                            <strong data-aliado-convocatoria-title>—</strong>
                            <small data-aliado-convocatoria-period>—</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="aliado_asunto">Asunto</label>
                        <input
                            type="text"
                            class="form-control"
                            id="aliado_asunto"
                            name="asunto"
                            maxlength="255"
                            required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="aliado_mensaje">Mensaje</label>
                        <textarea
                            class="form-control aliados-message"
                            id="aliado_mensaje"
                            name="mensaje"
                            rows="8"
                            maxlength="20000"
                            required></textarea>
                    </div>

                    <div class="alert alert-warning aliados-reenvio-alert d-none" data-aliado-reenvio-alert>
                        <i class="bi bi-exclamation-circle"></i>
                        <div>
                            <strong>Posible reenvío</strong>
                            <span data-aliado-reenvio-message></span>
                        </div>
                    </div>

                    <div class="aliados-send-status d-none" data-aliado-send-status></div>
                </form>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn aliados-btn-secondary"
                    data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button
                    type="button"
                    class="btn aliados-btn-primary"
                    data-aliado-send-button>
                    <i class="bi bi-send"></i>
                    Enviar por correo
                </button>
            </div>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalAliadoHistorial"
    tabindex="-1"
    aria-labelledby="modalAliadoHistorialTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content aliados-modal">
            <div class="modal-header">
                <div>
                    <span class="aliados-eyebrow">HISTORIAL DE DIFUSIÓN</span>
                    <h2 class="modal-title" id="modalAliadoHistorialTitulo">
                        Convocatorias compartidas
                    </h2>
                    <p data-aliado-history-context>Historial del aliado seleccionado.</p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar">
                </button>
            </div>

            <div class="modal-body">
                <div class="aliados-history-list" data-aliado-history-list>
                    <div class="aliados-history-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        Consultando historial…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
