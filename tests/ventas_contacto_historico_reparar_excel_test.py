"""Excel reformatea las fechas y guarda SI en observaciones: reparación sin perder datos."""
import csv
import hashlib
import json
import pathlib
import sys
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/"tools"))
import ventas_contacto_historico_preparar_revision as original
import ventas_contacto_historico_reparar_excel as repair
import ventas_contacto_historico_validar_revision as validate

with tempfile.TemporaryDirectory(prefix="impe-excel-fix-") as temp:
    base=pathlib.Path(temp)
    site=base/"site"
    site.mkdir()
    (site/"index.php").write_bytes(b"<?php")
    private=base/"privado"
    private.mkdir(mode=0o700)
    rows=[]
    for i in range(118):
        rows.append({
            "resultado_id":253+i,"usuario_id":9,"extension":"152",
            "pbx_call_id":"out_TEST_20261106_"+str(i+1).zfill(8),
            "fecha_llamada":"2026-11-05 09:45:23" if i<116 else "2026-11-06 11:08:09",
            "categoria":original.CANDIDATE if i<116 else "SIN_RESPUESTA_PBX_ACREDITADA",
            "revision_humana":"PENDIENTE",
            "observacion_revision":""
        })
    audit_doc={"tipo":"AUDITORIA_SOLO_LECTURA","resumen":{"total":118},"llamadas":rows}
    audit=private/"historial.json"
    audit.write_text(json.dumps(audit_doc),encoding="utf-8")
    input_file=private/"editado.csv"
    expected=original.load_report(audit)
    source=[]
    for n,r in enumerate(expected):
        r=dict(r)
        r["fecha_llamada"]="05/11/2026 09:45" if n<116 else "06/11/2026 11:08"
        if n<30:
            r["observacion_revision"]="SI"
        source.append(r)
    def write_rows(data):
        with input_file.open("w",encoding="utf-8",newline="") as stream:
            w=csv.DictWriter(stream,fieldnames=original.HEADERS)
            w.writeheader();w.writerows(data)
    write_rows(source)
    repaired,metrics=repair.reconcile(audit,input_file)
    assert metrics=={"total":118,"fechas_recuperadas":118,
        "decisiones_reubicadas":30,"faltan_observaciones":30,
        "si":30,"no":0,"pendientes":88},metrics
    for i,r in enumerate(repaired):
        assert r["fecha_llamada"]==rows[i]["fecha_llamada"]
        assert r["resultado_id"]==str(rows[i]["resultado_id"])
        assert r["pbx_call_id"]==rows[i]["pbx_call_id"]
    assert all(r["revision_humana"]=="SI" and not r["observacion_revision"] for r in repaired[:30])
    assert all(r["revision_humana"]=="PENDIENTE" for r in repaired[30:])
    output=repair.save(site,private,repaired,audit)
    assert output.is_file() and output.stat().st_mode&0o777==0o600
    assert audit_doc==json.loads(audit.read_text())
    # El validador bloquea SI sin evidencia: no lo salta la reparación.
    try:
        validate.validate(audit,output)
        raise AssertionError("Debió bloquear SI sin observación")
    except RuntimeError as e:
        assert "observación" in str(e)
    with output.open(encoding="utf-8",newline="") as f:
        fixed=list(csv.DictReader(f))
    for row in fixed[:30]:
        row["observacion_revision"]="Se escuchó conversación humana y se verificó el resultado"
    with output.open("w",encoding="utf-8",newline="") as f:
        w=csv.DictWriter(f,fieldnames=original.HEADERS)
        w.writeheader();w.writerows(fixed)
    verified=validate.validate(audit,output)
    assert sum(x["revision_humana"]=="SI" for x in verified)==30
    assert sum(x["revision_humana"]=="PENDIENTE" for x in verified)==88
    # Nunca reemplazar a ciegas una fecha del día/minuto incorrecto.
    source[0]["fecha_llamada"]="04/11/2026 09:45"
    write_rows(source)
    try:
        repair.reconcile(audit,input_file)
        raise AssertionError("Fecha distinta fue aceptada")
    except RuntimeError as e:
        assert "Fecha modificada" in str(e)
    source[0]["fecha_llamada"]="05/11/2026 09:45"
    source[0]["pbx_call_id"]="out_OTRA_LLAMADA"
    write_rows(source)
    try:
        repair.reconcile(audit,input_file)
        raise AssertionError("Identidad PBX distinta fue aceptada")
    except RuntimeError as e:
        assert "Columna protegida" in str(e)
    assert audit_doc==json.loads(audit.read_text()),"Auditoría original intacta"
    assert (site/"index.php").read_bytes()==b"<?php"
print("PASS: 118 fechas, 30 SI mal colocados, 88 pendientes; preservación de datos y bloqueo sin evidencia.")
