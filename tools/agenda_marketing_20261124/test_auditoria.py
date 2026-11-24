"""Auditoría aislada: permisos por perfil, fuentes instaladas y consultas sólo SELECT."""
import json
import subprocess
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
with tempfile.TemporaryDirectory(prefix='agenda-auditoria-') as temp:
    site = Path(temp)

    def put(name, text):
        path = site / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding='utf-8')

    put('index.php', '<?php // sitio de prueba')
    put('public/javascript/agenda_alertas.js', (HERE / 'base/agenda_alertas.js').read_text(encoding='utf-8'))
    put('public/javascript/recordatorios.js', "alertasAgenda('ingest', datos); alertasAgenda('setPresenter', mostrarToast); alertasAgenda('onToastShown', aviso);")
    put('public/javascript/convocatoria_notificaciones.js', (HERE / 'agenda_marketing.js').read_text(encoding='utf-8'))
    put('public/audio/agenda-aviso.mp3', 'audio simulado')
    put('app/views/layout/dashboard_footer.php', "'agenda_alertas.js'")
    put('app/views/layout/topbar.php', "$mostrarCentroAvisos = !empty($_SESSION['usuario_id']);")
    put('app/controllers/ReminderController.php', 'AgendaComentarioService ->notificaciones($usuarioId) ->notificaciones($usuarioId)')
    put('app/controllers/ConvocatoriaNotificacionController.php', 'AgendaMarketingNotificationService')
    rows = []
    for role_id, name, count, agenda, directed, convocatoria in [
        (1, 'Administrador', 1, 1, 0, 0),
        (2, 'Ventas', 3, 1, 0, 0),
        (3, 'Marketing', 2, 0, 1, 1),
        (4, 'Sin agenda', 4, 0, 0, 0),
    ]:
        rows.append(dict(id=role_id, nombre=name, usuarios_activos=count,
                         agenda_ver=agenda, comentarios_ver=agenda,
                         recibir_dirigidos=directed, enviar_administrador=0,
                         convocatorias_gestionar=convocatoria))
    put('roles.json', json.dumps(rows))
    put('config/db_connection.php', '''<?php
class AuditResult {
 function fetch_all($mode) { return json_decode(file_get_contents(__DIR__.'/../roles.json'), true); }
 function fetch_assoc() { return ['total'=>1]; }
}
class AuditDb {
 function query($sql) {
  if (!preg_match('/^SELECT\\b/i',ltrim($sql))) throw new Exception('Sólo SELECT');
  return new AuditResult();
 }
 function close() {}
}
class Database { function connect($persistent) { return new AuditDb(); } }
''')

    def audit():
        before = {str(p): p.read_bytes() for p in site.rglob('*') if p.is_file()}
        result = subprocess.run(['php', str(HERE / 'auditar_perfiles.php'), str(site)], capture_output=True)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        assert before == {str(p): p.read_bytes() for p in site.rglob('*') if p.is_file()}
        return json.loads(result.stdout)

    data = audit()
    assert all(data['archivos'].values()), data['archivos']
    assert data['resumen'] == dict(usuarios_con_permiso=6, usuarios_sin_permiso_de_comentarios=4,
                                  usuarios_con_integracion_pendiente=0,
                                  usuarios_activos_con_rol_inactivo_o_inexistente=1)
    assert data['perfiles'][2]['alcance'] == 'Sólo dirigidos'
    assert data['perfiles'][2]['canal'] == 'Campana de Marketing'
    assert data['perfiles'][3]['puede_recibir_comentarios'] is False
    put('public/javascript/convocatoria_notificaciones.js', '// sin corrección')
    data = audit()
    assert data['resumen']['usuarios_con_integracion_pendiente'] == 2
    assert data['perfiles'][2]['integracion_de_avisos_y_sonido'] == 'Pendiente de verificar'
    put('public/audio/agenda-aviso.mp3', '')
    assert audit()['resumen']['usuarios_con_integracion_pendiente'] == 6
print('Auditoría validada: permisos, integraciones ausentes, audio vacío y sólo lectura.')
