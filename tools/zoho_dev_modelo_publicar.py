#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicar únicamente bloque ADITIVO conciliado al modelo real de Hostinger.

--check: solo lectura.
--publish --confirm-model: backup privado + sustitución atómica del modelo.
--rollback --backup /path --confirm-rollback: restaura solo si el modelo sigue
exactamente como la versión Zoho publicada y backup original es válido.
NO toca credenciales, SQL, cron, controllers, servicios ni datos de usuarios.
Python 3.6 compatible. Rechaza versiones inesperadas.
"""
import argparse
import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import sys
import tempfile

REL = "app/models/FormularioRegistroModel.php"
ORIGINAL_SHA = "5089e339748fa7cd"
CANDIDATO_SHA = "74699f0b75430ea6"
SERVICIOS = {
    "app/services/ZohoDeveloperRegistroSync.php":
        "5255432448079eda6fedb836ffd6fc91cde5092d",
    "app/services/ZohoDeveloperRegistroWorker.php":
        "13ad361609066c608854e66b621ee59e5729a906",
    "tools/zoho_developer_registros_worker.php":
        "3c698f629d167f813f2e893c6fa35dae65f464df",
}


def sha(datos):
    return hashlib.sha256(datos).hexdigest()


def blob_sha(datos):
    return hashlib.sha1(
        ("blob %d\0" % len(datos)).encode("ascii") + datos
    ).hexdigest()


def relative_to(child, parent):
    try:
        child.relative_to(parent)
        return True
    except ValueError:
        return False


def comprobaciones(home, repo, sitio, candidato):
    home = home.resolve()
    if (repo.is_symlink() or sitio.is_symlink() or
            repo.resolve() != home / "crm-zoho-developer-piloto" or
            sitio.resolve() != home / "domains/fundacionrededumex.org/public_html"):
        raise ValueError("UBICACIONES_NO_AUTORIZADAS")
    origen = sitio / REL
    if origen.is_symlink() or not origen.is_file():
        raise ValueError("MODELO_ORIGINAL_AUSENTE")
    if candidato.is_symlink() or not candidato.is_file():
        raise ValueError("CANDIDATO_AUSENTE_O_ENLACE")
    real_candidato = candidato.resolve()
    if not relative_to(real_candidato, home) or "public_html" in real_candidato.parts:
        raise ValueError("CANDIDATO_FUERA_DE_CARPETA_PRIVADA")
    for path, esperado in SERVICIOS.items():
        archivo = sitio / path
        if archivo.is_symlink() or not archivo.is_file() or blob_sha(archivo.read_bytes()) != esperado:
            raise ValueError("SERVICIO_NO_COINCIDE_" + path)

    for carpeta in (sitio / "config", sitio.parent / "impe-private"):
        if (carpeta / "zoho_sync.local.php").exists():
            raise ValueError("CONFIG_ZOHO_PRESENTE_NO_PUBLICAR")
    if os.environ.get("IMPE_PRIVATE_CONFIG_DIR", ""):
        raise ValueError("DIRECTORIO_CONFIG_PRIVADA_EXPILCITO_NO_PUBLICAR")

    original = origen.read_bytes()
    candidato_bytes = candidato.read_bytes()
    if not sha(candidato_bytes).startswith(CANDIDATO_SHA):
        raise ValueError("CANDIDATO_SHA_CAMBIO")
    spec = importlib.util.spec_from_file_location(
        "zoho_conciliar", str(repo / "tools/zoho_conciliar_formulario_hostinger.py")
    )
    conciliador = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(conciliador)
    if sha(original).startswith(ORIGINAL_SHA):
        esperado = conciliador.parchear(original.decode("utf-8")).encode("utf-8")
        if esperado != candidato_bytes:
            raise ValueError("CANDIDATO_NO_ES_PARChE_UNICO")
        estado = "ORIGINAL"
    elif sha(original).startswith(CANDIDATO_SHA):
        if original != candidato_bytes:
            raise ValueError("MODELO_PERSONALIZADO_DESPUES_DE_ZOHO")
        estado = "CONCILIADO"
    else:
        raise ValueError("MODELO_CAMBIO_NO_PUBLICAR")
    return origen, original, candidato_bytes, estado


def copiar_archivo_exclusivo(ruta, datos, modo):
    fd = os.open(str(ruta), os.O_WRONLY | os.O_CREAT | os.O_EXCL, modo)
    with os.fdopen(fd, "wb") as file:
        file.write(datos)


def reemplazar_atomico(origen, datos, modo):
    fd, name = tempfile.mkstemp(prefix=".zoho-candidato-", dir=str(origen.parent))
    try:
        os.fchmod(fd, modo)
        with os.fdopen(fd, "wb") as file:
            file.write(datos)
            file.flush()
            os.fsync(file.fileno())
        os.replace(name, str(origen))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def preparar_respaldo(home, original):
    carpeta = Path(tempfile.mkdtemp(prefix=".zoho-modelo-respaldo-", dir=str(home)))
    respaldo = carpeta / "FormularioRegistroModel.php.original"
    copiar_archivo_exclusivo(respaldo, original, 0o600)
    if respaldo.read_bytes() != original:
        raise RuntimeError("ERROR_RESPALDO")
    return respaldo


def publicar(home, origen, original, candidato):
    if not sha(original).startswith(ORIGINAL_SHA):
        raise ValueError("MODELO_YA_CONCILIADO_O_PERSONALIZADO")
    modo = stat.S_IMODE(origen.stat().st_mode)
    if modo & 0o002:
        raise ValueError("MODELO_PERMISOS_INSEGUROS")
    respaldo = preparar_respaldo(home, original)
    # Protección contra cambios concurrentes antes de sustituir el archivo.
    if origen.is_symlink() or origen.read_bytes() != original:
        raise ValueError("MODELO_CAMBIO_DURANTE_PUBLICACION")
    reemplazar_atomico(origen, candidato, modo)
    if origen.read_bytes() != candidato:
        raise RuntimeError("VERIFICACION_PUBLICACION_FALLIDA")
    print("PUBLICADO_UNICO_MODELO: 18 líneas aditivas, 0 eliminadas")
    print("RESPALDO_PRIVADO:", respaldo)
    print("ORIGINAL_SHA:", sha(original)[:16], "CANDIDATO_SHA:", sha(candidato)[:16])
    print("NO se activó configuración, cron, DDL ni sincronización")


def deshacer(home, origen, actual, backup):
    if not sha(actual).startswith(CANDIDATO_SHA):
        raise ValueError("ROLLBACK_SIN_MODELO_CONCILIADO_IDENTICO")
    if backup is None or backup.is_symlink() or not backup.is_file():
        raise ValueError("BACKUP_ORIGINAL_NO_DISPONIBLE")
    archivo = backup.resolve()
    if not relative_to(archivo, home.resolve()) or "public_html" in archivo.parts:
        raise ValueError("BACKUP_NO_ES_PRIVADO")
    anterior = archivo.read_bytes()
    if not sha(anterior).startswith(ORIGINAL_SHA):
        raise ValueError("BACKUP_NO_ES_ORIGINAL_VALIDADO")
    modo = stat.S_IMODE(origen.stat().st_mode)
    if origen.is_symlink() or origen.read_bytes() != actual:
        raise ValueError("MODELO_CAMBIO_DURANTE_ROLLBACK")
    reemplazar_atomico(origen, anterior, modo)
    if origen.read_bytes() != anterior:
        raise RuntimeError("VERIFICACION_ROLLBACK_FALLIDA")
    print("RESTAURADO_MODELO_ORIGINAL:", sha(anterior)[:16])
    print("RESPALDO_PRIVADO_CONSERVADO:", backup)


def main():
    p = argparse.ArgumentParser()
    acciones = p.add_mutually_exclusive_group(required=True)
    acciones.add_argument("--check", action="store_true")
    acciones.add_argument("--publish", action="store_true")
    acciones.add_argument("--rollback", action="store_true")
    p.add_argument("--candidate", required=True)
    p.add_argument("--confirm-model", action="store_true")
    p.add_argument("--confirm-rollback", action="store_true")
    p.add_argument("--backup")
    a = p.parse_args()
    if a.publish != a.confirm_model or a.rollback != a.confirm_rollback:
        raise ValueError("CONFIRMACION_NO_COINCIDE")
    if a.rollback != (a.backup is not None):
        raise ValueError("RESPALDO_SOLO_EN_ROLLBACK")

    home = Path.home()
    repo = home / "crm-zoho-developer-piloto"
    sitio = home / "domains/fundacionrededumex.org/public_html"
    origen, original, candidato, estado = comprobaciones(
        home, repo, sitio, Path(a.candidate)
    )
    print("MODELO_ESTADO:", estado)
    print("MODELO_SHA256:", sha(original)[:16])
    print("CANDIDATO_SHA256:", sha(candidato)[:16])
    print("SERVICIOS_ZOHO: 3 IDENTICOS")
    print("HABILITACION: NO")
    if a.check:
        print("CHECK_OK: solo lectura; sin escrituras, SQL, cron ni envíos")
        return
    if a.publish:
        if estado != "ORIGINAL":
            raise ValueError("YA_PUBLICADO_O_MODELO_CAMBIO")
        publicar(home, origen, original, candidato)
    else:
        if estado != "CONCILIADO":
            raise ValueError("ROLLBACK_NO_AUTORIZADO")
        deshacer(home, origen, original, Path(a.backup))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("BLOQUEADO:", str(exc), file=sys.stderr)
        sys.exit(1)
