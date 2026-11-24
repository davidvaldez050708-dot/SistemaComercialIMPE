"""Paquete selectivo: normaliza UTC antes de comprobar llamadas propias del analista."""
import base64
import hashlib
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
helper = 'app/helpers/TelefoniaAsignacionFechaHelper.php'
service = 'app/services/TelefoniaEntrantesAnalistaService.php'
data = (root/helper).read_bytes().replace(b'\r\n', b'\n')
anchor = """            throw new DomainException('Asigna y activa una extensión de llamadas entrantes al Analista.');
        }
        return $fila;
"""
replacement = """            throw new DomainException('Asigna y activa una extensión de llamadas entrantes al Analista.');
        }
        // Normalizar una sola vez antes de filtrar historial y confirmar cierres.
        require_once __DIR__ . '/../helpers/TelefoniaAsignacionFechaHelper.php';
        $fila['desde'] = TelefoniaAsignacionFechaHelper::aMexico((string)$fila['desde']);
        return $fila;
"""
assert (root/service).read_text(encoding='utf-8').count(replacement) == 1
rows = [dict(path=helper, new_file=True, content=base64.b64encode(data).decode(), after_sha=hashlib.sha256(data).hexdigest()),
        dict(path=service, new_file=False, hunks=[dict(before=anchor, after=replacement)])]
(root/'tools/fecha_asignacion_analista_release.json').write_text(json.dumps(dict(release='fecha-asignacion-analista-v1', files=rows), ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
p = (root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8')
p = p.replace("FILES=('public/javascript/telefonia_marcador.js',)", 'FILES='+repr((helper, service)))
p = p.replace('marcador_estado_release.json', 'fecha_asignacion_analista_release.json').replace('marcador-estado-', 'fecha-asignacion-analista-')
p = p.replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.', 'Corrige el horario de asignación del analista; conserva propietario, evidencia y registros.')
p = p.replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.', 'Horario de asignación del analista corregido; se conservan permisos, evidencia PBX y registros históricos.')
(root/'tools/publicar_fecha_asignacion_analista.py').write_text(p, encoding='utf-8')
print('Paquete selectivo: 2 archivos; sin consultas SQL ni llamadas al instalar.')
