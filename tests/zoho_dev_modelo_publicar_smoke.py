#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba offline de instalación/reversión atómica del modelo con contenido sintético."""
import importlib.util
from pathlib import Path
import shutil
import tempfile

raiz = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "publicador_zoho_modelo", str(raiz / "tools/zoho_dev_modelo_publicar.py")
)
pub = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pub)
spec2 = importlib.util.spec_from_file_location(
    "conciliador_zoho", str(raiz / "tools/zoho_conciliar_formulario_hostinger.py")
)
conc = importlib.util.module_from_spec(spec2)
spec2.loader.exec_module(conc)

original = """<?php
class FormularioRegistroModel
{
    public function guardar($datos)
    {
        $id=$this->guardarOriginal($datos);
        $origen=strtoupper(trim((string)($datos['origen']??'')));
        if ((int)$id>0 && !in_array($origen,['MARKETING_INSCRIPCION','PUBLICO_INSCRIPCION'],true)) {
            try {
                ProspectoCentralIncorporacionService::despuesDeGuardar((int)$id);
            } catch (Throwable $e) {
                error_log('central');
            }
        }
        return $id;
    }

    private function guardarOriginal($datos)
    {
        return $this->insertar($datos);
    }

    private function insertar(array $datos)
    {
        $horario = HorarioContacto::paraGuardar($datos['horario_contacto'] ?? null);
        $this->convocatoriaExclusiva('token', 'ref', 'registro');
        return 42;
    }

    public function convocatoriaExclusiva($token, $referencia, string $tipo)
    {
        return true;
    }
}
""".encode("utf-8")
candidato = conc.parchear(original.decode("utf-8")).encode("utf-8")
pub.ORIGINAL_SHA = pub.sha(original)[:16]
pub.CANDIDATO_SHA = pub.sha(candidato)[:16]

def verificar(condicion, mensaje):
    if not condicion:
        raise AssertionError(mensaje)
    print("OK:", mensaje)

with tempfile.TemporaryDirectory(prefix="zoho-modelo-test-") as tmp:
    home = Path(tmp).resolve()
    repo = home / "crm-zoho-developer-piloto"
    sitio = home / "domains/fundacionrededumex.org/public_html"
    (repo / "tools").mkdir(parents=True)
    (sitio / "app/models").mkdir(parents=True)
    (sitio / "app/services").mkdir(parents=True)
    (sitio / "tools").mkdir()
    shutil.copy2(str(raiz / "tools/zoho_conciliar_formulario_hostinger.py"),
                 str(repo / "tools/zoho_conciliar_formulario_hostinger.py"))

    modelo = sitio / pub.REL
    modelo.write_bytes(original)
    observacion = sitio / "app/models/NoModificar.php"
    observacion.write_text("OTRAS FUNCIONES_INTACTAS", encoding="utf-8")
    for archivo in pub.SERVICIOS:
        ruta = sitio / archivo
        ruta.write_bytes((raiz / archivo).read_bytes())

    privado = home / "candidato.php"
    privado.write_bytes(candidato)
    path, antes, despues, estado = pub.comprobaciones(home, repo, sitio, privado)
    verificar(estado == "ORIGINAL" and antes == original and despues == candidato,
              "preflight completo del modelo original y tres servicios")

    pub.publicar(home, path, antes, despues)
    verificar(modelo.read_bytes() == candidato and observacion.read_text() == "OTRAS FUNCIONES_INTACTAS",
              "se instala solo el modelo conciliado con respaldo privado")
    backups = list(home.glob(".zoho-modelo-respaldo-*/FormularioRegistroModel.php.original"))
    verificar(len(backups) == 1 and backups[0].read_bytes() == original,
              "respaldo original íntegro")

    _, actual, _, estado = pub.comprobaciones(home, repo, sitio, privado)
    verificar(estado == "CONCILIADO" and actual == candidato,
              "publicación detectada y no vuelve a conciliar")

    try:
        pub.publicar(home, modelo, actual, despues)
    except ValueError:
        print("OK: segunda publicación del mismo modelo bloqueada")
    else:
        raise AssertionError("se permitió segunda publicación")

    modelo.write_bytes(candidato + b"\n// PERSONALIZADO")
    try:
        pub.comprobaciones(home, repo, sitio, privado)
    except ValueError:
        print("OK: personalización posterior bloquea la reversión")
    else:
        raise AssertionError("se ignoró personalización posterior")
    modelo.write_bytes(candidato)

    pub.deshacer(home, modelo, candidato, backups[0])
    verificar(modelo.read_bytes() == original and
              observacion.read_text() == "OTRAS FUNCIONES_INTACTAS",
              "rollback restauró solo el modelo original; otros archivos intactos")

    _, actual, _, estado = pub.comprobaciones(home, repo, sitio, privado)
    verificar(estado == "ORIGINAL" and actual == original,
              "preflight vuelve a estado original tras rollback")

print("PUBLICADOR_MODELO_ZOHO_OK: cambios aditivos, respaldo, rollback, sin BD")
