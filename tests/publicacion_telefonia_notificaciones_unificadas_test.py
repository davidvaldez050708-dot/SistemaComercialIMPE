#!/usr/bin/env python3
"""Parches de campanas con cambios Hostinger, CRLF y rollback SHA."""
import json,os,pathlib,re,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]
script=root/'tools/publicar_telefonia_notificaciones_unificadas_hostinger.sh'
m=re.search(r"SPEC=json.loads\(r'''(.*?)'''\)",script.read_text(encoding='utf8'),re.S)
assert m,'No existe manifiesto'
spec=json.loads(m.group(1))
assert len(spec['changes'])==9
assert spec['newFile']['path']=='prueba_telefonia/api/leer_todas_notificaciones_llamada.php'
assert all('telefonia' in x['path'].lower() or x['path'].endswith(('topbar.php','recordatorios.js','recordatorios.css','ConvocatoriaNotificacionController.php','ReminderController.php','convocatoria_notificaciones.js'))
 for x in spec['changes'])
with tempfile.TemporaryDirectory() as temp:
    parent=pathlib.Path(temp);site=parent/'public_html';site.mkdir()
    (site/'index.php').write_text('<?php',encoding='utf8')
    private=parent/'private'
    originals={};expected={}
    for i,item in enumerate(spec['changes']):
        path=item['path']
        latest=(root/path).read_text(encoding='utf8').replace('\r\n','\n')
        before=latest
        def norm(s):
            return (s+'\n' if s.endswith('\r') else s).replace('\r\n','\n')
        for old,new in reversed(item['patches']):
            o,z=norm(old),norm(new)
            assert before.count(z)==1,(path,'No hay fragmento nuevo unico')
            before=before.replace(z,o,1)
        reconstruct=before
        for old,new in item['patches']:
            o,z=norm(old),norm(new)
            assert reconstruct.count(o)==1,(path,'No hay fragmento viejo unico')
            reconstruct=reconstruct.replace(o,z,1)
        assert reconstruct==latest,path
        suffix='\n/* HOSTINGER: CONSERVAR PERSONALIZACIONES AJENAS */\n'
        eol='\r\n' if i%2 else '\n'
        initial=(before+suffix).replace('\n',eol).encode('utf8')
        target=(latest+suffix).replace('\n',eol).encode('utf8')
        f=site/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(initial)
        originals[path]=initial;expected[path]=target
    new=site/spec['newFile']['path'];new.parent.mkdir(parents=True,exist_ok=True)
    def run(*args):
        env=os.environ.copy();env['IMPE_CRM_PUBLIC']=str(site);env['IMPE_CRM_BACKUPS']=str(private)
        return subprocess.run(['bash',str(script)]+list(args),env=env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    check=run('--check')
    assert check.returncode==0 and 'Archivos a modificar: 10' in check.stdout,(check.stdout,check.stderr)
    assert not private.exists() and not new.exists()
    assert all((site/p).read_bytes()==v for p,v in originals.items())
    apply=run('--apply')
    assert apply.returncode==0,(apply.stdout,apply.stderr)
    assert all((site/p).read_bytes()==v for p,v in expected.items())
    assert new.read_bytes()==spec['newFile']['content'].encode('utf8')
    folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0])
    assert private in folder.parents
    installed=run('--check')
    assert installed.returncode==0 and 'Archivos a modificar: 0' in installed.stdout
    one=site/spec['changes'][2]['path']
    one.write_bytes(expected[spec['changes'][2]['path']]+b'\n// edit later\n')
    blocked=run('--restore',str(folder))
    assert blocked.returncode!=0 and 'CONFLICTO' in blocked.stderr
    one.write_bytes(expected[spec['changes'][2]['path']])
    restored=run('--restore',str(folder))
    assert restored.returncode==0,(restored.stdout,restored.stderr)
    assert not new.exists()
    assert all((site/p).read_bytes()==v for p,v in originals.items())
    # Variante exacta del servicio personalizado de Hostinger:
    # prueba --check, --apply, idempotencia, rollback y seguridad SHA256.
    variant=spec['hostingerVariants']['app/services/TelefoniaNotificacionService.php']
    with tempfile.TemporaryDirectory() as alt:
        altbase=pathlib.Path(alt)
        alt_site=altbase/'public_html'
        alt_site.mkdir()
        (alt_site/'index.php').write_text('<?php',encoding='utf8')
        original_base=(root/'app/services/TelefoniaNotificacionService.php').read_text(encoding='utf8')
        for old,new in reversed(spec['changes'][0]['patches']):
            assert original_base.count(new)==1
            original_base=original_base.replace(new,old,1)
        find_old="WHERE destino_usuario=? AND referencia=? AND (modo='DIRECTA' OR (modo='CONSULTADA' AND estado='DEVUELTA'))"
        find_site="WHERE destino_usuario=? AND referencia=? AND modo='DIRECTA'"
        assert original_base.count(find_old)==1
        original_base=original_base.replace(find_old,find_site,1)
        obsolete_begin=original_base.index('                // Cuando la PBX confirmó que no contestaron, el aviso anterior')
        obsolete_end=original_base.index('                // Una devolución asignada ya tiene su aviso propio con acceso a Llamar.',obsolete_begin)
        original_base=original_base[:obsolete_begin]+original_base[obsolete_end:]
        pendiente_begin=original_base.index('    public function pendientes(int $user,bool $recepcion): array {')
        pendiente_end=original_base.index('    public function leer(int $user,int $id): bool {',pendiente_begin)
        original_base=original_base[:pendiente_begin]+variant['patches'][3][0]+original_base[pendiente_end:]
        assert len(original_base.splitlines())==97,len(original_base.splitlines())
        import hashlib
        actual_sha=hashlib.sha256(original_base.encode('utf8')).hexdigest()
        assert actual_sha==variant['source_sha'],('SHA Hostinger reconstruido',actual_sha,variant['source_sha'])
        alt_original={}
        for item in spec['changes']:
            name=item['path']
            path=alt_site/name
            path.parent.mkdir(parents=True,exist_ok=True)
            if name=='app/services/TelefoniaNotificacionService.php':
                data=original_base.encode('utf8')
            else:
                data=originals[name]
            path.write_bytes(data)
            alt_original[name]=data
        path=alt_site/spec['newFile']['path']
        path.parent.mkdir(parents=True,exist_ok=True)
        def alt_run(*args):
            env=os.environ.copy()
            env['IMPE_CRM_PUBLIC']=str(alt_site)
            env['IMPE_CRM_BACKUPS']=str(altbase/'private')
            return subprocess.run(['bash',str(script)]+list(args),env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        check=alt_run('--check')
        assert check.returncode==0,('Alt check',check.stdout,check.stderr)
        apply=alt_run('--apply')
        assert apply.returncode==0,('Alt apply',apply.stdout,apply.stderr)
        alt_updated=(alt_site/'app/services/TelefoniaNotificacionService.php').read_bytes()
        updated_sha=hashlib.sha256(alt_updated).hexdigest()
        print('VARIANTE_HOSTINGER_UPDATED_SHA='+updated_sha,flush=True)
        assert updated_sha==variant['updated_sha'],('SHA posterior Hostinger',updated_sha,variant['updated_sha'])
        assert b'index#recepcion-telefonica' in alt_updated
        assert b'seguimientoVinculacion&action=llamadasEntrantes' in alt_updated
        assert b"modo='DIRECTA'" in alt_updated
        assert b'pendiente_operativo' in alt_updated
        assert b'operativasActuales' in alt_updated
        second=alt_run('--check')
        assert second.returncode==0 and 'Archivos a modificar: 0' in second.stdout,(second.stdout,second.stderr)
        folder=pathlib.Path(apply.stdout.split('Respaldo: ')[1].splitlines()[0])
        restore=alt_run('--restore',str(folder))
        assert restore.returncode==0,('Alt restore',restore.stdout,restore.stderr)
        assert all((alt_site/name).read_bytes()==data for name,data in alt_original.items())
        assert not path.exists()
        changed=alt_site/'app/services/TelefoniaNotificacionService.php'
        changed.write_bytes(alt_original['app/services/TelefoniaNotificacionService.php']+b'\\n// changed')
        denied=alt_run('--check')
        assert denied.returncode!=0 and 'CONFLICTO' in denied.stderr,(denied.stdout,denied.stderr)
        assert not (altbase/'private2').exists()
    # Publicador nunca reemplaza una personalización nueva que desconoce.
    unknown=site/spec['changes'][0]['path'];unknown.write_bytes(b'<?php // otra version')
    denial=run('--check')
    assert denial.returncode!=0 and 'CONFLICTO' in denial.stderr
    assert all((site/p).read_bytes()==v for p,v in originals.items() if p!=spec['changes'][0]['path'])
print('OK: 9 archivos personalizados, endpoint nuevo, CRLF, --check/--apply/--restore, permisos y conflictos')
