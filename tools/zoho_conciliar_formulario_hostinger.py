#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Prepara un parche ADITIVO sobre el modelo REAL de Hostinger.
Nunca escribe dentro del sitio; por defecto solo inspecciona.
Compatible con Python 3.6 (Hostinger).
"""
import argparse
import hashlib
import os
from pathlib import Path
import re
import sys

MODELO_RELATIVO = "app/models/FormularioRegistroModel.php"
SHA_PRODUCCION_ESPERADO = "5089e339748fa7cd"
MARCADOR = "ZohoDeveloperRegistroSync::encolarSiAutorizado"
ANCLA = (
    "        return $id;\n"
    "    }\n\n"
    "    private function guardarOriginal($datos)\n"
)
BLOQUE = (
    "        /*\n"
    "         * Zoho Developer es ADICIONAL al flujo comercial actual.\n"
    "         * Solo encola registros ficticios permitidos por config privada.\n"
    "         * Se ejecuta despues de guardarOriginal (transaccion de aliado ya cerrada).\n"
    "         */\n"
    "        if ((int)$id > 0 && !in_array($origen, ['MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION'], true)) {\n"
    "            try {\n"
    "                require_once __DIR__ . '/../services/ZohoDeveloperRegistroSync.php';\n"
    "                ZohoDeveloperRegistroSync::encolarSiAutorizado(\n"
    "                    $this->connection,\n"
    "                    (int)$id,\n"
    "                    (string)($datos['correo'] ?? '')\n"
    "                );\n"
    "            } catch (Throwable $error) {\n"
    "                error_log('[zoho_dev_piloto] no se pudo agregar trabajo a la cola.');\n"
    "            }\n"
    "        }\n\n"
)
MODELO_GUARDAR = re.compile(
    r"(?s)\bpublic function guardar\(\$datos\)\s*\{(.*?)\n    \}\n\n    private function guardarOriginal\(\$datos\)"
)


def parchear(texto):
    if MARCADOR in texto:
        raise ValueError("YA_EXISTE_GANCHO_ZOHO: no se duplica")
    if texto.count(ANCLA) != 1:
        raise ValueError("ANCLA_DIFERENTE: no se toca el modelo")
    bloques = MODELO_GUARDAR.findall(texto)
    if len(bloques) != 1:
        raise ValueError("FLUJO_GUARDAR_DESCONOCIDO")
    guardar = bloques[0]
    condiciones = (
        "$id=$this->guardarOriginal($datos);",
        "ProspectoCentralIncorporacionService::despuesDeGuardar((int)$id);",
        "['MARKETING_INSCRIPCION','PUBLICO_INSCRIPCION']",
        "return $id;",
    )
    if not all(cond in guardar for cond in condiciones):
        raise ValueError("GUARDAR_PERSONALIZADO_DIFERENTE")
    if "private function insertar(array $datos)" not in texto:
        raise ValueError("INSERTAR_PERSONALIZADO_AUSENTE")
    if "HorarioContacto::paraGuardar" not in texto:
        raise ValueError("HORARIO_CONTACTO_AUSENTE")
    if "convocatoriaExclusiva(" not in texto:
        raise ValueError("CONVOCATORIAS_EXCLUSIVAS_AUSENTES")
    resultado = texto.replace(ANCLA, BLOQUE + ANCLA, 1)
    if resultado.replace(BLOQUE, "", 1) != texto:
        raise ValueError("ERROR_VERIFICACION_PARChE")
    return resultado


def dentro_de(ruta, padre):
    try:
        ruta.relative_to(padre)
        return True
    except ValueError:
        return False


def main():
    parser = argparse.ArgumentParser(description="Prepara sin publicar integración Zoho aditiva")
    parser.add_argument("--site", required=True, help="Raíz real del sitio de Hostinger")
    grupo = parser.add_mutually_exclusive_group(required=True)
    grupo.add_argument("--check", action="store_true", help="Comprueba sin escribir")
    grupo.add_argument("--prepare", action="store_true", help="Genera copia privada, sin publicar")
    parser.add_argument("--output", help="Archivo privado destino; solo con --prepare")
    args = parser.parse_args()

    if args.check and args.output:
        parser.error("--output solo es válido con --prepare")
    if args.prepare and not args.output:
        parser.error("--prepare requiere --output")

    sitio = Path(args.site).resolve()
    ruta = sitio / MODELO_RELATIVO
    home = Path.home().resolve()
    if (
        sitio.name != "public_html"
        or sitio.parent.name != "fundacionrededumex.org"
        or not dentro_de(sitio, home)
        or ruta.is_symlink()
        or not ruta.is_file()
    ):
        raise ValueError("SITIO_REAL_NO_VERIFICADO")
    datos = ruta.read_bytes()
    sha = hashlib.sha256(datos).hexdigest()
    if not sha.startswith(SHA_PRODUCCION_ESPERADO):
        raise ValueError(
            "VERSION_CAMBIO: SHA256 actual=" + sha[:16] +
            "; se detiene para evitar sobrescribir personalizaciones"
        )
    if b"\r\n" in datos:
        raise ValueError("FINES_DE_LINEA_CAMBIADOS: revisar antes de continuar")
    texto = datos.decode("utf-8")
    parcheado = parchear(texto)
    salida = parcheado.encode("utf-8")
    print("ORIGEN: Hostinger / FormularioRegistroModel.php")
    print("SHA256 original:", sha[:16])
    print("SHA256 candidato:", hashlib.sha256(salida).hexdigest()[:16])
    print("PRESERVADO: guardarOriginal, insertar, convocatorias, ubicación, horario y CRM central")
    print("CAMBIO: solo un bloque agregado al final de guardar(); sin sustituciones")
    if args.check:
        print("CHECK OK: compatible, solo lectura; no se creó ningún archivo")
        return

    destino = Path(args.output).absolute()
    if destino.is_symlink() or not destino.parent.is_dir():
        raise ValueError("DESTINO_NO_VALIDO: carpeta privada debe existir")
    destino = destino.resolve()
    if (
        not dentro_de(destino, home)
        or dentro_de(destino, sitio)
        or "public_html" in destino.parts
        or destino == ruta
    ):
        raise ValueError("DESTINO_DEBE_SER_PRIVADO_Y_FUERA_DEL_SITIO")
    fd = os.open(str(destino), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "wb") as archivo:
            archivo.write(salida)
    except Exception:
        try:
            os.unlink(str(destino))
        except OSError:
            pass
        raise
    print("PREPARADO:", destino)
    print("NO PUBLICADO: el sitio real y su BD siguen intactos")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("BLOQUEADO:", str(exc), file=sys.stderr)
        sys.exit(1)
