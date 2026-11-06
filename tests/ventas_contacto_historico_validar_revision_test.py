"""La revisión SI/NO no altera datos y respeta ID/PBX/huellas originales."""
import csv
import importlib.util
import json
import pathlib
import sys
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/"tools"))
import ventas_contacto_historico_preparar_revision as base
import ventas_contacto_historico_validar_revision as verify

def fails(fn,msg):
    try:
        fn()
    except RuntimeError:
        return
    raise AssertionError(msg)

with tempfile.TemporaryDirectory(prefix="impe-revisiones-humanas-") as temp:
    root=pathlib.Path(temp)
    site=root/"webroot"
    site.mkdir()
    (site/"index.php").write_text("<?php")
    private=root/"private"
    private.mkdir(mode=0o700)
    data=[]
    for i in range(118):
        data.append({
            "resultado_id":i+1, "usuario_id":9,
            "extension":"152", "pbx_call_id":"out_20261106_"+str(i+1).zfill(8),
            "categoria":base.CANDIDATE if i<116 else "SIN_RESPUESTA_PBX_ACREDITADA",
            "fecha_llamada":"2026-11-06 10:00:00", "revision_humana":"PENDIENTE",
            "observacion_revision":""
        })
    record={"tipo":"AUDITORIA_SOLO_LECTURA","resumen":{"total":118},"llamadas":data}
    original=private/"original.json"
    original.write_text(json.dumps(record),encoding="utf-8")
    csv_path,rows=base.prepare(site,original,private)
    with csv_path.open(encoding="utf-8",newline="") as f:
        sheet=list(csv.DictReader(f))
    sheet[0]["revision_humana"]="SI"
    sheet[0]["observacion_revision"]="Audio revisado: conversación con una persona"
    sheet[1]["revision_humana"]="NO"
    sheet[1]["observacion_revision"]="Audio revisado: mensaje de buzón de voz"
    def overwrite():
        with csv_path.open("w",newline="",encoding="utf-8") as f:
            w=csv.DictWriter(f,fieldnames=base.HEADERS)
            w.writeheader()
            w.writerows(sheet)
    overwrite()
    result=verify.validate(original,csv_path)
    assert len(result)==118
    dest,report=verify.private_report(site,csv_path,result,private)
    assert dest.stat().st_mode&0o777==0o600
    assert report["total"]==118
    assert report["confirmadas_por_revision"]==1
    assert report["descartadas_por_revision"]==1
    assert report["pendientes"]==116
    assert [x["resultado_id"] for x in report["aprobadas"]]==[1]
    assert (site/"index.php").read_text()=="<?php"
    assert json.loads(original.read_text())==record
    fails(lambda:verify.private_report(site,csv_path,result,private),"No sobrescribir plan")
    sheet[0]["pbx_call_id"]="out_IDENTIDAD_ALTERADA"
    overwrite()
    fails(lambda:verify.validate(original,csv_path),"Debió rechazar identidad")
    sheet[0]["pbx_call_id"]=data[0]["pbx_call_id"]
    sheet[0]["observacion_revision"]=""
    overwrite()
    fails(lambda:verify.validate(original,csv_path),"Debió rechazar aprobación sin evidencia")
    sheet[0]["observacion_revision"]="Audio revisado: conversación con una persona"
    sheet[116]["revision_humana"]="SI"
    sheet[116]["observacion_revision"]="Grabación encontrada en registro distinto"
    overwrite()
    fails(lambda:verify.validate(original,csv_path),"Sin ANSWER no puede aprobarse")
    sheet[116]["revision_humana"]="PENDIENTE"
    sheet[116]["observacion_revision"]=""
    sheet.pop()
    overwrite()
    fails(lambda:verify.validate(original,csv_path),"Debió rechazar borrado de fila")
print("PASS: 118 filas, 1 SI/1 NO, privacidad, identidad y 2 sin PBX; sin cambios en sitio ni BD.")
