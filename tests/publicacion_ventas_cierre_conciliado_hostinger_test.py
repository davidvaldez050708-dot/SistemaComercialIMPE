"""Ensayo exhaustivo y reversible del publicador selectivo sobre variantes simuladas."""
import contextlib
import hashlib
import importlib.util
import io
import pathlib
import subprocess
import sys
import tempfile
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location(
    "impe_ventas_conciliado",ROOT/"tools/publicar_ventas_cierre_conciliado_hostinger.py")
pub=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pub)
assert len(pub.FILES)==7
assert len(pub.DEPS)==2
assert len(pub.CUSTOM)==3

def historical(path):
    ref=("2b65829adcb30d4e4b92e2f8adc79a7457e76f9d"
         if path in ("app/services/TelefoniaResultadoVentasService.php",
                     "public/javascript/telefonia_ventas_resultados.js")
         else "28afc28b330a59e88660179e592b6541a3804d2a")
    return subprocess.check_output(["git","show",ref+":"+path],cwd=str(ROOT))

def execute(site,backups,action,*other):
    argv=["publisher","--site",str(site),"--backup-root",str(backups),action]+list(other)
    with contextlib.redirect_stdout(io.StringIO()) as report,patch.object(sys,"argv",argv):
        pub.main()
    return report.getvalue()

with tempfile.TemporaryDirectory(prefix="impe-conciliado-ventas-") as temp:
    base=pathlib.Path(temp)
    site=base/"public_html"
    site.mkdir()
    (site/"index.php").write_text("<?php")
    backups=base/"private-backups"
    original={}
    for path in pub.FILES:
        dest=site/path
        dest.parent.mkdir(parents=True,exist_ok=True)
        if path=="app/services/TelefoniaVentasCierreApiVerificado.php":
            original[path]=None
            continue
        code=historical(path)
        # Variantes de prueba sobre archivos reales de Git: diferentes del
        # repositorio, pero con métodos originales intactos.
        if path in pub.CUSTOM:
            code+=b"\n/* PERSONALIZACION HOSTINGER PROTEGIDA */\n"
            pub.CUSTOM[path]["base"]="sha256:"+pub.digest(code)
        original[path]=code
        dest.write_bytes(code)
    controller=site/"app/controllers/TelefoniaController.php"
    controller.parent.mkdir(parents=True,exist_ok=True)
    controller.write_bytes(historical("app/controllers/TelefoniaController.php")+
                           b"\n/* CONTROLLER PERSONALIZADO, INTACTO */\n")
    evidence=site/"app/services/TelefoniaVentasEvidenciaService.php"
    evidence.write_bytes(b"<?php // EVIDENCIA PRIVADA PROTEGIDA")
    pub.DEPS={
        "app/controllers/TelefoniaController.php":pub.digest(controller.read_bytes()),
        "app/services/TelefoniaVentasEvidenciaService.php":pub.digest(evidence.read_bytes())
    }
    pub.FILES={
        path:(("sha256:"+pub.digest(original[path])) if original[path] is not None else None,after)
        for path,(_,after) in pub.FILES.items()
    }
    pub.fetch=lambda path,sha:(ROOT/path).read_bytes()
    before={p:p.read_bytes() for p in site.rglob("*") if p.is_file()}
    report=execute(site,backups,"--check")
    assert report.count("CONCILIADO PERSONALIZADO:")==3,report
    assert "PREPUBLICACIÓN COMPATIBLE: 7 archivos" in report,report
    assert not backups.exists()
    assert all(p.read_bytes()==b for p,b in before.items())
    report=execute(site,backups,"--apply")
    assert "APLICADO" in report,report
    for path in pub.CUSTOM:
        code=(site/path).read_bytes()
        assert b"PERSONALIZACION HOSTINGER PROTEGIDA" in code
        assert code!=original[path]
        assert "YA CONCILIADO PERSONALIZADO"==pub.reconcile_custom(path,code)[1]
        assert (site/path).read_bytes()==code
    assert controller.read_bytes()==before[controller]
    assert evidence.read_bytes()==before[evidence]
    assert "YA INSTALADO" in execute(site,backups,"--check")
    folder=next(backups.iterdir())
    # No sobreescribir ni restaurar cambios que otro usuario haya efectuado.
    modified=site/"public/javascript/telefonia_host.js"
    current=modified.read_bytes()
    modified.write_bytes(current+b"\n// MODIFICACION CONCURRENTE")
    try:
        execute(site,backups,"--restore",str(folder))
        raise AssertionError("Restauró sobre personalización desconocida")
    except RuntimeError as e:
        assert "VERSIÓN DIFERENTE" in str(e),str(e)
    modified.write_bytes(current)
    assert "RESTAURADO" in execute(site,backups,"--restore",str(folder))
    for path,data in original.items():
        target=site/path
        assert target.read_bytes()==data if data is not None else not target.exists(),path
    assert controller.read_bytes()==before[controller]
    assert evidence.read_bytes()==before[evidence]
    # Un cambio sólo de un caracter en el controlador cancela --check.
    controller.write_bytes(controller.read_bytes()+b"\n// otra variante")
    try:
        execute(site,backups,"--check")
        raise AssertionError("Se aceptó otro controlador")
    except RuntimeError as e:
        assert "DEPENDENCIA DIFERENTE" in str(e),str(e)
print("PASS: 7 archivos, controller y grabaciones conservados, 3 parches exactos, PHP/JS, idempotencia, rollback, conflictos.")
