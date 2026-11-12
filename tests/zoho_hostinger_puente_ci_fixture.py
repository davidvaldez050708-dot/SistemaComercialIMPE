#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Construye una variante SINTÉTICA similar al flujo de Hostinger.

Se usa el MISMO parche productivo y los servicios reales del PR, pero
NO se incluye ni se transmite el modelo real de Hostinger.
Solo para CI con MySQL temporal, jamás modifica public_html ni los datos IMPE.
"""
import importlib.util
import os
from pathlib import Path
import shutil
import sys

repo = Path(__file__).resolve().parents[1]
destino = Path(os.environ.get("ZOHO_PUENTE_ROOT", ""))
if (
    os.environ.get("GITHUB_ACTIONS") != "true"
    or os.environ.get("APP_ENV") != "testing"
    or os.environ.get("IMPE_DB_HOST") != "127.0.0.1:33308"
    or os.environ.get("IMPE_DB_NAME") != "impe_zoho_puente_ci"
    or not str(destino).startswith("/tmp/zoho-puente-ci-")
    or destino.is_symlink()
    or not destino.is_dir()
    or destino.resolve() != destino
):
    raise SystemExit("BLOQUEADO: fixture exclusivamente MySQL efimero GitHub Actions")

spec = importlib.util.spec_from_file_location(
    "zoho_conciliador", str(repo / "tools/zoho_conciliar_formulario_hostinger.py")
)
conciliador = importlib.util.module_from_spec(spec)
spec.loader.exec_module(conciliador)

# Se replica el contrato conocido del modelo de Hostinger, no su código real.
# El servicio central se SUSTITUYE POR UN DOBLE que nunca asigna prospectos.
modelo_original = """<?php
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../services/ProspectoCentralIncorporacionService.php';

final class HorarioContacto {
    public static function paraGuardar($valor) { return $valor; }
}
class FormularioRegistroModel {
    private $connection;

    public function __construct() {
        $this->connection = (new Database())->connect(false);
    }

    public function guardar($datos)
    {
        $id=$this->guardarOriginal($datos);
        $origen=strtoupper(trim((string)($datos['origen']??'')));
        if ((int)$id>0 && !in_array($origen,['MARKETING_INSCRIPCION','PUBLICO_INSCRIPCION'],true)) {
            try {
                ProspectoCentralIncorporacionService::despuesDeGuardar((int)$id);
            } catch (Throwable $e) {
                error_log('[puente_ci] central simulado falló.');
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
        $origen = strtoupper(trim((string)($datos['origen'] ?? 'PUBLICO')));
        $inscripcion = in_array($origen, ['MARKETING_INSCRIPCION', 'PUBLICO_INSCRIPCION'], true);
        $tabla = $inscripcion ? 'formulario_inscripciones' : 'formulario_registros';
        $stmt = $this->connection->prepare(
            "INSERT INTO {$tabla}
             (nombre, apellido, correo, movil, horario_contacto, origen, created_at, updated_at)
             VALUES (?,?,?,?,?,?,NOW(),NOW())"
        );
        $nombre = (string)($datos['nombre'] ?? 'Prueba');
        $apellido = (string)($datos['apellido'] ?? 'Staging');
        $correo = (string)($datos['correo'] ?? '');
        $movil = (string)($datos['movil'] ?? '');
        $horario = (string)$horario;
        $stmt->bind_param('ssssss', $nombre, $apellido, $correo, $movil, $horario, $origen);
        $stmt->execute();
        $stmt->close();
        return (int)$this->connection->insert_id;
    }

    public function convocatoriaExclusiva($token, $referencia, string $tipo)
    {
        return true;
    }
}
"""
modelo_conciliado = conciliador.parchear(modelo_original)
assert modelo_conciliado.replace(conciliador.BLOQUE, "", 1) == modelo_original
assert modelo_conciliado.count(conciliador.MARCADOR) == 1
assert (
    modelo_conciliado.index("ProspectoCentralIncorporacionService::despuesDeGuardar")
    < modelo_conciliado.index(conciliador.MARCADOR)
)
for carpeta in ("app/models", "app/services", "config", "private", "home"):
    (destino / carpeta).mkdir(parents=True, exist_ok=True)
(destino / "app/models/FormularioRegistroModel.php").write_text(modelo_conciliado, encoding="utf-8")

doble_comercial = """<?php
// Este doble NO puede crear prospectos ni asignaciones.
final class ProspectoCentralIncorporacionService {
    public static $llamadas = [];
    public static $fallar = false;
    public static function despuesDeGuardar(int $id): void {
        $db = (new Database())->connect(false);
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes WHERE registro_id=?'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$fila['n'] !== 0) {
            throw new LogicException('COLA_CREADA_ANTES_DE_LLAMADA_COMERCIAL');
        }
        self::$llamadas[] = $id;
        if (self::$fallar) {
            throw new RuntimeException('ERROR_COMERCIAL_SINTETICO');
        }
    }
}
"""
(destino / "app/services/ProspectoCentralIncorporacionService.php").write_text(
    doble_comercial, encoding="utf-8"
)

for relativo in (
    "app/services/ZohoDeveloperRegistroSync.php",
    "app/services/ZohoDeveloperRegistroWorker.php",
    "config/config.php",
    "config/private_config.php",
    "config/db_connection.php",
):
    shutil.copyfile(str(repo / relativo), str(destino / relativo))
print("PUENTE_CI_PREPARADO: modelo sintetico conciliado + servicios reales; 0 datos comerciales")
