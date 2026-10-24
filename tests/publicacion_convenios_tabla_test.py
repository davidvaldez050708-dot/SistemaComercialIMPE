"""Publicador de tabla: bytes reales Hostinger, QR intacto, conflicto y restauración."""
import importlib.util
import hashlib
import re
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base_test',str(ROOT/'tests/publicacion_convenios_detalle_etapa3_test.py'))
base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)
base.MANIFEST=ROOT/'tools/convenios_tabla_release.json'
base.FILES={'public/css/convenios_directorio_tabla.css','public/javascript/crear_convocatoria.js','public/javascript/crear_convocatoria_v2_extension.js','public/javascript/convenios_borradores_v4.js','app/views/convocatorias/crear_desde_convenio.php'}
unpack=lambda value:base.gzip.decompress(base.base64.b64decode(value)).replace(b'\r\n',b'\n')
class PublicacionTabla(base.ConveniosDetalleSelectivo):
    hosted=False
    def install(self,crlf=False,customized=False):
        originals=super().install(crlf,customized)
        if self.hosted:
            for row in self.manifest['files']:
                if not row.get('variants'):continue
                data=unpack(row['variants'][0]['base'])
                if crlf:data=data.replace(b'\n',b'\r\n')
                (self.site/row['path']).write_bytes(data);originals[row['path']]=data
        return originals
    def unchanged(self,originals):
        for name,data in originals.items():self.assertEqual((self.site/name).read_bytes() if (self.site/name).exists() else None,data,name)
    def verify(self,crlf=False,customized=False):
        if not self.hosted:return super().verify(crlf,customized)
        originals=self.install(crlf);self.run_tool('--check');self.unchanged(originals);output=self.run_tool('--apply')
        for row in self.manifest['files']:
            expected=unpack(row['variants'][0]['target'] if row.get('variants') else row['target'])
            self.assertEqual((self.site/row['path']).read_bytes().replace(b'\r\n',b'\n'),expected)
        self.assertIn('ya publicada',self.run_tool('--apply'))
        self.run_tool('--restore',output.split('Respaldo: ')[1].splitlines()[0]);self.unchanged(originals)
    def test_hostinger_exacto(self):self.hosted=True;self.verify()
    def test_hostinger_crlf(self):self.hosted=True;self.verify(crlf=True)
    def test_conflicto_no_escribe_ningun_archivo(self):
        originals=self.install();name='app/views/convocatorias/crear_desde_convenio.php'
        data=originals[name].replace(b'class="convenios-fila convenios-list-row"',b'class="fila-personalizada"');self.assertNotEqual(data,originals[name]);(self.site/name).write_bytes(data);originals[name]=data
        self.assertIn('CONFLICTO',self.run_tool('--apply',ok=False));self.unchanged(originals)
    def test_personalizador_qr_integro(self):
        row=next(r for r in self.manifest['files'] if r['path'].endswith('convenios_borradores_v4.js'))
        before,after=[unpack(row['variants'][0][key]) for key in ['base','target']]
        marker=b'/* IMPE_CONVENIOS_QR_COMPARTIDO_V5_20261023'
        self.assertEqual(before[before.index(marker):],after[after.index(marker):])
    def test_hashes_instrucciones_corresponden_a_descarga_git(self):
        doc=(ROOT/'docs/CONVENIOS_TABLA_INSTITUCIONAL.md').read_text(encoding='utf-8')
        revision=re.search(r'(?m)^REV=([0-9a-f]{40})$',doc).group(1)
        hashes=dict((name,digest) for digest,name in re.findall(r"'([0-9a-f]{64})  (publicar\.py|entrega\.json)'",doc))
        paths={'publicar.py':'tools/publicar_aliados_registro.py','entrega.json':'tools/convenios_tabla_release.json'}
        self.assertEqual(set(hashes),set(paths))
        for name,path in paths.items():
            # Git almacena LF aunque el checkout Windows convierta a CRLF.
            published=subprocess.check_output(['git','show',revision+':'+path],cwd=str(ROOT))
            self.assertEqual(hashes[name],hashlib.sha256(published).hexdigest(),name)
    def test_paquete_coincide_con_commit_y_solo_interfaz(self):
        for row in self.manifest['files']:
            self.assertEqual(unpack(row['target']),subprocess.check_output(['git','show',self.manifest['source']+':'+row['path']],cwd=str(ROOT)).replace(b'\r\n',b'\n'))
        self.assertFalse(any(r['path'].endswith('.sql') or '/prospectos/' in r['path'] or '/controllers/' in r['path'] or '/models/' in r['path'] for r in self.manifest['files']))
    def test_restore_no_pisa_cambios_posteriores(self):
        self.install();output=self.run_tool('--apply');name='public/css/convenios_directorio_tabla.css';file=self.site/name;file.write_bytes(file.read_bytes()+b'/* Cambio posterior */\n')
        current={r['path']:(self.site/r['path']).read_bytes() for r in self.manifest['files']}
        self.assertIn('archivo modificado',self.run_tool('--restore',output.split('Respaldo: ')[1].splitlines()[0],ok=False));self.unchanged(current)
if __name__=='__main__':unittest.main()
