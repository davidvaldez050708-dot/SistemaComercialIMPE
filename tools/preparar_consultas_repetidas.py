"""Genera cambios selectivos sobre los métodos instalados, conservando sus variantes."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = ('app/services/TelefoniaVentasLlamadasService.php',
         'app/controllers/TelefoniaController.php', 'app/controllers/ReminderController.php')

def original(name):
    import subprocess
    return subprocess.check_output(['git', 'show', 'd096323:'+name], cwd=str(ROOT)).decode('utf-8').replace('\r\n', '\n')

rows=[]
service=original(FILES[0])
start=service.index('        $metadata=$this->rows(')
end=service.index('            if(($route',start)
old=service[start:end]
current=(ROOT/FILES[0]).read_text(encoding='utf-8')
start=current.index('        $metadata=$this->rows(')
end=current.index('            if(($route',start)
rows.append(dict(path=FILES[0],new_file=False,hunks=[dict(before=old,after=current[start:end])]))

old='    public function detalleVenta()\n    {\n        $this->validarAtencionVentaJson(false);\n'
new=old+'''        // La identidad queda validada; la lectura PBX no necesita bloquear la navegación.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
'''
rows.append(dict(path=FILES[1],new_file=False,hunks=[dict(before=old,after=new)]))

release='''        // Observaciones conserva su deduplicación en sesión antes de liberarla.
        $requiereSesionObservaciones = !$esAdministrador &&
            (!empty($puedeOperarSeguimientos) || ($puedeCentroReuniones && $esAnalista));
        if (!$requiereSesionObservaciones && session_status() === PHP_SESSION_ACTIVE) session_write_close();

'''
local_prefix='            exit;\n        }\n\n'
local_tail="        if ($esAdministrador) {\n            $resultado = (new AdminNotificationService())->obtener();"
local=local_prefix+local_tail
installed='        // Conservar el orden y las conexiones independientes de los servicios usados.\n'
observations='''            $observaciones = $this->reminderObservacionService->obtener(
                $usuarioId,
                10
            );
'''
rows.append(dict(path=FILES[2],new_file=False,hunks=[
    dict(before=local,after=local_prefix+release+local_tail,variants=[dict(before=installed,after=release+installed)]),
    dict(before=observations,after=observations+'            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();\n')]))

(ROOT/'tools/consultas_repetidas_release.json').write_text(json.dumps(dict(release='consultas-repetidas-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
publisher=(ROOT/'tools/publicar_rendimiento_inicio.py').read_text(encoding='utf-8')
publisher=publisher.replace('"""Publicación selectiva de rendimiento de inicio; conserva personalizaciones."""',
                          '"""Publicación selectiva de consultas por conjuntos y liberación de sesión."""')
publisher=publisher.replace("FILES=('app/models/RolModel.php', 'app/controllers/LoginController.php', 'app/controllers/TelefoniaController.php')",'FILES='+repr(FILES))
publisher=publisher.replace('rendimiento_inicio_release.json','consultas_repetidas_release.json').replace('rendimiento-inicio-','consultas-repetidas-')
publisher=publisher.replace('def main():','''def activar_perfil(site, private):
    import time
    if (site/'app/helpers/PerfilPeticion.php').is_file():
        private.mkdir(parents=True,exist_ok=True,mode=0o700)
        now=int(time.time())
        for name,value in [('perfil-peticiones-activo.json',dict(version=1,hasta=now+3600)),
                           ('consultas-repetidas-publicado.json',dict(desde=now))]:
            p=private/name
            if p.is_symlink():raise RuntimeError('Registro privado no válido')
            write(p,json.dumps(value).encode('utf-8'),0o600)
        print('MEDICION_ACTIVA: 60 minutos; informe posterior separado.')

def main():''')
publisher=publisher.replace("if not plan:print('SIN_CAMBIOS: paquete ya aplicado.');return", "if not plan:activar_perfil(site,backups);print('SIN_CAMBIOS: paquete ya aplicado.');return")
publisher=publisher.replace("    print(('RESTAURACION_OK'", "    if not args.restore:activar_perfil(site,backups)\n    print(('RESTAURACION_OK'")
publisher=publisher.replace("    spec=json.loads", "    if backups!=(site.parent/'impe-private').resolve():raise RuntimeError('Usar impe-private hermano de public_html')\n    spec=json.loads")
(ROOT/'tools/publicar_consultas_repetidas.py').write_text(publisher,encoding='utf-8')
print('Paquete selectivo generado: 3 archivos.')
