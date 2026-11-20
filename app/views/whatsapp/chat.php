                <header class="whatsapp-chat-header">
                    <div class="whatsapp-chat-contact">
                        <span class="whatsapp-avatar is-large">
                            <?= $texto(strtoupper(substr(
                                trim((string)(
                                    $conversacionSeleccionada['prospecto_nombre'] ??
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
                                    $conversacionSeleccionada['prospecto_nombre'] ??
                                    $conversacionSeleccionada['aliado_nombre'] ??
                                    $conversacionSeleccionada['nombre_contacto'] ??
                                    $conversacionSeleccionada['telefono_contacto']
                                ) ?>
                            </strong>
                            <?php $chatEtiqueta=$conversacionSeleccionada;require __DIR__.'/ventas_etiqueta.php'; ?>
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
                                Si la ventana está cerrada, se requiere una plantilla aprobada para iniciar el contacto.
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
                                                title="<?= $texto($estadoMensaje) ?>"
                                                data-message-status-icon>
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
                    <div class="whatsapp-window-notice <?= $ventanaServicioAbierta ? 'd-none' : '' ?>" data-whatsapp-window-notice>
                        <i class="bi bi-info-circle"></i>
                        <div>
                            <strong>La ventana de atención está cerrada.</strong>
                            <span>
                                Para iniciar el contacto se requiere una plantilla aprobada. Cuando responda, se habilitará texto libre durante 24 horas.
                            </span>
                        </div>

                        <?php if (!empty($puedePlantillaPrueba)): ?>
                            <button
                                type="button"
                                class="btn whatsapp-template-button"
                                data-whatsapp-test-template>
                                <i class="bi bi-send-check"></i>
                                Enviar plantilla de prueba
                            </button>
                        <?php endif; ?>
                        <?php if (WhatsAppContextoService::esVentas() && $puedeEnviarWhatsapp): ?>
                            <?php require __DIR__.'/ventas_inicio.php'; ?>
                        <?php endif; ?>
                    </div>

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
                        <div class="whatsapp-composer-feedback d-none" data-whatsapp-composer-feedback></div>
                    <?php else: ?>
                        <div class="whatsapp-readonly-note">
                            <i class="bi bi-eye"></i>
                            <?= empty($estadoConfiguracionWhatsapp['lista'])?'El envío se habilitará cuando termine la configuración del canal.':'Tu permiso permite consultar esta conversación, pero no responderla.' ?>
                        </div>
                    <?php endif; ?>
                </footer>
