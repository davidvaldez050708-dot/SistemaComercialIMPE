"""Reconstrucción estricta de los archivos auditados; no acepta parches por aproximación."""
from pathlib import Path
import hashlib
import json
import re
import subprocess

BASE='d9db39603d923bd475d7348c8f981b6540009412'
SHA256={
 'app/models/RolModel.php':'c78520a31c8831cc94fbcfde7d39bca18d19b374725598b37e9d204f33f7e783',
 'app/views/roles/index.php':'9632c342156f625b36aa0878f5edc906c9a44063688d81e1d22d1c302eeb460e',
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
    for rel,data in files.items():assert hashlib.sha256(data).hexdigest()==SHA256[rel],rel
    assert files.keys()==SHA256.keys()
    return files

if __name__=='__main__':
    for rel in reconstruir():print('SHA256_HOSTINGER_OK:',rel)
