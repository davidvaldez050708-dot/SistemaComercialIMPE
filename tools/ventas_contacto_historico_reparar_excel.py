#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Corrige únicamente fechas alteradas por Excel y SI/NO en columna equivocada.

Lee el CSV de revisión y el JSON privado de la auditoría original; recupera
fecha_llamada exacta de la auditoría. Traslada SI/NO de observacion_revision a
revision_humana sólo si la decisión aún es PENDIENTE. No inventa observaciones
y NO se conecta a la BD ni modifica el CRM o los CSV originales.
Compatible con Python 3.6 (Hostinger).
"""
import argparse
import csv
import hashlib
import os
import re
import stat
import sys
from datetime import datetime
from pathlib import Path

import ventas_contacto_historico_preparar_revision as audit_module

DATE_FORMATS = (
    "%Y-%m-%d %H:%M:%S",
    "%d/%m/%Y %H:%M:%S",
    "%d/%m/%Y %H:%M",
    "%Y-%m-%d %H:%M",
)


def original_datetime(value):
    if not value:
        return None
    return datetime.strptime(value, "%Y-%m-%d %H:%M:%S")


def check_date(edited, original, identifier):
    if not original:
        if edited:
            raise RuntimeError("La fecha de la llamada ID {} no debe tener valor".format(identifier))
        return
    expected = original_datetime(original)
    for fmt in DATE_FORMATS:
        try:
            observed = datetime.strptime(edited, fmt)
        except ValueError:
            continue
        # Un archivo Excel puede suprimir los segundos: validar el MISMO minuto.
        if (observed.year,observed.month,observed.day,
            observed.hour,observed.minute) != (
            expected.year,expected.month,expected.day,expected.hour,expected.minute):
            raise RuntimeError("Fecha modificada realmente para ID {}; se esperaba la fecha original".format(identifier))
        if "%S" in fmt and observed.second != expected.second:
            raise RuntimeError("Segundos modificados para ID {}".format(identifier))
        return
    raise RuntimeError("Fecha no reconocida para ID {}".format(identifier))


def reconcile(audit_path, edited_path):
    original_rows = audit_module.load_report(audit_path)
    indexed = {str(r["resultado_id"]): r for r in original_rows}
    if edited_path.is_symlink() or not edited_path.is_file() or not stat.S_ISREG(edited_path.stat().st_mode):
        raise RuntimeError("CSV editado no encontrado o es enlace simbólico")
    if edited_path.stat().st_size > 8 * 1024 * 1024:
        raise RuntimeError("CSV excede tamaño permitido")
    result=[]
    seen=set()
    date_fixes=0
    moved=0
    missing_note=0
    with edited_path.open("r",encoding="utf-8-sig",newline="") as stream:
        reader=csv.DictReader(stream)
        if reader.fieldnames != audit_module.HEADERS:
            raise RuntimeError("Las columnas cambiaron; no se reparará el archivo")
        for current in reader:
            identifier=(current.get("resultado_id") or "").strip()
            if not identifier.isdigit() or identifier not in indexed or identifier in seen:
                raise RuntimeError("ID duplicado o desconocido: " + identifier[:40])
            seen.add(identifier)
            original=indexed[identifier]
            # NO reparar silenciosamente usuario, extensión, PBX, categoría ni SHA.
            for key in audit_module.HEADERS:
                if key in ("fecha_llamada","revision_humana","observacion_revision"):
                    continue
                if (current.get(key) or "") != str(original[key]):
                    raise RuntimeError("Columna protegida {} modificada en ID {}".format(key,identifier))
            date=(current.get("fecha_llamada") or "").strip()
            check_date(date, str(original["fecha_llamada"]), identifier)
            if date != original["fecha_llamada"]:
                date_fixes+=1
            decision=(current.get("revision_humana") or "").strip().upper()
            note=(current.get("observacion_revision") or "").strip()
            if decision not in ("PENDIENTE","SI","NO"):
                raise RuntimeError("Decisión desconocida en ID {}".format(identifier))
            if decision=="PENDIENTE" and note.upper() in ("SI","NO"):
                # La decisión se anotó por error en observaciones.
                # NO inferir qué se escuchó: la nota debe completarla el revisor.
                decision=note.upper()
                note=""
                moved+=1
            if decision=="PENDIENTE" and note:
                raise RuntimeError("Observación con decisión aún pendiente: ID {}".format(identifier))
            if decision in ("SI","NO"):
                if original["categoria"] != audit_module.CANDIDATE:
                    raise RuntimeError("No se permite SI/NO sin ANSWER y audio propio: ID {}".format(identifier))
                if len(note)<10:
                    missing_note+=1
            entry={key:str(original[key]) for key in audit_module.HEADERS}
            entry["fecha_llamada"]=str(original["fecha_llamada"])
            entry["revision_humana"]=decision
            entry["observacion_revision"]=note
            result.append(entry)
    if seen != set(indexed) or len(result)!=len(original_rows):
        raise RuntimeError("Faltan resultados; no se genera archivo parcial")
    return result,{"total":len(result),"fechas_recuperadas":date_fixes,
                   "decisiones_reubicadas":moved,"faltan_observaciones":missing_note,
                   "si":sum(r["revision_humana"]=="SI" for r in result),
                   "no":sum(r["revision_humana"]=="NO" for r in result),
                   "pendientes":sum(r["revision_humana"]=="PENDIENTE" for r in result)}


def save(site,out_dir,rows,audit_path):
    site=site.resolve(strict=True)
    out_dir=out_dir.resolve(strict=True)
    if not (site/"index.php").is_file() or not out_dir.is_dir():
        raise RuntimeError("Sitio o carpeta privada no válidos")
    if out_dir.is_symlink() or os.path.commonpath([str(site),str(out_dir)])==str(site):
        raise RuntimeError("No escribir CSV en public_html")
    if out_dir.stat().st_mode & 0o077:
        raise RuntimeError("Carpeta privada debe tener permisos 0700")
    sha=hashlib.sha256(audit_path.read_bytes()).hexdigest()[:12]
    path=out_dir/("revisar_ventas_whatsapp_fechas_corregidas_"+sha+".csv")
    fd=os.open(str(path),os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        with os.fdopen(fd,"w",encoding="utf-8",newline="") as output:
            writer=csv.DictWriter(output,fieldnames=audit_module.HEADERS)
            writer.writeheader()
            writer.writerows(rows)
    except BaseException:
        path.unlink()
        raise
    return path


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--audit",required=True)
    parser.add_argument("--revision",required=True)
    parser.add_argument("--site",required=True)
    parser.add_argument("--out-dir",required=True)
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check",action="store_true")
    mode.add_argument("--write",action="store_true")
    opts=parser.parse_args()
    audit=Path(opts.audit)
    rows,stats=reconcile(audit,Path(opts.revision))
    print("FILAS:",stats["total"],"FECHAS EXCEL:",stats["fechas_recuperadas"])
    print("DECISIONES SI/NO EN COLUMNA EQUIVOCADA:",stats["decisiones_reubicadas"])
    print("SI:",stats["si"],"NO:",stats["no"],"PENDIENTES:",stats["pendientes"])
    print("DECISIONES QUE REQUIEREN OBSERVACIÓN REAL:",stats["faltan_observaciones"])
    if opts.check:
        print("CHECK OK: archivos originales y CRM intactos.")
        return
    path=save(Path(opts.site),Path(opts.out_dir),rows,audit)
    print("CSV REPARADO PRIVADO:",path)
    print("ATENCIÓN: completar observaciones reales de los SI/NO antes de validar.")
    print("NO se modificó ningún resultado ni contador del CRM.")


if __name__=="__main__":
    try:
        main()
    except Exception as exc:
        print("ERROR:",exc,file=sys.stderr)
        sys.exit(1)
