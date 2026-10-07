<?php
$resumenCorreos = is_array($resumenCorreos ?? null)
    ? $resumenCorreos
    : [
        'total' => 0,
        'enviados' => 0,
        'pendientes' => 0,
        'borradores' => 0
    ];

$correos = is_array($correos ?? null)
    ? $correos
    : [];

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>

<section class="correo-marketing-module">
    <section class="dashboard-panel correo-marketing-selector">
        <div class="correo-marketing-selector-heading">
            <div>
                <h2 class="panel-title">Centro de correos</h2>
                <p>
                    Consulta y organiza los correos relacionados con las actividades de Marketing.
                </p>
            </div>

            <div class="correo-marketing-selector-actions">
                <button
                    type="button"
                    class="btn btn-system-save correo-marketing-compose-button"
                    data-marketing-compose>
                    <i class="bi bi-pencil-square"></i>
                    Redactar correo
                </button>

                <span class="correo-marketing-selector-icon" aria-hidden="true">
                    <i class="bi bi-envelope-paper"></i>
                </span>
            </div>
        </div>

        <div class="correo-marketing-summary-grid">
            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-envelope"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['total'] ?? 0) ?></strong>
                    <span>Total de correos</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-send-check"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['enviados'] ?? 0) ?></strong>
                    <span>Enviados</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-clock-history"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['pendientes'] ?? 0) ?></strong>
                    <span>Pendientes</span>
                </div>
            </article>

            <article class="correo-marketing-summary-card">
                <span class="correo-marketing-summary-icon">
                    <i class="bi bi-file-earmark-text"></i>
                </span>
                <div>
                    <strong><?= (int)($resumenCorreos['borradores'] ?? 0) ?></strong>
                    <span>Borradores</span>
                </div>
            </article>
        </div>
    </section>

    <section class="dashboard-panel correo-marketing-toolbar-panel">
        <div class="correo-marketing-toolbar-heading">
            <div>
                <h2 class="panel-title">Bandeja de correos</h2>
                <p>Busca y filtra los correos registrados en este módulo.</p>
            </div>
        </div>

        <form class="correo-marketing-toolbar" action="#" method="GET">
            <div class="data-filter-field correo-marketing-search">
                <label for="correo_marketing_buscar">Buscar correo</label>
                <div class="module-search">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        class="form-control"
                        id="correo_marketing_buscar"
                        name="buscar"
                        placeholder="Buscar por asunto, destinatario o correo..."
                        autocomplete="off">
                </div>
            </div>

            <div class="data-filter-field">
                <label for="correo_marketing_estado">Estado</label>
                <select
                    class="form-select"
                    id="correo_marketing_estado"
                    name="estado">
                    <option value="">Todos</option>
                    <option value="enviado">Enviados</option>
                    <option value="borrador">Borradores</option>
                </select>
            </div>

            <div class="data-filter-field">
                <label for="correo_marketing_tipo">Tipo</label>
                <select
                    class="form-select"
                    id="correo_marketing_tipo"
                    name="tipo">
                    <option value="">Todos</option>
                    <option value="convocatoria">Convocatorias</option>
                    <option value="general">General</option>
                </select>
            </div>
        </form>
    </section>

    <div class="correo-marketing-results">
        <span data-correo-results-count>
            <?= count($correos) ?>
            <?= count($correos) === 1 ? 'resultado' : 'resultados' ?>
        </span>
    </div>

    <section class="dashboard-panel correo-marketing-list-panel">
        <div class="correo-marketing-tabs" role="tablist" aria-label="Bandejas de correo">
            <button
                type="button"
                class="correo-marketing-tab is-active"
                data-correo-tab="todos"
                aria-selected="true">
                <i class="bi bi-inbox"></i>
                Todos
            </button>

            <button
                type="button"
                class="correo-marketing-tab"
                data-correo-tab="enviados"
                aria-selected="false">
                <i class="bi bi-send-check"></i>
                Enviados
            </button>

            <button
                type="button"
                class="correo-marketing-tab"
                data-correo-tab="borradores"
                aria-selected="false">
                <i class="bi bi-file-earmark-text"></i>
                Borradores
            </button>
        </div>

        <div class="table-responsive">
            <table class="table users-table correo-marketing-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Destinatario</th>
                        <th>Asunto</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($correos)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="correo-marketing-empty">
                                    <span class="correo-marketing-empty-icon">
                                        <i class="bi bi-envelope"></i>
                                    </span>

                                    <div>
                                        <h3>Aún no hay correos registrados</h3>
                                        <p>
                                            Cuando el módulo tenga correos asociados,
                                            aparecerán aquí para consulta y seguimiento.
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($correos as $correo): ?>
                            <?php
                            $estadoCodigoCorreo = strtolower(
                                trim((string)($correo['estado_codigo'] ?? 'pendiente'))
                            );
                            $tipoCodigoCorreo = strtolower(
                                trim((string)($correo['tipo_codigo'] ?? 'general'))
                            );
                            $busquedaCorreo = trim(
                                (string)($correo['destinatario'] ?? '') . ' ' .
                                (string)($correo['correo'] ?? '') . ' ' .
                                (string)($correo['asunto'] ?? '')
                            );
                            ?>
                            <tr
                                data-correo-row
                                data-correo-estado="<?= $texto($estadoCodigoCorreo) ?>"
                                data-correo-tipo="<?= $texto($tipoCodigoCorreo) ?>"
                                data-correo-busqueda="<?= $texto($busquedaCorreo) ?>">
                                <td>
                                    <strong><?= $texto($correo['destinatario'] ?? '—') ?></strong>
                                    <small><?= $texto($correo['correo'] ?? '') ?></small>
                                </td>
                                <td><?= $texto($correo['asunto'] ?? '—') ?></td>
                                <td><?= $texto($correo['tipo'] ?? 'General') ?></td>
                                <td><?= $texto($correo['fecha'] ?? '—') ?></td>
                                <td>
                                    <span class="correo-marketing-status">
                                        <?= $texto($correo['estado'] ?? 'Pendiente') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button
                                        type="button"
                                        class="table-action-button"
                                        aria-label="Ver correo"
                                        data-marketing-mail-view
                                        data-mail-id="<?= (int)($correo['id'] ?? 0) ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="d-none" data-correo-filter-empty>
                            <td colspan="6">
                                <div class="correo-marketing-empty">
                                    <span class="correo-marketing-empty-icon">
                                        <i class="bi bi-envelope"></i>
                                    </span>
                                    <div>
                                        <h3>No hay correos en esta vista</h3>
                                        <p>
                                            Ajusta los filtros o selecciona otra bandeja.
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>


<div
    class="modal fade"
    id="modalCorreoMarketing"
    tabindex="-1"
    aria-labelledby="modalCorreoMarketingTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable correo-marketing-compose-dialog">
        <div class="modal-content system-form-modal correo-marketing-compose-modal">
            <form
                enctype="multipart/form-data"
                data-marketing-mail-form>

                <input
                    type="hidden"
                    name="correo_id"
                    value=""
                    data-marketing-mail-id>

                <div class="modal-header system-form-modal-header">
                    <div>
                        <h5
                            class="system-form-modal-title"
                            id="modalCorreoMarketingTitulo">
                            Redactar correo
                        </h5>
                        <p class="system-form-modal-subtitle">
                            Redacta el mensaje y, si lo necesitas, adjunta documentos.
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
                    <div
                        class="alert alert-danger d-none mb-3"
                        data-marketing-mail-error>
                    </div>

                    <div class="correo-marketing-recipient-card mb-3">
                        <span>DESTINATARIO</span>
                        <strong data-marketing-mail-recipient>
                            Escribe un correo electrónico
                        </strong>
                    </div>

                    <div class="mb-3">
                        <label
                            class="form-label"
                            for="correo_marketing_para">
                            Para
                        </label>

                        <input
                            class="form-control system-form-control"
                            id="correo_marketing_para"
                            type="email"
                            name="destinatario"
                            maxlength="255"
                            placeholder="correo@dominio.com"
                            autocomplete="email"
                            data-marketing-mail-to
                            required>
                    </div>

                    <div class="mb-3">
                        <label
                            class="form-label"
                            for="correo_marketing_asunto">
                            Asunto
                        </label>

                        <input
                            class="form-control system-form-control"
                            id="correo_marketing_asunto"
                            type="text"
                            name="asunto"
                            maxlength="255"
                            placeholder="Escribe el asunto del correo"
                            data-marketing-mail-subject
                            required>
                    </div>

                    <div class="mb-3">
                        <label
                            class="form-label"
                            for="correo_marketing_mensaje">
                            Mensaje
                        </label>

                        <textarea
                            class="form-control system-form-control correo-marketing-message"
                            id="correo_marketing_mensaje"
                            name="cuerpo"
                            rows="5"
                            maxlength="20000"
                            placeholder="Escribe tu mensaje..."
                            data-marketing-mail-body
                            required></textarea>
                    </div>

                    <div class="mb-0">
                        <label
                            class="form-label"
                            for="correo_marketing_adjuntos">
                            Adjuntos
                            <span class="text-muted">(opcional)</span>
                        </label>

                        <input
                            class="form-control system-form-control"
                            id="correo_marketing_adjuntos"
                            type="file"
                            name="adjuntos[]"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.png,.jpg,.jpeg"
                            data-marketing-mail-files>

                        <div class="form-text correo-marketing-file-help">
                            PDF, Office, TXT, CSV, PNG o JPG. Máximo 8 archivos,
                            12 MB por archivo y 20 MB en total.
                        </div>

                        <div
                            class="correo-marketing-files d-none"
                            data-marketing-mail-files-list>
                        </div>
                    </div>

                    <div class="correo-marketing-mail-note">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            El correo se enviará desde tu cuenta institucional.
                            Si tienes una firma configurada en Mi perfil,
                            se incluirá automáticamente.
                        </span>
                    </div>
                </div>

                <div class="modal-footer system-form-modal-footer">
                    <button
                        type="button"
                        class="btn btn-system-cancel"
                        data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="btn btn-system-light"
                        data-marketing-mail-draft>
                        <i class="bi bi-file-earmark-arrow-down me-2"></i>
                        Guardar borrador
                    </button>

                    <button
                        type="submit"
                        class="btn btn-system-save"
                        data-marketing-mail-send>
                        <i class="bi bi-send me-2"></i>
                        Enviar correo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<div
    class="modal fade"
    id="modalCorreoMarketingDetalle"
    tabindex="-1"
    aria-labelledby="modalCorreoMarketingDetalleTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content system-form-modal correo-marketing-detail-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5
                        class="system-form-modal-title"
                        id="modalCorreoMarketingDetalleTitulo">
                        Correo enviado
                    </h5>
                    <p class="system-form-modal-subtitle">
                        Consulta el contenido del correo registrado.
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
                <div
                    class="alert alert-danger d-none mb-3"
                    data-marketing-mail-detail-error>
                </div>

                <div
                    class="correo-marketing-detail-loading"
                    data-marketing-mail-detail-loading>
                    <span
                        class="spinner-border spinner-border-sm"
                        aria-hidden="true">
                    </span>
                    <span>Cargando correo...</span>
                </div>

                <div
                    class="d-none"
                    data-marketing-mail-detail-content>

                    <div class="correo-marketing-detail-meta">
                        <div>
                            <span>PARA</span>
                            <strong data-marketing-mail-detail-to>—</strong>
                            <small data-marketing-mail-detail-name></small>
                        </div>

                        <div>
                            <span data-marketing-mail-detail-date-label>FECHA</span>
                            <strong data-marketing-mail-detail-date>—</strong>
                        </div>

                        <div>
                            <span>ESTADO</span>
                            <strong data-marketing-mail-detail-status>—</strong>
                        </div>
                    </div>

                    <div class="correo-marketing-detail-section">
                        <span class="correo-marketing-detail-label">
                            Asunto
                        </span>
                        <div
                            class="correo-marketing-detail-subject"
                            data-marketing-mail-detail-subject>
                            —
                        </div>
                    </div>

                    <div class="correo-marketing-detail-section">
                        <span class="correo-marketing-detail-label">
                            Mensaje
                        </span>
                        <div
                            class="correo-marketing-detail-message"
                            data-marketing-mail-detail-body>
                            —
                        </div>
                    </div>

                    <div
                        class="correo-marketing-detail-section d-none"
                        data-marketing-mail-detail-attachments-section>
                        <span class="correo-marketing-detail-label">
                            Adjuntos
                        </span>
                        <div
                            class="correo-marketing-detail-attachments"
                            data-marketing-mail-detail-attachments>
                        </div>
                    </div>

                    <div class="correo-marketing-detail-footer">
                        <span>
                            <i class="bi bi-envelope-check"></i>
                            <span data-marketing-mail-detail-provider></span>
                        </span>

                        <span
                            class="d-none"
                            data-marketing-mail-detail-signature>
                            <i class="bi bi-pen"></i>
                            Firma institucional incluida
                        </span>
                    </div>
                </div>
            </div>

            <div class="modal-footer system-form-modal-footer">
                <button
                    type="button"
                    class="btn btn-system-light d-none"
                    data-marketing-mail-detail-edit>
                    <i class="bi bi-pencil-square me-2"></i>
                    Seguir editando
                </button>

                <button
                    type="button"
                    class="btn btn-system-light"
                    data-marketing-mail-detail-copy>
                    <i class="bi bi-copy me-2"></i>
                    Copiar contenido
                </button>

                <button
                    type="button"
                    class="btn btn-system-cancel"
                    data-bs-dismiss="modal">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
