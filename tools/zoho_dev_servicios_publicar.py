#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publica exclusivamente TRES archivos NUEVOS, nunca el modelo ni SQL.

--check: lectura, exige instalación Hostinger concreta y configuracion inactiva.
--publish --confirm-new-files: creación O_EXCL con rollback parcial.
--rollback --confirm-only-matching: solo elimina exactamente archivos de esta entrega.

Compatible Python 3.6. No lee registros MySQL ni secretos.
"""
import argparse
import hashlib
import os
from pathlib import Path
import sys

RAMA = "feat/zoho-developer-formularios-piloto-20261112"
MODELO_ORIGINAL = "5089e339748fa7cd"
ARCHIVOS = {
    "app/services/ZohoDeveloperRegistroSync.php":
        "5255432448079eda6fedb836ffd6fc91cde5092d",
    "app/services/ZohoDeveloperRegistroWorker.php":
        "13ad361609066c608854e66b621ee59e5729a906",
    "tools/zoho_developer_registros_worker.php":
        "3c698f629d167f813f2e893c6fa35dae65f464df",
}


def git_blob_sha(datos):
    return hashlib.sha1(
        ("blob %d\0" % len(datos)).encode("ascii") + datos
    ).hexdigest()


def datos_validados(origen, entradas=ARCHIVOS):
    archivos = {}
    for relativo, hash_esperado in entradas.items():
        archivo = origen / relativo
        if archivo.is_symlink() or not archivo.is_file():
            raise ValueError("FUENTE_AUSENTE_O_SYMLINK: " + relativo)
        contenido = archivo.read_bytes()
        if git_blob_sha(contenido) != hash_esperado:
            raise ValueError("FUENTE_PERSONALIZADA_O_VERSION_DIFERENTE: " + relativo)
        archivos[relativo] = contenido
    return archivos


def planificar(destino, archivos, rollback=False):
    resultados = {}
    for relativo, datos in archivos.items():
        ruta = destino / relativo
        if ruta.is_symlink() or not ruta.parent.is_dir() or ruta.parent.is_symlink():
            raise ValueError("DESTINO_INVALIDO: " + relativo)
        if ruta.exists():
            if not ruta.is_file() or ruta.read_bytes() != datos:
                raise ValueError("DESTINO_EXISTENTE_PERSONALIZADO: " + relativo)
            resultados[relativo] = "IDENTICO"
        else:
            resultados[relativo] = "AUSENTE"
    estados = set(resultados.values())
    if len(estados) > 1:
        raise ValueError("ESTADO_PARCIAL_NO_PUBLICAR: verificar manualmente")
    if rollback and estados != {"IDENTICO"}:
        raise ValueError("ROLLBACK_REQUIERE_TODOS_IDENTICOS")
    return resultados


def instalar_solo_nuevos(destino, archivos):
    plan = planificar(destino, archivos)
    if set(plan.values()) != {"AUSENTE"}:
        raise ValueError("YA_EXISTEN_ARCHIVOS: no sobrescribir")
    nuevos = []
    try:
        for relativo, datos in archivos.items():
            ruta = destino / relativo
            fd = os.open(str(ruta), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
            nuevos.append(ruta)
            with os.fdopen(fd, "wb") as file:
                file.write(datos)
            os.chmod(str(ruta), 0o644)
            if ruta.read_bytes() != datos:
                raise RuntimeError("VERIFICACION_TRAS_ESCRITURA")
    except Exception:
        # Deshacer solamente los archivos recién creados por esta invocación.
        for ruta in reversed(nuevos):
            try:
                if ruta.exists() and ruta.read_bytes() == archivos[str(ruta.relative_to(destino))]:
                    ruta.unlink()
            except Exception:
                pass
        raise
    if set(planificar(destino, archivos).values()) != {"IDENTICO"}:
        raise RuntimeError("VERIFICACION_FINAL_FALLIDA")
    return len(nuevos)


def revertir_solo_identicos(destino, archivos):
    planificar(destino, archivos, rollback=True)
    for relativo, datos in reversed(list(archivos.items())):
        ruta = destino / relativo
        if not ruta.is_symlink() and ruta.is_file() and ruta.read_bytes() == datos:
            ruta.unlink()
        else:
            raise RuntimeError("ROLLBACK_CAMBIO_CONCURRENTE")
    return len(archivos)


def entorno_aprobado(home, repo, sitio):
    home = home.resolve()
    esperado_repo = home / "crm-zoho-developer-piloto"
    esperado_sitio = home / "domains/fundacionrededumex.org/public_html"
    if (repo.is_symlink() or sitio.is_symlink() or
            repo.resolve() != esperado_repo or sitio.resolve() != esperado_sitio or
            not repo.is_dir() or not sitio.is_dir()):
        raise ValueError("RUTAS_NO_AUTORIZADAS")

    modelo = sitio / "app/models/FormularioRegistroModel.php"
    if modelo.is_symlink() or not modelo.is_file() or not hashlib.sha256(
            modelo.read_bytes()).hexdigest().startswith(MODELO_ORIGINAL):
        raise ValueError("MODELO_PRODUCCION_CAMBIO")

    # Si existe config Zoho aunque esté disabled, detenerse para inspección.
    # No leer su contenido ni credenciales.
    for carpeta in (sitio / "config", sitio.parent / "impe-private"):
        if (carpeta / "zoho_sync.local.php").exists():
            raise ValueError("CONFIG_ZOHO_PRESENTE: revisar antes de publicar")
    if os.environ.get("IMPE_PRIVATE_CONFIG_DIR", ""):
        raise ValueError("CONFIG_PRIVADA_EXPLICITA: revisar antes de publicar")
    return True


def main():
    p = argparse.ArgumentParser()
    modo = p.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--publish", action="store_true")
    modo.add_argument("--rollback", action="store_true")
    p.add_argument("--confirm-new-files", action="store_true")
    p.add_argument("--confirm-only-matching", action="store_true")
    args = p.parse_args()

    if args.publish != args.confirm_new_files or args.rollback != args.confirm_only_matching:
        raise ValueError("CONFIRMACION_NO_COINCIDE")

    home = Path.home()
    repo = home / "crm-zoho-developer-piloto"
    sitio = home / "domains/fundacionrededumex.org/public_html"
    entorno_aprobado(home, repo, sitio)
    archivos = datos_validados(repo)

    plan = planificar(sitio, archivos, rollback=args.rollback)
    for relativo, estado in plan.items():
        print(estado, relativo)
    print("MODELO_PRODUCCION_INTACTO: SHA256 empieza por", MODELO_ORIGINAL)
    print("CONFIG_ZOHO: SIN_ARCHIVOS_DE_HABILITACION")
    if args.check:
        print("CHECK_OK: sin escrituras, SQL, cron ni envios")
        return

    if args.publish:
        print("PUBLICADOS_NUEVOS:", instalar_solo_nuevos(sitio, archivos))
        print("NO se publicó modelo, configuración ni cron; envíos desactivados")
    else:
        print("REVERTIDOS_IDENTICOS:", revertir_solo_identicos(sitio, archivos))
        print("No se modificó ningún otro archivo")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("BLOQUEADO:", str(exc), file=sys.stderr)
        sys.exit(1)
