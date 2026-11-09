#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Conciliación conservadora de Telefonía personalizada y embudo IMPE.

--check NO ESCRIBE: valida los hashes reales de Hostinger, realiza una
fusión en memoria, revisa anclas, sintaxis PHP y consistencia de JavaScript.
--apply sólo se admite tras comprobar los cuatro archivos, crea un respaldo
privado y restaura los originales ante error.

No modifica SQL, tablas, módulos de otras áreas, llamadas ni grabaciones.
Diseñado para Python 3.6+. No depende de comentarios del código original.
"""
from __future__ import print_function
import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

FILES = {
    'app/services/TelefoniaResultadoVentasService.php':
        '707891cd9fcbc474151aa938a559fe7f1ddfe6bc',
    'app/views/telefonia/resultado_ventas_form.php':
        '94db44f8b6c5e96350e5e6f3209e896a820beedf',
    'app/views/telefonia/ventas_llamadas.php':
        '94589abb38f46337f0a4ce827ee4cdfe6540d473',
    'public/javascript/telefonia_ventas_resultados.js':
        'bfcc67613e16c27b508536649081e9e356816da9'
}

PHP_SERVICE = 'app/services/TelefoniaResultadoVentasService.php'
PHP_FORM = 'app/views/telefonia/resultado_ventas_form.php'
PHP_CALLS = 'app/views/telefonia/ventas_llamadas.php'
JS = 'public/javascript/telefonia_ventas_resultados.js'

ANCHOR_SERVICE = "}catch(Throwable $e){$this->db->rollback();throw $e;}"
BRIDGE = """        // IMPE_EMBUDO_PBX_POSTCOMMIT_V1
        // Telefonía ya registró el resultado definitivo. Si la vinculación
        // comercial falla, no se revierte ni se altera ese registro PBX.
        $idComercial = $datos['prospecto_asignacion_id'] ?? '';
        if (is_string($idComercial) &&
            preg_match('/^[1-9][0-9]*$/D', $idComercial)) {
            try {
                require_once __DIR__.'/EmbudoComercialResultadoBridge.php';
                $this->db->begin_transaction();
                (new EmbudoComercialResultadoBridge($this->db))->aplicar(
                    $usuarioId, (int)$idComercial, $call, $resultado, $next ?? null
                );
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollback();
                error_log('[embudo_resultado_pbx] '.get_class($e).' codigo='.(int)$e->getCode());
            }
        }
"""
CALLS_ATTR = """data-sales-assignment-id="<?= htmlspecialchars(
    (string)(filter_input(INPUT_GET, 'prospecto_asignacion_id', FILTER_VALIDATE_INT) ?: ''),
    ENT_QUOTES, 'UTF-8'
) ?>" """
CALLS_HIDDEN = """<input type="hidden" name="prospecto_asignacion_id" value="<?= htmlspecialchars(
    (string)(filter_input(INPUT_GET, 'prospecto_asignacion_id', FILTER_VALIDATE_INT) ?: ''),
    ENT_QUOTES, 'UTF-8'
) ?>">
  """
JS_ASSIGN = """    // IMPE_EMBUDO_ASIGNACION_RESULTADO_V1: contexto opt-in del prospecto.
    const asignacionEmbudo = String(root.dataset.salesAssignmentId || '');
    form.elements.prospecto_asignacion_id.value =
      /^[1-9][0-9]*$/.test(asignacionEmbudo) ? asignacionEmbudo : '';
"""

class Conflicto(Exception):
    pass

def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()

def unico(s,old,new,desc):
    n=s.count(old)
    if n!=1:
        raise Conflicto('{}: ancla exacta {} vez/veces, se necesita 1'.format(desc,n))
    return s.replace(old,new,1)

def render_service(s):
    if 'IMPE_EMBUDO_PBX_POSTCOMMIT_V1' in s:
        raise Conflicto('Servicio: integración previa distinta, no conciliar')
    if s.count('function guardar(')!=1:
        raise Conflicto('Servicio: método guardar distinto')
    if s.count('if($callbacks)$callbacks->aplicarResultado(')!=1:
        raise Conflicto('Servicio: integración de devoluciones distinta')
    if s.count(ANCHOR_SERVICE)!=1:
        raise Conflicto('Servicio: final de transacción desconocido')
    # Comprobamos que el ancla está DENTRO del método guardar,
    # y después de una confirmación del resultado telefónico.
    start=s.index('function guardar(')
    end=s.index(ANCHOR_SERVICE,start)
    if (end<start or s.count('$this->db->commit();',start,end)!=1):
        raise Conflicto('Servicio: no se confirmó una sola transacción PBX')
    return unico(s,ANCHOR_SERVICE,ANCHOR_SERVICE+'\n'+BRIDGE,'Servicio: enlace post-commit')

def render_form(s):
    if s.count('name="prospecto_asignacion_id"') or s.count("name='prospecto_asignacion_id'"):
        raise Conflicto('Formulario: referencia de embudo ya declarada de otro modo')
    if len(re.findall(r'<form\b[^>]*\bdata-sales-form\b',s))!=1:
        raise Conflicto('Formulario: formulario de Ventas no único')
    ext='<input type="hidden" name="extension">'
    return unico(s,ext,ext+'<input type="hidden" name="prospecto_asignacion_id">','Formulario: extensión')

def render_calls(s):
    if 'data-sales-assignment-id' in s or 'name="prospecto_asignacion_id"' in s:
        raise Conflicto('Mis llamadas: contexto de embudo ya personalizado')
    if len(re.findall(r'<section\b[^>]*\bdata-sales-history\b',s))!=1:
        raise Conflicto('Mis llamadas: contenedor no único')
    s=unico(s,'data-save-url=',CALLS_ATTR+'data-save-url=','Mis llamadas: guardado')
    # El formulario de Hostinger conserva la petición GET, pero su clase
    # y atributos pueden haber cambiado para filtros/evidencias de PBX.
    # Insertar solo después de la etiqueta <form> original, sin reemplazarla.
    formas=list(re.finditer(
        r'<form\\b[^>]*\\bmethod\\s*=\\s*([\\x22\\x27])get\\1[^>]*>',
        s, re.IGNORECASE
    ))
    if len(formas)!=1:
        raise Conflicto('Mis llamadas: filtro GET no único ({} coincidencias)'.format(len(formas)))
    m=formas[0]
    s=s[:m.end()]+'\n  '+CALLS_HIDDEN+s[m.end():]
    return s

def render_js(s):
    if ('IMPE_EMBUDO_ASIGNACION_RESULTADO_V1' in s or
        'form.elements.prospecto_asignacion_id.value' in s):
        raise Conflicto('JS: referencia previa de embudo no conciliada')
    if ('const root=document.querySelector' not in s or
        'data-sales-history' not in s):
        raise Conflicto('JS: contexto de llamada personalizado diferente')
    target='form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;'
    return unico(s,target,target+'\n'+JS_ASSIGN,'JS: selección de llamada PBX')

def lint_php(data,path):
    fd,tmp=tempfile.mkstemp(suffix='.php',prefix='impe-embudo-php-')
    try:
        with os.fdopen(fd,'wb') as f:f.write(data)
        p=subprocess.run(['php','-l',tmp],stdout=subprocess.PIPE,
          stderr=subprocess.PIPE,universal_newlines=True)
        if p.returncode:
            raise Conflicto('PHP inválido en {}: {}'.format(path,(p.stdout+p.stderr)[:450]))
    finally:
        os.unlink(tmp)

def lint_js(data,path):
    node=shutil.which('node')
    if not node:
        print('AVISO: Node.js no instalado. Sintaxis JS se verifica en CI; revisar antes de apply.')
        return
    fd,tmp=tempfile.mkstemp(suffix='.js',prefix='impe-embudo-js-')
    try:
        with os.fdopen(fd,'wb') as f:f.write(data)
        p=subprocess.run([node,'--check',tmp],stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,universal_newlines=True)
        if p.returncode:
            raise Conflicto('JavaScript inválido en {}: {}'.format(path,(p.stdout+p.stderr)[:450]))
    finally:
        os.unlink(tmp)

def preparar(site):
    cambios={}
    for path,expected in FILES.items():
        target=site/path
        if not target.is_file() or target.is_symlink():
            raise Conflicto('Archivo no disponible o enlace: '+path)
        original=target.read_bytes()
        actual=blob(original)
        try:
            s=original.decode('utf-8')
        except UnicodeError:
            raise Conflicto('Archivo no UTF-8: '+path)
        if path==PHP_SERVICE: render=render_service
        elif path==PHP_FORM: render=render_form
        elif path==PHP_CALLS: render=render_calls
        else: render=render_js
        # Cuando ya se publicó correctamente, verificar los marcadores
        # sin intentar insertarlos por segunda vez.
        if actual!=expected:
            markers={
                PHP_SERVICE:'IMPE_EMBUDO_PBX_POSTCOMMIT_V1',
                PHP_FORM:'name="prospecto_asignacion_id"',
                PHP_CALLS:'data-sales-assignment-id',
                JS:'IMPE_EMBUDO_ASIGNACION_RESULTADO_V1'
            }
            marker=markers[path]
            signatures={
                PHP_SERVICE: ANCHOR_SERVICE+'\n'+BRIDGE,
                PHP_FORM: '<input type="hidden" name="extension"><input type="hidden" name="prospecto_asignacion_id">',
                PHP_CALLS: CALLS_ATTR+'data-save-url=',
                JS: 'form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;\n'+JS_ASSIGN
            }
            already=s.count(marker)==1 and s.count(signatures[path])==1
            if path==PHP_CALLS:
                already=already and s.count(CALLS_HIDDEN)==1
            if already:
                print('APLICADO / PERSONALIZADO: {} git_blob={}'.format(path,actual))
                continue
            raise Conflicto('Archivo distinto del diagnóstico o parcial: {} git_blob={}'.format(path,actual))
        revised=render(s).encode('utf-8')
        if revised==original:
            raise Conflicto('Sin diferencias en '+path)
        if path.endswith('.php'):
            lint_php(revised,path)
        else:
            lint_js(revised,path)
        cambios[path]=(original,revised)
        print('CONCILIADO EN MEMORIA: {} {} -> {}'.format(path,actual,blob(revised)))
    return cambios

def publicar(site,backup_root,cambios):
    if not cambios:
        print('SIN CAMBIOS: las referencias ya fueron encontradas')
        return
    if backup_root is None:
        raise Conflicto('--apply necesita --backup-root')
    if backup_root.resolve()==site.resolve() or site.resolve() in backup_root.resolve().parents:
        raise Conflicto('El respaldo tiene que estar fuera de public_html')
    backup_root.mkdir(parents=True,exist_ok=True)
    dest=backup_root/('embudo-telefonia-conciliada-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid()))
    dest.mkdir(mode=0o700)
    manifest=[]
    for path,(old,new) in cambios.items():
        b=dest/path;b.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(str(site/path),str(b))
        manifest.append({'archivo':path,'antes':hashlib.sha256(old).hexdigest(),
                        'despues':hashlib.sha256(new).hexdigest()})
    (dest/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    escritos=[]
    try:
        for path,(old,new) in cambios.items():
            target=site/path
            if target.read_bytes()!=old:
                raise Conflicto('Archivo cambió antes de publicar: '+path)
            fd,temp=tempfile.mkstemp(prefix='.embudo-fusion-',dir=str(target.parent))
            try:
                with os.fdopen(fd,'wb') as f:
                    f.write(new);f.flush();os.fsync(f.fileno())
                os.chmod(temp,target.stat().st_mode & 0o777)
                os.replace(temp,str(target))
                escritos.append(path)
            finally:
                if os.path.exists(temp):os.unlink(temp)
        print('APLICADO: {} archivos conciliados'.format(len(escritos)))
        print('RESPALDO PRIVADO: '+str(dest))
    except Exception:
        for path in reversed(escritos):
            shutil.copy2(str(dest/path),str(site/path))
        print('RESTAURADO TRAS ERROR: '+str(dest),file=sys.stderr)
        raise

def main():
    pa=argparse.ArgumentParser()
    pa.add_argument('--site',required=True)
    pa.add_argument('--backup-root')
    grp=pa.add_mutually_exclusive_group(required=True)
    grp.add_argument('--check',action='store_true')
    grp.add_argument('--apply',action='store_true')
    opts=pa.parse_args()
    site=Path(opts.site).resolve()
    if not site.is_dir():raise Conflicto('Sitio no encontrado')
    cambios=preparar(site)
    if opts.check:
        print('CHECK COMPLETO: {} archivos preparados, CERO escrituras'.format(len(cambios)))
        print('No ejecutar --apply sin verificar antes migración SQL y validación de regresiones PBX.')
    else:
        if not opts.backup_root:raise Conflicto('Falta --backup-root')
        publicar(site,Path(opts.backup_root).resolve(),cambios)
        preparar(site)
        print('POST CHECK OK: código publicado, sin datos ni motores alterados')

if __name__=='__main__':
    try:
        main()
    except (OSError,Conflicto,RuntimeError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr)
        sys.exit(2)
