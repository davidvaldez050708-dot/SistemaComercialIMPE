<?php

$correoEntrante = $correoEntrante ?? [];
$mensajeExito = $mensajeExito ?? '';
$mensajeError = $mensajeError ?? '';

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = static function ($valor) use ($texto) {
    $valor = trim((string)$valor);
    return $valor !== '' ? $texto($valor) : '—';
};

$formatearFecha = static function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    try {
        $meses = [
            'Jan' => 'ene', 'Feb' => 'feb', 'Mar' => 'mar', 'Apr' => 'abr',
            'May' => 'may', 'Jun' => 'jun', 'Jul' => 'jul', 'Aug' => 'ago',
            'Sep' => 'sep', 'Oct' => 'oct', 'Nov' => 'nov', 'Dec' => 'dic'
        ];
        $fechaObjeto = new DateTime($fecha);
        return strtr($fechaObjeto->format('d M Y · H:i'), $meses);
    } catch (Throwable $error) {
        return $fecha;
    }
};

$respuestaId = (int)($correoEntrante['id'] ?? 0);
$seguimientoId = (int)($correoEntrante['seguimiento_id'] ?? 0);
$reunionId = (int)($correoEntrante['reunion_id'] ?? 0);
$contexto = strtoupper(trim((string)($correoEntrante['contexto'] ?? 'OFERTA')));
$estado = strtoupper(trim((string)($correoEntrante['estado'] ?? 'PENDIENTE')));
$puedeRegistrar = !empty($correoEntrante['puede_registrar_respuesta']);
$nombreEntidad = trim((string)($correoEntrante['nombre_entidad'] ?? ''));
$nombreEntidad = $nombreEntidad !== '' ? $nombreEntidad : 'Institución';
$vistaPrevia = trim((string)($correoEntrante['vista_previa'] ?? ''));
$contenidoCompleto = trim((string)($correoEntrante['contenido_completo'] ?? ''));
$tieneContenidoCompleto = $contenidoCompleto !== '';
$contenidoMostrado = $tieneContenidoCompleto ? $contenidoCompleto : $vistaPrevia;
$respuestaSugerida = mb_substr($contenidoMostrado, 0, 8000, 'UTF-8');

$esReunion = $contexto === 'REUNION';
$etiquetaContexto = $esReunion
    ? 'Respuesta sobre reunión'
    : 'Respuesta a oferta / seguimiento';
$urlExpediente = $seguimientoId > 0
    ? BASE_URL . 'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
        $seguimientoId . ($esReunion ? '#exp-reunion' : '#exp-oficios')
    : BASE_URL . 'index.php?controller=seguimientoVinculacion&action=index';
?>

<?php if ($mensajeError !== ''): ?>
    <div class="alert alert-danger login-alert mb-3" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= $texto($mensajeError) ?></span>
    </div>
<?php endif; ?>

<?php if ($mensajeExito !== ''): ?>
    <div class="alert alert-success login-alert mb-3" role="status">
        <i class="bi bi-check2-circle"></i>
        <span><?= $texto($mensajeExito) ?></span>
    </div>
<?php endif; ?>

<section class="dashboard-panel linkage-panel mb-3">
    <a class="linkage-back-link" href="<?= $texto($urlExpediente) ?>">
        <i class="bi bi-arrow-left"></i>
        <?= $seguimientoId > 0 ? 'Volver al expediente' : 'Volver a seguimiento' ?>
    </a>

    <div class="linkage-heading">
        <div>
            <span>CORREO RECIBIDO</span>
            <h2><?= $texto($nombreEntidad) ?></h2>
            <p><?= $texto($etiquetaContexto) ?></p>
        </div>
        <div class="linkage-state-pill">
            <?= $estado === 'REGISTRADA' ? 'Respuesta registrada' : 'Pendiente de revisar' ?>
        </div>
    </div>
</section>

<section class="dashboard-panel linkage-detail-panel mb-3">
    <div class="users-list-header">
        <div>
            <h2>Respuesta detectada por correo</h2>
            <p>
                El sistema vinculó este mensaje con el seguimiento por el correo de la institución.
                Abrirlo no cambia automáticamente la ruta.
            </p>
        </div>
    </div>

    <div class="linkage-detail-grid">
        <div class="detail-row">
            <span>De</span>
            <strong><?= $valor($correoEntrante['remitente'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Recibido</span>
            <strong><?= $texto($formatearFecha($correoEntrante['recibido_at'] ?? '')) ?></strong>
        </div>
        <div class="detail-row">
            <span>Buzón</span>
            <strong><?= $valor($correoEntrante['mailbox'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Contexto</span>
            <strong><?= $texto($etiquetaContexto) ?></strong>
        </div>
        <div class="detail-row" style="grid-column:1/-1;">
            <span>Asunto</span>
            <strong><?= $valor($correoEntrante['asunto'] ?? '') ?></strong>
        </div>
    </div>

    <div class="linkage-detail-notes" style="margin-top:14px;">
        <span><?= $tieneContenidoCompleto ? 'Contenido completo consultado en Hostinger' : 'Vista previa recibida por Hostinger' ?></span>
        <p style="white-space:pre-wrap;overflow-wrap:anywhere;"><?= $contenidoMostrado !== '' ? $texto($contenidoMostrado) : 'Hostinger no incluyó texto legible en este evento.' ?></p>
    </div>

    <div class="security-note mt-3 mb-0">
        <i class="bi <?= $tieneContenidoCompleto ? 'bi-envelope-open' : 'bi-info-circle' ?>"></i>
        <span>
            <?php if ($tieneContenidoCompleto): ?>
                El contenido se recuperó de INBOX mediante Hostinger Mail API.
            <?php elseif (!empty($correoEntrante['contenido_api_disponible'])): ?>
                No fue posible identificar el mensaje completo con suficiente seguridad; se muestra la vista previa del webhook.
            <?php else: ?>
                Se muestra la vista previa del webhook. La lectura completa estará disponible cuando Hostinger Mail API esté configurada en este servidor.
            <?php endif; ?>
        </span>
    </div>

    <?php if (
        trim((string)($correoEntrante['mensaje_externo_id'] ?? '')) !== '' ||
        trim((string)($correoEntrante['thread_id'] ?? '')) !== '' ||
        (int)($correoEntrante['contenido_api_uid'] ?? 0) > 0
    ): ?>
        <div class="security-note mt-2 mb-0">
            <i class="bi bi-link-45deg"></i>
            <span>
                Referencia de correo:
                <?php if (trim((string)($correoEntrante['mensaje_externo_id'] ?? '')) !== ''): ?>
                    mensaje <?= $texto($correoEntrante['mensaje_externo_id']) ?>
                <?php endif; ?>
                <?php if (trim((string)($correoEntrante['thread_id'] ?? '')) !== ''): ?>
                    <?= trim((string)($correoEntrante['mensaje_externo_id'] ?? '')) !== '' ? ' · ' : '' ?>
                    conversación <?= $texto($correoEntrante['thread_id']) ?>
                <?php endif; ?>
                <?php if ((int)($correoEntrante['contenido_api_uid'] ?? 0) > 0): ?>
                    · UID <?= (int)$correoEntrante['contenido_api_uid'] ?>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
</section>

<?php if ($esReunion): ?>
    <section class="dashboard-panel linkage-detail-panel mb-3">
        <div class="users-list-header">
            <div>
                <h2>Respuesta relacionada con la reunión</h2>
                <p>
                    El Analista y la Cuenta Clave pueden revisar este mensaje. La reunión no se confirma,
                    reprograma ni cancela automáticamente por recibir un correo.
                </p>
            </div>
        </div>

        <div class="linkage-detail-grid">
            <div class="detail-row">
                <span>Estado de reunión</span>
                <strong><?= $valor($correoEntrante['reunion_estado'] ?? '') ?></strong>
            </div>
            <div class="detail-row">
                <span>Fecha coordinada</span>
                <strong><?= $texto($formatearFecha($correoEntrante['reunion_fecha'] ?? '')) ?></strong>
            </div>
        </div>

        <?php if ($seguimientoId > 0): ?>
            <div class="mt-3">
                <a class="btn btn-system-save" href="<?= $texto($urlExpediente) ?>">
                    <i class="bi bi-calendar-event me-1"></i>
                    Abrir reunión en el expediente
                </a>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($puedeRegistrar): ?>
    <section class="dashboard-panel linkage-detail-panel mb-3">
        <div class="users-list-header">
            <div>
                <h2>Confirmar respuesta en la ruta</h2>
                <p>
                    Revisa el mensaje antes de registrarlo. Esto evita que respuestas automáticas,
                    rebotes o mensajes ajenos hagan avanzar el seguimiento por sí solos.
                </p>
            </div>
        </div>

        <form
            action="<?= BASE_URL ?>index.php?controller=reminder&action=registrarCorreoEntranteRespuesta"
            method="POST">
            <input type="hidden" name="respuesta_id" value="<?= $respuestaId ?>">

            <div class="system-form-grid">
                <div>
                    <label class="form-label" for="respuesta_tipo_correo">¿Qué respondió la institución?</label>
                    <select
                        class="form-select system-form-control"
                        id="respuesta_tipo_correo"
                        name="respuesta_tipo"
                        required>
                        <option value="">Selecciona una opción</option>
                        <option value="INTERESADO">Interesado / respuesta positiva</option>
                        <option value="MAS_INFORMACION">Solicita más información</option>
                        <option value="QUIERE_REUNION">Quiere agendar una reunión</option>
                        <option value="CONTACTAR_DESPUES">Contactar más adelante</option>
                        <option value="NO_INTERESADO">No interesado</option>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="contactar_despues_correo">Retomar contacto el</label>
                    <input
                        class="form-control system-form-control"
                        id="contactar_despues_correo"
                        type="datetime-local"
                        name="contactar_despues_at">
                    <div class="form-text">Solo es necesario si eliges “Contactar más adelante”.</div>
                </div>

                <div style="grid-column:1/-1;">
                    <label class="form-label" for="respuesta_texto_correo">Respuesta recibida</label>
                    <textarea
                        class="form-control system-form-control"
                        id="respuesta_texto_correo"
                        name="respuesta_texto"
                        rows="7"
                        maxlength="8000"
                        required><?= $texto($respuestaSugerida) ?></textarea>
                    <div class="form-text">
                        Puedes corregir o completar el texto antes de guardarlo en el historial.
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a class="btn btn-system-light" href="<?= $texto($urlExpediente) ?>">
                    Revisar expediente
                </a>
                <button type="submit" class="btn btn-system-save">
                    <i class="bi bi-check2-circle me-1"></i>
                    Registrar como respuesta
                </button>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="dashboard-panel linkage-detail-panel mb-3">
        <div class="security-note mb-0">
            <i class="bi bi-info-circle"></i>
            <span>
                <?= $estado === 'REGISTRADA'
                    ? 'Esta respuesta ya fue incorporada al historial del seguimiento.'
                    : 'Este mensaje es informativo para tu rol. El Analista responsable conserva el control del avance de la ruta.' ?>
            </span>
        </div>

        <?php if ($seguimientoId > 0): ?>
            <div class="mt-3">
                <a class="btn btn-system-light" href="<?= $texto($urlExpediente) ?>">
                    Abrir expediente
                </a>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
