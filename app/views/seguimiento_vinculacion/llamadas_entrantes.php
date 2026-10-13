<?php
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$llamadas = is_array($bandejaEntrantes['llamadas'] ?? null)
    ? $bandejaEntrantes['llamadas'] : [];
$seleccionarId = trim((string)($_GET['pbx_call_id'] ?? ''));
$estadosEntrantes = $estadosEntrantes ?? [];
$duracionEntrante = static function ($segundos) {
    return sprintf('%02d:%02d', intdiv(max(0, (int)$segundos), 60),
        max(0, (int)$segundos) % 60);
};
?>
<section class="dashboard-panel linkage-incoming-page"
    data-linkage-incoming
    data-auto-call="<?= $esc($seleccionarId) ?>"
    data-search-url="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=buscarEntrantesSeguimientos') ?>"
    data-save-url="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=registrarLlamadaEntrante') ?>">
    <div class="linkage-incoming-heading">
        <div>
            <span class="linkage-incoming-eyebrow">VINCULACIÓN · TELEFONÍA</span>
            <h2>Llamadas recibidas</h2>
            <p>Clasifica las llamadas de tu extensión y registra el resultado en el expediente correspondiente.</p>
        </div>
        <div class="linkage-incoming-heading-actions">
            <span class="linkage-incoming-extension">
                <i class="bi bi-headset" aria-hidden="true"></i>
                Extensión <?= $esc($bandejaEntrantes['extension'] ?? '—') ?>
            </span>
            <a class="btn btn-system-light"
                href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index') ?>">
                <i class="bi bi-arrow-left"></i> Volver a Seguimiento
            </a>
        </div>
    </div>
    <?php if ($errorEntrantes !== ''): ?>
        <div class="alert alert-warning mt-3" role="alert"><?= $esc($errorEntrantes) ?></div>
    <?php endif; ?>
    <div class="linkage-incoming-table-wrap">
        <table class="table align-middle linkage-incoming-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Número entrante</th>
                    <th>Duración</th>
                    <th>Estado</th>
                    <th>Expediente</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($llamadas as $llamada):
                    $pbxId = (string)($llamada['pbx_call_id'] ?? '');
                    $situacion = (string)($llamada['clasificacion'] ?? 'PENDIENTE');
                    $pbx = (string)($llamada['estado_pbx'] ?? '');
                    $finalizada = in_array($pbx, ['ended', 'transferred'], true);
                    $vinculada = $situacion === 'VINCULADA';
                    $sinIdentificar = $situacion === 'SIN_IDENTIFICAR';
                    $seguimientoId = (int)($llamada['seguimiento_id'] ?? 0);
                    $numero = trim((string)($llamada['numero'] ?? ''));
                    $numero = $numero !== '' ? $numero : 'Número privado';
                ?>
                <tr data-incoming-row="<?= $esc($pbxId) ?>">
                    <td class="text-nowrap"><?= $esc($llamada['fecha'] ?? '') ?></td>
                    <td><strong><?= $esc($numero) ?></strong>
                        <small class="d-block text-muted">Entrante · Extensión <?= $esc($bandejaEntrantes['extension'] ?? '') ?></small>
                    </td>
                    <td class="text-nowrap"><?= $esc($duracionEntrante($llamada['duracion'] ?? 0)) ?></td>
                    <td>
                        <?php if ($vinculada): ?>
                            <span class="linkage-incoming-tag is-success">Registrada</span>
                        <?php elseif ($sinIdentificar): ?>
                            <span class="linkage-incoming-tag">Sin identificar</span>
                        <?php elseif (!$finalizada): ?>
                            <span class="linkage-incoming-tag">En proceso</span>
                        <?php elseif (empty($llamada['contestada'])): ?>
                            <span class="linkage-incoming-tag">Sin respuesta</span>
                        <?php else: ?>
                            <span class="linkage-incoming-tag is-pending">Pendiente de resultado</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($seguimientoId > 0): ?>
                            <a href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=detalle&id=' . $seguimientoId) ?>"
                                class="linkage-incoming-expediente">
                                <i class="bi bi-folder2-open"></i>
                                Ver expediente
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (!$vinculada && $finalizada && (!$sinIdentificar || !empty($llamada['contestada']))): ?>
                            <button type="button" class="btn btn-system-save linkage-incoming-open"
                                data-call-id="<?= $esc($pbxId) ?>"
                                data-phone="<?= $esc($llamada['numero'] ?? '') ?>"
                                data-answer="<?= !empty($llamada['contestada']) ? '1' : '0' ?>"
                                data-duration="<?= $esc($duracionEntrante($llamada['duracion'] ?? 0)) ?>">
                                <i class="bi bi-journal-check"></i>
                                <?= $sinIdentificar ? 'Vincular expediente' : 'Registrar resultado' ?>
                            </button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$llamadas): ?>
                <tr><td colspan="6" class="linkage-incoming-empty">
                    <i class="bi bi-telephone"></i>
                    No se registran llamadas entrantes para tu extensión en los últimos 30 días.
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="linkage-incoming-footnote">
        El historial usa eventos confirmados por Zadarma; solo las llamadas clasificadas se vinculan a Seguimiento.
        No se consideran verificaciones efectivas sin evidencias del proceso institucional.
    </p>
</section>

<div class="modal fade" id="modalLlamadaEntranteAnalista" tabindex="-1"
     aria-labelledby="tituloEntranteAnalista" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog linkage-incoming-modal-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="tituloEntranteAnalista">Registrar resultado de llamada</h5>
                    <p class="system-form-modal-subtitle" data-incoming-modal-description>
                        Identifica la institución para guardar la interacción.
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form data-incoming-form>
                <div class="modal-body system-form-modal-body">
                    <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pbx_call_id" data-incoming-pbx>
                    <div class="linkage-incoming-summary">
                        <span><i class="bi bi-telephone-inbound"></i> <strong data-incoming-number>—</strong></span>
                        <span><i class="bi bi-clock"></i> <strong data-incoming-duration>00:00</strong></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">¿A qué institución corresponde?</label>
                        <select name="decision" class="form-select" data-incoming-decision required>
                            <option value="existente">Institución con seguimiento existente</option>
                            <option value="nueva">Institución nueva</option>
                            <option value="sin_identificar">No se pudo identificar</option>
                        </select>
                    </div>
                    <div data-incoming-existing>
                        <label class="form-label" for="incomingLookup">Buscar expediente por institución o teléfono</label>
                        <input id="incomingLookup" class="form-control" type="search" data-incoming-lookup
                            placeholder="Escribe al menos dos caracteres" autocomplete="off">
                        <select class="form-select mt-2" name="seguimiento_id" data-incoming-followup>
                            <option value="">Selecciona un expediente</option>
                        </select>
                        <small class="text-muted">Solo aparecen seguimientos que te pertenecen.</small>
                    </div>
                    <div data-incoming-new hidden>
                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label class="form-label" for="incomingName">Nombre de la institución</label>
                                <input id="incomingName" name="nombre_entidad" type="text" maxlength="180" class="form-control">
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label" for="incomingType">Tipo de entidad</label>
                                <select id="incomingType" name="tipo_entidad" class="form-select">
                                    <option value="INSTITUCION">Institución</option>
                                    <option value="SECRETARIA">Secretaría</option>
                                    <option value="MUNICIPIO">Municipio</option>
                                    <option value="EMPRESA">Empresa</option>
                                    <option value="ORGANIZACION">Organización</option>
                                    <option value="OTRO">Otro</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="incomingState">Territorio asignado</label>
                                <select id="incomingState" name="estado_id" class="form-select">
                                    <option value="">Selecciona un estado</option>
                                    <?php foreach ($estadosEntrantes as $estado): ?>
                                        <option value="<?= (int)$estado['id'] ?>"><?= $esc($estado['nombre'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div data-incoming-results>
                        <hr>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingResult">Resultado</label>
                                <select id="incomingResult" class="form-select" name="resultado">
                                    <option value="">Seleccionar resultado</option>
                                    <option value="CONTACTO_CORRECTO">Contacto correcto</option>
                                    <option value="SOLICITO_INFORMACION">Solicitó información</option>
                                    <option value="SOLICITO_LLAMAR_DESPUES">Solicitó volver a llamar</option>
                                    <option value="NO_INTERESADO">No interesado</option>
                                    <option value="OTRO">Otro</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingPerson">Persona que llamó</label>
                                <input id="incomingPerson" name="persona_atendio" maxlength="140" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingNext">Próxima acción</label>
                                <select id="incomingNext" name="proxima_accion" class="form-select">
                                    <option value="">Sin próxima acción</option>
                                    <option value="Volver a llamar">Volver a llamar</option>
                                    <option value="Enviar WhatsApp">Enviar WhatsApp</option>
                                    <option value="Revisar correo">Revisar correo</option>
                                    <option value="Preparar oficio">Preparar oficio</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingNextAt">Fecha de próxima acción</label>
                                <input id="incomingNextAt" name="proxima_accion_at" type="datetime-local" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="incomingNotes">Observaciones</label>
                        <textarea id="incomingNotes" name="observacion" rows="3" maxlength="1000"
                            placeholder="Resumen de la conversación" class="form-control"></textarea>
                    </div>
                    <div class="alert alert-danger mt-3 mb-0 d-none" data-incoming-error role="alert"></div>
                </div>
                <div class="modal-footer system-form-modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save" data-incoming-submit>
                        <i class="bi bi-check2-circle"></i> Guardar resultado
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
