#!/usr/bin/env python3
"""Smoke checks for commercial multi-program UI, without CRM or MySQL."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
service=(root/'app/services/ProspectoAsesorConfiguracionService.php').read_text()
view=(root/'app/views/prospectos/partials/configuracion_asesores.php').read_text()
css=(root/'public/css/prospectos_asesores.css').read_text()
js=(root/'public/javascript/prospectos_asesores.js').read_text()
central=(root/'app/views/prospectos/central.php').read_text()
migration=(root/'database/migrations/2026_11_06_prospectos_asesores_multiprograma.sql').read_text()
checks={
    'reutiliza AvatarHelper':"renderAvatarUsuario(" in view and "foto_perfil" in service,
    'foto modal existente':"system-avatar-photo-button" in js,
    'programas múltiples sin lista fija': 'name="programas[]"' in view and "$programasAsesores" in view,
    'selección total por grupo': 'data-asesores-todos' in view and "refrescarGrupo" in js,
    'Bachillerato y Titulación': "'Bachillerato'=>[]" in view and "'Titulación'=>[]" in view,
    'prepara otros programas': "'Otros programas'=>[]" in view,
    'jornadas compactas': "grid-template-columns:repeat(2,minmax(0,1fr))" in css,
    'versionado sin cambio': "version=version+1" in service,
    'audita todos los programas': "'programas'=>$antesProgramas" in service and "'programas'=>$codigos" in service,
    'no reparte prospectos': 'prospecto_asignaciones' not in migration and 'Zadarma' not in migration,
    'JS solo Asignaciones': "$seccionProspectos==='asignaciones'" in central and "prospectos_asesores.js" in central,
    'backfill idempotente': 'WHERE NOT EXISTS' in migration,
}
for name, success in checks.items():
    if not success:
        raise SystemExit("FALLO: "+name)
    print("OK:",name)
