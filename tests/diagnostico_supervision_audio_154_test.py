"""Solo cuatro fuentes y sin escrituras en producción."""
import importlib.util
import pathlib
import tempfile
import zipfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location(
    "diagnostico_supervision",ROOT/"tools/diagnosticar_supervision_audio_154_hostinger.py")
module=importlib.util.module_from_spec(s)
s.loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix="impe-supervision-154-") as folder:
    base=pathlib.Path(folder)
    site=base/"public_html"
    site.mkdir()
    (site/"index.php").write_text("<?php")
    original={}
    for name in module.SOURCE_FILES:
        path=site/name
        path.parent.mkdir(parents=True,exist_ok=True)
        original[name]=b"<?php // Fuente aislada: "+name.encode("ascii")
        path.write_bytes(original[name])
    file_map, hashes=module.read_snapshot(site.resolve())
    assert len(file_map)==len(hashes)==4
    for h in hashes:
        assert len(h["git_blob"])==40
        assert len(h["sha256"])==64
    private=base/"private"
    zip_file=module.save_private(private,site.resolve(),file_map,hashes)
    assert zip_file.parent==private
    assert zip_file.stat().st_mode&0o777==0o600
    with zipfile.ZipFile(zip_file) as z:
        assert set(z.namelist())==set(module.SOURCE_FILES)|{"manifest.json","LEEME.txt"}
        for name, raw in original.items():
            assert z.read(name)==raw
    for name, raw in original.items():
        assert (site/name).read_bytes()==raw
    try:
        module.save_private(site/"backups",site.resolve(),file_map,hashes)
        raise AssertionError("Permitió publicar ZIP en webroot")
    except RuntimeError:
        pass
    file=site/module.SOURCE_FILES[0]
    file.unlink()
    file.symlink_to(private/"falso")
    try:
        module.read_snapshot(site.resolve())
        raise AssertionError("Permitió leer enlace simbolico")
    except RuntimeError:
        pass
print("PASS: cuatro fuentes, hashes, ZIP privado, sitio intacto, ruta web y symlinks rechazados.")
