<?php // Presentación de avance; datos y autorizaciones se resuelven en el servidor. ?>
<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/aliados_avance.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../../public/css/aliados_avance.css'),0,16) ?>">
<div class="modal fade" id="modalAliadoAvance" tabindex="-1" aria-labelledby="modalAliadoAvanceTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <div class="modal-content aliados-modal">
            <div class="modal-header">
                <div><span class="aliados-eyebrow">DIFUSIÓN POR CONVOCATORIA</span><h2 class="modal-title" id="modalAliadoAvanceTitulo">Avance y respuestas</h2><p data-avance-context>Consultando convocatorias…</p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="aliados-avance-intro"><i class="bi bi-info-circle" aria-hidden="true"></i><p>Una convocatoria cuenta como compartida cuando se envía por correo o confirmas que la compartiste por WhatsApp. La respuesta de la institución se registra por separado.</p></div>
                <div class="aliados-avance-totals" data-avance-totals aria-live="polite"></div>
                <label class="aliados-avance-filter"><input type="checkbox" data-avance-only-pending> Mostrar sólo pendientes de compartir</label>
                <div class="aliados-avance-list" data-avance-list></div>
                <form class="aliados-respuesta-form" data-avance-response-form hidden>
                    <h3 data-avance-response-title>Registrar respuesta</h3>
                    <p>Actualiza este apartado cuando tengas información de la institución. No modifica el avance de envío.</p>
                    <input type="hidden" name="convocatoria_id"><input type="hidden" name="version">
                    <div class="aliados-respuesta-fields">
                        <div><label class="form-label" for="aliado_respuesta_estado">Respuesta de la institución</label><select class="form-select" id="aliado_respuesta_estado" name="estado" required></select></div>
                        <div><label class="form-label" for="aliado_respuesta_nota">Nota del contacto realizado</label><textarea class="form-control" id="aliado_respuesta_nota" name="nota" rows="3" minlength="3" maxlength="1000" required></textarea></div>
                    </div>
                    <div class="aliados-respuesta-actions"><button type="button" class="btn btn-system-light" data-avance-response-cancel>Cancelar</button><button type="submit" class="btn btn-system-save"><i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar respuesta</button></div>
                </form>
                <p class="aliados-avance-status" data-avance-status role="status" aria-live="polite"></p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button></div>
        </div>
    </div>
</div>
<script defer src="<?= $texto(BASE_URL) ?>public/javascript/aliados_avance.js?v=<?= substr(hash_file('sha256',__DIR__.'/../../../../public/javascript/aliados_avance.js'),0,16) ?>"></script>
