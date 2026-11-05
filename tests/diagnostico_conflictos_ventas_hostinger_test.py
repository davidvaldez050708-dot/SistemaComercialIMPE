"""Prueba del diagnóstico privado: sólo lectura, sin SQL ni publicación."""
import importlib.util
import pathlib
import tempfile
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
script = root / "tools/diagnosticar_tres_conflictos_ventas_hostinger.py"
spec = importlib.util.spec_from_file_location("impe_ventas_diagnostico", script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix="impe-ventas-tres-variantes-") as folder:
    home=pathlib.Path(folder)
    site=home/"public_html"
    site.mkdir()
    (site/"index.php").write_text("<?php")
    original={}
    for rel in module.TARGETS:
        path=site/rel
        path.parent.mkdir(parents=True,exist_ok=True)
        original[rel]=b"<?php // archivo personalizado: " + rel.encode("ascii")
        path.write_bytes(original[rel])
    original["index.php"]=(site/"index.php").read_bytes()
    snapshots,metadata=module.read_snapshot(site.resolve())
    assert set(snapshots)==set(module.TARGETS)
    assert len(metadata)==3
    private=home/"private"
    zip_path=module.create_bundle(private,snapshots,metadata)
    assert zip_path.parent==private
    assert zip_path.stat().st_mode&0o777==0o600
    with zipfile.ZipFile(zip_path) as z:
        assert set(z.namelist())==set(module.TARGETS)|{"manifest.json","LEEME.txt"}
        for rel in module.TARGETS:
            assert z.read(rel)==original[rel]
    for rel,data in original.items():
        assert (site/rel).read_bytes()==data
    wrong=site/"app/controllers/TelefoniaController.php"
    wrong.unlink()
    wrong.symlink_to(home/"private/invalid.php")
    try:
        module.read_snapshot(site.resolve())
        raise AssertionError("Accepted symbolic link")
    except RuntimeError as error:
        assert "archivo regular" in str(error)
print("PASS: diagnóstico incluye sólo tres fuentes, hashes, archivo privado; código original intacto y enlaces simbólicos rechazados.")
