#!/usr/bin/env python3
"""Contract assertions: scoped read-only Mi cartera never delegates to admin lists."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding='utf-8')
repo=read('app/services/MiCarteraRepository.php')
controller=read('app/controllers/MiCarteraController.php')
router=read('index.php')
sidebar=read('app/views/layout/sidebar.php')
admin=read('app/controllers/ProspectoController.php')
listado=read('app/views/mi_cartera/index.php')
detalle=read('app/views/mi_cartera/detalle.php')
migration=read('database/migrations/2026_11_06_ventas_mi_cartera_permiso.sql')
checks={
 'controller Mi cartera separado del administrativo':"case 'miCartera':" in router and "MiCarteraController" in router,
 'autorización por rol y permiso':"($_SESSION['rol_id']??0)!==3" in controller and "prospectos.mi_cartera" in controller,
 'solo métodos GET':"REQUEST_METHOD" in controller and "!==\'GET\'" in controller,
 'no confía en asesor_id de URL':"$_GET['asesor_id']" not in controller and "a.asesor_id=?" in repo,
 'consulta asignaciones vigentes':repo.count('a.vigente=1')>=3,
 'detalle confirma asignación ANTES de fuentes':repo.index("if (!$p) return null")<repo.index("$p['programas']"),
 'detalle inexistente o ajeno = 404':"http_response_code(404)" in controller,
 'cabecera anti-cache':"private, no-store" in controller,
 'asignados y filtros en la misma consulta':"WHERE a.asesor_id=? AND a.vigente=1" in repo and "LIMIT ? OFFSET ?" in repo,
 'sin escrituras operativas':all(w not in repo.upper() for w in ['UPDATE PROSPECTO_', 'DELETE FROM PROSPECTO_', 'INSERT INTO PROSPECTO_']),
 'no utiliza ficha administrativa':"ProspectoController" not in controller and "prospecto&action=detalle" not in detalle,
 'la vista no enlaza al almacén global':"controller=prospecto" not in listado and "controller=prospecto" not in detalle,
 'no expone asignaciones de otros usuarios':"usuarioId" in controller and "a.asesor_id=?" in repo,
 'navegación escritorio/móvil':sidebar.count('controller=miCartera&amp;action=index')==2,
 'no da acceso global a asesor':"(int)($_SESSION['rol_id']??0)===3" in admin and "!==3" in sidebar,
 'nuevo permiso independiente':"prospectos.mi_cartera" in migration and "prospectos.ver_todos" not in migration.split("INSERT IGNORE INTO")[1],
 'permiso inicial únicamente Asesor de Ventas':"r.nombre='Asesor de Ventas'" in migration,
 'migración idempotente respeta revocación':"@impe_cartera_permiso_nuevo=1" in migration,
 'sin filas inventadas':"MiCarteraRepository" in controller and "Todavía no tienes prospectos asignados" in listado,
 'no modifica teléfono':"TelefoniaMarcador" not in controller and "Zadarma" not in repo,
}
for label,passed in checks.items():
    if not passed: raise SystemExit('ERROR: '+label)
    print('OK:',label)
print('OK: %d comprobaciones de autorización, UI y compatibilidad.'%len(checks))
