<div
    class="modal fade"
    id="modalMiPerfil"
    tabindex="-1"
    aria-labelledby="modalMiPerfilTitulo"
    aria-hidden="true"
    data-my-profile-modal
    data-profile-load-url="<?= BASE_URL ?>index.php?controller=usuario&action=obtenerMiPerfil"
    data-profile-update-url="<?= BASE_URL ?>index.php?controller=usuario&action=actualizarMiPerfil">
    <div class="modal-dialog modal-dialog-centered system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalMiPerfilTitulo">Mi perfil</h5>
                    <p class="system-form-modal-subtitle">Actualiza tu información personal</p>
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
