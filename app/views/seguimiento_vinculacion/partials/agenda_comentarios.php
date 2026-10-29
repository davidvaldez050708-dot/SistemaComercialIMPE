<?php // Integración independiente de las acciones de reunión. ?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/agenda_comentarios.css?v=<?= filemtime(ROOT_PATH . '/public/css/agenda_comentarios.css') ?>">
<p class="agenda-comments-status" data-agenda-comments-status role="status" hidden></p>
<aside class="agenda-side-card agenda-comments-panel" data-agenda-comments-panel hidden aria-label="Detalle del día">
    <header class="agenda-comments-heading">
        <h3 data-agenda-comments-date></h3>
        <button type="button" data-agenda-comments-close aria-label="Cerrar detalle del día"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>
    <div class="agenda-comments-tabs" role="tablist" aria-label="Detalle de agenda">
        <button type="button" role="tab" id="agendaComentariosTabReuniones" aria-controls="agendaComentariosReuniones" aria-selected="false" tabindex="-1" data-agenda-comments-tab="reuniones">Reuniones <span data-agenda-comments-meeting-count>(0)</span></button>
        <button type="button" role="tab" id="agendaComentariosTabComentarios" aria-controls="agendaComentariosLista" aria-selected="true" data-agenda-comments-tab="comentarios">Comentarios <span data-agenda-comments-count>(0)</span></button>
    </div>
    <div id="agendaComentariosReuniones" role="tabpanel" aria-labelledby="agendaComentariosTabReuniones" data-agenda-comments-meetings hidden></div>
    <div id="agendaComentariosLista" role="tabpanel" aria-labelledby="agendaComentariosTabComentarios" data-agenda-comments-list></div>
    <button type="button" class="btn btn-system-save agenda-comments-new" data-agenda-comments-new><i class="bi bi-chat-left-text" aria-hidden="true"></i> Agregar comentario</button>
    <p class="agenda-comments-hint">Los comentarios compartidos son visibles para quienes tienen Agenda. Los privados sólo los ves tú.</p>
</aside>
<dialog class="agenda-comment-editor" data-agenda-comment-editor aria-labelledby="agendaComentarioTitulo">
    <form data-agenda-comment-form novalidate>
        <header class="agenda-comments-heading">
            <div><h3 id="agendaComentarioTitulo">Agregar comentario</h3><p data-agenda-comment-date></p></div>
            <button type="button" data-agenda-comment-cancel aria-label="Cerrar comentario"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <label class="visually-hidden" for="agendaComentarioTexto">Comentario</label>
        <textarea id="agendaComentarioTexto" name="comentario" rows="4" maxlength="500" placeholder="Escribe un comentario…" aria-describedby="agendaComentarioContador" required></textarea>
        <p id="agendaComentarioContador" class="agenda-comment-counter" data-agenda-comment-counter>0 / 250</p>
        <div class="agenda-comment-recipient">
            <label for="agendaComentarioDestinatario" class="agenda-comment-hour-label">Notificar a</label>
            <select id="agendaComentarioDestinatario" name="destinatario_id" class="form-select system-form-control" disabled aria-describedby="agendaComentarioDestinatarioAyuda">
                <option value="">Todos los usuarios con Agenda</option>
            </select>
            <p class="agenda-comments-hint" id="agendaComentarioDestinatarioAyuda">Elige quién recibirá el aviso y, si indicas hora, el recordatorio. El comentario compartido seguirá visible en Agenda.</p>
        </div>
        <label for="agendaComentarioHora" class="agenda-comment-hour-label">Hora <span>(opcional)</span></label>
        <div class="agenda-comment-hour-row">
            <input id="agendaComentarioHora" name="hora" class="form-control system-form-control" type="time" lang="es-MX" step="60" disabled aria-describedby="agendaComentarioHoraAyuda">
            <label class="agenda-comment-switch"><input type="checkbox" role="switch" name="sin_hora" checked> Sin hora</label>
        </div>
        <p class="agenda-comments-hint" id="agendaComentarioHoraAyuda">Pulsa el reloj para elegir hora, minutos y a. m./p. m. Con hora, recibirás el recordatorio habitual. Sin hora, sólo un aviso al añadirlo.</p>
        <div class="agenda-comment-privacy">
            <label class="agenda-comment-switch"><input type="checkbox" role="switch" name="privado" aria-describedby="agendaComentarioPrivadoAyuda"> Comentario privado <strong data-agenda-comment-private-value>No</strong></label>
            <p class="agenda-comments-hint" id="agendaComentarioPrivadoAyuda">Compartido con los usuarios que tienen acceso a Agenda.</p>
        </div>
        <p class="agenda-comment-error" data-agenda-comment-error role="alert" hidden></p>
        <footer><button type="button" class="btn btn-system-cancel" data-agenda-comment-cancel>Cancelar</button><button type="submit" class="btn btn-system-save" data-agenda-comment-save>Guardar</button></footer>
    </form>
</dialog>
<dialog class="agenda-comment-delete" data-agenda-comment-delete aria-labelledby="agendaComentarioBorrarTitulo">
    <h3 id="agendaComentarioBorrarTitulo">Eliminar comentario</h3>
    <p>Se retirará de la agenda y dejará de generar avisos.</p>
    <p class="agenda-comment-error" data-agenda-comment-delete-error role="alert" hidden></p>
    <footer><button type="button" class="btn btn-system-cancel" data-agenda-comment-delete-cancel>Cancelar</button><button type="button" class="btn btn-system-save" data-agenda-comment-delete-confirm>Eliminar</button></footer>
</dialog>
<script src="<?= BASE_URL ?>public/javascript/agenda_comentarios.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/agenda_comentarios.js') ?>" defer></script>
