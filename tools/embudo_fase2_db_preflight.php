<?php
/**
 * Solo lectura. Preflight de la migración 2A y dependencias reales de Hostinger.
 * Uso: php embudo_fase2_db_preflight.php /ruta/public_html /ruta/embudo_fase2.sql
 * No crea tablas, modifica permisos, distribuye prospectos ni prepara DDL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
$site = $argv[1] ?? '';
$file = $argv[2] ?? '';
$requiereInstalada = ($argv[3] ?? '') === '--installed';
if (!is_string($site) || !is_dir($site) ||
    !is_string($file) || !is_file($file)) {
    fwrite(STDERR, "Uso: php embudo_fase2_db_preflight.php SITIO SQL\n");
    exit(2);
}
$bytes = file_get_contents($file);
$esperado = '478f3405b956c8801e93af588deec08f5d481054';
if (!is_string($bytes) ||
    !hash_equals($esperado, sha1('blob '.strlen($bytes)."\0".$bytes))) {
    fwrite(STDERR, "BLOQUEADO: migración 2A no íntegra.\n");
    exit(2);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once rtrim($site, '/').'/config/db_connection.php';
    $db = (new Database())->connect(false);

    $obtenerTabla = static function (string $nombre) use ($db): ?string {
        $q = $db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $q->bind_param('s', $nombre);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        return $row ? strtoupper((string)$row['ENGINE']) : null;
    };
    $obtenerColumnas = static function (string $nombre) use ($db): array {
        $q = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $q->bind_param('s', $nombre);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();
        return array_column($rows, 'COLUMN_NAME');
    };
    $requeridas = [
        'roles' => ['id','nombre','estado'],
        'usuarios' => ['id','rol_id','estado'],
        'permisos' => ['id','codigo','modulo','nombre','descripcion','estado'],
        'rol_permisos' => ['rol_id','permiso_id'],
        'prospectos_comerciales' => ['id','telefono_normalizado'],
        'prospecto_asignaciones' => ['id','prospecto_id','asesor_id','vigente'],
        'prospecto_embudo_etapas' => ['asignacion_id','etapa','cambiado_por','actualizado_at'],
        'prospecto_embudo_movimientos' => [
            'id','asignacion_id','etapa_anterior','etapa_nueva','motivo','usuario_id','creado_at'
        ],
        'prospecto_distribucion_control' => ['id','meta_habilitado','aliados_habilitado']
    ];
    foreach ($requeridas as $tabla => $campos) {
        $engine = $obtenerTabla($tabla);
        if ($engine !== 'INNODB') {
            throw new RuntimeException("Tabla base ausente o no InnoDB: $tabla");
        }
        $actuales = $obtenerColumnas($tabla);
        foreach ($campos as $campo) {
            if (!in_array($campo,$actuales,true)) {
                throw new RuntimeException("Falta campo base $tabla.$campo");
            }
        }
        echo "BASE OK: $tabla\n";
    }

    $control = $db->query(
        'SELECT meta_habilitado, aliados_habilitado
         FROM prospecto_distribucion_control WHERE id=1'
    )->fetch_assoc();
    if (!$control || (int)$control['meta_habilitado'] !== 0 ||
        (int)$control['aliados_habilitado'] !== 0) {
        throw new RuntimeException('Meta o Aliados activados; no continuar.');
    }
    echo "DISTRIBUCIÓN OK: Meta y Aliados desactivados\n";

    $q = $db->prepare(
        "SELECT id FROM roles WHERE nombre=? AND estado=1"
    );
    $rol = 'Asesor de Ventas';
    $q->bind_param('s',$rol);
    $q->execute();
    $asesores = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    if (count($asesores) !== 1) {
        throw new RuntimeException('Rol Asesor de Ventas no único o inactivo.');
    }
    echo "ROL OK: Asesor de Ventas habilitado\n";

    $nuevas = [
        'prospecto_embudo_agenda' => [
            'asignacion_id','proxima_accion_at','revision_fecha','actualizado_at','actualizado_por'
        ],
        'prospecto_embudo_llamadas' => [
            'id','asignacion_id','usuario_id','extension','pbx_call_id','resultado','creado_at'
        ]
    ];
    foreach ($nuevas as $tabla => $campos) {
        $engine = $obtenerTabla($tabla);
        if ($engine === null) {
            if ($requiereInstalada) {
                throw new RuntimeException("Migración 2A sin instalar: $tabla");
            }
            echo "PENDIENTE DE MIGRACIÓN: $tabla\n";
            continue;
        }
        if ($engine !== 'INNODB') {
            throw new RuntimeException("Tabla nueva incompatible: $tabla");
        }
        foreach ($campos as $campo) {
            if (!in_array($campo,$obtenerColumnas($tabla),true)) {
                throw new RuntimeException("Tabla nueva incompleta: $tabla.$campo");
            }
        }
        echo "YA EXISTE Y ES COMPATIBLE: $tabla\n";
    }

    $q = $db->prepare('SELECT id, estado FROM permisos WHERE codigo=? LIMIT 2');
    foreach ([
        'ventas.embudo.mover',
        'ventas.recuperacion.gestionar',
        'ventas.descartados.gestionar'
    ] as $codigo) {
        $q->bind_param('s',$codigo);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!$rows) {
            if ($requiereInstalada) {
                throw new RuntimeException("Permiso de fase 2A sin instalar: $codigo");
            }
            echo "PERMISO POR INSTALAR: $codigo\n";
        } elseif (count($rows) !== 1 || (int)$rows[0]['estado'] !== 1) {
            throw new RuntimeException("Permiso existente incompatible o inactivo: $codigo");
        } else {
            echo "PERMISO YA EXISTENTE: $codigo\n";
        }
    }
    $q->close();
    echo ($requiereInstalada
        ? "CHECK BD 2A INSTALADA OK: tablas y permisos activos; sin escrituras.\n"
        : "CHECK BD FASE 2A OK: SQL íntegro, sin escrituras.\n");
    $db->close();
} catch (Throwable $e) {
    error_log('[embudo_fase2_preflight] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR, "BLOQUEADO: preflight BD 2A: ".$e->getMessage()."\n");
    exit(2);
}
