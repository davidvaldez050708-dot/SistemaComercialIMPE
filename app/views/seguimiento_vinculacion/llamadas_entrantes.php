<?php
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$llamadas = is_array($bandejaEntrantes['llamadas'] ?? null)
    ? $bandejaEntrantes['llamadas'] : [];
$seleccionarId = trim((string)($_GET['sdk_session_id'] ?? ($_GET['pbx_call_id'] ?? '')));
$abrirSoloDetalle = !empty($desdeMisLlamadas) && (string)($_GET['detalle'] ?? '') === '1';
$estadosEntrantes = $estadosEntrantes ?? [];

// Paginación en el servidor: no renderizar 100+ tarjetas ni reproductores.
// Mantener los contadores globales sobre el historial completo.
$llamadasPorPagina = 10;
$totalLlamadasEntrantes = count($llamadas);
$totalPaginasEntrantes = max(1, (int)ceil($totalLlamadasEntrantes / $llamadasPorPagina));
$paginaPedida = $_GET['pagina'] ?? 1;
$paginaEntrantes = filter_var($paginaPedida, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]);
$paginaEntrantes = $paginaEntrantes === false
    ? 1
    : min($paginaEntrantes, $totalPaginasEntrantes);

// Los botones/notificaciones que apuntan a una llamada específica deben
// encontrarla aunque no esté dentro de las primeras diez.
if ($seleccionarId !== '') {
    foreach ($llamadas as $posicion => $llamadaCandidata) {
        if ((string)($llamadaCandidata['pbx_call_id'] ?? '') === $seleccionarId ||
            (string)($llamadaCandidata['sdk_session_id'] ?? '') === $seleccionarId) {
            $paginaEntrantes = intdiv((int)$posicion, $llamadasPorPagina) + 1;
            break;
        }
    }
}
$indiceInicialEntrantes = ($paginaEntrantes - 1) * $llamadasPorPagina;
$llamadasPaginaEntrantes = array_slice($llamadas, $indiceInicialEntrantes, $llamadasPorPagina);
$primeraEntrante = $totalLlamadasEntrantes > 0 ? $indiceInicialEntrantes + 1 : 0;
$ultimaEntrante = min($totalLlamadasEntrantes, $indiceInicialEntrantes + count($llamadasPaginaEntrantes));
$desdeMisLlamadas = !empty($desdeMisLlamadas);
$urlPaginaEntrantes = static function (int $numero) use ($desdeMisLlamadas): string {
    return BASE_URL . ($desdeMisLlamadas
        ? 'index.php?controller=telefonia&action=misLlamadas&vista=recibidas'
        : 'index.php?controller=seguimientoVinculacion&action=llamadasEntrantes')
        . '&pagina=' . $numero . '#incomingHistoryTitle';
};
$paginasVisiblesEntrantes = [];
if ($totalPaginasEntrantes > 1) {
    // Primera, última y dos adyacentes a la actual; insertar separadores.
    foreach ([1, $paginaEntrantes - 2, $paginaEntrantes - 1,
              $paginaEntrantes, $paginaEntrantes + 1, $paginaEntrantes + 2,
              $totalPaginasEntrantes] as $numero) {
        if ($numero >= 1 && $numero <= $totalPaginasEntrantes) {
            $paginasVisiblesEntrantes[$numero] = $numero;
        }
    }
    ksort($paginasVisiblesEntrantes, SORT_NUMERIC);
}

$pendientesEntrantes = count(array_filter($llamadas, static function ($fila) {
    return (string)($fila['clasificacion'] ?? 'PENDIENTE') === 'PENDIENTE'
        && !empty($fila['contestada'])
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
    data-current-user="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>"
    data-auto-call="<?= $abrirSoloDetalle ? '' : $esc($seleccionarId) ?>"
    data-focus-call="<?= $abrirSoloDetalle ? $esc($seleccionarId) : '' ?>"
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
            <?php if (!$desdeMisLlamadas): ?>
            <a class="btn btn-system-light linkage-incoming-back" href="<?= BASE_URL ?>index.php?controller=telefonia&amp;action=devoluciones"><i class="bi bi-telephone-forward" aria-hidden="true"></i><span>Llamadas por devolver</span></a>
            <?php endif; ?>
            <?php if (!$desdeMisLlamadas): ?>
            <a class="btn btn-system-light linkage-incoming-back"
                href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index') ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Volver a Seguimiento</span>
            </a>
            <?php endif; ?>
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
            <div class="linkage-call-summary linkage-incoming-stats" aria-label="Resumen de llamadas recibidas">
                <div><span>Recibidas</span><strong><?= count($llamadas) ?></strong></div>
                <div><span>Pendientes</span><strong><?= $pendientesEntrantes ?></strong></div>
                <div><span>Vinculadas</span><strong><?= $vinculadasEntrantes ?></strong></div>
                <div><span>Internas</span><strong><?= $internasEntrantes ?></strong></div>
            </div>
            <section class="linkage-call-section linkage-incoming-history-section" aria-labelledby="incomingHistoryTitle">
                <div class="linkage-call-section-heading linkage-incoming-list-head">
                    <div>
                        <strong id="incomingHistoryTitle">Historial de llamadas</strong>
                        <p>Registro cronológico de tu extensión. Las llamadas internas no cuentan como contactos institucionales.</p>
                    </div>
                    <span class="linkage-call-recordings-count" aria-label="<?= $totalLlamadasEntrantes ?> registros"><?= $totalLlamadasEntrantes ?></span>
                </div>
                <div class="linkage-call-history-list linkage-incoming-history" role="list">
                <?php foreach ($llamadasPaginaEntrantes as $indice => $llamada):
                    $pbxId = (string)($llamada['pbx_call_id'] ?? '');
                    $esWebrtc = ($llamada['fuente'] ?? '') === 'SDK_WEBRTC';
                    $esInterna = ($llamada['tipo_llamada'] ?? '') === 'INTERNA';
                    $situacion = (string)($llamada['clasificacion'] ?? 'PENDIENTE');
                    $sdkIdConciliado = trim((string)($llamada['sdk_session_id'] ?? ''));
                    $usarPbx=!$esWebrtc && !empty($llamada['pbx_conversacion_confirmada']);
                    $idRegistroResultado = !$usarPbx && $sdkIdConciliado !== '' ? $sdkIdConciliado : $pbxId;
                    $fuenteRegistroResultado = $usarPbx ? 'PBX' : (($sdkIdConciliado!=='' || $esWebrtc) ? 'SDK_WEBRTC' : 'PBX');
                    $pbx = (string)($llamada['estado_pbx'] ?? '');
                    $finalizada = in_array($pbx, ['ended', 'transferred'], true);
                    $vinculada = $situacion === 'VINCULADA';
                    $sinIdentificar = $situacion === 'SIN_IDENTIFICAR';
                    $seguimientoId = (int)($llamada['seguimiento_id'] ?? 0);
                    $grabacionInterna = $esInterna && $esWebrtc && !empty($llamada['grabacion_disponible']);
                    $audioInstitucionSaliente = !$esWebrtc && !$esInterna && !empty($llamada['grabacion_institucion_transferida']);
                    $audioPbx = !$esWebrtc && !$esInterna && !empty($llamada['contestada']) &&
                        (!empty($llamada['grabacion_pbx_reportada'])||$audioInstitucionSaliente);
                    $audioInternoUrl = $grabacionInterna
                        ? BASE_URL . 'prueba_telefonia/api/grabacion_interna_analista.php?session_id=' . rawurlencode($pbxId)
                        : '';
                    $numero = trim((string)($llamada['numero'] ?? ''));
                    $numero = $numero !== '' ? $numero : 'Número privado';
                    $fechaBruta = trim((string)($llamada['fecha'] ?? ''));
                    $fechaObj = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fechaBruta);
                    $fechaVisual = $fechaObj && $fechaObj->format('Y-m-d H:i:s') === $fechaBruta
                        ? $fechaObj->format('d/m/Y · H:i')
                        : $fechaBruta;
                    $duracionSegundos = max(0, (int)($llamada['duracion'] ?? 0));
                    $controlAudioId = 'incoming-audio-' . (int)$indice;
                ?>
                    <article class="linkage-call-history-card linkage-incoming-card"
                        role="listitem" data-incoming-row="<?= $esc($pbxId) ?>">
                        <span class="linkage-call-recording-card-icon" aria-hidden="true">
                            <i class="bi <?= $esInterna ? 'bi-arrow-left-right' : 'bi-telephone-inbound' ?>"></i>
                        </span>
                        <div class="linkage-call-recording-body">
                            <div class="linkage-call-recording-top">
                                <div class="linkage-call-history-identity">
                                    <strong><?= ($llamada['fuente']??'')==='OPERACION' ? 'Transferencia sin respuesta' : ($esInterna ? 'Llamada interna' : 'Llamada entrante') ?></strong>
                                    <span>
                                        <?= $esInterna
                                            ? 'Ext. ' . $esc($numero) . ' → ' . $esc($bandejaEntrantes['extension'] ?? '')
                                            : 'Origen: ' . $esc($numero) ?>
                                    </span>
                                    <?php if (!empty($llamada['transferida_desde'])): ?>
                                        <span><?= ($llamada['fuente']??'')==='OPERACION'?'Intento de transferencia desde extensión':'Transferida desde extensión' ?> <?= $esc($llamada['transferida_desde']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="linkage-call-history-date">
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    <?= $esc($fechaVisual) ?>
                                </span>
                            </div>

                            <div class="linkage-call-detail linkage-incoming-card-detail">
                                <?php if ($esWebrtc): ?>
                                    <div class="linkage-call-detail-row">
                                        <span class="linkage-call-detail-label">Registro</span>
                                        <span class="linkage-call-detail-value">
                                            <?= $grabacionInterna
                                                ? 'Grabación disponible'
                                                : 'Llamada registrada · esperando confirmación de la centralita' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($seguimientoId > 0): ?>
                                    <div class="linkage-call-detail-row">
                                        <span class="linkage-call-detail-label">Expediente</span>
                                        <span class="linkage-call-detail-value">
                                            <a href="<?= $esc(BASE_URL . 'index.php?controller=seguimientoVinculacion&action=detalle&id=' . $seguimientoId) ?>"
                                                class="linkage-incoming-expediente">
                                                <i class="bi bi-folder2-open" aria-hidden="true"></i> Ver expediente
                                            </a>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="linkage-call-history-footer">
                                <div class="linkage-call-result-group">
                                    <?php if ($esInterna && $vinculada): ?>
                                        <span class="linkage-call-result-badge is-no-contact">Interna · revisar vínculo de prueba</span>
                                    <?php elseif ($audioPbx): ?>
                                        <button type="button" class="linkage-call-audio-state is-available linkage-call-recording-toggle"
                                            data-incoming-pbx-toggle
                                            data-pbx-id="<?= $esc($pbxId) ?>"
                                            data-pbx-salida-institucion="<?= $audioInstitucionSaliente?'1':'0' ?>"
                                            data-seguimiento-id="<?= $seguimientoId ?>"
                                            aria-controls="<?= $esc($controlAudioId) ?>" aria-expanded="false">
                                            <i class="bi bi-record-circle" aria-hidden="true"></i>
                                            <span><?= $audioInstitucionSaliente?'Consultar grabaciones':'Consultar grabación' ?></span>
                                            <i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>
                                        </button>
                                    <?php elseif ($esInterna): ?>
                                        <span class="linkage-call-result-badge is-verification-test">Interna · no contabiliza</span>
                                    <?php elseif ($vinculada): ?>
                                        <span class="linkage-call-result-badge is-contact">Registrada</span>
                                    <?php elseif ($sinIdentificar): ?>
                                        <span class="linkage-call-result-badge">Sin identificar</span>
                                    <?php elseif (!$finalizada): ?>
                                        <span class="linkage-call-result-badge">En proceso</span>
                                    <?php elseif (empty($llamada['contestada'])): ?>
                                        <span class="linkage-call-result-badge is-no-contact">Sin respuesta</span>
                                    <?php else: ?>
                                        <span class="linkage-call-result-badge is-no-contact">Pendiente de resultado</span>
                                    <?php endif; ?>
                            <?php // El reproductor y el registro son acciones independientes. ?>
                            <?php if (!$esInterna && !$vinculada && $finalizada && !empty($llamada['contestada'])): ?>
                                <span class="linkage-incoming-register-inline">
                                    <button type="button" class="linkage-incoming-open"
                                        data-call-id="<?= $esc($idRegistroResultado) ?>"
                                        data-pbx-call-id="<?= $esc($pbxId) ?>"
                                        data-sdk-reference="<?= $esc($sdkIdConciliado) ?>"
                                        data-source="<?= $esc($fuenteRegistroResultado) ?>"
                                        data-phone="<?= $esc($llamada['numero'] ?? '') ?>"
                                        data-answer="<?= !empty($llamada['contestada']) ? '1' : '0' ?>"
                                        data-duration="<?= $esc($duracionEntrante($duracionSegundos)) ?>">
                                        <i class="bi bi-journal-check" aria-hidden="true"></i>
                                        <?= $sinIdentificar ? 'Vincular expediente' : 'Registrar resultado' ?>
                                    </button>
                                </span>
                            <?php endif; ?>
                            <?php if ($vinculada && $usarPbx && !empty($llamada['verificacion_pendiente']) && $sdkIdConciliado!==''): ?>
                                <button type="button" class="linkage-incoming-open"
                                    data-confirm-verification="<?= $esc($pbxId) ?>" data-sdk-reference="<?= $esc($sdkIdConciliado) ?>">
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Confirmar verificación
                                </button>
                            <?php endif; ?>
                                </div>
                                <div class="linkage-call-history-meta">
                                    <span class="linkage-call-recording-duration">
                                        <i class="bi bi-clock" aria-hidden="true"></i>
                                        <?= $esc($duracionEntrante($duracionSegundos)) ?>
                                    </span>
                                    <?php if ($grabacionInterna): ?>
                                        <button type="button"
                                            class="linkage-call-audio-state is-available linkage-call-recording-toggle"
                                            data-incoming-audio-toggle
                                            aria-controls="<?= $esc($controlAudioId) ?>"
                                            aria-expanded="false">
                                            <i class="bi bi-record-circle" aria-hidden="true"></i>
                                            <span>Grabación disponible</span>
                                            <i class="bi bi-chevron-down linkage-call-recording-chevron" aria-hidden="true"></i>
                                        </button>
                                    <?php elseif ($esInterna): ?>
                                        <span class="linkage-call-audio-state is-processing">
                                            <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                            <span>Grabación no vinculada</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($grabacionInterna): ?>
                                <div class="linkage-call-inline-player linkage-incoming-audio-panel"
                                    id="<?= $esc($controlAudioId) ?>" data-incoming-audio-panel hidden>
                                    <div class="linkage-call-inline-player-heading">
                                        <span><i class="bi bi-soundwave" aria-hidden="true"></i> Grabación de llamada</span>
                                        <small>Audio privado de la extensión <?= $esc($bandejaEntrantes['extension'] ?? '') ?></small>
                                    </div>
                                    <div class="linkage-call-custom-player" data-incoming-player>
                                        <audio class="linkage-call-player-audio-source" preload="none"
                                            src="<?= $esc($audioInternoUrl) ?>"></audio>
                                        <button type="button" class="linkage-call-player-button linkage-call-player-play"
                                            data-incoming-play aria-label="Reproducir grabación" aria-pressed="false">
                                            <i class="bi bi-play-fill" aria-hidden="true"></i>
                                        </button>
                                        <span class="linkage-call-player-time" data-incoming-time>0:00</span>
                                        <input type="range" min="0" max="100" step="0.1" value="0"
                                            class="linkage-call-player-progress" data-incoming-progress
                                            aria-label="Posición del audio" aria-valuetext="0:00" />
                                        <span class="linkage-call-player-time" data-incoming-total>
                                            <?= $esc($duracionEntrante($duracionSegundos)) ?>
                                        </span>
                                        <button type="button" class="linkage-call-player-button linkage-call-player-volume"
                                            data-incoming-volume aria-label="Silenciar grabación" aria-pressed="false">
                                            <i class="bi bi-volume-up" aria-hidden="true"></i>
                                        </button>
                                        <details class="linkage-call-player-menu">
                                            <summary class="linkage-call-player-button linkage-call-player-more"
                                                aria-label="Opciones de grabación" title="Opciones">
                                                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                            </summary>
                                            <div class="linkage-call-player-menu-content">
                                                <a class="linkage-call-player-download"
                                                    href="<?= $esc($audioInternoUrl . '&download=1') ?>">
                                                    <i class="bi bi-download" aria-hidden="true"></i>
                                                    Descargar audio
                                                </a>
                                            </div>
                                        </details>
                                    </div>
                                    <p class="linkage-incoming-player-error" data-incoming-player-error
                                        role="status" aria-live="polite" hidden></p>
                                </div>
                            <?php elseif ($audioPbx): ?>
                                <div class="linkage-call-inline-player linkage-incoming-audio-panel"
                                    id="<?= $esc($controlAudioId) ?>" data-incoming-pbx-panel hidden>
                                    <div class="linkage-call-inline-player-heading">
                                        <span><i class="bi bi-soundwave" aria-hidden="true"></i> Grabación disponible</span>
                                        <small>Los segmentos de la conversación se reproducen en orden.</small>
                                    </div>
                                    <div data-incoming-pbx-audios></div>
                                    <p class="linkage-incoming-player-error" data-incoming-pbx-error role="status" aria-live="polite" hidden></p>
                                </div>
                            <?php elseif ($esInterna && $esWebrtc && $situacion === 'INTERNA' && $finalizada): ?>
                                <div class="linkage-incoming-card-actions">
                                    <button type="button" class="btn btn-system-light linkage-incoming-sync-audio"
                                        data-incoming-sync-audio="<?= $esc($pbxId) ?>">
                                        <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>
                                        Buscar grabación
                                    </button>
                                    <small class="linkage-incoming-sync-message" role="status" aria-live="polite"></small>
                                </div>
                            <?php endif; ?>

                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
                <?php if ($totalPaginasEntrantes > 1): ?>
                    <nav class="linkage-incoming-pagination" aria-label="Páginas del historial de llamadas">
                        <span class="linkage-incoming-pagination-range" aria-live="polite">
                            Mostrando <?= $primeraEntrante ?>–<?= $ultimaEntrante ?>
                            de <?= $totalLlamadasEntrantes ?> llamadas
                        </span>
                        <div class="linkage-incoming-pagination-pages">
                            <?php if ($paginaEntrantes > 1): ?>
                                <a class="linkage-incoming-pagination-arrow"
                                    href="<?= $esc($urlPaginaEntrantes($paginaEntrantes - 1)) ?>"
                                    rel="prev" aria-label="Página anterior">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                    <span>Anterior</span>
                                </a>
                            <?php else: ?>
                                <span class="linkage-incoming-pagination-arrow is-disabled" aria-disabled="true">
                                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                                    <span>Anterior</span>
                                </span>
                            <?php endif; ?>
                            <?php $paginaAnteriorVisible = 0; ?>
                            <?php foreach ($paginasVisiblesEntrantes as $numeroPagina): ?>
                                <?php if ($paginaAnteriorVisible > 0 && $numeroPagina - $paginaAnteriorVisible > 1): ?>
                                    <span class="linkage-incoming-pagination-gap" aria-hidden="true">…</span>
                                <?php endif; ?>
                                <?php if ($numeroPagina === $paginaEntrantes): ?>
                                    <span class="linkage-incoming-pagination-number is-current"
                                        aria-label="Página <?= $numeroPagina ?>" aria-current="page">
                                        <?= $numeroPagina ?>
                                    </span>
                                <?php else: ?>
                                    <a class="linkage-incoming-pagination-number"
                                        href="<?= $esc($urlPaginaEntrantes($numeroPagina)) ?>"
                                        aria-label="Ir a la página <?= $numeroPagina ?>">
                                        <?= $numeroPagina ?>
                                    </a>
                                <?php endif; ?>
                                <?php $paginaAnteriorVisible = $numeroPagina; ?>
                            <?php endforeach; ?>
                            <?php if ($paginaEntrantes < $totalPaginasEntrantes): ?>
                                <a class="linkage-incoming-pagination-arrow"
                                    href="<?= $esc($urlPaginaEntrantes($paginaEntrantes + 1)) ?>"
                                    rel="next" aria-label="Página siguiente">
                                    <span>Siguiente</span>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </a>
                            <?php else: ?>
                                <span class="linkage-incoming-pagination-arrow is-disabled" aria-disabled="true">
                                    <span>Siguiente</span>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </section>
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
        El historial reúne las llamadas de tu extensión y se actualiza con la información de la centralita.
        Las llamadas internas entre extensiones activas se conservan en el historial telefónico, sin crear contactos institucionales ni verificaciones efectivas.
    </p>
</section>

<?php require __DIR__.'/../telefonia/resultado_analista_form.php'; ?>
