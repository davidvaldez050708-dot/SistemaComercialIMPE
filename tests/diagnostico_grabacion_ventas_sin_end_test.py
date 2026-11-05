"""El diagnóstico de audio de Ventas jamás escribe en public_html ni consulta datos."""
import importlib.util
import pathlib
import tempfile
import zipfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("diagnostico",
    ROOT/"tools/diagnosticar_grabacion_ventas_sin_end_hostinger.py")
diagnostico=importlib.util.module_from_spec(spec)
spec.loader.exec_module(diagnostico)

with tempfile.TemporaryDirectory(prefix="impe-ventas-audio-") as folder:
    root=pathlib.Path(folder)
    site=root/"public_html"
    site.mkdir()
    (site/"index.php").write_bytes(b"<?php")
    originals={}
    for relative in diagnostico.SOURCE_FILES:
        file=site/relative
        file.parent.mkdir(parents=True,exist_ok=True)
        contents=b"<?php // fixture de "+relative.encode("ascii")
        file.write_bytes(contents)
        originals[relative]=contents
    data,manifest=diagnostico.read_sources(site.resolve())
    assert len(data)==6
    assert len(manifest)==6
    for row in manifest:
        assert len(row["sha256"])==64
        assert len(row["git_blob"])==40
    priv=root/"privado"
    output=diagnostico.make_zip(priv,data,manifest)
    assert output.is_file()
    assert output.stat().st_mode&0o777==0o600
    with zipfile.ZipFile(output,"r") as z:
        expected=set(originals)|{"manifest.json","LEEME.txt"}
        assert set(z.namelist())==expected
        for file,contents in originals.items():
            assert z.read(file)==contents
    for file,contents in originals.items():
        assert (site/file).read_bytes()==contents
    malicious=site/diagnostico.SOURCE_FILES[0]
    malicious.unlink()
    malicious.symlink_to(root/"privado/falso.php")
    try:
        diagnostico.read_sources(site.resolve())
        raise AssertionError("Symlink accepted")
    except RuntimeError:
        pass
print("PASS: six code-only files, protected private ZIP, no production changes.")
