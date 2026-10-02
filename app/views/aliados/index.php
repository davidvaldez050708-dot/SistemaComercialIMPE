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
$puedeConsultarConvocatorias = $puedeConsultarConvocatorias ?? false;
$puedeGestionarContactos = $puedeGestionarContactos ?? false;
$estructuraContactosDisponible = $estructuraContactosDisponible ?? false;
$puedeAbrirExpediente = tienePermiso('seguimientos_vinculacion.ver');

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

    <section class="aliados-summary-grid">
        <article class="aliados-summary-card">
            <span class="aliados-summary-icon"><i class="bi bi-buildings"></i></span>
            <div>
                <strong><?= (int)$resumenAliados['total'] ?></strong>
                <span>Aliados activos</span>
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
                    <span class="aliados-eyebrow">DIRECTORIO</span>
                    <h3>Instituciones aliadas</h3>
                    <p>Busca por institución o filtra por territorio y Analista de origen.</p>
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

            <form method="GET" class="aliados-filter-grid" data-aliados-filters>
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
                    href="<?= BASE_URL ?>index.php?controller=aliado&action=index"
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
                        <?php foreach ($aliados as $aliado): ?>
                            <?php
                            $ubicacion = trim((string)($aliado['municipio_nombre'] ?? ''));
                            if ($ubicacion !== '') {
                                $ubicacion .= ', ';
                            }
                            $ubicacion .= (string)($aliado['estado_nombre'] ?? '');

                            $correo = trim((string)($aliado['correo_contacto'] ?? ''));
                            $whatsapp = trim((string)($aliado['whatsapp_contacto'] ?? ''));
                            $contactoDifusion = trim(
                                (string)($aliado['contacto_difusion_preferido'] ?? '')
                            );
                            $contactoDifusionEsWhatsapp =
                                (int)($aliado['contacto_difusion_confirmado_whatsapp'] ?? 0) === 1;
                            $normalizarNumeroVista = static function ($valor) {
                                return preg_replace('/[^0-9]+/', '', (string)$valor);
                            };
                            $mostrarContactoDifusion =
                                $contactoDifusion !== '' &&
                                $normalizarNumeroVista($contactoDifusion) !==
                                    $normalizarNumeroVista($whatsapp);
                            $puedeEnviarAliado =
                                $puedeCompartirCorreo &&
                                $correo !== '' &&
                                filter_var($correo, FILTER_VALIDATE_EMAIL);
                            ?>
                            <?php
                            $textoBusquedaAliado = trim(implode(' ', [
                                $aliado['nombre_entidad'] ?? '',
                                $aliado['contacto_nombre'] ?? '',
                                $aliado['contacto_cargo'] ?? '',
                                $aliado['correo_contacto'] ?? '',
                                $aliado['estado_nombre'] ?? '',
                                $aliado['municipio_nombre'] ?? '',
                                $aliado['analista_nombre'] ?? '',
                                $aliado['telefono_verificado'] ?? '',
                                $aliado['telefono_fuente'] ?? '',
                                $aliado['whatsapp_verificado'] ?? '',
                                $aliado['contacto_difusion_preferido'] ?? '',
                                $aliado['contactos_difusion_busqueda'] ?? ''
                            ]));
                            ?>
                            <tr
                                data-aliado-row
                                data-search="<?= $texto($textoBusquedaAliado) ?>"
                                data-estado-id="<?= (int)($aliado['estado_id'] ?? 0) ?>"
                                data-municipio-id="<?= (int)($aliado['municipio_id'] ?? 0) ?>"
                                data-analista-id="<?= (int)($aliado['analista_id'] ?? 0) ?>">
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
                                    <?php
                                    $mostrarAccionesSecundarias =
                                        $puedeGestionarContactos ||
                                        $puedeVerHistorial ||
                                        $puedeAbrirExpediente;
                                    ?>
                                    <div class="aliados-actions">
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
            <div class="aliados-empty-state d-none" data-aliados-filter-empty>
                <span><i class="bi bi-search"></i></span>
                <strong>No hay aliados que coincidan</strong>
                <p>Prueba con otros criterios o limpia los filtros del directorio.</p>
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
                                <input type="checkbox" name="preferido_difusion" value="1">
                                <span>
                                    <strong>Preferido para difusión</strong>
                                    <small>Será la primera opción cuando se habilite WhatsApp Business.</small>
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
