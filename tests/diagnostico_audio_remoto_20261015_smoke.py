from pathlib import Path
source = Path("public/javascript/telefonia_webrtc_dtmf.js").read_text()
assert "async function diagnoseAudio()" in source
assert "getStats()" in source
assert "getReceivers()" in source
assert "querySelectorAll('audio,video')" in source
assert "diagnoseAudio" in source.split("return Object.freeze({",1)[1]
diagnostic = source.split("async function diagnoseAudio()",1)[1].split("return Object.freeze({",1)[0]
for forbidden in ("srcObject =", ".play(", ".pause(", "replaceTrack(", "setRemoteDescription(", "setSinkId(", ".volume =", ".muted ="):
    assert forbidden not in diagnostic, forbidden
installer = Path("tools/desplegar_diagnostico_audio_remoto_hostinger.py").read_text()
assert 'public/javascript/telefonia_webrtc_dtmf.js' in installer
assert 'd14f295abbe2c326392d464f716f9c4c24b35e11' in installer
assert "--check" in installer and "--apply" in installer
print("Diagnóstico de audio: verificación estática OK.")
