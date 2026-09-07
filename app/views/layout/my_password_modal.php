<div
    class="modal fade"
    id="modalCambiarMiPassword"
    tabindex="-1"
    aria-labelledby="modalCambiarMiPasswordTitulo"
    aria-hidden="true"
    data-my-password-modal
    data-password-update-url="<?= BASE_URL ?>index.php?controller=usuario&action=actualizarMiPassword">
    <div class="modal-dialog modal-dialog-centered system-confirm-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCambiarMiPasswordTitulo">Cambiar contraseña</h5>
                    <p class="system-form-modal-subtitle">Ingresa y confirma tu nueva contraseña.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form data-my-password-form>
                <div class="modal-body">
                    <div class="alert alert-danger login-alert d-none" role="alert" data-my-password-alert></div>

                    <div class="mb-3">
                        <label class="form-label login-label" for="mi_password_nueva">Nueva contraseña</label>
                        <div class="input-group login-input-group">
                            <input
                                type="password"
                                class="form-control system-form-control"
                                id="mi_password_nueva"
                                name="password_nueva"
                                autocomplete="new-password"
                                minlength="8"
                                required>
                            <button type="button" class="btn password-toggle password-view-toggle" aria-label="Mostrar contraseña">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label login-label" for="mi_password_confirmacion">Confirmar nueva contraseña</label>
                        <div class="input-group login-input-group">
                            <input
                                type="password"
                                class="form-control system-form-control"
                                id="mi_password_confirmacion"
                                name="confirmar_password"
                                autocomplete="new-password"
                                minlength="8"
                                required>
                            <button type="button" class="btn password-toggle password-view-toggle" aria-label="Mostrar contraseña">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save" data-my-password-submit>Cambiar contraseña</button>
                </div>
            </form>
        </div>
    </div>
</div>
