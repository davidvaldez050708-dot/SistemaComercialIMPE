<?php

$aliados = $aliados ?? [];
$aliadosPorMunicipio = $aliadosPorMunicipio ?? [];
$municipiosAliados = $municipiosAliados ?? [];
$analistasAliados = $analistasAliados ?? [];
$resumenAliados = $resumenAliados ?? [
    'total' => 0,
    'municipios' => 0,
    'con_correo' => 0,
    'con_whatsapp' => 0,
    'convocatorias_vigentes' => 0
];
$filtros = $filtros ?? [];
$estructuraAliadosDisponible = $estructuraAliadosDisponible ?? false;
$puedeCompartirCorreo = $puedeCompartirCorreo ?? false;
$puedeVerHistorial = $puedeVerHistorial ?? false;
$puedeConsultarConvocatorias = $puedeConsultarConvocatorias ?? false;
$puedeGestionarContactos = $puedeGestionarContactos ?? false;
$estructuraContactosDisponible = $estructuraContactosDisponible ?? false;
$puedeAbrirExpediente = $puedeAbrirExpediente ?? false;
$puedeUsarWhatsapp = $puedeUsarWhatsapp ?? false;
$puedePrepararWhatsapp = $puedePrepararWhatsapp ?? false;

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

$normalizarNumeroVista = static function ($valor) {
    $digitos = preg_replace('/[^0-9]+/', '', (string)$valor);

    if (strlen($digitos) === 13 && strpos($digitos, '521') === 0) {
        $digitos = '52' . substr($digitos, 3);
    }

    return $digitos;
};

?>

<section
    class="aliados-module aliados-state-module"
    data-aliados-module
    data-estado-id="<?= (int)($estado['id'] ?? 0) ?>"
    data-base-url="<?= $texto(BASE_URL) ?>">

    <?php if (!$estructuraAliadosDisponible): ?>
        <div class="alert alert-warning aliados-structure-alert" role="alert">
            <i class="bi bi-exclamation-triangle"></i>
            <div>
                <strong>Falta preparar la estructura de Aliados.</strong>
                <span>Aplica las migraciones del módulo antes de utilizarlo.</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="linkage-state-nav aliados-state-nav">
        <a
            class="linkage-back-link"
            href="<?= BASE_URL ?>index.php?controller=aliado&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver a territorios
        </a>

        <div class="linkage-state-identity">
            <strong><?= $texto($estado['nombre'] ?? '') ?></strong>
            <span>Aliados institucionales</span>
        </div>
    </div>

    <section class="aliados-summary-grid aliados-state-summary" aria-label="Resumen de aliados">
        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-buildings"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['total'] ?></strong>
                <span>Aliados</span>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-geo-alt"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['municipios'] ?></strong>
                <span>Municipios con aliados</span>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-envelope-check"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['con_correo'] ?></strong>
                <span>Con correo</span>
            </div>
        </article>

        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-whatsapp"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['con_whatsapp'] ?></strong>
                <span>WhatsApp confirmado</span>
            </div>
        </article>

        <?php if ($puedeConsultarConvocatorias): ?>
            <article class="aliados-summary-card">
                <span class="aliados-summary-icon"><i class="bi bi-megaphone"></i></span>
                <div>
                    <strong><?= (int)$resumenAliados['convocatorias_vigentes'] ?></strong>
                    <span>Convocatorias vigentes</span>
                </div>
            </article>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel aliados-directory-panel">
        <div class="aliados-directory-controls">
            <div class="aliados-section-heading">
                <div>
                    <span class="aliados-eyebrow">DIRECTORIO TERRITORIAL</span>
                    <h3>Instituciones aliadas</h3>
                    <p>
                        Consulta y difunde convocatorias entre los aliados de
                        <?= $texto($estado['nombre'] ?? 'este Estado') ?>.
                    </p>
                </div>

                <div class="aliados-directory-meta">
                    <span class="aliados-source-chip">
                        <i class="bi bi-patch-check"></i>
                        Convenio formalizado
                    </span>
                    <span class="aliados-result-count" data-aliados-result-count>
                        <?= count($aliados) ?> resultado<?= count($aliados) === 1 ? '' : 's' ?>
                    </span>
                </div>
            </div>

            <form method="GET" class="aliados-filter-grid aliados-state-filter-grid" data-aliados-filters>
                <input type="hidden" name="controller" value="aliado">
                <input type="hidden" name="action" value="estado">
                <input type="hidden" name="estado_id" value="<?= (int)($estado['id'] ?? 0) ?>">

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
                            placeholder="Institución, contacto, correo o teléfono"
                            autocomplete="off">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="aliados_municipio">Municipio</label>
                    <select class="form-select" id="aliados_municipio" name="municipio_id">
                        <option value="0">Todos</option>
                        <?php foreach ($municipiosAliados as $municipio): ?>
                            <option
                                value="<?= (int)$municipio['id'] ?>"
                                <?= (int)($filtros['municipio_id'] ?? 0) === (int)$municipio['id'] ? 'selected' : '' ?>>
                                <?= $texto($municipio['nombre']) ?>
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

                <div>
                    <label class="form-label" for="aliados_difusion">Difusión</label>
                    <select class="form-select" id="aliados_difusion" name="difusion">
                        <option value="todos" <?= ($filtros['difusion'] ?? 'todos') === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="con" <?= ($filtros['difusion'] ?? '') === 'con' ? 'selected' : '' ?>>Con difusión</option>
                        <option value="sin" <?= ($filtros['difusion'] ?? '') === 'sin' ? 'selected' : '' ?>>Sin difusión</option>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="aliados_formalizacion">Formalización</label>
                    <select class="form-select" id="aliados_formalizacion" name="formalizacion">
                        <option value="todas" <?= ($filtros['formalizacion'] ?? 'todas') === 'todas' ? 'selected' : '' ?>>Cualquier fecha</option>
                        <option value="30" <?= ($filtros['formalizacion'] ?? '') === '30' ? 'selected' : '' ?>>Últimos 30 días</option>
                        <option value="90" <?= ($filtros['formalizacion'] ?? '') === '90' ? 'selected' : '' ?>>Últimos 90 días</option>
                        <option value="mes" <?= ($filtros['formalizacion'] ?? '') === 'mes' ? 'selected' : '' ?>>Este mes</option>
                        <option value="anio" <?= ($filtros['formalizacion'] ?? '') === 'anio' ? 'selected' : '' ?>>Este año</option>
                    </select>
                </div>

                <div class="aliados-filter-actions">
                    <a
                        class="btn aliados-btn-secondary"
                        href="<?= BASE_URL ?>index.php?controller=aliado&action=estado&estado_id=<?= (int)($estado['id'] ?? 0) ?>"
                        data-aliados-clear>
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Limpiar
                    </a>
                </div>
            </form>
        </div>

        <div class="aliados-directory-results">
            <?php if (empty($aliados)): ?>
                <div class="aliados-empty-state">
                    <span><i class="bi bi-buildings"></i></span>
                    <strong>Este territorio todavía no tiene aliados formalizados</strong>
                    <p>
                        Las instituciones aparecerán aquí automáticamente cuando su convenio quede formalizado.
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($aliadosPorMunicipio as $grupoMunicipio): ?>
                    <section
                        class="aliados-municipality-group"
                        data-aliado-municipio-group
                        data-municipio-id="<?= (int)($grupoMunicipio['id'] ?? 0) ?>">
                        <header class="aliados-municipality-header">
                            <div>
                                <i class="bi bi-geo-alt"></i>
                                <strong><?= $texto($grupoMunicipio['nombre'] ?? 'Municipio') ?></strong>
                            </div>
                            <span data-group-count>
                                <?= count($grupoMunicipio['aliados'] ?? []) ?>
                                aliado<?= count($grupoMunicipio['aliados'] ?? []) === 1 ? '' : 's' ?>
                            </span>
                        </header>

                        <div class="table-responsive" data-aliados-table-wrap>
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
                                    <?php foreach ($grupoMunicipio['aliados'] as $aliado): ?>
                                        <?php
                                        $correo = trim((string)($aliado['correo_contacto'] ?? ''));
                                        $whatsapp = trim((string)($aliado['whatsapp_contacto'] ?? ''));
                                        $contactoDifusion = trim(
                                            (string)($aliado['contacto_difusion_preferido'] ?? '')
                                        );
                                        $contactoDifusionEsWhatsapp =
                                            (int)($aliado['contacto_difusion_confirmado_whatsapp'] ?? 0) === 1;
                                        $mostrarContactoDifusion =
                                            $contactoDifusion !== '' &&
                                            $normalizarNumeroVista($contactoDifusion) !==
                                                $normalizarNumeroVista($whatsapp);
                                        $correoDisponible =
                                            $puedeCompartirCorreo &&
                                            $correo !== '' &&
                                            filter_var($correo, FILTER_VALIDATE_EMAIL);
                                        $textoBusquedaAliado = trim(implode(' ', [
                                            $aliado['nombre_entidad'] ?? '',
                                            $aliado['contacto_nombre'] ?? '',
                                            $aliado['contacto_cargo'] ?? '',
                                            $aliado['correo_contacto'] ?? '',
                                            $aliado['municipio_nombre'] ?? '',
                                            $aliado['analista_nombre'] ?? '',
                                            $aliado['telefono_verificado'] ?? '',
                                            $aliado['telefono_fuente'] ?? '',
                                            $aliado['whatsapp_verificado'] ?? '',
                                            $aliado['contacto_difusion_preferido'] ?? '',
                                            $aliado['contactos_difusion_busqueda'] ?? ''
                                        ]));
                                        $aliadoTieneWhatsappConfirmado =
                                            trim((string)($aliado['whatsapp_verificado'] ?? '')) !== '' ||
                                            (int)($aliado['tiene_whatsapp_confirmado_contacto'] ?? 0) === 1;
                                        $aliadoTieneWhatsappAutorizado =
                                            (int)($aliado['tiene_whatsapp_autorizado_contacto'] ?? 0) === 1;
                                        $whatsappManualDisponible =
                                            $puedePrepararWhatsapp &&
                                            $aliadoTieneWhatsappAutorizado;
                                        $puedeCompartirAliado =
                                            $correoDisponible ||
                                            $whatsappManualDisponible;
                                        $mostrarAccionesSecundarias =
                                            $puedeGestionarContactos ||
                                            $puedeVerHistorial ||
                                            $puedeAbrirExpediente ||
                                            (
                                                $puedeUsarWhatsapp &&
                                                $aliadoTieneWhatsappAutorizado
                                            );
                                        ?>
                                        <tr
                                            data-aliado-row
                                            data-search="<?= $texto($textoBusquedaAliado) ?>"
                                            data-estado-id="<?= (int)($aliado['estado_id'] ?? 0) ?>"
                                            data-municipio-id="<?= (int)($aliado['municipio_id'] ?? 0) ?>"
                                            data-analista-id="<?= (int)($aliado['analista_id'] ?? 0) ?>"
                                            data-tiene-difusion="<?= !empty($aliado['ultimo_envio_id']) ? '1' : '0' ?>"
                                            data-formalizado-at="<?= $texto(substr((string)($aliado['convenio_formalizado_at'] ?? ''), 0, 10)) ?>">
                                            <td>
                                                <div class="aliados-institution-cell">
                                                    <span class="aliados-institution-icon">
                                                        <i class="bi bi-building-check"></i>
                                                    </span>
                                                    <div>
                                                        <strong><?= $texto($aliado['nombre_entidad'] ?? 'Institución') ?></strong>
                                                        <span><?= $texto($aliado['tipo_entidad'] ?? 'Institución') ?></span>
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
                                                            <span class="aliados-channel-pill is-whatsapp-confirmed">
                                                                <i class="bi bi-whatsapp"></i>
                                                                <?= $texto($whatsapp) ?>
                                                            </span>
                                                        <?php endif; ?>

                                                        <?php if ($mostrarContactoDifusion): ?>
                                                            <span class="aliados-channel-pill <?= $contactoDifusionEsWhatsapp ? 'is-whatsapp-confirmed' : 'is-pending' ?>">
                                                                <i class="bi <?= $contactoDifusionEsWhatsapp ? 'bi-whatsapp' : 'bi-telephone' ?>"></i>
                                                                <?= $texto($contactoDifusion) ?>
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
                                                    <strong><?= $texto($fecha($aliado['convenio_formalizado_at'] ?? '')) ?></strong>
                                                    <span>Convenio formalizado</span>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if (!empty($aliado['ultimo_envio_id'])): ?>
                                                    <div class="aliados-last-send">
                                                        <strong><?= $texto($aliado['ultima_convocatoria_titulo'] ?? 'Convocatoria') ?></strong>
                                                        <span>
                                                            <?= $texto(
                                                                strtoupper((string)($aliado['ultimo_envio_canal'] ?? 'CORREO')) === 'WHATSAPP_MANUAL'
                                                                    ? 'WhatsApp manual'
                                                                    : (
                                                                        strtoupper((string)($aliado['ultimo_envio_canal'] ?? 'CORREO')) === 'WHATSAPP'
                                                                            ? 'WhatsApp'
                                                                            : 'Correo'
                                                                    )
                                                            ) ?> ·
                                                            <?= $texto($fechaHora($aliado['ultimo_envio_at'] ?? '')) ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="aliados-no-send">Sin difusiones registradas</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <div class="aliados-actions">
                                                    <?php if ($puedeCompartirCorreo || $puedePrepararWhatsapp): ?>
                                                        <button
                                                            type="button"
                                                            class="btn aliados-share-button"
                                                            data-aliado-share="<?= (int)$aliado['seguimiento_id'] ?>"
                                                            <?= $puedeCompartirAliado ? '' : 'disabled' ?>
                                                            title="<?= $puedeCompartirAliado
                                                                ? 'Compartir convocatoria'
                                                                : 'El aliado no tiene un canal autorizado disponible' ?>">
                                                            <i class="bi bi-send"></i>
                                                            Compartir
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($mostrarAccionesSecundarias): ?>
                                                        <div class="dropdown dropstart aliados-actions-menu">
                                                            <button
                                                                type="button"
                                                                class="aliados-more-button"
                                                                data-bs-toggle="dropdown"
                                                                data-bs-auto-close="true"
                                                                aria-expanded="false"
                                                                aria-label="Más acciones"
                                                                title="Más acciones">
                                                                <i class="bi bi-three-dots"></i>
                                                            </button>

                                                            <ul class="dropdown-menu aliados-actions-dropdown">
                                                                <?php if ($puedeGestionarContactos): ?>
                                                                    <li>
                                                                        <button
                                                                            type="button"
                                                                            class="dropdown-item"
                                                                            data-aliado-contacts="<?= (int)$aliado['seguimiento_id'] ?>">
                                                                            <i class="bi bi-person-lines-fill"></i>
                                                                            <span>Gestionar contactos</span>
                                                                        </button>
                                                                    </li>
                                                                <?php endif; ?>

                                                                <?php if ($puedeVerHistorial): ?>
                                                                    <li>
                                                                        <button
                                                                            type="button"
                                                                            class="dropdown-item"
                                                                            data-aliado-history="<?= (int)$aliado['seguimiento_id'] ?>">
                                                                            <i class="bi bi-clock-history"></i>
                                                                            <span>Historial de difusión</span>
                                                                        </button>
                                                                    </li>
                                                                <?php endif; ?>

                                                                <?php if ($puedeUsarWhatsapp && $aliadoTieneWhatsappAutorizado): ?>
                                                                    <li>
                                                                        <a
                                                                            class="dropdown-item"
                                                                            href="<?= BASE_URL ?>index.php?controller=whatsapp&action=abrirAliado&seguimiento_id=<?= (int)$aliado['seguimiento_id'] ?>">
                                                                            <i class="bi bi-whatsapp"></i>
                                                                            <span>Abrir conversación</span>
                                                                        </a>
                                                                    </li>
                                                                <?php endif; ?>

                                                                <?php if ($puedeAbrirExpediente): ?>
                                                                    <?php if ($puedeGestionarContactos || $puedeVerHistorial): ?>
                                                                        <li><hr class="dropdown-divider"></li>
                                                                    <?php endif; ?>
                                                                    <li>
                                                                        <a
                                                                            class="dropdown-item"
                                                                            href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=<?= (int)$aliado['seguimiento_id'] ?>">
                                                                            <i class="bi bi-folder2-open"></i>
                                                                            <span>Abrir expediente</span>
                                                                        </a>
                                                                    </li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>

                <div class="aliados-empty-state d-none" data-aliados-filter-empty>
                    <span><i class="bi bi-search"></i></span>
                    <strong>No hay aliados que coincidan</strong>
                    <p>Prueba con otros criterios o limpia los filtros del territorio.</p>
                </div>
            <?php endif; ?>
        </div>
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
                <div class="aliados-channel-selector" data-aliado-channel-selector>
                    <?php if ($puedeCompartirCorreo): ?>
                        <button
                            type="button"
                            class="aliados-channel-option"
                            data-aliado-share-channel="CORREO">
                            <i class="bi bi-envelope-check"></i>
                            <span>
                                <strong>Correo</strong>
                                <small data-channel-status>Validando destinatario…</small>
                            </span>
                        </button>
                    <?php endif; ?>

                    <?php if ($puedePrepararWhatsapp): ?>
                        <button
                            type="button"
                            class="aliados-channel-option"
                            data-aliado-share-channel="WHATSAPP_MANUAL">
                            <i class="bi bi-whatsapp"></i>
                            <span>
                                <strong>Preparar para WhatsApp</strong>
                                <small data-channel-status>Validando número…</small>
                            </span>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($puedePrepararWhatsapp): ?>
                    <div
                        class="aliados-whatsapp-readiness aliados-whatsapp-manual-note d-none"
                        data-aliado-whatsapp-readiness>
                        <div class="aliados-whatsapp-readiness-icon">
                            <i class="bi bi-whatsapp"></i>
                        </div>
                        <div class="aliados-whatsapp-readiness-copy">
                            <strong data-aliado-whatsapp-title>WhatsApp del aliado</strong>
                            <span data-aliado-whatsapp-detail>
                                El CRM preparará el material. El envío se realizará fuera del sistema.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <form data-aliado-share-form>
                    <input type="hidden" name="seguimiento_id" value="">
                    <input type="hidden" name="confirmar_reenvio" value="0">
                    <input type="hidden" name="canal" value="">

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
                            <small class="aliados-preview-link" data-aliado-convocatoria-link>
                                Sin enlace de registro
                            </small>
                        </div>
                    </div>

                    <div class="mb-3" data-aliado-email-only>
                        <label class="form-label" for="aliado_asunto">Asunto</label>
                        <input
                            type="text"
                            class="form-control"
                            id="aliado_asunto"
                            name="asunto"
                            maxlength="255">
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
                        <small class="aliados-message-help" data-aliado-message-help>
                            Puedes ajustar el mensaje antes de enviarlo.
                        </small>
                    </div>

                    <?php if ($puedePrepararWhatsapp): ?>
                        <div class="aliados-whatsapp-manual-tools d-none" data-aliado-whatsapp-tools>
                            <div class="aliados-whatsapp-manual-head">
                                <div>
                                    <span class="aliados-preview-label">MATERIAL LISTO PARA COMPARTIR</span>
                                    <strong>WhatsApp manual</strong>
                                </div>
                                <span class="aliados-whatsapp-manual-badge">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    Fuera del CRM
                                </span>
                            </div>

                            <div class="aliados-whatsapp-manual-actions">
                                <button type="button" class="btn aliados-manual-action" data-aliado-copy-number>
                                    <i class="bi bi-telephone"></i>
                                    Copiar número
                                </button>
                                <button type="button" class="btn aliados-manual-action" data-aliado-copy-message>
                                    <i class="bi bi-copy"></i>
                                    Copiar mensaje
                                </button>
                                <button type="button" class="btn aliados-manual-action" data-aliado-copy-link>
                                    <i class="bi bi-link-45deg"></i>
                                    Copiar enlace
                                </button>
                                <a
                                    class="btn aliados-manual-action"
                                    href="#"
                                    download
                                    data-aliado-download-image>
                                    <i class="bi bi-download"></i>
                                    Descargar imagen
                                </a>
                                <a
                                    class="btn aliados-manual-action is-primary"
                                    href="#"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-aliado-open-whatsapp-manual>
                                    <i class="bi bi-whatsapp"></i>
                                    Abrir WhatsApp
                                </a>
                            </div>

                            <p class="aliados-whatsapp-manual-help">
                                Después de enviarla en WhatsApp, vuelve aquí y usa
                                <strong>Marcar como compartida</strong> para conservar el historial.
                            </p>
                        </div>
                    <?php endif; ?>

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
                    Enviar convocatoria
                </button>
            </div>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalAliadoContactos"
    tabindex="-1"
    aria-labelledby="modalAliadoContactosTitulo"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content aliados-modal">
            <div class="modal-header">
                <div>
                    <span class="aliados-eyebrow">CONTACTOS DE DIFUSIÓN</span>
                    <h2 class="modal-title" id="modalAliadoContactosTitulo">
                        Canales del aliado
                    </h2>
                    <p data-aliado-contacts-context>
                        Administra números para futuras comunicaciones sin modificar el expediente original.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="aliados-contact-source-note">
                    <i class="bi bi-shield-check"></i>
                    <span>
                        Los teléfonos obtenidos durante la vinculación se muestran como referencia.
                        Al elegir uno para difusión se guarda una relación independiente y el dato original permanece intacto.
                    </span>
                </div>

                <div class="aliados-contact-list" data-aliado-contact-list>
                    <div class="aliados-history-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        Consultando contactos…
                    </div>
                </div>

                <div class="aliados-contact-editor" data-aliado-contact-editor>
                    <div class="aliados-contact-editor-heading">
                        <div>
                            <strong data-contact-editor-title>Agregar número de difusión</strong>
                            <span>Úsalo cuando la institución comparta un número específico para convocatorias.</span>
                        </div>
                        <button
                            type="button"
                            class="btn aliados-btn-secondary d-none"
                            data-contact-editor-cancel>
                            Cancelar edición
                        </button>
                    </div>

                    <form data-aliado-contact-form>
                        <input type="hidden" name="seguimiento_id" value="">
                        <input type="hidden" name="contacto_id" value="0">
                        <input type="hidden" name="origen" value="CUENTA_CLAVE">

                        <div class="aliados-contact-form-grid">
                            <div>
                                <label class="form-label" for="aliado_contacto_numero">Número</label>
                                <input
                                    type="tel"
                                    class="form-control"
                                    id="aliado_contacto_numero"
                                    name="numero"
                                    maxlength="40"
                                    placeholder="Ej. 477 123 4567"
                                    required>
                            </div>
                            <div>
                                <label class="form-label" for="aliado_contacto_etiqueta">Etiqueta</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="aliado_contacto_etiqueta"
                                    name="etiqueta"
                                    maxlength="80"
                                    placeholder="Ej. Difusión, Dirección, Admisiones"
                                    value="Difusión"
                                    required>
                            </div>
                        </div>

                        <div class="aliados-contact-options">
                            <label>
                                <input type="checkbox" name="confirmado_whatsapp" value="1">
                                <span>
                                    <strong>Confirmado para WhatsApp</strong>
                                    <small>Marca sólo si la institución confirmó que este número usa WhatsApp.</small>
                                </span>
                            </label>
                            <label>
                                <input type="checkbox" name="autorizado_whatsapp" value="1">
                                <span>
                                    <strong>Autorizó comunicaciones por WhatsApp</strong>
                                    <small>Marca sólo cuando la institución haya aceptado recibir mensajes en este número.</small>
                                </span>
                            </label>
                            <label>
                                <input type="checkbox" name="preferido_difusion" value="1">
                                <span>
                                    <strong>Preferido para difusión</strong>
                                    <small>Será la primera opción sugerida al abrir la conversación.</small>
                                </span>
                            </label>
                        </div>

                        <div class="aliados-contact-form-actions">
                            <span class="aliados-contact-form-status d-none" data-contact-form-status></span>
                            <button type="submit" class="btn aliados-btn-primary">
                                <i class="bi bi-check2-circle"></i>
                                Guardar contacto
                            </button>
                        </div>
                    </form>
                </div>
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
