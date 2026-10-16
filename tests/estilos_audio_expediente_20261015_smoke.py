from pathlib import Path
import re

css = Path("public/css/seguimiento_llamadas_expediente.css").read_text()
assert ".linkage-call-history-card button.linkage-call-recording-toggle" in css
selector = css.split(".linkage-call-history-card button.linkage-call-recording-toggle {", 1)[1].split("}",1)[0]
assert "font-size: 10px" in selector
assert "font: inherit;" not in selector
assert "min-height: 28px" in selector
assert ".linkage-call-history-card .linkage-incoming-audio-panel .linkage-call-inline-player" in css
assert "max-width: min(730px, 100%)" in css
assert ".linkage-call-history-card .linkage-incoming-audio-panel .linkage-call-inline-player + .linkage-call-inline-player" in css
# No modificación en motor de llamadas ni componentes compartidos.
print("Estilos audios transferidos: OK (alcance expediente, botón compacto, segmentos uniformes).")
