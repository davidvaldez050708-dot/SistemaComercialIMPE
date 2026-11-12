<?php
/** Modal V3 compartido por tablero y bandejas; autorización siempre en servidor. */
$escBandeja=static fn($value)=>htmlspecialchars((string)($value??''),ENT_QUOTES,'UTF-8');
?>
<dialog id="impe-v3b-dialog" class="impe-embudo-dialog impe-v3b-dialog"
        aria-labelledby="impe-v3b-titulo">
    <form method="dialog" id="impe-v3b-form">
        <header>
            <h3 id="impe-v3b-titulo">Revisión comercial V3</h3>
            <button type="button" data-v3b-cerrar aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="impe-v3b-form-cuerpo">
            <p class="impe-v3b-datos" id="impe-v3b-datos"></p>
            <p class="impe-v3b-ayuda" id="impe-v3b-ayuda"></p>
            <label data-v3b-dia hidden>Jornada telefónica revisada
                <input name="dia" type="date" max="<?= $escBandeja(
                    (new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d')) ?>">
            </label>
            <label data-v3b-subetapa hidden>Motivo acreditado
                <select name="subetapa">
                    <option value="">Selecciona un motivo</option>
                    <option value="NO_INTERESADO">Declaró que no está interesado</option>
                    <option value="NUMERO_INVALIDO">Número inexistente acreditado por PBX</option>
                    <option value="NO_CONTACTAR">Solicitud expresa de no volver a contactar</option>
                </select>
            </label>
            <label data-v3b-no-contactar hidden class="impe-v3b-confirmacion">
                <input type="checkbox" name="confirmacion_no_contactar" value="1">
                <span>Confirmo que la persona solicitó explícitamente que no volvamos
                    a contactarla y que revisé la conversación PBX correspondiente.
                    Se bloquearán nuevas llamadas comerciales y envíos de WhatsApp.</span>
            </label>
            <label>Justificación de la revisión
                <textarea name="motivo" minlength="8" maxlength="125" rows="3"
                          placeholder="Indica la evidencia revisada y la decisión tomada" required></textarea>
            </label>
            <label class="impe-v3b-confirmacion">
                <input name="confirmacion" type="checkbox" value="1" required>
                <span>He revisado el historial y confirmo esta decisión manual. No se enviarán llamadas ni mensajes automáticamente.</span>
            </label>
            <p class="impe-v3b-error" role="alert" data-v3b-error hidden></p>
        </div>
        <div class="impe-embudo-dialog-footer">
            <button type="button" data-v3b-cerrar>Cancelar</button>
            <button type="submit" id="impe-v3b-confirmar">Confirmar decisión</button>
        </div>
    </form>
</dialog>
