#!/usr/bin/env python3
"""Publicación selectiva de avisos del tablero V3. Compatible con Python 3.6."""

import argparse
from pathlib import Path
import shutil
import subprocess

import publicar_embudo_refresco_automatico_hostinger_v1 as publicador


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for argumento in ("site", "source-dir", "backup-root", "backup-folder", "confirm"):
        parser.add_argument("--" + argumento, required=argumento == "site")
    modo = parser.add_mutually_exclusive_group(required=True)
    for opcion in ("check", "apply", "rollback"):
        modo.add_argument("--" + opcion, action="store_true")
    args = parser.parse_args()

    archivo = "public/javascript/embudo_comercial_v3_operativo.js"
    publicador.FILES = {archivo: (
        "483ecc09201ce51be7a752c0c653fc7f1159c86d",
        "7c762035b559be80f4f232952a32a40bac71df8d",
    )}
    publicador.GUARDS = {
        "app/views/embudo_comercial/operativo_v3.php": "e5b26a84ad2baa178feb74c54cd4b4771ee3e00d",
        "app/services/EmbudoComercialResultadoBridge.php": "f03f8da725dfe3645db9ce9e1a76e11c38c81427",
    }
    publicador.CONFIRM = "PUBLICAR_TOAST_VENTAS_V3_IMPE"
    sitio = publicador.root_site(args.site)
    if args.rollback:
        if not args.backup_folder:
            raise publicador.Blocked("Indica el respaldo exacto con --backup-folder.")
        publicador.rollback(sitio, args.backup_folder, args.confirm)
        return

    if not args.source_dir:
        raise publicador.Blocked("Indica el paquete con --source-dir.")
    fuentes = Path(args.source_dir).resolve(strict=True)
    if fuentes == sitio or sitio in fuentes.parents:
        raise publicador.Blocked("El paquete debe estar fuera de public_html.")
    js = publicador.source_path(fuentes, archivo)
    if not all(fragmento in js.read_bytes() for fragmento in (
        b"system-toast", b"BootstrapToast", b"sessionStorage.removeItem(avisoKey)",
        b"confirmarGuardado('gestion')", b"confirmarGuardado('movimiento')",
        b"telefonoActivo()", b"gestionarPotencialV3", b"moverEtapaV3",
    )):
        raise publicador.Blocked("El JavaScript no cumple los contratos del aviso.")
    node = shutil.which("node")
    if node:
        subprocess.run([node, "--check", str(js)], check=True)
    if args.check:
        estado = publicador.scan(sitio, fuentes)
        print("CHECK_OK: " + ("ya aplicado." if estado[archivo] == publicador.FILES[archivo][1]
                              else "un archivo compatible, sitio intacto."))
    else:
        publicador.publish(sitio, fuentes, args.backup_root, args.confirm)


if __name__ == "__main__":
    try:
        main()
    except (publicador.Blocked, OSError, ValueError, subprocess.SubprocessError) as error:
        raise SystemExit("BLOQUEADO: " + str(error))
