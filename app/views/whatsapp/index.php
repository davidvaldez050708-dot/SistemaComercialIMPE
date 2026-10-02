<?php

$conversaciones = $conversaciones ?? [];
$mensajesIniciales = $mensajesIniciales ?? [];
$conversacionSeleccionada = $conversacionSeleccionada ?? null;
$estructuraWhatsappDisponible = $estructuraWhatsappDisponible ?? false;
$estadoConfiguracionWhatsapp = $estadoConfiguracionWhatsapp ?? [
    'lista' => false,
    'faltantes' => []
];
$puedeEnviarWhatsapp = $puedeEnviarWhatsapp ?? false;
$puedeGestionarConversaciones = $puedeGestionarConversaciones ?? false;
$puedeGestionarCuentas = $puedeGestionarCuentas ?? false;
$cuentasDisponibles = $cuentasDisponibles ?? [];
$cuentasAdministracion = $cuentasAdministracion ?? [];
$usuariosWhatsapp = $usuariosWhatsapp ?? [];
$ventanaServicioAbierta = $ventanaServicioAbierta ?? false;
$mensajeWhatsapp = $mensajeWhatsapp ?? '';
$errorWhatsapp = $errorWhatsapp ?? '';

$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fechaHora = static function ($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '';
    }

    try {
        return (new DateTime($valor))->format('d/m/Y H:i');
    } catch (Throwable $error) {
        return '';
    }
};

$hora = static function ($valor) {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '';
    }

    try {
        return (new DateTime($valor))->format('H:i');
    } catch (Throwable $error) {
        return '';
    }
};

$estadoIcono = static function ($estado) {
    $estado = strtoupper(trim((string)$estado));

    if ($estado === 'LEIDO') {
        return 'bi-check2-all';
    }

    if ($estado === 'ENTREGADO') {
        return 'bi-check2-all';
    }

    if ($estado === 'ENVIADO') {
        return 'bi-check2';
    }

    if ($estado === 'ERROR') {
        return 'bi-exclamation-circle';
    }

    return 'bi-clock';
};

$callbackUrl = BASE_URL .
    'index.php?controller=whatsappWebhook&action=webhook';

$ultimaIdMensaje = 0;
foreach ($mensajesIniciales as $mensaje) {
    $ultimaIdMensaje = max($ultimaIdMensaje, (int)($mensaje['id'] ?? 0));
}

?>

<section
    class="whatsapp-module"
    data-whatsapp-module
    data-base-url="<?= $texto(BASE_URL) ?>"
    data-conversation-id="<?= (int)($conversacionSeleccionada['id'] ?? 0) ?>"
    data-last-message-id="<?= (int)$ultimaIdMensaje ?>">

    <?php if ($mensajeWhatsapp !== ''): ?>
        <div class="alert alert-success login-alert" role="alert">
            <i class="bi bi-check-circle"></i>
            <span><?= $texto($mensajeWhatsapp) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorWhatsapp !== ''): ?>
        <div class="alert alert-danger login-alert" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= $texto($errorWhatsapp) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$estructuraWhatsappDisponible): ?>
        <div class="alert alert-warning whatsapp-setup-alert" role="alert">
            <i class="bi bi-database-exclamation"></i>
            <div>
                <strong>Falta preparar WhatsApp.</strong>
                <span>Aplica la migración 2026_10_02_c_whatsapp_conversaciones.sql.</span>
            </div>
        </div>
    <?php elseif (!$estadoConfiguracionWhatsapp['lista']): ?>
        <div class="alert alert-warning whatsapp-setup-alert" role="alert">
            <i class="bi bi-gear"></i>
            <div>
                <strong>Cloud API todavía no está configurada.</strong>
                <span>
                    Faltan:
                    <?= $texto(implode(', ', $estadoConfiguracionWhatsapp['faltantes'] ?? [])) ?>.
                    Puedes explorar la bandeja, pero el envío/webhook requerirán la configuración local.
                </span>
            </div>
        </div>
    <?php endif; ?>

    <section class="dashboard-panel whatsapp-toolbar">
        <div>
            <span class="whatsapp-eyebrow">COMUNICACIÓN</span>
            <h2>WhatsApp Business</h2>
            <p>
                Conversaciones centralizadas dentro del Sistema Comercial.
            </p>
        </div>

        <div class="whatsapp-toolbar-actions">
            <span class="whatsapp-api-status <?= !empty($estadoConfiguracionWhatsapp['lista']) ? 'is-ready' : 'is-pending' ?>">
                <i class="bi <?= !empty($estadoConfiguracionWhatsapp['lista']) ? 'bi-cloud-check' : 'bi-cloud-slash' ?>"></i>
                <?= !empty($estadoConfiguracionWhatsapp['lista']) ? 'Cloud API lista' : 'Configuración pendiente' ?>
            </span>

            <?php if ($puedeGestionarCuentas): ?>
                <button
                    type="button"
                    class="btn whatsapp-secondary-button"
                    data-bs-toggle="modal"
                    data-bs-target="#modalWhatsAppCanales">
                    <i class="bi bi-sim"></i>
                    Canales
                </button>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-panel whatsapp-workspace">
        <aside class="whatsapp-inbox">
            <div class="whatsapp-inbox-header">
                <div>
                    <strong>Conversaciones</strong>
                    <span><?= count($conversaciones) ?> activas</span>
                </div>
            </div>

            <div class="whatsapp-search">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    class="form-control"
                    placeholder="Buscar contacto o institución"
                    autocomplete="off"
                    data-whatsapp-search>
            </div>

            <div class="whatsapp-conversation-list" data-whatsapp-conversation-list>
                <?php if (empty($conversaciones)): ?>
                    <div class="whatsapp-list-empty">
                        <i class="bi bi-chat-square-dots"></i>
                        <strong>Sin conversaciones</strong>
                        <span>
                            Abre WhatsApp desde un Aliado o recibe un mensaje en un canal configurado.
                        </span>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversaciones as $conversacion): ?>
                        <?php
                        $nombreConversacion = trim(
                            (string)(
                                $conversacion['aliado_nombre'] ??
                                $conversacion['nombre_contacto'] ??
                                ''
                            )
                        );

                        if ($nombreConversacion === '') {
                            $nombreConversacion =
                                (string)($conversacion['telefono_contacto'] ?? 'WhatsApp');
                        }

                        $activa =
                            (int)($conversacionSeleccionada['id'] ?? 0) ===
                            (int)$conversacion['id'];
                        ?>
                        <a
                            href="<?= BASE_URL ?>index.php?controller=whatsapp&action=index&conversacion_id=<?= (int)$conversacion['id'] ?>"
                            class="whatsapp-conversation-item <?= $activa ? 'is-active' : '' ?>"
                            data-whatsapp-conversation-item
                            data-conversation-id="<?= (int)$conversacion['id'] ?>"
                            data-search="<?= $texto(
                                $nombreConversacion . ' ' .
                                ($conversacion['nombre_contacto'] ?? '') . ' ' .
                                ($conversacion['telefono_contacto'] ?? '') . ' ' .
                                ($conversacion['aliado_estado'] ?? '') . ' ' .
                                ($conversacion['aliado_municipio'] ?? '')
                            ) ?>">
                            <span class="whatsapp-avatar">
                                <?= $texto(strtoupper(substr($nombreConversacion, 0, 1))) ?>
                            </span>

                            <div class="whatsapp-conversation-copy">
                                <div class="whatsapp-conversation-top">
                                    <strong><?= $texto($nombreConversacion) ?></strong>
                                    <time><?= $texto($hora($conversacion['ultimo_mensaje_at'] ?? '')) ?></time>
                                </div>

                                <div class="whatsapp-conversation-bottom">
                                    <span>
                                        <?= $texto(
                                            $conversacion['ultimo_mensaje_preview'] ??
                                            'Sin mensajes todavía'
                                        ) ?>
                                    </span>

                                    <?php if ((int)($conversacion['no_leidos'] ?? 0) > 0): ?>
                                        <b><?= (int)$conversacion['no_leidos'] ?></b>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <div class="whatsapp-chat-column">
            <?php if ($conversacionSeleccionada): ?>
                <header class="whatsapp-chat-header">
                    <div class="whatsapp-chat-contact">
                        <span class="whatsapp-avatar is-large">
                            <?= $texto(strtoupper(substr(
                                trim((string)(
                                    $conversacionSeleccionada['aliado_nombre'] ??
                                    $conversacionSeleccionada['nombre_contacto'] ??
                                    'W'
                                )),
                                0,
                                1
                            ))) ?>
                        </span>

                        <div>
                            <strong data-whatsapp-chat-name>
                                <?= $texto(
                                    $conversacionSeleccionada['aliado_nombre'] ??
                                    $conversacionSeleccionada['nombre_contacto'] ??
                                    $conversacionSeleccionada['telefono_contacto']
                                ) ?>
                            </strong>
                            <span data-whatsapp-chat-phone>
                                <?= $texto($conversacionSeleccionada['telefono_contacto'] ?? '') ?>
                                · <?= $texto($conversacionSeleccionada['cuenta_nombre'] ?? 'WhatsApp') ?>
                            </span>
                        </div>
                    </div>

                    <span
                        class="whatsapp-window-status <?= $ventanaServicioAbierta ? 'is-open' : 'is-closed' ?>"
                        data-whatsapp-window-status>
                        <i class="bi <?= $ventanaServicioAbierta ? 'bi-clock-history' : 'bi-lock' ?>"></i>
                        <?= $ventanaServicioAbierta ? 'Ventana de 24 h abierta' : 'Ventana cerrada' ?>
                    </span>
                </header>

                <div class="whatsapp-messages" data-whatsapp-messages>
                    <?php if (empty($mensajesIniciales)): ?>
                        <div class="whatsapp-chat-empty" data-whatsapp-chat-empty>
                            <i class="bi bi-chat-dots"></i>
                            <strong>Conversación lista</strong>
                            <span>
                                Si la ventana está cerrada, inicia la prueba con una plantilla aprobada.
                            </span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mensajesIniciales as $mensaje): ?>
                            <?php
                            $saliente =
                                strtoupper((string)$mensaje['direccion']) === 'SALIENTE';
                            $estadoMensaje =
                                strtoupper((string)($mensaje['estado'] ?? ''));
                            ?>
                            <article
                                class="whatsapp-message <?= $saliente ? 'is-outgoing' : 'is-incoming' ?>"
                                data-message-id="<?= (int)$mensaje['id'] ?>">
                                <div class="whatsapp-message-bubble">
                                    <?php if (($mensaje['tipo'] ?? 'TEXT') !== 'TEXT'): ?>
                                        <span class="whatsapp-message-type">
                                            <?= $texto($mensaje['tipo']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <p><?= nl2br($texto($mensaje['contenido'] ?? '')) ?></p>

                                    <div class="whatsapp-message-meta">
                                        <time>
                                            <?= $texto($hora(
                                                $mensaje['enviado_at'] ??
                                                $mensaje['created_at'] ??
                                                ''
                                            )) ?>
                                        </time>

                                        <?php if ($saliente): ?>
                                            <i
                                                class="bi <?= $texto($estadoIcono($estadoMensaje)) ?> <?= $estadoMensaje === 'LEIDO' ? 'is-read' : '' ?>"
                                                title="<?= $texto($estadoMensaje) ?>">
                                            </i>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($estadoMensaje === 'ERROR' && !empty($mensaje['error_detalle'])): ?>
                                        <small class="whatsapp-message-error">
                                            <?= $texto($mensaje['error_detalle']) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <footer class="whatsapp-composer">
                    <?php if (!$ventanaServicioAbierta): ?>
                        <div class="whatsapp-window-notice" data-whatsapp-window-notice>
                            <i class="bi bi-info-circle"></i>
                            <div>
                                <strong>La ventana de atención está cerrada.</strong>
                                <span>
                                    Para iniciar la prueba usa una plantilla aprobada. Cuando el contacto responda, se habilitará texto libre durante 24 horas.
                                </span>
                            </div>

                            <?php if ($puedeEnviarWhatsapp): ?>
                                <button
                                    type="button"
                                    class="btn whatsapp-template-button"
                                    data-whatsapp-test-template>
                                    <i class="bi bi-send-check"></i>
                                    Enviar plantilla de prueba
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($puedeEnviarWhatsapp): ?>
                        <form data-whatsapp-send-form>
                            <input
                                type="hidden"
                                name="conversacion_id"
                                value="<?= (int)$conversacionSeleccionada['id'] ?>">

                            <textarea
                                class="form-control"
                                name="mensaje"
                                rows="2"
                                maxlength="4096"
                                placeholder="<?= $ventanaServicioAbierta ? 'Escribe un mensaje…' : 'La ventana está cerrada' ?>"
                                <?= $ventanaServicioAbierta ? '' : 'disabled' ?>
                                data-whatsapp-message-input></textarea>

                            <button
                                type="submit"
                                class="btn whatsapp-send-button"
                                <?= $ventanaServicioAbierta ? '' : 'disabled' ?>
                                data-whatsapp-send-button
                                aria-label="Enviar mensaje">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="whatsapp-readonly-note">
                            <i class="bi bi-eye"></i>
                            Tu rol puede consultar conversaciones, pero no enviar mensajes.
                        </div>
                    <?php endif; ?>
                </footer>
            <?php else: ?>
                <div class="whatsapp-no-conversation">
                    <span><i class="bi bi-whatsapp"></i></span>
                    <strong>Selecciona una conversación</strong>
                    <p>
                        Cuando abras WhatsApp desde un Aliado o llegue un mensaje, aparecerá en esta bandeja.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <aside class="whatsapp-context">
            <?php if ($conversacionSeleccionada): ?>
                <div class="whatsapp-context-heading">
                    <span>CONTEXTO</span>
                    <strong>Información comercial</strong>
                </div>

                <dl class="whatsapp-context-list">
                    <div>
                        <dt>Canal</dt>
                        <dd>
                            <strong><?= $texto($conversacionSeleccionada['cuenta_nombre'] ?? 'WhatsApp') ?></strong>
                            <span><?= $texto($conversacionSeleccionada['cuenta_numero'] ?? '') ?></span>
                        </dd>
                    </div>

                    <div>
                        <dt>Responsable</dt>
                        <dd>
                            <strong>
                                <?= $texto(
                                    trim((string)($conversacionSeleccionada['responsable_nombre'] ?? '')) !== ''
                                        ? $conversacionSeleccionada['responsable_nombre']
                                        : 'Sin asignar'
                                ) ?>
                            </strong>
                        </dd>
                    </div>

                    <?php if (!empty($conversacionSeleccionada['aliado_seguimiento_id'])): ?>
                        <div>
                            <dt>Institución aliada</dt>
                            <dd>
                                <strong><?= $texto($conversacionSeleccionada['aliado_nombre'] ?? 'Institución') ?></strong>
                                <span>
                                    <?= $texto(trim(
                                        (string)($conversacionSeleccionada['aliado_municipio'] ?? '') .
                                        (
                                            !empty($conversacionSeleccionada['aliado_municipio'])
                                                ? ', '
                                                : ''
                                        ) .
                                        (string)($conversacionSeleccionada['aliado_estado'] ?? '')
                                    )) ?>
                                </span>
                            </dd>
                        </div>

                        <a
                            class="btn whatsapp-context-link"
                            href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=detalle&id=<?= (int)$conversacionSeleccionada['aliado_seguimiento_id'] ?>">
                            <i class="bi bi-folder2-open"></i>
                            Abrir expediente
                        </a>
                    <?php endif; ?>

                    <div>
                        <dt>Ventana de servicio</dt>
                        <dd>
                            <strong>
                                <?= $ventanaServicioAbierta ? 'Abierta' : 'Cerrada' ?>
                            </strong>
                            <span>
                                <?= $ventanaServicioAbierta
                                    ? 'Hasta ' . $texto($fechaHora($conversacionSeleccionada['ventana_servicio_hasta'] ?? ''))
                                    : 'Requiere plantilla aprobada para iniciar' ?>
                            </span>
                        </dd>
                    </div>
                </dl>
            <?php else: ?>
                <div class="whatsapp-context-empty">
                    <i class="bi bi-layout-sidebar-inset-reverse"></i>
                    <span>El contexto de la conversación aparecerá aquí.</span>
                </div>
            <?php endif; ?>
        </aside>
    </section>
</section>

<?php if ($puedeGestionarCuentas): ?>
    <div
        class="modal fade"
        id="modalWhatsAppCanales"
        tabindex="-1"
        aria-labelledby="modalWhatsAppCanalesTitulo"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content whatsapp-channel-modal">
                <div class="modal-header">
                    <div>
                        <span class="whatsapp-eyebrow">CONFIGURACIÓN</span>
                        <h2 class="modal-title" id="modalWhatsAppCanalesTitulo">
                            Canales de WhatsApp
                        </h2>
                        <p>
                            Registra el número de prueba de Meta o líneas empresariales y asígnalas a usuarios.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
                </div>

                <div class="modal-body whatsapp-channel-modal-body">
                    <section class="whatsapp-channel-list">
                        <div class="whatsapp-channel-list-heading">
                            <div>
                                <strong>Canales registrados</strong>
                                <span>
                                    El token y App Secret nunca se almacenan aquí.
                                </span>
                            </div>
                        </div>

                        <?php if (empty($cuentasAdministracion)): ?>
                            <div class="whatsapp-channel-empty">
                                <i class="bi bi-sim"></i>
                                Aún no hay canales registrados.
                            </div>
                        <?php else: ?>
                            <?php foreach ($cuentasAdministracion as $cuenta): ?>
                                <article class="whatsapp-channel-item">
                                    <div>
                                        <strong><?= $texto($cuenta['nombre']) ?></strong>
                                        <span>
                                            <?= $texto($cuenta['numero_mostrado']) ?> ·
                                            <?= $texto($cuenta['tipo']) ?>
                                        </span>
                                        <small>
                                            <?= !empty($cuenta['usuario_nombre'])
                                                ? 'Asignado a ' . $texto($cuenta['usuario_nombre'])
                                                : 'Canal compartido' ?>
                                        </small>
                                    </div>

                                    <div class="whatsapp-channel-badges">
                                        <?php if ((int)$cuenta['es_predeterminada'] === 1): ?>
                                            <span>Predeterminado</span>
                                        <?php endif; ?>

                                        <span class="<?= (int)$cuenta['activo'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                            <?= (int)$cuenta['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                    <section class="whatsapp-channel-editor">
                        <div class="whatsapp-channel-editor-heading">
                            <strong>Agregar canal</strong>
                            <span>
                                Para el MVP puedes usar el Phone Number ID del número de prueba de Meta.
                            </span>
                        </div>

                        <form data-whatsapp-channel-form>
                            <input type="hidden" name="cuenta_id" value="0">

                            <div class="whatsapp-channel-form-grid">
                                <div>
                                    <label class="form-label" for="whatsapp_canal_nombre">
                                        Nombre del canal
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="whatsapp_canal_nombre"
                                        name="nombre"
                                        maxlength="100"
                                        placeholder="Ej. WhatsApp Pruebas"
                                        required>
                                </div>

                                <div>
                                    <label class="form-label" for="whatsapp_phone_number_id">
                                        Phone Number ID
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="whatsapp_phone_number_id"
                                        name="phone_number_id"
                                        maxlength="80"
                                        autocomplete="off"
                                        required>
                                </div>

                                <div>
                                    <label class="form-label" for="whatsapp_numero_mostrado">
                                        Número visible
                                    </label>
                                    <input
                                        type="tel"
                                        class="form-control"
                                        id="whatsapp_numero_mostrado"
                                        name="numero_mostrado"
                                        maxlength="40"
                                        placeholder="+52 ..."
                                        required>
                                </div>

                                <div>
                                    <label class="form-label" for="whatsapp_canal_usuario">
                                        Usuario asignado
                                    </label>
                                    <select
                                        class="form-select"
                                        id="whatsapp_canal_usuario"
                                        name="usuario_id">
                                        <option value="0">Canal compartido</option>
                                        <?php foreach ($usuariosWhatsapp as $usuario): ?>
                                            <option value="<?= (int)$usuario['id'] ?>">
                                                <?= $texto(
                                                    trim(
                                                        (string)$usuario['nombre'] . ' ' .
                                                        (string)$usuario['apellidos']
                                                    ) .
                                                    ' · ' .
                                                    (string)$usuario['rol']
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label" for="whatsapp_canal_tipo">
                                        Tipo
                                    </label>
                                    <select
                                        class="form-select"
                                        id="whatsapp_canal_tipo"
                                        name="tipo">
                                        <option value="PRUEBA">Prueba de Meta</option>
                                        <option value="EMPRESARIAL">Empresarial</option>
                                    </select>
                                </div>

                                <div class="whatsapp-channel-options">
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="es_predeterminada"
                                            value="1">
                                        <span>
                                            <strong>Canal predeterminado</strong>
                                            <small>
                                                Se usa cuando un usuario no tiene línea propia.
                                            </small>
                                        </span>
                                    </label>

                                    <label>
                                        <input
                                            type="checkbox"
                                            name="activo"
                                            value="1"
                                            checked>
                                        <span>
                                            <strong>Activo</strong>
                                            <small>Disponible para conversaciones.</small>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="whatsapp-channel-form-status d-none" data-whatsapp-channel-status></div>

                            <div class="whatsapp-channel-form-actions">
                                <button
                                    type="submit"
                                    class="btn whatsapp-primary-button">
                                    <i class="bi bi-check2-circle"></i>
                                    Guardar canal
                                </button>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="modal-footer whatsapp-webhook-footer">
                    <div>
                        <strong>Callback URL para Meta</strong>
                        <code><?= $texto($callbackUrl) ?></code>
                    </div>

                    <button
                        type="button"
                        class="btn whatsapp-secondary-button"
                        data-copy-text="<?= $texto($callbackUrl) ?>">
                        <i class="bi bi-copy"></i>
                        Copiar URL
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
