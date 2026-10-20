<style><?php echo file_get_contents(ROOT_PATH.'/public/css/telefonia_disponibilidad.css'); ?></style>
<dialog class="impe-availability-dialog system-form-modal" data-availability-incoming aria-labelledby="availabilityTitle">
  <div class="system-form-modal-header"><h2 id="availabilityTitle">¿Puedes atender esta llamada?</h2><p class="system-form-modal-subtitle" data-availability-caller></p></div>
  <div class="system-form-modal-body"><p>Recepción sigue conversando con la persona. Al aceptar, podrá transferirte la llamada.</p><div class="impe-availability-summary" data-availability-countdown></div><button type="button" class="persistent-phone-action" data-availability-audio hidden><i class="bi bi-volume-up" aria-hidden="true"></i> Activar avisos sonoros</button><p data-availability-feedback role="status"></p></div>
  <div class="system-form-modal-footer"><button type="button" class="persistent-phone-action is-primary" data-availability-pending hidden>Registrar resultado pendiente</button><button type="button" class="persistent-phone-action" data-availability-decline>No disponible</button><button type="button" class="persistent-phone-action is-primary" data-availability-accept>Puedo atender</button></div>
</dialog>
<dialog class="impe-availability-dialog system-form-modal" data-availability-callback aria-labelledby="callbackTitle">
 <form>
  <div class="system-form-modal-header"><h2 id="callbackTitle">Solicitar devolución de llamada</h2><p class="system-form-modal-subtitle">Confirma los datos con la persona antes de guardar.</p></div>
  <div class="system-form-modal-body">
   <label for="callbackName">Nombre del contacto</label><input id="callbackName" name="contacto" maxlength="100" required autocomplete="off">
   <label for="callbackPhone">Teléfono para devolver la llamada</label><input id="callbackPhone" name="telefono" type="tel" maxlength="40" required>
   <label for="callbackNote">Institución y motivo de la llamada</label><textarea id="callbackNote" name="nota" rows="3" maxlength="500"></textarea>
   <p data-callback-feedback role="status"></p>
  </div>
  <div class="system-form-modal-footer"><button type="button" class="persistent-phone-action" data-callback-close>Cancelar</button><button type="submit" class="persistent-phone-action is-primary">Guardar devolución</button></div>
 </form>
</dialog>

<aside class="impe-availability-waiting" data-availability-waiting hidden role="status"><i class="bi bi-telephone-forward"></i><span>Recepción solicita disponibilidad. Guarda el resultado anterior para poder aceptar.</span></aside>
