#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador selectivo y reversible: historial y resultado superpuesto del Analista.
No ejecuta SQL, no modifica Zadarma/SIP ni llamadas desde Seguimiento.
Compatible con Python 3.6 (Hostinger).
"""
from __future__ import print_function
import argparse
import contextlib
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ALLOW = {
    'app/views/telefonia/shell.php',
    'public/javascript/telefonia_analista_resultado_superpuesto.js',
    'public/javascript/telefonia_analista_historial_pasivo.js',
    'public/javascript/seguimiento_entrantes_analista.js',
    'app/services/TelefoniaEntrantesAnalistaService.php',
    'app/views/seguimiento_vinculacion/llamadas_entrantes.php',
    'app/services/TelefoniaMisLlamadasService.php',
    'app/views/telefonia/mis_llamadas.php'
}

def checksum(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def git_blob(data):
    if data is None:
        return None
    raw=('blob %d\0' % len(data)).encode('ascii')+data
    return hashlib.sha1(raw).hexdigest()

def inside(root, name):
    part=Path(name)
    if part.is_absolute() or '..' in part.parts or '\\' in name:
        raise ValueError('Ruta no permitida: '+name)
    target=root/part
    if root not in target.resolve().parents:
        raise ValueError('Ruta fuera de CRM: '+name)
    node=target
    while node!=root:
        if node.is_symlink():
            raise ValueError('Ruta simbólica no permitida: '+name)
        node=node.parent
    return target

def read(path):
    return path.read_bytes() if path.is_file() else None

def shell_surgical_patch(data, item):
    """Inserta una sola dependencia delante del observador del Analista.
    No reemplaza scripts, lógica, permisos ni HTML personalizado del shell.
    """
    if data is None:
        raise ValueError('CONFLICTO: falta la vista shell.php')
    # El HTML/JS puede estar personalizado: únicamente aceptamos el include
    # original como ancla, dentro del bloque de Analista con su configuración.
    existing = b"public/javascript/telefonia_analista_historial_pasivo.js?v="
    overlay = b"public/javascript/telefonia_analista_resultado_superpuesto.js?v="
    if data.count(existing) != 1:
        raise ValueError('CONFLICTO: include del observador de Analista ausente o duplicado')
    line_start = data.rfind(b'\n', 0, data.index(existing)) + 1
    line_end = data.find(b'\n', data.index(existing))
    if line_end < 0: line_end = len(data)
    line = data[line_start:line_end]
    if not line.lstrip().startswith(b'<script src="') or b'</script>' not in line:
        raise ValueError('CONFLICTO: formato del include del Analista no reconocido')
    prefix = data[max(0, line_start - 3200):line_start]
    suffix = data[line_end:line_end + 1200]
    if (b'IMPE_ANALYST_WEBRTC_JOURNAL' not in prefix or
            b'<?php endif; ?>' not in suffix):
        raise ValueError('CONFLICTO: el include del Analista no está en su bloque esperado')
    if overlay in data:
        if data.count(overlay) != 1 or data.index(overlay) > data.index(existing):
            raise ValueError('CONFLICTO: el formulario superpuesto ya tiene otro orden')
        return data
    # La línea exacta proviene del manifest verificado del commit, no de
    # una plantilla dinámica inventada por el instalador.
    after = item['changes'][0]['after'].encode('utf-8')
    desired = [
        line for line in after.splitlines()
        if overlay in line and b'<script src=' in line
    ]
    if len(desired) != 1:
        raise ValueError('INTEGRIDAD: no existe un include único del formulario')
    wanted = desired[0]
    indentation = line[:len(line) - len(line.lstrip())]
    replacement = indentation + wanted.lstrip()
    newline = b'\r\n' if b'\r\n' in data else b'\n'
    return data[:line_start] + replacement + newline + data[line_start:]


def service_surgical_patch(data, item):
    """Añade sólo la consulta de salientes clasificadas en listar().
    Conserva cambios locales en recepción, transferencias, SDK y grabaciones.
    """
    if data is None:
        raise ValueError('CONFLICTO: falta TelefoniaEntrantesAnalistaService.php')
    anchor = b'        $sesiones = $clasificadorInterno->listar($usuarioId);'
    method = b'    public function listar(int $usuarioId): array'
    if data.count(anchor) != 1 or data.count(method) != 1:
        raise ValueError('CONFLICTO: estructura del historial operativo desconocida')
    start = data.index(method)
    target = data.index(anchor)
    if target <= start:
        raise ValueError('CONFLICTO: marcador de sesiones fuera de listar()')
    prefix = data[start:target]
    for required in [
        b'$clasificaciones = [];',
        b'$llamadas = [];',
        b'foreach ($ids as $id)',
        b'$llamadas[] = [',
        b'$clasificadorInterno'
    ]:
        if required not in prefix:
            raise ValueError('CONFLICTO: dependencia ausente en listar(): ' +
                             required.decode('ascii'))
    reference = item['changes'][0]['after'].encode('utf-8')
    begin = reference.index(b'        // Las salientes del auricular')
    end = reference.index(b'        // Dos fuentes distintas:', begin)
    insert = reference[begin:end]
    if b'\r\n' in data:
        insert = insert.replace(b'\n', b'\r\n')
    # Prevenir duplicados y estados parcialmente instalados.
    if data.count(insert) == 1:
        return data
    if (b'$stmtSalientes' in data or
            b"GROUP BY pbx_call_id ORDER BY fecha DESC LIMIT 75" in data):
        raise ValueError('CONFLICTO: consulta de salientes ya modificada')
    if b'NOTIFY_OUT_START' in prefix:
        raise ValueError('CONFLICTO: existe otra consulta saliente en listar()')
    return data[:target] + insert + data[target:]


def history_view_surgical_patch(data, item):
    """Arregla los enlaces de detalle sin sobrescribir HTML o textos locales.

    Los cambios visuales son opcionales si Hostinger ya personalizó esas
    etiquetas; el enlace funcional por PBX/SDK sí exige una estructura única.
    """
    if data is None:
        raise ValueError('CONFLICTO: falta la vista Mis llamadas')
    # Cuando el archivo es exactamente la versión base, conservar la salida
    # bit a bit del manifiesto verificado por GitHub.
    if git_blob(data)==item.get('beforeBlob'):
        result=data
        for h in item['changes']:
            before=h['before'].encode('utf-8')
            after=h['after'].encode('utf-8')
            if result.count(before)!=1:
                raise ValueError('INTEGRIDAD: bloque base distinto en Mis llamadas')
            result=result.replace(before, after, 1)
        return result
    if git_blob(data)==item.get('afterBlob'):
        return data
    import re
    candidate=data
    if candidate.count(b'<?php foreach ($items as $fila):')!=1:
        raise ValueError('CONFLICTO: bucle de historial personalizado no reconocido')
    if candidate.count(b'$pbxId =')!=1 or candidate.count(b'$detalleTexto =')<1:
        raise ValueError('CONFLICTO: identidad de llamada ausente o duplicada')
    if candidate.count(b'$esc($detalleUrl)')!=1:
        raise ValueError('CONFLICTO: enlace de detalle no identificado de forma única')
    expression=re.compile(
        rb'\}\s*elseif\s*\(\s*!empty\(\s*\$puedeGestionAnalista\s*\)'
        rb'\s*&&\s*(?:!\s*\$saliente\s*&&\s*)?'
        rb'\$pbxId\s*!==\s*[\x27][\x27]\s*\)\s*\{'
    )
    found=list(expression.finditer(candidate))
    if len(found)!=1:
        raise ValueError('CONFLICTO: rama del enlace Analista diferente o duplicada')
    match=found[0]
    branch=candidate[match.start():match.end()]
    revised=re.sub(rb'!\s*\$saliente\s*&&\s*', b'', branch, count=1)
    candidate=candidate[:match.start()]+revised+candidate[match.end():]
    match=expression.search(candidate)
    block=candidate[match.end():]
    # No modificar el enlace de devoluciones ni recepción Marketing.
    next_branch=re.search(rb'\}\s*elseif\s*\(',block)
    if not next_branch or next_branch.start()>1600:
        raise ValueError('CONFLICTO: ramas de navegación inesperadas')
    block_end=match.end()+next_branch.start()
    analyst=candidate[match.end():block_end]
    assignment=re.search(rb'\$detalleUrl\s*=\s*BASE_URL.*?;',analyst,re.DOTALL)
    if not assignment or assignment.end()-assignment.start()>850:
        raise ValueError('CONFLICTO: destino del detalle Analista no reconocido')
    before_link=assignment.group(0)
    old=re.compile(rb"'&vista=recibidas&detalle=1&pbx_call_id='\s*\.\s*"
                   rb'rawurlencode\(\$pbxId\)')
    if before_link.count(b'sdk_session_id=')==1 and before_link.count(b'pbx_call_id=')==1:
        updated_link=before_link
    elif len(old.findall(before_link))==1:
        newline=b'\r\n' if b'\r\n' in candidate else b'\n'
        replacement=(b"'&vista=recibidas&detalle=1&' ."+newline+
                     b"                    (($fila['fuente_operativa'] ?? '') === 'SDK_WEBRTC'"
                     b" ? 'sdk_session_id=' : 'pbx_call_id=') . rawurlencode($pbxId)")
        updated_link=old.sub(lambda _:replacement,before_link,count=1)
    else:
        raise ValueError('CONFLICTO: enlace de detalle Analista personalizado; no se reemplaza')
    if (b'&vista=recibidas&detalle=1&' not in updated_link or
            b'sdk_session_id=' not in updated_link or
            b'pbx_call_id=' not in updated_link or
            b'rawurlencode($pbxId)' not in updated_link):
        raise ValueError('CONFLICTO: destino Analista no cubre ambas referencias')
    candidate=(candidate[:match.end()+assignment.start()] + updated_link +
               candidate[match.end()+assignment.end():])
    # Estado visual: reemplazar sólo una expresión exacta conocida; conservar
    # los badges personalizados que no coincidan, sin inventar estado PBX.
    old_badge=b"'Conectada' : 'Sin conexión confirmada'"
    new_badge=("'Conectada' : (!empty($fila['observada']) ? "
               "'Conexión WebRTC observada' : 'Sin conexión confirmada')").encode('utf-8')
    if candidate.count(new_badge)==1:
        pass
    elif candidate.count(old_badge)==1:
        candidate=candidate.replace(old_badge,new_badge,1)
    else:
        print('AVISO: indicador de conexión personalizado conservado en Mis llamadas.')
    # La leyenda es texto informativo: nunca sobrescribir redacción local.
    previous=item['changes'][2]['before'].encode('utf-8')
    revised_note=item['changes'][2]['after'].encode('utf-8')
    old_note=re.search(rb'<p class="impe-my-calls-note">.*?</p>',previous,re.DOTALL)
    new_note=re.search(rb'<p class="impe-my-calls-note">.*?</p>',revised_note,re.DOTALL)
    if not old_note or not new_note or candidate.count(b'class="impe-my-calls-note"')!=1:
        raise ValueError('CONFLICTO: nota de procedencia del historial no reconocida')
    if old_note.group(0) in candidate:
        candidate=candidate.replace(old_note.group(0),new_note.group(0),1)
    elif new_note.group(0) not in candidate:
        print('AVISO: texto informativo personalizado conservado en Mis llamadas.')
    return candidate


def proposed(data, item):
    if item['path']=='app/views/telefonia/shell.php' and item['action']=='patch':
        return shell_surgical_patch(data, item)
    if item['path']=='app/services/TelefoniaEntrantesAnalistaService.php' and item['action']=='patch':
        return service_surgical_patch(data, item)
    if item['path']=='app/views/telefonia/mis_llamadas.php' and item['action']=='patch':
        return history_view_surgical_patch(data, item)
    if item['action']=='create':
        candidate=item['content'].encode('utf-8')
        if data is not None and data != candidate:
            raise ValueError('CONFLICTO: archivo ya existente distinto: '+item['path'])
    elif item['action']=='patch':
        if data is None:
            raise ValueError('CONFLICTO: no existe: '+item['path'])
        candidate=data
        for i, h in enumerate(item['changes']):
            before=h['before'].encode('utf-8')
            after=h['after'].encode('utf-8')
            if b'\r\n' in candidate:
                before=before.replace(b'\n',b'\r\n')
                after=after.replace(b'\n',b'\r\n')
            if candidate.count(after)==1:
                continue
            if candidate.count(before)!=1 or candidate.count(after)!=0:
                raise ValueError('CONFLICTO: personalización no reconocida en '+item['path']+' fragmento '+str(i+1))
            candidate=candidate.replace(before,after,1)
    else:
        raise ValueError('Acción de manifest desconocida')
    if data is None or git_blob(data)==item.get('beforeBlob'):
        if git_blob(candidate)!=item.get('afterBlob'):
            raise ValueError('INTEGRIDAD: código nuevo distinto del esperado en '+item['path'])
    return candidate

def verify(site,manifest):
    rows=manifest.get('files')
    if manifest.get('schema')!='impe.analista.historial.overlay.v1' or not isinstance(rows,list):
        raise ValueError('Manifest incompatible')
    names=[item['path'] for item in rows]
    if len(names)!=len(ALLOW) or set(names)!=ALLOW:
        raise ValueError('Manifest incluye archivos no autorizados')
    ready=[]
    for item in rows:
        path=inside(site,item['path'])
        before=read(path)
        after=proposed(before,item)
        mode=(path.stat().st_mode & 0o777) if before is not None else 0o644
        ready.append({'path':path,'name':item['path'],'before':before,
                      'after':after,'mode':mode})
        print(('YA INSTALADO: ' if before==after else 'COMPATIBLE: ')+item['path'])
    for item in ready:
        suffix=item['path'].suffix
        cmd=['php','-l'] if suffix=='.php' else (
            ['node','--check'] if suffix=='.js' and shutil.which('node') else [])
        if not cmd:
            if suffix=='.js':
                print('AVISO: Node no disponible; JS requiere CI aprobado antes de publicar.')
            continue
        with tempfile.TemporaryDirectory(prefix='impe-analista-lint-') as temp:
            file=Path(temp)/('verify'+suffix)
            file.write_bytes(item['after'])
            p=subprocess.run(cmd+[str(file)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if p.returncode:
                raise ValueError('SINTAXIS: '+item['name']+': '+p.stderr.decode('utf-8','replace')[:450])
    return ready

def atomic(path,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.impe-analista-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

@contextlib.contextmanager
def lock(root):
    root.mkdir(parents=True,exist_ok=True,mode=0o700)
    p=root/'.impe-historial-overlay.lock'
    fd=os.open(str(p),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    try:
        os.write(fd,str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        try: p.unlink()
        except OSError: pass

def publish(site,backups,manifest):
    # No crear carpetas, siquiera de backup, si la compatibilidad falla.
    verify(site,manifest)
    with lock(backups):
        rows=verify(site,manifest)
        changed=[x for x in rows if x['before']!=x['after']]
        if not changed:
            print('YA INSTALADO: sin escrituras')
            return
        archive=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),dir=str(backups)))
        os.chmod(str(archive),0o700)
        files=[]
        for item in changed:
            if item['before'] is not None:
                original=inside(archive,'original/'+item['name'])
                original.parent.mkdir(parents=True,exist_ok=True)
                original.write_bytes(item['before'])
            files.append({'path':item['name'],'before':checksum(item['before']),
                          'after':checksum(item['after']),'mode':item['mode']})
        (archive/'respaldo.json').write_text(json.dumps({
            'site':str(site),'manifest_head':manifest.get('head'),
            'files':files
        },indent=2),encoding='utf-8')
        for item in changed:
            if read(item['path'])!=item['before']:
                raise RuntimeError('CAMBIO CONCURRENTE: '+item['name']+'. No se aplicó nada.')
        installed=[]
        try:
            for item in changed:
                if read(item['path'])!=item['before']:
                    raise RuntimeError('CAMBIO CONCURRENTE: '+item['name'])
                item['path'].parent.mkdir(parents=True,exist_ok=True)
                atomic(item['path'],item['after'],item['mode'])
                installed.append(item)
                if read(item['path'])!=item['after']:
                    raise RuntimeError('Verificación fallida: '+item['name'])
        except BaseException:
            for item in reversed(installed):
                if read(item['path'])==item['after']:
                    if item['before'] is None:
                        item['path'].unlink()
                    else:
                        atomic(item['path'],item['before'],item['mode'])
                else:
                    print('REVISAR MANUALMENTE: '+item['name'],file=sys.stderr)
            raise
        print('APLICADO: '+str(len(changed))+' archivos. RESPALDO: '+str(archive))
        print('Sin SQL, sin borrar grabaciones ni modificar motor WebRTC.')

def restore(site,backups,folder):
    archive=Path(folder).resolve()
    if backups not in archive.parents:
        raise ValueError('Respaldo fuera del directorio autorizado')
    with lock(backups):
        data=json.loads((archive/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site')!=str(site):
            raise ValueError('Respaldo pertenece a otro sitio')
        checked=[]
        for item in data.get('files',[]):
            if item['path'] not in ALLOW:
                raise ValueError('Respaldo contiene ruta no autorizada')
            path=inside(site,item['path'])
            before=read(inside(archive,'original/'+item['path'])) if item['before'] else None
            if checksum(before)!=item['before'] or checksum(read(path))!=item['after']:
                raise ValueError('CONFLICTO: archivo posterior distinto: '+item['path'])
            checked.append((path,before,int(item['mode'])))
        for path,before,mode in reversed(checked):
            if before is None: path.unlink()
            else: atomic(path,before,mode)
        print('RESTAURADO: sólo archivos de esta entrega; sin SQL.')

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--site',required=True)
    parser.add_argument('--backup-root',required=True)
    parser.add_argument('--manifest',default=str(Path(__file__).parent/'analista_historial_overlay_manifest.json'))
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--apply',action='store_true')
    mode.add_argument('--restore')
    opt=parser.parse_args()
    site=Path(opt.site).resolve()
    backup=Path(opt.backup_root).resolve()
    if not (site/'index.php').is_file():
        raise ValueError('Ruta --site no es CRM')
    if backup==site or site in backup.parents or backup in site.parents:
        raise ValueError('Respaldo debe estar fuera de la instalación')
    manifest=json.loads(Path(opt.manifest).read_text(encoding='utf-8'))
    if opt.restore: restore(site,backup,opt.restore)
    elif opt.apply: publish(site,backup,manifest)
    else:
        verify(site,manifest)
        print('CHECK OK: 8 archivos, compatibilidad y sintaxis; sin escrituras ni SQL.')

if __name__=='__main__':
    try: main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr)
        sys.exit(1)
