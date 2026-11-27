"""Sincronizar código candidato con el fixture privado; nunca copia configuración."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil

SOURCE=Path(__file__).resolve().parents[3]


def sync(root):
    root=Path(root).resolve();meta=json.loads((root/'lab.json').read_text());c=json.loads((root/'credentials.json').read_text())
    if meta.get('synthetic') is not True or c['host']!='127.0.0.1' or c['port']!=3308 or c['database']!='impe_telefonia_lab':raise RuntimeError('No es el fixture aislado')
    if (root/'site').is_symlink() or root==SOURCE or SOURCE in root.parents:raise RuntimeError('Ruta privada no válida')
    hashes={}
    for folder in ['app','public','prueba_telefonia']:
        for p in (SOURCE/folder).rglob('*'):
            if not p.is_file() or p.is_symlink() or p.suffix not in ('.php','.js','.css'):continue
            name=p.relative_to(SOURCE)
            if 'config' in name.parts or p.name in ('db_connection.php','zadarma_config.php'):continue
            out=root/'site'/name;out.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,out)
            hashes[name.as_posix()]=hashlib.sha256(p.read_bytes()).hexdigest()
    digest=hashlib.sha256(json.dumps(hashes,sort_keys=True).encode()).hexdigest()
    (root/'sources.json').write_text(json.dumps(dict(sha256=digest,files=hashes),indent=2))
    return digest


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--lab-root',required=True);args=p.parse_args();print('LAB_SYNC_OK SHA256='+sync(args.lab_root))
