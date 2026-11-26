"""Publicador: copia temporal, huellas, preservación y reversión. Sin red."""
import importlib.util, pathlib, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('publicador', root/'tools/publicar_denue_circuito_hostinger.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)
base = 'b38a95a713dbd97cbcde248f331c73afaee202fe'
with tempfile.TemporaryDirectory() as temp:
    d = pathlib.Path(temp)
    site = d/'site'; site.mkdir()
    (site/'index.php').write_text('<?php')
    (site/'storage/cache/denue').mkdir(parents=True)
    backup = d/'backup'
    def fixture(name):
        data = subprocess.check_output(['git','show',base+':'+name],cwd=str(root))
        path = site/name; path.parent.mkdir(parents=True,exist_ok=True); path.write_bytes(data)
        return data
    deps = []
    for name, expected in p.DEPENDENCIES:
        data=fixture(name)
        # La vista y el controlador de Hostinger son personalizados; se simula su huella.
        if '/controllers/' not in name and '/views/' not in name:
            assert p.digest(data)==expected
        deps.append((name,p.digest(data)))
    p.DEPENDENCIES=deps
    for name,before,after in p.FILES:
        if before: assert p.digest(fixture(name))==before
        assert p.digest((root/name).read_bytes())==after
    snapshot=lambda: {str(x.relative_to(site)):x.read_bytes() for x in site.rglob('*') if x.is_file()}
    original=snapshot()
    p.prepare(site,root)
    assert snapshot()==original and not backup.exists()
    p.apply(site,root,backup)
    for name,before,after in p.FILES: assert p.digest((site/name).read_bytes())==after
    for name,_ in deps: assert (site/name).read_bytes()==original[name]
    p.apply(site,root,backup)
    assert len(list(backup.iterdir()))==1
    p.restore(site,backup,next(backup.iterdir()))
    assert snapshot()==original
    path=site/p.FILES[1][0]
    path.write_bytes(path.read_bytes()+b'\n')
    changed=snapshot()
    try:
        p.prepare(site,root)
    except RuntimeError as e:
        assert 'VERSION DIFERENTE' in str(e)
    else:
        raise AssertionError('No rechazó versión diferente')
    assert snapshot()==changed
print('Publicador DENUE: check, aplicación, idempotencia, reversión y rechazo de versión OK')
