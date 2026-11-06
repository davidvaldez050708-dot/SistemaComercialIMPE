#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prepara una hoja CSV privada para REVISAR, nunca para reclasificar.

Compatible con Python 3.6. No tiene conexión a MySQL, Zadarma o internet.
Todos los registros comienzan PENDIENTE y solo los SI revisados manualmente
podrán alimentar un publicador posterior con nuevas validaciones.
"""
import argparse
import csv
import hashlib
import json
import os
import re
import stat
import sys
from pathlib import Path

HEADERS = [
    "resultado_id", "usuario_id", "extension", "fecha_llamada",
    "pbx_call_id", "categoria", "revision_humana",
    "observacion_revision", "auditoria_sha256",
]
CANDIDATE = "PENDIENTE_ESCUCHA_Y_CONFIRMACION_HUMANA"
CATEGORIES = {
    CANDIDATE, "SIN_RESPUESTA_PBX_ACREDITADA",
    "SIN_REFERENCIA_LOCAL_DE_AUDIO", "LLAMADA_NO_VINCULADA",
    "NO_ES_ENVIO_WHATSAPP_UNICO_V3",
}
MAX_BYTES = 8 * 1024 * 1024


def load_report(path):
    if path.is_symlink() or not path.is_file() or not stat.S_ISREG(path.stat().st_mode):
        raise RuntimeError("Auditoría ausente o no regular")
    if path.stat().st_size > MAX_BYTES:
        raise RuntimeError("La auditoría supera el límite permitido")
    raw = path.read_bytes()
    data = json.loads(raw.decode("utf-8"))
    if not isinstance(data, dict) or data.get("tipo") != "AUDITORIA_SOLO_LECTURA":
        raise RuntimeError("No es la auditoría histórica esperada")
    rows = data.get("llamadas")
    if not isinstance(rows, list) or len(rows) > 5000:
        raise RuntimeError("Número de filas inesperado")
    if data.get("resumen", {}).get("total") != len(rows):
        raise RuntimeError("El total de la auditoría no coincide")
    seen = set()
    accepted = []
    for row in rows:
        if not isinstance(row, dict):
            raise RuntimeError("Fila inválida")
        identifier = row.get("resultado_id")
        user = row.get("usuario_id")
        ext = str(row.get("extension", ""))
        pbx = str(row.get("pbx_call_id", ""))
        cat = str(row.get("categoria", ""))
        date = row.get("fecha_llamada")
        if type(identifier) is not int or identifier <= 0 or identifier in seen:
            raise RuntimeError("ID duplicado o inválido")
        seen.add(identifier)
        if type(user) is not int or user <= 0:
            raise RuntimeError("Usuario inválido")
        if not re.match(r"^[0-9]{3,6}$", ext) or not re.match(r"^[a-zA-Z0-9_-]{6,100}$", pbx):
            raise RuntimeError("Identidad de PBX/extensión inválida")
        if cat not in CATEGORIES:
            raise RuntimeError("Categoría de auditoría desconocida")
        if date is not None and (not isinstance(date, str) or
            not re.match(r"^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$",date)):
            raise RuntimeError("Fecha de llamada inválida")
        if row.get("revision_humana") != "PENDIENTE":
            raise RuntimeError("La auditoría de origen ya está modificada")
        accepted.append({
            "resultado_id": identifier,
            "usuario_id": user,
            "extension": ext,
            "fecha_llamada": date or "",
            "pbx_call_id": pbx,
            "categoria": cat,
            "revision_humana": "PENDIENTE",
            "observacion_revision": "",
            "auditoria_sha256": hashlib.sha256(raw).hexdigest(),
        })
    return accepted


def prepare(site, audit, out_dir):
    site = site.resolve(strict=True)
    if not (site / "index.php").is_file():
        raise RuntimeError("La ruta del sitio no contiene index.php")
    rows = load_report(audit)
    out_dir = out_dir.expanduser()
    if out_dir.is_symlink():
        raise RuntimeError("No usar directorios simbólicos")
    # Exigir directorio privado preexistente: nunca escribir en public_html.
    out_dir = out_dir.resolve(strict=True)
    if not out_dir.is_dir() or os.path.commonpath([str(site), str(out_dir)]) == str(site):
        raise RuntimeError("La revisión debe guardarse fuera de public_html")
    if out_dir.stat().st_mode & 0o077:
        raise RuntimeError("Directorio de revisión accesible a otros usuarios; requiere chmod 700")
    audit_sha = rows[0]["auditoria_sha256"] if rows else hashlib.sha256(audit.read_bytes()).hexdigest()
    name = "revisar_ventas_whatsapp_" + audit_sha[:12] + ".csv"
    destination = out_dir / name
    fd = os.open(str(destination), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as stream:
            writer = csv.DictWriter(stream, fieldnames=HEADERS, dialect="excel")
            writer.writeheader()
            writer.writerows(rows)
    except BaseException:
        destination.unlink()
        raise
    return destination, rows


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--site", required=True)
    parser.add_argument("--audit", required=True)
    parser.add_argument("--out-dir", required=True)
    args = parser.parse_args()
    output, rows = prepare(Path(args.site), Path(args.audit), Path(args.out_dir))
    candidates = sum(row["categoria"] == CANDIDATE for row in rows)
    print("REVISIÓN PREPARADA:", output)
    print("REGISTROS:", len(rows), "PARA ESCUCHAR:", candidates)
    print("TODOS PENDIENTES. No se corrigieron registros ni conteos.")
    print("Después de escuchar cada audio, marcar SI o NO y describir la evidencia.")
    print("Conservar el CSV en carpeta privada, fuera de public_html.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("ERROR:", str(error), file=sys.stderr)
        sys.exit(1)
