"""Preflight, personalizaciones, conflicto, aplicación y rollback en carpeta aislada."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PUB=ROOT/'tools/publicar_oficios_modal_hostinger.py'
MANIFEST=ROOT/'tools/oficios_modal_documento_final_manifest.json'
spec=json.loads(MANIFEST.read_text(encoding='utf-8'))
assert len(spec['files'])==5
with tempfile.TemporaryDirectory(prefix='impe-oficios-seguro-') as td:
    base=Path(td)
    site=base/'sitio';site.mkdir()
    (site/'index.php').write_text('<?php // CRM aislado',encoding='utf-8')
    backups=base/'respaldos'
    for row in spec['files']:
        file=site/row['path']
        file.parent.mkdir(parents=True,exist_ok=True)
        original=subprocess.check_output(['git','show',spec['base']+':'+row['path']],cwd=str(ROOT))
        if file.suffix=='.php':
            original+=b'\n// HOSTINGER: personalizacion compatible\n'
        else:
            original=b'/* HOSTINGER: personalizacion compatible */\n'+original
        file.write_bytes(original)
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*flags,ok=True):
        p=subprocess.run([sys.executable,str(PUB),'--site',str(site),
           '--backup-root',str(backups),'--manifest',str(MANIFEST)]+list(flags),
           stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        value=(p.stdout+p.stderr).decode('utf8','replace')
        if (p.returncode==0)!=ok:
            raise AssertionError(value)
        return value
    initial=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==initial and not backups.exists(),'--check debe ser lectura pura'
    f=site/'public/javascript/oficios_vinculacion.js'
    old=f.read_bytes()
    h=next(x for x in spec['files'] if x['path']==f.relative_to(site).as_posix())['changes'][0]['before'].encode('utf8')
    assert old.count(h)==1
    f.write_bytes(old.replace(h,b'// PERSONALIZACION CONFLICTIVA',1))
    conflicted=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==conflicted and not backups.exists()
    f.write_bytes(old)
    assert 'APLICADO:' in run('--apply')
    after=snapshot()
    for row in spec['files']:
        changed=after[row['path']]
        assert b'HOSTINGER: personalizacion compatible' in changed,row['path']
        assert changed!=initial[row['path']]
    folders=[p for p in backups.iterdir() if p.is_dir()]
    assert len(folders)==1
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    assert len([p for p in backups.iterdir() if p.is_dir()])==1
    f.write_bytes(f.read_bytes()+b'\n// CAMBIO POSTERIOR\n')
    assert 'CONFLICTO' in run('--restore',str(folders[0]),ok=False)
    f.write_bytes(after[f.relative_to(site).as_posix()])
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    assert snapshot()==initial,'El rollback debe preservar íntegramente las personalizaciones'

    # Simula el método de Hostinger descrito en el diagnóstico del sitio.
    # La prueba autoriza el hash de la variante de laboratorio; en producción
    # el publicador sólo acepta el SHA256 exacto verificado en Hostinger.
    import importlib.util
    spec_pub=importlib.util.spec_from_file_location('oficios_publicador',str(PUB))
    pub=importlib.util.module_from_spec(spec_pub)
    spec_pub.loader.exec_module(pub)
    assert pub.HOSTINGER_DOCX_SHA256=='ff3720e6905ad92ddd4515f6e8a793d68a4cfb9a29476ba2862abd75c2241440'
    path_converter=site/'app/services/OficioDocxPdfService.php'
    source=path_converter.read_bytes()
    old_signature=b'public function generarDocx(array $vista)'
    assert source.count(old_signature)==1
    synthetic=source.replace(old_signature,
        b'public function generarDocx(array $vista, $prepararPlantilla = false)',1)
    old_fill=b'            $rellenado = $this->rellenarConTemplateProcessor($rutaDocx, $vista);'
    assert synthetic.count(old_fill)==1
    new_fill=r"""
            // Como en Hostinger: omitir relleno de generaciones guardadas.
            $procesada = !$prepararPlantilla && preg_match(
                '~^storage/templates/oficios_personalizados/generacion_\d{8}_\d{6}_[a-f0-9]{12}\.docx$~D',
                str_replace('\\', '/', (string)($vista['archivo_plantilla'] ?? ''))
            ) === 1;
            $rellenado = $procesada ? ['ok' => true] : (
                trim((string)($vista['archivo_plantilla'] ?? '')) !== ''
                    ? $this->rellenarConTemplateProcessor($rutaDocx, $vista)
                    : $this->rellenarDocx($rutaDocx, $this->construirReemplazos($vista))
            );""".encode('utf-8')
    synthetic=synthetic.replace(old_fill,new_fill,1)
    pub.HOSTINGER_DOCX_SHA256=pub.sha(synthetic)
    assert pub.is_existing_hostinger_docx(synthetic)
    assert not pub.is_existing_hostinger_docx(source)
    path_converter.write_bytes(synthetic)
    variant_snapshot=snapshot()
    rows=pub.verify(site,spec)
    entry=next(x for x in rows if x['name']=='app/services/OficioDocxPdfService.php')
    assert entry['before']==entry['after']==synthetic,'No reescribir el convertidor que ya cumple'
    private_variant=base/'respaldos-convertidor-personalizado'
    pub.publish(site,private_variant,spec)
    assert path_converter.read_bytes()==synthetic,'El convertidor personalizado no debe cambiar tras publicar'
    published=snapshot()
    assert published['app/controllers/OficioVinculacionController.php']!=variant_snapshot['app/controllers/OficioVinculacionController.php']
    assert len([x for x in private_variant.iterdir() if x.is_dir()])==1
    folder_variant=next(x for x in private_variant.iterdir() if x.is_dir())
    pub.restore(site,private_variant,str(folder_variant))
    assert snapshot()==variant_snapshot,'Rollback debe dejar intacto el convertidor personalizado'


    # Reproducir el controlador real reportado por Hostinger: el método ya
    # pasa TRUE al preparar una copia y conserva FALSE al descargarla.
    controller_path=site/'app/controllers/OficioVinculacionController.php'
    controller_base=controller_path.read_bytes()
    old_call=(b'            $documento = (new OficioDocxPdfService())->generarDocx(\n'
              b"                (array)($vista['vista_previa'] ?? [])\n"
              b'            );')
    new_call=(b'            $documento = (new OficioDocxPdfService())->generarDocx(\n'
              b"                (array)($vista['vista_previa'] ?? []),\n"
              b'                true // Primera preparaci\xc3\xb3n de esta copia, no una descarga de un oficio preparado.\n'
              b'            );')
    assert controller_base.count(old_call)==1,'La prueba no reconoce el controlador base'
    prepared=controller_base.replace(old_call,new_call,1)
    pub.HOSTINGER_OFICIOS_CONTROLLER_SHA256=pub.sha(prepared)
    assert pub.controller_has_prepared_flag(
        prepared,next(x for x in spec['files'] if x['path']=='app/controllers/OficioVinculacionController.php'))
    controller_path.write_bytes(prepared)
    original_prepared=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==original_prepared,'El nuevo preflight no debe editar ni borrar código'
    assert 'APLICADO' in run('--apply')
    converted=controller_path.read_bytes()
    assert converted.count(new_call)==1,'Reescribió la llamada TRUE personalizada'
    assert converted.count(b"['predeterminada', 'personalizada', 'documento_final']")==1
    assert 'YA INSTALADO' in run('--check'),'La variante debe ser idempotente'
    archive_prepared=sorted([x for x in backups.iterdir() if x.is_dir()])[-1]
    assert 'RESTAURADO' in run('--restore',str(archive_prepared))
    assert snapshot()==original_prepared,'La reversión debe recuperar el controlador intacto'

print('PASS publicador oficios: 5 archivos, --check sin escritura, compatibilidad, idempotencia, conflicto y rollback')
