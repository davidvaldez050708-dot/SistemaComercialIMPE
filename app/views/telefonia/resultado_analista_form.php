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
                    <?php if(!empty($esDevolucion)): ?><input type="hidden" name="intento_id"><?php endif; ?>
                    <input type="hidden" name="pbx_call_id" data-incoming-pbx>
                    <input type="hidden" name="sdk_session_id" data-incoming-sdk-id>
                    <input type="hidden" name="sdk_reference" data-incoming-sdk-reference>
                    <p class="text-muted small" data-incoming-draft-status role="status" hidden></p>
                    <div class="linkage-incoming-summary">
                        <span><i class="bi bi-telephone-inbound" aria-hidden="true"></i> <strong data-incoming-number>—</strong></span>
                        <span><i class="bi bi-clock" aria-hidden="true"></i> <strong data-incoming-duration>00:00</strong></span>
                    </div>
                    <!-- La indicación técnica WebRTC queda en el historial.
                         La confirmación manual y su protección permanecen abajo. -->
                    <div data-incoming-sdk-notice hidden></div>
                    <div class="mb-3">
                        <label class="form-label">¿A qué institución corresponde?</label>
                        <select data-native-select name="decision" class="form-select" data-incoming-decision required>
                            <option value="existente">Institución con seguimiento existente</option>
                            <?php if (!empty($esDevolucion)): ?><option value="identificada">Institución identificada sin crear expediente</option><?php endif; ?>
                            <option value="nueva">Institución nueva · crear expediente</option>
                            <option value="sin_identificar">No se pudo identificar</option>
                        </select>
                    </div>
                    <div data-incoming-existing>
                        <div class="linkage-incoming-confirmed" data-incoming-confirmed hidden>
                            <span class="linkage-incoming-confirmed-icon" aria-hidden="true">
                                <i class="bi bi-building-check"></i>
                            </span>
                            <div class="linkage-incoming-confirmed-copy">
                                <span>Institución identificada por Recepción</span>
                                <strong data-incoming-confirmed-name></strong>
                                <small>Expediente de tu cartera. Confirma los datos durante la conversación.</small>
                            </div>
                            <button type="button" class="btn linkage-incoming-change-record"
                                data-incoming-change-expediente>Cambiar</button>
                        </div>
                        <div data-incoming-existing-search>
                            <label class="form-label" for="incomingLookup">Buscar expediente</label>
                            <input id="incomingLookup" class="form-control" type="search" data-incoming-lookup
                                placeholder="Nombre o teléfono" autocomplete="off">
                            <select data-native-select class="form-select mt-2" name="seguimiento_id" data-incoming-followup>
                                <option value="">Selecciona un expediente</option>
                            </select>
                        </div>
                    </div>
                    <?php if (!empty($esDevolucion)): ?><div class="mb-3" data-incoming-identified hidden><label class="form-label" for="callbackIdentifiedName">Nombre de la institución</label><input id="callbackIdentifiedName" name="institucion_nombre" maxlength="150" class="form-control"><small class="system-form-modal-subtitle d-block mt-2">Conserva la identificación en esta llamada sin crear un expediente.</small></div><?php endif; ?>
                    <?php if(!empty($esDevolucion)): ?><div class="system-form-modal-subtitle mb-3" data-incoming-callback-answer hidden></div><?php endif; ?>
                    <div data-incoming-new hidden>
                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label class="form-label" for="incomingName">Nombre de la institución</label>
                                <input id="incomingName" name="nombre_entidad" type="text" maxlength="180" class="form-control">
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label" for="incomingType">Tipo de entidad</label>
                                <select data-native-select id="incomingType" name="tipo_entidad" class="form-select">
                                    <option value="INSTITUCION">Institución</option>
                                    <option value="SECRETARIA">Secretaría</option>
                                    <option value="MUNICIPIO">Municipio</option>
                                    <option value="EMPRESA">Empresa</option>
                                    <option value="ORGANIZACION">Organización</option>
                                    <option value="OTRO">Otro</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingPhoneCaptured">Teléfono de contacto</label>
                                <input id="incomingPhoneCaptured" name="telefono_capturado" type="tel"
                                    maxlength="40" class="form-control" autocomplete="off"
                                    placeholder="Número proporcionado en la llamada">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingEmailCaptured">Correo institucional</label>
                                <input id="incomingEmailCaptured" name="correo_capturado" type="email"
                                    maxlength="160" class="form-control" autocomplete="off"
                                    placeholder="contacto@institucion.mx">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="incomingState">Territorio asignado</label>
                                <select data-native-select id="incomingState" name="estado_id" class="form-select">
                                    <option value="">Selecciona un estado</option>
                                    <?php foreach ($estadosEntrantes as $estado): ?>
                                        <option value="<?= (int)$estado['id'] ?>"><?= $esc($estado['nombre'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="incomingMunicipality">Municipio (opcional)</label>
                                <select data-native-select id="incomingMunicipality" name="municipio_id" class="form-select"
                                    data-incoming-municipality data-municipality-url="<?= BASE_URL ?>prueba_telefonia/api/municipios_llamada.php" disabled>
                                    <option value="">Selecciona primero un estado</option>
                                </select>
                                <small class="system-form-modal-subtitle d-block mt-2" data-municipality-status role="status"></small>
                            </div>
                        </div>
                    </div>
                    <div data-incoming-results>
                        <hr>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingResult">Resultado</label>
                                <select data-native-select id="incomingResult" class="form-select" name="resultado">
                                    <option value="">Seleccionar resultado</option>
                                    <option value="CONTACTO_CORRECTO">Contacto correcto</option>
                                    <option value="CONTACTO_REFERIDO">Me proporcionaron otro contacto</option>
                                    <option value="BUZON_VOZ">Buzón de voz</option>
                                    <option value="FUERA_SERVICIO">Fuera del área / fuera de servicio</option>
                                    <option value="SIN_RESPUESTA">Sin respuesta</option>
                                    <option value="OCUPADO">Ocupado</option>
                                    <option value="NUMERO_INCORRECTO">Número incorrecto</option>
                                    <option value="SOLICITO_INFORMACION">Solicitó información</option>
                                    <option value="SOLICITO_LLAMAR_DESPUES">Solicitó volver a llamar</option>
                                    <option value="NO_INTERESADO">No interesado</option>
                                    <option value="OTRO">Otro</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingPerson">Persona que atendió</label>
                                <input id="incomingPerson" name="persona_atendio" maxlength="140" class="form-control">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="incomingNext">Próxima acción</label>
                                <select data-native-select id="incomingNext" name="proxima_accion" class="form-select">
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
                    <div class="linkage-call-verification-box mt-3" data-incoming-referred hidden>
                        <strong>Contacto proporcionado por la institución</strong>
                        <p class="text-muted small">Estos datos se incorporarán al expediente. La persona referida puede ser distinta de quien atendió.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6"><label class="form-label" for="incomingReferredName">Nombre del contacto</label>
                                <input id="incomingReferredName" name="nuevo_contacto_nombre" maxlength="140" class="form-control"></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="incomingReferredRole">Cargo o área</label>
                                <input id="incomingReferredRole" name="nuevo_contacto_cargo" maxlength="150" class="form-control"></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="incomingReferredPhone">Teléfono proporcionado</label>
                                <input id="incomingReferredPhone" name="nuevo_telefono_contacto" type="tel" maxlength="40" class="form-control"></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="incomingReferredEmail">Correo proporcionado</label>
                                <input id="incomingReferredEmail" name="nuevo_correo_contacto" type="email" maxlength="160" class="form-control"></div>
                        </div>
                    </div>
                    <div class="mt-3" data-incoming-sdk-confirm hidden>
                        <label><input type="checkbox" name="confirmacion_manual_sdk" value="1">
                            Confirmo que atendí esta llamada y conversé con la persona indicada
                        </label>
                    </div>
                    <div class="linkage-incoming-evidence mt-3" data-incoming-evidence hidden>
                        <strong>Datos verificados durante esta llamada</strong>
                        <p>Confirma lo que verificaste. Cuenta como máximo una institución por día; si falta la confirmación de la llamada, queda pendiente de acreditar.</p>
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
