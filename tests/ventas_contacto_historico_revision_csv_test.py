"""Prepara CSV privado de 118 llamadas sin clasificar contactos automáticamente."""
import csv
import importlib.util
import json
import os
import pathlib
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
p=ROOT/"tools/ventas_contacto_historico_preparar_revision.py"
spec=importlib.util.spec_from_file_location("revision",str(p))
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

def check(ok,label):
    if not ok: raise AssertionError(label)

with tempfile.TemporaryDirectory(prefix="impe-revision-whatsapp-") as temp:
    root=pathlib.Path(temp)
    site=root/"public_html"
    site.mkdir()
    (site/"index.php").write_text("<?php")
    out=root/"private"
    out.mkdir(mode=0o700)
    rows=[]
    for i in range(118):
        rows.append({
          "resultado_id":i+1,
          "usuario_id":9 if i<41 else 7,
          "extension":"152" if i<41 else "155",
          "pbx_call_id":"out_20261106_"+str(i+1).zfill(8),
          "categoria":(mod.CANDIDATE if i<116
                       else "SIN_RESPUESTA_PBX_ACREDITADA"),
          "fecha_llamada":"2026-11-06 10:00:00",
          "fecha_registro":"2026-11-06 10:05:00",
          "direccion":"Saliente",
          "conexion_pbx":i<116,
          "referencia_grabacion_local":True,
          "revision_humana":"PENDIENTE",
          "observacion_revision":"",
        })
    report={
      "tipo":"AUDITORIA_SOLO_LECTURA",
      "resumen":{"total":len(rows)},
      "llamadas":rows,
    }
    audit=out/"auditoria.json"
    audit.write_text(json.dumps(report),encoding="utf-8")
    export, prepared=mod.prepare(site,audit,out)
    check(len(prepared)==118,"118 filas")
    check(sum(x["categoria"]==mod.CANDIDATE for x in prepared)==116,"116 revisables")
    check(export.stat().st_mode & 0o777 == 0o600,"CSV permiso privado")
    check(export.parent==out,"CSV fuera de public_html")
    with export.open(encoding="utf-8",newline="") as f:
        data=list(csv.DictReader(f))
    check(len(data)==118,"CSV completo")
    check(all(x["revision_humana"]=="PENDIENTE" for x in data),"ningún SI automático")
    check(all(x["observacion_revision"]=="" for x in data),"sin evidencia inventada")
    check(len({x["auditoria_sha256"] for x in data})==1,"misma auditoría")
    check((site/"index.php").read_text()=="<?php","sitio intacto")
    check(json.loads(audit.read_text())==report,"auditoría intacta")
    try:
        mod.prepare(site,audit,out)
        raise AssertionError("Debe rechazar sobreescritura")
    except FileExistsError:
        pass
    rows[4]["resultado_id"]=rows[3]["resultado_id"]
    audit.write_text(json.dumps(report),encoding="utf-8")
    try:
        mod.load_report(audit)
        raise AssertionError("Debe rechazar ID duplicado")
    except RuntimeError:
        pass
    rows[4]["resultado_id"]=5
    rows[0]["pbx_call_id"]="=HYPERLINK(\"url\")"
    audit.write_text(json.dumps(report),encoding="utf-8")
    try:
        mod.load_report(audit)
        raise AssertionError("Debe rechazar fórmula CSV")
    except RuntimeError:
        pass
    rows[0]["pbx_call_id"]="out_20261106_00000001"
    audit.write_text(json.dumps(report),encoding="utf-8")
    try:
        mod.prepare(site,audit,site)
        raise AssertionError("Debe rechazar CSV dentro de webroot")
    except RuntimeError:
        pass
print("PASS: 118 filas; 116 revisión y 2 sin ANSWER; CSV privado 0600, no autoconversión, no cambios SQL.")
