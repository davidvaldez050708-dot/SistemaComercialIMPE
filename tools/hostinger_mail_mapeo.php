<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/db_connection.php';
require_once $rootPath . '/app/services/HostingerApiService.php';

const DOMINIO_CORPORATIVO = 'rededucativamexico.org';
const ROLES_CORREO_INSTITUCIONAL = [4, 6];

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$normalizar = function ($texto) {
    $texto = strtolower(trim((string)$texto));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

    if (is_string($ascii) && $ascii !== '') {
        $texto = strtolower($ascii);
    }

    return preg_replace('/[^a-z0-9.]+/', '', $texto) ?: '';
};

$dominioOrden = function ($orden) {
    $valor = $orden['domain'] ?? $orden['domain_name'] ?? $orden['domainName'] ?? '';

    if (is_string($valor) || is_numeric($valor)) {
        return strtolower(trim((string)$valor));
    }

    if (is_array($valor)) {
        foreach (['name', 'domain', 'domain_name', 'domainName', 'value'] as $clave) {
            if (isset($valor[$clave]) && (is_string($valor[$clave]) || is_numeric($valor[$clave]))) {
                return strtolower(trim((string)$valor[$clave]));
            }
        }
    }

    return '';
};

$variantesUsuario = function ($usuario) use ($normalizar) {
    $nombre = trim((string)($usuario['nombre'] ?? ''));
    $apellidos = trim((string)($usuario['apellidos'] ?? ''));
    $username = trim((string)($usuario['usuario'] ?? ''));

    $partesNombre = preg_split('/\s+/', $nombre) ?: [];
    $partesApellido = preg_split('/\s+/', $apellidos) ?: [];
    $primerNombre = $normalizar($partesNombre[0] ?? '');
    $primerApellido = $normalizar($partesApellido[0] ?? '');
    $segundoApellido = $normalizar($partesApellido[1] ?? '');
    $usuarioNormalizado = $normalizar($username);
    $variantes = [];

    if ($usuarioNormalizado !== '') {
        $variantes[] = $usuarioNormalizado;
    }

    if ($primerNombre !== '' && $primerApellido !== '') {
        $inicial = substr($primerNombre, 0, 1);
        $variantes[] = $primerNombre . '.' . $primerApellido;
        $variantes[] = $primerNombre . $primerApellido;
        $variantes[] = $inicial . $primerApellido;
        $variantes[] = $inicial . '.' . $primerApellido;
    }

    if ($primerApellido !== '') {
        $variantes[] = $primerApellido;
    }

    if ($primerNombre !== '' && $segundoApellido !== '') {
        $inicial = substr($primerNombre, 0, 1);
        $variantes[] = $inicial . $segundoApellido;
        $variantes[] = $primerNombre . '.' . $segundoApellido;
    }

    return array_values(array_unique(array_filter($variantes)));
};

$linea('Mapeo de usuarios - Hostinger Mail');
$linea(str_repeat('=', 44));
$linea('Solo consulta. No modifica usuarios, buzones ni tokens.');
$linea('Dominio objetivo: ' . DOMINIO_CORPORATIVO);
$linea();

$hostinger = new HostingerApiService();

if (!$hostinger->estaConfigurado()) {
    $linea('[ERROR] HOSTINGER_API_TOKEN no está configurado.');
    exit(2);
}

$ordenes = $hostinger->listarOrdenesCorreo();

if (!($ordenes['ok'] ?? false)) {
    $linea('[ERROR] ' . ($ordenes['mensaje'] ?? 'No fue posible consultar Hostinger.'));
    $linea('Detalle: ' . ($ordenes['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

$ordenObjetivo = null;
foreach (($ordenes['ordenes'] ?? []) as $orden) {
    if (!is_array($orden)) {
        continue;
    }

    if ($dominioOrden($orden) === DOMINIO_CORPORATIVO) {
        $ordenObjetivo = $orden;
        break;
    }
}

if (!$ordenObjetivo) {
    $linea('[ERROR] No se encontró un servicio activo para ' . DOMINIO_CORPORATIVO . '.');
    exit(1);
}

$orderId = trim((string)(
    $ordenObjetivo['id'] ??
    $ordenObjetivo['resource_id'] ??
    $ordenObjetivo['resourceId'] ??
    ''
));

if ($orderId === '') {
    $linea('[ERROR] El servicio de correo no tiene Order ID.');
    exit(1);
}

$buzonesRespuesta = $hostinger->listarBuzones($orderId);
$aliasesRespuesta = $hostinger->listarAliases($orderId);

if (!($buzonesRespuesta['ok'] ?? false)) {
    $linea('[ERROR] No fue posible consultar los buzones.');
    $linea('Detalle: ' . ($buzonesRespuesta['mensaje_tecnico'] ?? 'Sin detalle.'));
    exit(1);
}

if (!($aliasesRespuesta['ok'] ?? false)) {
    $linea('[AVISO] No fue posible consultar aliases; el mapeo continuará solo con buzones.');
    $aliasesRespuesta = ['aliases' => []];
}

$direcciones = [];

foreach (($buzonesRespuesta['buzones'] ?? []) as $buzon) {
    if (!is_array($buzon)) {
        continue;
    }

    $correo = strtolower(trim((string)($buzon['address'] ?? $buzon['email'] ?? '')));
    $id = trim((string)($buzon['id'] ?? $buzon['resource_id'] ?? $buzon['resourceId'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    $direcciones[$correo] = [
        'correo' => $correo,
        'tipo' => 'BUZON',
        'mailbox_id' => $id,
        'destino' => $correo
    ];
}

foreach (($aliasesRespuesta['aliases'] ?? []) as $alias) {
    if (!is_array($alias)) {
        continue;
    }

    $correo = strtolower(trim((string)($alias['address'] ?? $alias['email'] ?? '')));
    $mailbox = is_array($alias['mailbox'] ?? null) ? $alias['mailbox'] : [];
    $mailboxId = trim((string)($mailbox['id'] ?? $mailbox['resource_id'] ?? $mailbox['resourceId'] ?? ''));
    $destino = strtolower(trim((string)($mailbox['address'] ?? $mailbox['email'] ?? '')));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    $direcciones[$correo] = [
        'correo' => $correo,
        'tipo' => 'ALIAS',
        'mailbox_id' => $mailboxId,
        'destino' => $destino
    ];
}

$database = new Database();
$conexion = $database->connect();
$roles = implode(',', array_map('intval', ROLES_CORREO_INSTITUCIONAL));
$sql = "SELECT id, nombre, apellidos, correo, usuario, rol_id
        FROM usuarios
        WHERE estado = 1
          AND rol_id IN ({$roles})
        ORDER BY rol_id, nombre, apellidos";
$resultado = $conexion->query($sql);
$usuarios = [];

if ($resultado) {
    while ($fila = $resultado->fetch_assoc()) {
        $usuarios[] = $fila;
    }
}

$linea('[OK] Servicio encontrado: ' . DOMINIO_CORPORATIVO . ' | ' . $orderId);
$linea('Buzones: ' . count($buzonesRespuesta['buzones'] ?? []));
$linea('Aliases: ' . count($aliasesRespuesta['aliases'] ?? []));
$linea('Usuarios activos Analista/Cuenta Clave: ' . count($usuarios));
$linea();

$confirmados = 0;
$sugeridos = 0;
$pendientes = 0;

foreach ($usuarios as $usuario) {
    $nombreCompleto = trim($usuario['nombre'] . ' ' . $usuario['apellidos']);
    $correoSistema = strtolower(trim((string)$usuario['correo']));
    $rol = (int)$usuario['rol_id'] === 4 ? 'Analista' : 'Cuenta Clave';
    $linea($nombreCompleto . ' | ' . $rol);
    $linea('  Sistema: ' . $correoSistema);

    if (isset($direcciones[$correoSistema])) {
        $item = $direcciones[$correoSistema];
        $linea('  [OK] Coincide exactamente con ' . strtolower($item['tipo']) . ' de Hostinger.');
        if ($item['tipo'] === 'ALIAS' && $item['destino'] !== '') {
            $linea('       Alias dirigido a: ' . $item['destino']);
        }
        $confirmados++;
        $linea();
        continue;
    }

    $variantes = $variantesUsuario($usuario);
    $candidatos = [];

    foreach ($direcciones as $correo => $item) {
        $local = strstr($correo, '@', true);
        $localNormalizado = $normalizar($local === false ? '' : $local);

        if ($localNormalizado !== '' && in_array($localNormalizado, $variantes, true)) {
            $candidatos[$correo] = $item;
        }
    }

    if (count($candidatos) === 1) {
        $item = reset($candidatos);
        $linea('  [POSIBLE] ' . $item['correo'] . ' (' . strtolower($item['tipo']) . ')');
        if ($item['tipo'] === 'ALIAS' && $item['destino'] !== '') {
            $linea('            Alias dirigido a: ' . $item['destino']);
        }
        $linea('  No se modificó la base; esta coincidencia debe confirmarse.');
        $sugeridos++;
    } elseif (count($candidatos) > 1) {
        $linea('  [REVISAR] Hay varias coincidencias posibles:');
        foreach ($candidatos as $item) {
            $linea('    - ' . $item['correo'] . ' (' . strtolower($item['tipo']) . ')');
        }
        $pendientes++;
    } else {
        $linea('  [PENDIENTE] No se encontró una coincidencia razonable en buzones o aliases.');
        $pendientes++;
    }

    $linea();
}

$linea('Resumen');
$linea('-------');
$linea('Coincidencias exactas: ' . $confirmados);
$linea('Coincidencias posibles: ' . $sugeridos);
$linea('Sin resolver / revisar: ' . $pendientes);
$linea();
$linea('No se realizó ningún cambio y no se envió ningún correo.');
