<?php

$agendaReuniones = $agendaReuniones ?? [];
$seguimientosElegibles = $seguimientosElegibles ?? [];
$celdasAgenda = $celdasAgenda ?? [];
$agendaRolId = (int)($agendaRolId ?? 0);
$esAnalistaAgenda = $agendaRolId === 4;
$esCuentaClaveAgenda = $agendaRolId === 6;

$pendientesAgenda = array_values(array_filter(
    $agendaReuniones,
    static function ($reunion) use ($esAnalistaAgenda, $esCuentaClaveAgenda) {
        $estado = (string)($reunion['estado'] ?? '');

        if ($esCuentaClaveAgenda) {
            return $estado === 'SOLICITADA';
        }

        if ($esAnalistaAgenda) {
            return in_array($estado, ['CAMBIO_SOLICITADO', 'CONFIRMADA'], true);
        }

        return false;
    }
));

?>

<section
    class="agenda-page"
    data-agenda-root
    data-agenda-role="<?= $agendaRolId ?>"
    data-agenda-month="<?= htmlspecialchars($mesAgenda, ENT_QUOTES, 'UTF-8') ?>"
    data-agenda-initial-follow="<?= (int)$agendaSeguimientoInicial ?>"
    data-agenda-initial-meeting="<?= (int)$agendaReunionInicial ?>">

    <?php if ($agendaRequiereMigracion): ?>
        <div class="alert alert-warning agenda-migration-alert" role="alert">
            <i class="bi bi-database-exclamation"></i>
            <div>
                <strong>Falta activar la agenda en esta base de datos.</strong>
                <span>
                    Ejecuta la migración
                    <code>database/migrations/2026_09_04_agenda_reuniones_vinculacion.sql</code>
                    y vuelve a cargar esta pantalla.
                </span>
            </div>
        </div>
    <?php endif; ?>

    <div class="agenda-toolbar">
        <div>
            <p class="agenda-eyebrow">
                <?= $esCuentaClaveAgenda ? 'CUENTA CLAVE · KAM' : 'ANALISTA DE DATOS' ?>
            </p>
            <h2><?= htmlspecialchars($tituloMesAgenda, ENT_QUOTES, 'UTF-8') ?></h2>
            <p>
                <?= $esCuentaClaveAgenda
                    ? 'Revisa las fechas propuestas, confirma la reunión y agrega el enlace de Zoom.'
                    : 'Consulta tus reuniones, propone fechas y da seguimiento a las confirmaciones de Cuenta Clave.' ?>
            </p>
        </div>

        <div class="agenda-toolbar-actions">
            <a
                class="btn agenda-nav-button"
                href="<?= BASE_URL ?>index.php?controller=agendaReunion&action=index&mes=<?= htmlspecialchars($mesAnterior, ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Mes anterior">
                <i class="bi bi-chevron-left"></i>
            </a>

            <a
                class="btn agenda-today-button"
                href="<?= BASE_URL ?>index.php?controller=agendaReunion&action=index&mes=<?= date('Y-m') ?>">
                Hoy
            </a>

            <a
                class="btn agenda-nav-button"
                href="<?= BASE_URL ?>index.php?controller=agendaReunion&action=index&mes=<?= htmlspecialchars($mesSiguiente, ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Mes siguiente">
                <i class="bi bi-chevron-right"></i>
            </a>

            <?php if ($esAnalistaAgenda && !$agendaRequiereMigracion): ?>
                <button
                    type="button"
                    class="btn btn-system-save agenda-new-button"
                    data-agenda-new-request
                    <?= empty($seguimientosElegibles) ? 'disabled' : '' ?>>
                    <i class="bi bi-calendar-plus"></i>
                    Solicitar reunión
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="agenda-layout">
        <section class="agenda-calendar-card">
            <div class="agenda-week-header" aria-hidden="true">
                <span>Lun</span>
                <span>Mar</span>
                <span>Mié</span>
                <span>Jue</span>
                <span>Vie</span>
                <span>Sáb</span>
                <span>Dom</span>
            </div>

            <div class="agenda-calendar-grid">
                <?php foreach ($celdasAgenda as $celda): ?>
                    <article
                        class="agenda-day <?= !empty($celda['es_mes']) ? '' : 'is-outside' ?> <?= !empty($celda['es_hoy']) ? 'is-today' : '' ?>"
                        data-agenda-date="<?= htmlspecialchars((string)$celda['fecha'], ENT_QUOTES, 'UTF-8') ?>">

                        <div class="agenda-day-number">
                            <span><?= htmlspecialchars((string)$celda['numero'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($celda['es_hoy'])): ?>
                                <small>Hoy</small>
                            <?php endif; ?>
                        </div>

                        <div class="agenda-day-events">
                            <?php foreach (($celda['reuniones'] ?? []) as $reunion): ?>
                                <?php
                                $estadoClase = strtolower(str_replace('_', '-', (string)($reunion['estado'] ?? 'solicitada')));
                                $horaEvento = '';
                                try {
                                    $horaEvento = (new DateTime((string)$reunion['fecha_propuesta']))->format('H:i');
                                } catch (Throwable $error) {
                                    $horaEvento = '';
                                }
                                ?>
                                <button
                                    type="button"
                                    class="agenda-event is-<?= htmlspecialchars($estadoClase, ENT_QUOTES, 'UTF-8') ?>"
                                    data-agenda-meeting="<?= (int)($reunion['id'] ?? 0) ?>"
                                    title="Abrir reunión">
                                    <span class="agenda-event-time">
                                        <?= htmlspecialchars($horaEvento, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <strong>
                                        <?= htmlspecialchars((string)($reunion['nombre_entidad'] ?? 'Reunión'), ENT_QUOTES, 'UTF-8') ?>
                                    </strong>
                                    <small>
                                        <?= htmlspecialchars((string)($reunion['estado_etiqueta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="agenda-side-card">
            <div class="agenda-side-heading">
                <span class="agenda-side-icon">
                    <i class="bi <?= $esCuentaClaveAgenda ? 'bi-inbox' : 'bi-bell' ?>"></i>
                </span>
                <div>
                    <h3><?= $esCuentaClaveAgenda ? 'Por confirmar' : 'Requiere tu atención' ?></h3>
                    <p>
                        <?= $esCuentaClaveAgenda
                            ? 'Solicitudes enviadas por los Analistas.'
                            : 'Cambios solicitados o reuniones listas para confirmar por correo.' ?>
                    </p>
                </div>
            </div>

            <div class="agenda-pending-list">
                <?php if (empty($pendientesAgenda)): ?>
                    <div class="agenda-empty-state">
                        <i class="bi bi-check2-circle"></i>
                        <strong>Todo al día</strong>
                        <span>No tienes reuniones que requieran una acción inmediata.</span>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($pendientesAgenda, 0, 6) as $reunion): ?>
                        <button
                            type="button"
                            class="agenda-pending-item"
                            data-agenda-meeting="<?= (int)($reunion['id'] ?? 0) ?>">
                            <span class="agenda-pending-status">
                                <i class="bi bi-calendar-event"></i>
                            </span>
                            <span class="agenda-pending-copy">
                                <strong>
                                    <?= htmlspecialchars((string)($reunion['nombre_entidad'] ?? 'Reunión'), ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                                <span>
                                    <?= htmlspecialchars((string)($reunion['fecha_legible'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <small>
                                    <?= htmlspecialchars((string)($reunion['estado_etiqueta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </small>
                            </span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($esAnalistaAgenda && empty($seguimientosElegibles) && !$agendaRequiereMigracion): ?>
                <div class="agenda-side-note">
                    <i class="bi bi-info-circle"></i>
                    <span>
                        Cuando un seguimiento llegue al paso 11 aparecerá aquí como disponible para solicitar reunión.
                    </span>
                </div>
            <?php endif; ?>
        </aside>
    </div>

    <div class="modal fade" id="modalAgendaSolicitud" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered agenda-modal-dialog">
            <div class="modal-content system-form-modal agenda-modal">
                <form data-agenda-request-form>
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Solicitar reunión</h5>
                            <p class="modal-subtitle">
                                Propón una fecha. Cuenta Clave recibirá la solicitud para confirmarla.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                        <div class="alert alert-danger d-none agenda-form-error" data-agenda-form-error></div>

                        <div class="agenda-context-card d-none" data-agenda-follow-context></div>

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Seguimiento</label>
                                <select class="form-select system-form-control" name="seguimiento_id" required data-agenda-follow-select>
                                    <option value="">Selecciona una institución</option>
                                    <?php foreach ($seguimientosElegibles as $seguimiento): ?>
                                        <option value="<?= (int)($seguimiento['id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string)($seguimiento['nombre_entidad'] ?? 'Institución'), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fecha y hora propuesta</label>
                                <input class="form-control system-form-control" type="datetime-local" name="fecha_propuesta" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Duración</label>
                                <select class="form-select system-form-control" name="duracion_minutos" required>
                                    <option value="30">30 min</option>
                                    <option value="45">45 min</option>
                                    <option value="60" selected>60 min</option>
                                    <option value="90">90 min</option>
                                    <option value="120">120 min</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Modalidad</label>
                                <select class="form-select system-form-control" name="modalidad" required>
                                    <option value="VIRTUAL" selected>Virtual</option>
                                    <option value="PRESENCIAL">Presencial</option>
                                    <option value="HIBRIDA">Híbrida</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Objetivo de la reunión</label>
                                <input
                                    class="form-control system-form-control"
                                    type="text"
                                    name="objetivo"
                                    maxlength="500"
                                    placeholder="Ej. Presentar la propuesta de vinculación y revisar próximos acuerdos"
                                    required>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notas para Cuenta Clave</label>
                                <textarea
                                    class="form-control system-form-control"
                                    name="notas_analista"
                                    rows="3"
                                    maxlength="4000"
                                    placeholder="Disponibilidad informada por la institución, asistentes u observaciones..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-system-save" data-agenda-request-save>
                            <i class="bi bi-send"></i>
                            Enviar a Cuenta Clave
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalAgendaDetalle" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered agenda-modal-dialog">
            <div class="modal-content system-form-modal agenda-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" data-agenda-detail-title>Detalle de reunión</h5>
                        <p class="modal-subtitle" data-agenda-detail-subtitle></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body" data-agenda-detail-body></div>
            </div>
        </div>
    </div>

    <script type="application/json" id="agendaReunionesData">
        <?= json_encode($agendaReuniones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>
    </script>

    <script type="application/json" id="agendaSeguimientosData">
        <?= json_encode($seguimientosElegibles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>
    </script>
</section>
