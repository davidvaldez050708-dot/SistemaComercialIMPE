module.exports=(etapa,modo)=>`<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/embudo.css"><link rel="stylesheet" href="/tablero.css"><main class="impe-embudo--v3-operativo">
  <div class="impe-embudo-board"><article class="impe-v3o-card" data-asignacion-id="1" data-etapa="${etapa}" data-v3-subetapa="CALIFICADO" data-v3-fecha-pendiente="2099-01-01T10:00">
    <button data-v3-gestionar>Gestionar</button>
    <button data-v3-actividad data-version="7" data-pendiente="${modo==='REPROGRAMAR'?1:0}">Actividad</button>
  </article>
  <article class="impe-v3o-card" data-asignacion-id="2" data-etapa="CONTACTADO" data-v3-destinos="SEGUIMIENTO,POTENCIAL">
    <button data-v3-mover>Mover</button>
  </article>
  </div><dialog id="impe-v3o-dialog"><form id="impe-v3o-mover-form">
    <span id="impe-v3o-dialog-datos"></span>
    <select id="impe-v3o-destino"><option>SEGUIMIENTO</option><option>POTENCIAL</option></select>
    <input id="impe-v3o-motivo"><label id="impe-v3o-fecha-label">
    <input id="impe-v3o-fecha" type="datetime-local"></label>
    <label id="impe-v3o-calificacion-label" hidden>
      <input id="impe-v3o-calificacion" type="checkbox">Intención confirmada
    </label><p id="impe-v3o-dialog-error" hidden></p><button id="impe-v3o-enviar">Confirmar</button>
  </form></dialog>
  <dialog id="impe-v3o-potencial-dialog"><form id="impe-v3o-potencial-form">
    <span id="impe-v3o-potencial-datos"></span>
    <select id="impe-v3o-potencial-subetapa"><option>CALIFICADO</option><option>REUNIENDO_DOCUMENTOS</option></select>
    <input id="impe-v3o-potencial-fecha"><input id="impe-v3o-potencial-motivo">
    <p id="impe-v3o-potencial-error" hidden></p><button id="impe-v3o-potencial-guardar">Guardar</button>
  </form></dialog>
  <dialog class="impe-embudo-dialog" id="impe-v3o-actividad-dialog"><form>
    <header><h3>Actividad comercial</h3></header>
    <div class="impe-v3o-actividad-cuerpo">
      <p data-actividad-prospecto></p>
      <select name="operacion"><option>REGISTRAR</option><option>PROGRAMAR</option><option>REPROGRAMAR</option><option>COMPLETAR</option></select>
      <label data-actividad-fecha><input name="fecha" type="datetime-local"></label>
      <label data-actividad-subetapa><select name="subetapa"><option value=""></option></select></label>
      <input name="motivo" required>
      <p data-actividad-error hidden></p>
    </div>
    <div class="impe-embudo-dialog-footer"><button type="submit">Guardar actividad</button></div>
  </form></dialog>
  </main><script src="/bootstrap.js"></script><script src="/tablero.js"></script>`;
