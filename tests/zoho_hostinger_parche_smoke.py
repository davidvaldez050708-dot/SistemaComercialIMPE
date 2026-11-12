#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verifica el parche de conciliación usando únicamente código sintético."""
from pathlib import Path
import importlib.util
import subprocess
import sys
import tempfile

script = Path(__file__).resolve().parents[1] / "tools/zoho_conciliar_formulario_hostinger.py"
spec = importlib.util.spec_from_file_location("zoho_conciliar", str(script))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

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
"""

def check(cond, label):
    if not cond:
        raise RuntimeError("FALLO: " + label)
    print("OK:", label)

patched = mod.parchear(original)
check(patched.count(mod.MARCADOR) == 1, "se inserta el gancho una sola vez")
check(patched.replace(mod.BLOQUE, "", 1) == original, "modelo original byte por byte conservado")
check(patched.find("ProspectoCentralIncorporacionService::despuesDeGuardar") <
      patched.find(mod.MARCADOR) < patched.find("return $id;"),
      "se encola despues de incorporacion central y antes del retorno")
check("HorarioContacto::paraGuardar" in patched and
      "convocatoriaExclusiva" in patched and "private function insertar" in patched,
      "se conservan horarios, convocatorias e inserción")

with tempfile.TemporaryDirectory(prefix="zoho-parche-") as tmp:
    file_path = Path(tmp) / "FormularioRegistroModel.php"
    file_path.write_text(patched, encoding="utf-8")
    result = subprocess.run(
        ["php", "-l", str(file_path)],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        universal_newlines=True
    )
    check(result.returncode == 0, "PHP sintacticamente correcto")

for invalido, desc in [
    (patched, "no se duplica gancho"),
    (original.replace("ProspectoCentralIncorporacionService::despuesDeGuardar",
                      "ServicioDesconocido::despuesDeGuardar"), "bloquea modificación comercial"),
    (original.replace("HorarioContacto::paraGuardar", "HorarioContacto::cambiar"),
     "bloquea falta de horario"),
    (original.replace("private function guardarOriginal", "private function otraFuncion"),
     "bloquea ancla alterada"),
]:
    try:
        mod.parchear(invalido)
    except ValueError:
        print("OK:", desc)
    else:
        raise RuntimeError("FALLO: " + desc)

print("COMPATIBILIDAD_HOSTINGER_PARCHE_OK: sin escrituras ni BD")
