#!/usr/bin/env python3
"""Genera manifiesto autocontenido de archivos selectivos desde commits revisados."""
import argparse
import base64
import gzip
import hashlib
import json
import io
from pathlib import Path
import subprocess

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--base',required=True)
parser.add_argument('--source',default='HEAD')
parser.add_argument('--output',default='tools/aliados_registro_release.json')
args=parser.parse_args()
root=Path(__file__).resolve().parent.parent
def git(*argv):
    return subprocess.check_output(['git','-C',str(root)]+list(argv))
def pack(data):
    buffer=io.BytesIO()
    with gzip.GzipFile(fileobj=buffer,mode='wb',mtime=0) as compressed:
        compressed.write(data)
    return base64.b64encode(buffer.getvalue()).decode('ascii')
source=git('rev-parse',args.source).decode().strip()
base=git('rev-parse',args.base).decode().strip()
files=[]
for path in git('diff','--name-only',base,source).decode().splitlines():
    if not (path.startswith(('app/','public/')) or path in ['database/migrations/2026_10_21_aliados_registro.sql','tools/migrar_aliados_registro.php']):
        continue
    if 'telefonia' in path.lower() or 'inscripcion' in path.lower():
        raise RuntimeError('Fuera de alcance: '+path)
    desired=git('show',source+':'+path)
    has_base=subprocess.run(['git','-C',str(root),'cat-file','-e',base+':'+path],stdout=subprocess.PIPE,stderr=subprocess.PIPE).returncode==0
    original=git('show',base+':'+path) if has_base else None
    files.append({'path':path,'sha256':hashlib.sha256(desired).hexdigest(),'base':pack(original) if original is not None else None,'target':pack(desired)})
Path(args.output).write_text(json.dumps({'base':base,'source':source,'files':files},indent=2),encoding='utf-8')
print('Entrega selectiva: {} archivos, commit {}'.format(len(files),source))
