<?php
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$llamadas = is_array($bandejaEntrantes['llamadas'] ?? null)
    ? $bandejaEntrantes['llamadas'] : [];
$seleccionarId = trim((string)($_GET['sdk_session_id'] ?? ($_GET['pbx_call_id'] ?? '')));
$estadosEntrantes = $estadosEntrantes ?? [];
$pendientesEntrantes = count(array_filter($llamadas, static function ($fila) {
    return (string)($fila['clasificacion'] ?? 'PENDIENTE') === 'PENDIENTE'
        && (string)($fila['tipo_llamada'] ?? 'ENTRANTE') !== 'INTERNA';
}));
$vinculadasEntrantes = count(array_filter($llamadas, static function ($fila) {
    return (string)($fila['clasificacion'] ?? '') === 'VINCULADA'
        && (string)($fila['tipo_llamada'] ?? 'ENTRANTE') !== 'INTERNA';
}));
$internasEntrantes = count(array_filter($llamadas, static function ($fila) {
    return (string)($fila['tipo_llamada'] ?? 'ENTRANTE') === 'INTERNA';
}));
$duracionEntrante = static function ($segundos) {
    return sprintf('%02d:%02d', intdiv(max(0, (int)$segundos), 60),
        max(0, (int)$segundos) % 60);
};
?>
<section class="dashboard-panel linkage-incoming-page"
    data-linkage-incoming
    data-auto-call="<?= $esc($seleccionarId) ?>"
    data-search-url="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=buscarEntrantesSeguimientos') ?>"
    data-save-url="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=registrarLlamadaEntrante') ?>"
    data-audio-sync-url="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=sincronizarGrabacionInterna') ?>">
    <header class="linkage-incoming-heading">
        <div class="linkage-incoming-heading-copy">
            <span class="linkage-incoming-eyebrow">
                <i class="bi bi-telephone-inbound" aria-hidden="true"></i>
                SEGUIMIENTO · TELEFONÍA
            </span>
            <h2>Llamadas recibidas</h2>
            <p>Consulta tus llamadas entrantes y registra su resultado en el expediente institucional correspondiente.</p>
        </div>
        <div class="linkage-incoming-heading-actions">
            <span class="linkage-incoming-extension">
                <i class="bi bi-headset" aria-hidden="true"></i>
                Extensión <?= $esc($bandejaEntrantes['extension'] ?: 'Sin configurar') ?>
            </span>
            <a class="btn btn-system-light linkage-incoming-back"
                href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index') ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Volver a Seguimiento</span>
            </a>
        </div>
    </header>
    <?php if ($errorEntrantes !== ''): ?>
        <div class="linkage-incoming-feedback" role="alert">
            <span class="linkage-incoming-feedback-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
            <div>
                <strong>No es posible consultar tus llamadas</strong>
                <p><?= $esc($errorEntrantes) ?></p>
                <small>Si todavía no tienes extensión, solicita al administrador que la configure y habilite las entrantes.</small>
            </div>
        </div>
    <?php else: ?>
        <?php if ($llamadas): ?>
            <div class="linkage-incoming-stats" aria-label="Resumen de llamadas recibidas">
                <div class="linkage-incoming-stat"><span class="stat-icon"><i class="bi bi-telephone-inbound"></i></span><div>
                    <span>Recibidas</span><strong><?= count($llamadas) ?></strong>
                </div></div>
                <div class="linkage-incoming-stat"><span class="stat-icon"><i class="bi bi-clock-history"></i></span><div>
                    <span>Pendientes</span><strong><?= $pendientesEntrantes ?></strong>
                </div></div>
                <div class="linkage-incoming-stat"><span class="stat-icon"><i class="bi bi-folder-check"></i></span><div>
                    <span>Vinculadas</span><strong><?= $vinculadasEntrantes ?></strong>
                </div></div>
                <div class="linkage-incoming-stat"><span class="stat-icon"><i class="bi bi-arrow-left-right"></i></span><div>
                    <span>Internas</span><strong><?= $internasEntrantes ?></strong>
                </div></div>
            </div>
            <div class="linkage-incoming-list-head">
                <div><h3>Historial de llamadas</h3><p>Movimientos recientes de tu extensión telefónica.</p></div>
                <span class="linkage-incoming-list-total"><?= count($llamadas) ?> <?= count($llamadas) === 1 ? 'registro' : 'registros' ?></span>
            </div>
            <div class="linkage-incoming-table-wrap">
                <table class="table align-middle linkage-incoming-table">
                    <thead><tr>
                        <th>Fecha</th><th>Tipo de llamada</th><th>Duración</th>
                        <th>Estado</th><th>Expediente</th><th class="text-end">Acción</th>
                    </tr></thead>
                    <tbody>
                <?php foreach ($llamadas as $llamada):
                    $pbxId = (string)($llamada['pbx_call_id'] ?? '');
                    $esWebrtc = ($llamada['fuente'] ?? '') === 'SDK_WEBRTC';
                    $esInterna = ($llamada['tipo_llamada'] ?? '') === 'INTERNA';
                    $situacion = (string)($llamada['clasificacion'] ?? 'PENDIENTE');
                    $pbx = (string)($llamada['estado_pbx'] ?? '');
                    $finalizada = in_array($pbx, ['ended', 'transferred'], true);
                    $vinculada = $situacion === 'VINCULADA';
                    $sinIdentificar = $situacion === 'SIN_IDENTIFICAR';
                    $seguimientoId = (int)($llamada['seguimiento_id'] ?? 0);
                    $grabacionInterna = $esInterna && !empty($llamada['grabacion_disponible']);
                    $audioInternoUrl = $grabacionInterna
                        ? BASE_URL . 'prueba_telefonia/api/grabacion_interna_analista.php?session_id=' . rawurlencode($pbxId)
                        : '';
                    $numero = trim((string)($llamada['numero'] ?? ''));
                    $numero = $numero !== '' ? $numero : 'Número privado';
                ?>
                <tr data-incoming-row="<?= $esc($pbxId) ?>">
                    <td data-label="Fecha" class="text-nowrap"><?= $esc($llamada['fecha'] ?? '') ?></td>
                    <td data-label="Tipo de llamada" class="linkage-incoming-caller">
                        <strong><?= $esc($numero) ?></strong>
                        <small class="d-block text-muted">
                            <?php if ($esInterna): ?>
                                <span>Interna · Ext. <?= $esc($numero) ?> → <?= $esc($bandejaEntrantes['extension'] ?? '') ?></span>
                            <?php else: ?>
                                <span>Entrante · Extensión <?= $esc($bandejaEntrantes['extension'] ?? '') ?></span>
                            <?php endif; ?>
                            <?php if ($esWebrtc): ?>
                                <span class="linkage-incoming-source">
                                    <i class="bi bi-headset" aria-hidden="true"></i>
                                    WebRTC observado
                                </span>
                                <span class="linkage-incoming-source-note">Sin confirmación PBX</span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td data-label="Duración" class="text-nowrap linkage-incoming-duration"><?= $esc($duracionEntrante($llamada['duracion'] ?? 0)) ?></td>
                    <td data-label="Estado">
                        <?php if ($esInterna && $vinculada): ?>
                            <span class="linkage-incoming-tag is-pending">Interna · revisar vínculo de prueba</span>
                        <?php elseif ($esInterna): ?>
                            <span class="linkage-incoming-tag">Interna · no contabiliza</span>
                        <?php elseif ($vinculada): ?>
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
                    <td data-label="Expediente">
                        <?php if ($seguimientoId > 0): ?>
                            <a href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=detalle&id=' . $seguimientoId) ?>"
                                class="linkage-incoming-expediente">
                                <i class="bi bi-folder2-open"></i>
                                Ver expediente
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end linkage-incoming-action-cell" data-label="Acción">
                        <?php if ($grabacionInterna): ?>
                            <details class="linkage-incoming-internal-audio">
                                <summary><i class="bi bi-play-circle" aria-hidden="true"></i> Escuchar grabación</summary>
                                <audio controls preload="none" src="<?= $esc($audioInternoUrl) ?>" aria-label="Grabación de llamada interna"></audio>
                                <a href="<?= $esc($audioInternoUrl . '&download=1') ?>">Descargar audio</a>
                            </details>
                        <?php elseif ($esInterna && $esWebrtc && $situacion === 'INTERNA' && $finalizada): ?>
                            <button type="button" class="btn btn-system-light linkage-incoming-sync-audio"
                                data-incoming-sync-audio="<?= $esc($pbxId) ?>">
                                <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>
                                Buscar grabación
                            </button>
                            <small class="linkage-incoming-sync-message" role="status" aria-live="polite"></small>
                        <?php elseif ($esInterna && !$vinculada): ?>
                            <small class="text-muted">Grabación pendiente de vinculación</small>
                        <?php elseif (!$vinculada && $finalizada && (!$sinIdentificar || !empty($llamada['contestada']))): ?>
                            <button type="button" class="btn btn-system-save linkage-incoming-open"
                                data-call-id="<?= $esc($pbxId) ?>"
                                data-source="<?= $esWebrtc ? 'SDK_WEBRTC' : 'PBX' ?>"
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
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="linkage-incoming-empty-panel">
                <span class="linkage-incoming-empty-icon"><i class="bi bi-telephone-inbound" aria-hidden="true"></i></span>
                <h3>Aún no hay llamadas recibidas</h3>
                <p>Cuando tu extensión reciba una llamada, aparecerá aquí para consultar su estado y registrar el resultado.</p>
                <span>Se muestran las llamadas de los últimos 30 días.</span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <p class="linkage-incoming-footnote">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        El historial distingue eventos firmados de la centralita y sesiones observadas por WebRTC.
        Las llamadas internas entre extensiones activas se conservan en el historial telefónico, sin crear contactos institucionales ni verificaciones efectivas.
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
            <form data-incoming-form class="linkage-incoming-form">
                <div class="modal-body system-form-modal-body" data-incoming-scroll-region tabindex="0" aria-label="Formulario de resultado de llamada">
                    <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pbx_call_id" data-incoming-pbx>
                    <input type="hidden" name="sdk_session_id" data-incoming-sdk-id>
                    <div class="linkage-incoming-summary">
                        <span><i class="bi bi-telephone-inbound" aria-hidden="true"></i> <strong data-incoming-number>—</strong></span>
                        <span><i class="bi bi-clock" aria-hidden="true"></i> <strong data-incoming-duration>00:00</strong></span>
                    </div>
                    <div class="linkage-incoming-sdk-notice" data-incoming-sdk-notice role="note" hidden>
                        <span class="linkage-incoming-sdk-notice-icon" aria-hidden="true">
                            <i class="bi bi-info-circle"></i>
                        </span>
                        <div>
                            <strong>Registro de llamada WebRTC</strong>
                            <p>Observada desde el navegador, sin confirmación de la centralita.
                                Al vincularla confirma que hubo conversación. No genera verificaciones efectivas.</p>
                        </div>
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
                    <div class="mt-3" data-incoming-sdk-confirm hidden>
                        <label><input type="checkbox" name="confirmacion_manual_sdk" value="1">
                            Confirmo que atendí esta llamada y conversé con la persona indicada
                        </label>
                    </div>
                    <div class="linkage-incoming-evidence mt-3" data-incoming-evidence hidden>
                        <strong>Datos verificados durante esta llamada</strong>
                        <p>Solo se contabilizan cuando se registran evidencias y la centralita confirma que hubo conversación real.</p>
                        <label><input type="checkbox" name="verificacion_telefono_confirmado" value="1">
                            Confirmé que el teléfono corresponde a la institución</label>
                        <label><input type="checkbox" name="verificacion_correo_confirmado" value="1">
                            Confirmé el correo institucional registrado</label>
                        <label><input type="checkbox" name="verificacion_contacto_confirmado" value="1">
                            Confirmé a la persona o área de contacto</label>
                    </div>
                    <div class="linkage-incoming-discard mt-3" data-incoming-discard hidden>
                        <label><input type="checkbox" name="descartar" value="1" data-incoming-discard-toggle>
                            Descartar este seguimiento porque la institución no está interesada</label>
                        <div data-incoming-discard-reason hidden>
                            <label class="form-label mt-2" for="incomingDiscardReason">Motivo de descarte</label>
                            <textarea id="incomingDiscardReason" name="motivo_descarte" rows="2"
                                maxlength="500" class="form-control"
                                placeholder="Indica el motivo por el que se descarta"></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="incomingNotes">Observaciones</label>
                        <textarea id="incomingNotes" name="observacion" rows="3" maxlength="1000"
                            placeholder="Resumen de la conversación" class="form-control"></textarea>
                    </div>
                    <div class="alert alert-danger mt-3 mb-0 d-none" data-incoming-error role="alert"></div>
                </div>
                <div class="modal-footer system-form-modal-footer linkage-incoming-form-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save" data-incoming-submit>
                        <i class="bi bi-check2-circle"></i> Guardar resultado
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
