#!/usr/bin/env python3
"""Paquete exclusivamente visual sobre la ficha lateral ya instalada."""
import base64
import gzip
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='4c7182877f0781a8d62d9ea6cc97757efac616d9'
FILES={
    'public/css/prospectos_ficha_panel.css':'f2aa740e7be2c2df1edf843683cc62b58ac6b954091888206f3959f703770252',
    'public/javascript/prospectos_ficha_panel.js':'13c2106f0688040c742f4f1a1d7a895888a26102916c832529b80bcc9bee05cf',
}
DEPENDENCIES={
    'public/javascript/prospectos_central.js':'b7d8ef79c76ea3d18d9b19fef6be742f599b0a3c711b4d1a8a4ac03348781d7d',
    'public/css/seguimiento_estabilizacion_visual.css':'df3231476ba8c53e4c763cab2884be6a0e5f0d2345d855a7ce92e13fd258b956',
}


def sha(data):
    return hashlib.sha256(data).hexdigest()


def pack(data):
    return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')


def build():
    rows=[]
    for name,expected in FILES.items():
        before=subprocess.check_output(['git','show',BASE+':'+name],cwd=str(ROOT))
        if sha(before)!=expected:
            raise RuntimeError('La base no coincide con Hostinger: '+name)
        after=(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
        rows.append({'path':name,'base_sha256':expected,'base':pack(before),
                     'sha256':sha(after),'target':pack(after)})
    return {'schema':1,'source':'prospectos-panel-visual-v1','files':rows,
            'dependencies':[{'path':n,'sha256':s} for n,s in DEPENDENCIES.items()]}


if __name__=='__main__':
    (ROOT/'tools/prospectos_panel_visual_release.json').write_text(
        json.dumps(build(),ensure_ascii=False,indent=2)+'\n',encoding='utf-8',newline='\n')
    print('Manifiesto visual: 2 archivos y 2 dependencias de sólo lectura.')
