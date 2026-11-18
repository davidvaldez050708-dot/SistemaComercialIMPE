"""Reconstrucción estricta de los archivos auditados; no acepta parches por aproximación."""
from pathlib import Path
import hashlib
import json
import re
import subprocess

BASE='aa0d27a9f4403f2ebe30d5afd3430979ab73c791'
SHA256={
 'app/models/WhatsAppModel.php':'d364c4c1777033fd4d11e7dd56194eebebb783f06e06f1ca326cb6a973bc786c',
 'app/views/whatsapp/index.php':'ab8d5e0cd5e6b029c821006659d987e0a75894bd6b055b867f6fceeb4c2c593a',
 'public/javascript/whatsapp.js':'614fe5a5058e156c9b5bbe95dbcc1e01c8134f3cb9664d8ce81ce8156159d391',
 'app/views/embudo_comercial/operativo_v3.php':'a4696a2ff179280714a0abcc4adae49feb473601cbc352e8ba56182c817dc13c',
 'app/views/aliados/estado.php':'79283b5266d245c79770a348c6e17278e1092893709284b718ec18e9cc7d6c28',
 'app/views/seguimiento_vinculacion/detalle.php':'d0bceb50458eab3b9952df0a5ef366e23fab94e6a2ab2e10f3c56ae5d52ae0c4',
 'app/controllers/WhatsAppController.php':'c14643115bbe25d881ddcea1c745ce8e65613672a4851136dc12040150b5ccee',
 'app/services/WhatsAppCloudApiService.php':'4b13a512ffe49b398b35b9bc37b67e81d0c2eea99f2402c73d26ed03a2e7f2a6',
 'app/services/ProspectoNoContactarV3.php':'42e71aed24436f14faa7b5a014ca6e57fe70db5e4d05e65ec36dc42aa5cae26e',
 'app/helpers/PermissionHelper.php':'1ef40e3df10307ec89d047c32a373c8244fd03e83828200d63af9d34680f5bc2',
 'app/helpers/AdminRolePolicy.php':'b8d459e5937bf812b30b29bfc746a6850a3742fcb749b382d8ff2f628c6ca97a',
 'index.php':'4e10d4771b68a28178822c692bb52f03601df6728e4d59de98a5f21ae5013c8b',
 'app/helpers/SupervisionRolePolicy.php':'f9542f3c578d953cfde72f807cf98bc770beffac7e606f8bf9f975d5324d001b',
}

def reconstruir():
    root=Path(__file__).resolve().parents[2]
    audit=json.loads(Path(__file__).with_suffix('.patch.json').read_text(encoding='utf-8'))
    assert audit['base_revision']==BASE
    files={}
    for section in re.split(r'^ARCHIVO: ',''.join(audit['diff_lines']),flags=re.M)[1:]:
        rel=section.splitlines()[0].strip()
        assert rel in SHA256 and rel not in files,rel
        original=subprocess.check_output(['git','show',BASE+':'+rel],cwd=root).decode().splitlines(True)
        lines=section.splitlines(True)[1:];out=[];pos=i=0
        while i<len(lines):
            m=re.match(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@',lines[i])
            if not m:i+=1;continue
            start=int(m[1])-1;assert start>=pos
            out.extend(original[pos:start]);pos=start;i+=1;removed=added=0
            while i<len(lines) and lines[i][:1] in (' ','+','-') and not lines[i].startswith(('+++','---')):
                line=lines[i];payload=line[1:]
                no_newline=i+1<len(lines) and lines[i+1].startswith('\\ No newline')
                if no_newline:payload=payload.removesuffix('\n')
                if line[0] in (' ','-'):
                    assert original[pos]==payload,(rel,pos,repr(original[pos]),repr(payload))
                    pos+=1;removed+=1
                if line[0] in (' ','+'):out.append(payload);added+=1
                i+=2 if no_newline else 1
            assert removed==int(m[2] or 1) and added==int(m[4] or 1),(rel,m[0],removed,added)
        out.extend(original[pos:]);files[rel]=''.join(out).encode()
    for rel in SHA256:
        if rel in files:continue
        if rel in audit['extra_files']:files[rel]=audit['extra_files'][rel].encode()
        else:
            oid='af214cb21fc5322b6cbb2e0a204ee8c5e32cfe15' if rel=='app/views/embudo_comercial/operativo_v3.php' else BASE+':'+rel
            files[rel]=subprocess.check_output(['git','show',oid],cwd=root)
    for rel,data in files.items():assert hashlib.sha256(data).hexdigest()==SHA256[rel],rel
    assert files.keys()==SHA256.keys()
    return files

if __name__=='__main__':
    for rel in reconstruir():print('SHA256_HOSTINGER_OK:',rel)
