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
                            <div class="mail-signature-box border rounded-3 bg-light">
                                <div class="mail-signature-heading d-flex align-items-start justify-content-between gap-2 flex-wrap">
                                    <div>
                                        <label class="form-label login-label mb-0" for="mi_perfil_firma">Firma de correo</label>
                                        <div class="mail-signature-description text-muted">
                                            Se agregará automáticamente a los correos enviados desde el sistema.
                                        </div>
                                    </div>
                                    <span class="mail-signature-badge badge text-bg-light border">PNG · JPG · WEBP</span>
                                </div>

                                <div
                                    class="mail-signature-preview bg-white border rounded-3 d-flex align-items-center justify-content-center"
                                    data-mail-signature-preview>
                                    <span class="text-muted small">Sin firma configurada</span>
                                </div>

                                <input
                                    class="form-control system-form-control mail-signature-file"
                                    id="mi_perfil_firma"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    data-mail-signature-file>

                                <div class="mail-signature-actions d-flex gap-2 flex-wrap">
                                    <button
                                        type="button"
                                        class="btn btn-system-save btn-sm mail-signature-action"
                                        data-mail-signature-save
                                        disabled>
                                        <i class="bi bi-upload me-1"></i>
                                        Guardar firma
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-system-light btn-sm mail-signature-action"
                                        data-mail-signature-delete
                                        disabled>
                                        <i class="bi bi-trash me-1"></i>
                                        Quitar firma
                                    </button>
                                </div>

                                <div class="mail-signature-status text-muted" data-mail-signature-status>
                                    Consultando firma...
                                </div>
                                <div class="mail-signature-help text-muted">
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

<style>
#modalMiPerfil .mail-signature-box {
    padding: .8rem .9rem;
}

#modalMiPerfil .mail-signature-heading {
    margin-bottom: .55rem;
}

#modalMiPerfil .mail-signature-description,
#modalMiPerfil .mail-signature-status,
#modalMiPerfil .mail-signature-help {
    font-size: .76rem;
    line-height: 1.35;
}

#modalMiPerfil .mail-signature-badge {
    font-size: .68rem;
    font-weight: 700;
    padding: .3rem .5rem;
}

#modalMiPerfil .mail-signature-preview {
    min-height: 54px;
    max-height: 104px;
    padding: .35rem .55rem;
    margin-bottom: .5rem;
    overflow: hidden;
}

#modalMiPerfil .mail-signature-file {
    min-height: 36px;
    font-size: .78rem;
    padding-top: .32rem;
    padding-bottom: .32rem;
}

#modalMiPerfil .mail-signature-file::file-selector-button {
    font-size: .78rem;
}

#modalMiPerfil .mail-signature-actions {
    margin-top: .5rem;
}

#modalMiPerfil .mail-signature-action {
    min-height: 34px;
    padding: .36rem .7rem !important;
    font-size: .78rem !important;
    line-height: 1.15;
    font-weight: 600;
}

#modalMiPerfil .mail-signature-status {
    margin-top: .5rem;
}

#modalMiPerfil .mail-signature-help {
    margin-top: .18rem;
}
</style>
