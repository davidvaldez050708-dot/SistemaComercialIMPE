<dialog class="impe-availability-dialog system-form-modal" data-sales-prospect-dialog aria-labelledby="salesProspectTitle">
 <form>
  <div class="system-form-modal-header"><h2 id="salesProspectTitle">Datos del prospecto</h2><p class="system-form-modal-subtitle">Datos informados durante la atención. Guardarlos no termina la llamada ni registra su resultado.</p></div>
  <div class="system-form-modal-body">
   <label for="salesCaptureName">Nombre completo</label><input id="salesCaptureName" name="nombre" maxlength="150" required autocomplete="name">
   <label for="salesCapturePhone">Teléfono</label><input id="salesCapturePhone" type="tel" name="telefono" maxlength="40" required autocomplete="tel">
   <label for="salesCaptureEmail">Correo electrónico (opcional)</label><input id="salesCaptureEmail" type="email" name="correo" maxlength="180" autocomplete="email">
   <label for="salesCaptureProgram">Programa educativo de interés (opcional)</label><input id="salesCaptureProgram" name="programa" maxlength="120">
   <label for="salesCaptureNotes">Notas de atención</label><textarea id="salesCaptureNotes" name="notas" rows="3" maxlength="1000"></textarea>
   <p data-sales-prospect-feedback class="system-form-modal-subtitle" role="status" aria-live="polite"></p>
  </div>
  <div class="system-form-modal-footer"><button type="button" class="persistent-phone-action" data-sales-prospect-close>Cerrar</button><button type="submit" class="persistent-phone-action is-primary">Guardar datos</button></div>
 </form>
</dialog>
