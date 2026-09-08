<div
    class="modal fade"
    id="modalMiPerfil"
    tabindex="-1"
    aria-labelledby="modalMiPerfilTitulo"
    aria-hidden="true"
    data-my-profile-modal
    data-profile-load-url="<?= BASE_URL ?>index.php?controller=usuario&action=obtenerMiPerfil"
    data-profile-update-url="<?= BASE_URL ?>index.php?controller=usuario&action=actualizarMiPerfil"
    data-signature-status-url="<?= BASE_URL ?>index.php?controller=firmaCorreo&action=estado"
    data-signature-save-url="<?= BASE_URL ?>index.php?controller=firmaCorreo&action=guardar"
    data-signature-delete-url="<?= BASE_URL ?>index.php?controller=firmaCorreo&action=eliminar">
    <div class="modal-dialog modal-dialog-centered system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalMiPerfilTitulo">Mi perfil</h5>
                    <p class="system-form-modal-subtitle">Actualiza tu información personal y tu firma de correo</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form enctype="multipart/form-data" data-my-profile-form>
                <div class="modal-body">
                    <div class="alert login-alert d-none" role="alert" data-my-profile-alert></div>

                    <div
                        class="data-photo-preview data-photo-preview-round mx-auto mb-3"
                        data-my-profile-preview
                        data-profile-photo
                        data-photo-url=""
                        data-photo-name="Usuario"
                        data-photo-role="Usuario">
                        <i class="bi bi-person"></i>
                    </div>

                    <div class="system-form-grid">
                        <div>
                            <label class="form-label login-label" for="mi_perfil_nombre">Nombre</label>
                            <input class="form-control system-form-control" id="mi_perfil_nombre" name="nombre" required>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_apellidos">Apellidos</label>
                            <input class="form-control system-form-control" id="mi_perfil_apellidos" name="apellidos" required>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_telefono">Teléfono</label>
                            <input class="form-control system-form-control" id="mi_perfil_telefono" name="telefono">
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_correo">Correo</label>
                            <input class="form-control system-form-control" id="mi_perfil_correo" name="correo" type="email" required>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_foto">Foto de perfil</label>
                            <input class="form-control system-form-control" id="mi_perfil_foto" name="foto_perfil" type="file" accept="image/jpeg,image/png,image/webp" data-my-profile-photo>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_usuario">Usuario</label>
                            <input class="form-control system-form-control" id="mi_perfil_usuario" readonly data-my-profile-username>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_rol">Rol</label>
                            <input class="form-control system-form-control" id="mi_perfil_rol" readonly data-my-profile-role>
                        </div>
                        <div>
                            <label class="form-label login-label" for="mi_perfil_estado">Estado</label>
                            <input class="form-control system-form-control" id="mi_perfil_estado" readonly data-my-profile-status>
                        </div>

                        <div class="system-form-grid-full">
                            <div class="border rounded-3 p-3 bg-light">
                                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-2">
                                    <div>
                                        <label class="form-label login-label mb-1" for="mi_perfil_firma">Firma de correo</label>
                                        <div class="text-muted small">
                                            Se agregará automáticamente a los correos que envíes desde el sistema.
                                        </div>
                                    </div>
                                    <span class="badge text-bg-light border">PNG · JPG · WEBP</span>
                                </div>

                                <div
                                    class="bg-white border rounded-3 p-2 mb-2 d-flex align-items-center justify-content-center"
                                    style="min-height:72px;"
                                    data-mail-signature-preview>
                                    <span class="text-muted small">Sin firma configurada</span>
                                </div>

                                <input
                                    class="form-control system-form-control"
                                    id="mi_perfil_firma"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    data-mail-signature-file>

                                <div class="d-flex gap-2 flex-wrap mt-2">
                                    <button
                                        type="button"
                                        class="btn btn-system-save btn-sm"
                                        data-mail-signature-save
                                        disabled>
                                        <i class="bi bi-upload me-1"></i>
                                        Guardar firma
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-system-light btn-sm"
                                        data-mail-signature-delete
                                        disabled>
                                        <i class="bi bi-trash me-1"></i>
                                        Quitar firma
                                    </button>
                                </div>

                                <div class="form-text mt-2" data-mail-signature-status>
                                    Consultando firma...
                                </div>
                                <div class="form-text">
                                    Máximo 3 MB. Si cambias tu correo, guarda primero el perfil y después vuelve a cargar la firma.
                                </div>
                            </div>
                        </div>

                        <div class="system-form-grid-full">
                            <label class="form-label login-label" for="mi_perfil_ultimo_acceso">Último acceso</label>
                            <input class="form-control system-form-control" id="mi_perfil_ultimo_acceso" readonly data-my-profile-last-access>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save" data-my-profile-submit>Actualizar perfil</button>
                </div>
            </form>
        </div>
    </div>
</div>
