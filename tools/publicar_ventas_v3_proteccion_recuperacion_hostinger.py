#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicar exclusivamente la protección de Recuperación del Bridge PBX de asesores V3.
CHECK/APPLY/ROLLBACK por hash exacto, sin SQL y sin modificar Telefonía.
"""
import argparse
import sys
import publicar_ventas_v3_gestion_potenciales_hostinger as p

p.ARCHIVOS = [
    ("app/services/EmbudoComercialResultadoBridge.php",
     "f03f8da725dfe3645db9ce9e1a76e11c38c81427",
     "4388551ad7aa46bf6b027716d7455edd4751431c"),
]
p.NO_MODIFICAR = {
    # No reconfigurar acceso V3, resultados PBX ni políticas vigentes.
    "app/services/EmbudoComercialV3AccesoControlado.php":
        "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/TelefoniaResultadoVentasService.php":
        "1d4472ba73e7de4097ef2f592104160b254d7f8f",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
        "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
        "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
}
p.CONFIRMAR = "PUBLICAR_GUARDIA_RECUPERACION_V3_IMPE"
p.REVERTIR = "REVERTIR_GUARDIA_RECUPERACION_V3_IMPE"


def validar_paquete(stage):
    rel, _, nuevo = p.ARCHIVOS[0]
    archivo = p.confiable(stage, rel)
    if p.blob(archivo) != nuevo:
        raise p.Bloqueo("Bridge fuera de versión revisada.")
    p.lint_php(archivo)
    texto = p.leer(archivo)
    for regla, mensaje in [
        (b'bloquearRecuperacionAutomaticaV3' in texto, "regla de aislamiento"),
        (b'EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuarioId)' in texto,
         "acceso V3 privado y por rol"),
        (b"return ['vinculada'=>true,'movida'=>false,'etapa'=>$etapaAnterior]" in texto,
         "conservar llamada vinculada y etapa"),
        (b'INSERT INTO prospecto_embudo_llamadas' in texto, "vinculación PBX existente"),
        (b'INSERT INTO prospecto_embudo_movimientos' in texto, "historial anterior"),
        (b'IMPE_VENTAS_V3_PILOTO' not in texto and
         b'EmbudoComercialV3PilotoControlado' not in texto, "piloto antiguo ausente"),
    ]:
        if not regla:
            raise p.Bloqueo("Contrato no verificado: " + mensaje)
    print("CONTRATOS_GUARDIA_V3_OK: V3 aislado, teléfono, historia y piloto ausente.")


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--site", required=True)
    ap.add_argument("--stage")
    ap.add_argument("--backup-root")
    ap.add_argument("--backup-dir")
    ap.add_argument("--confirm")
    modo = ap.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    modo.add_argument("--rollback", action="store_true")
    a = ap.parse_args()
    site = p.raiz(a.site)
    if not p.dentro(p.confiable(site,"config/db_connection.php"),site):
        raise p.Bloqueo("Raíz del CRM no reconocida.")
    if a.rollback:
        if a.confirm != p.REVERTIR or not a.backup_dir:
            raise p.Bloqueo("ROLLBACK requiere confirmación y respaldo específico.")
        backup = p.raiz(a.backup_dir)
        if p.dentro(backup,site):
            raise p.Bloqueo("Respaldo dentro del sitio público.")
        p.revertir(site, backup)
        print("ROLLBACK_GUARDIA_V3_OK")
        return
    if not a.stage:
        raise p.Bloqueo("Falta stage.")
    stage = p.raiz(a.stage)
    if p.dentro(stage,site):
        raise p.Bloqueo("Stage dentro de public_html.")
    validar_paquete(stage)
    estado = p.estado(site)
    if a.check:
        print("CHECK_GUARDIA_V3_OK: "+("ya aplicado." if estado=="READY" else "listo para publicar."))
        return
    if a.confirm != p.CONFIRMAR or not a.backup_root:
        raise p.Bloqueo("Publicación requiere confirmación explícita y respaldo.")
    root = p.raiz(a.backup_root,False)
    if p.dentro(root,site):
        raise p.Bloqueo("Respaldo dentro del sitio público.")
    p.publicar(site,stage,root)
    print("APPLY_GUARDIA_V3_OK: bridge único; sin SQL ni cambios en Telefonía.")
    print("Verificar CHECK y registro de llamadas antes de continuar.")


if __name__=="__main__":
    try:
        main()
    except (p.Bloqueo,OSError,ValueError) as exc:
        print("BLOQUEADO: "+str(exc),file=sys.stderr)
        sys.exit(2)
