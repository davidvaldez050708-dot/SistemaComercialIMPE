<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AliadoModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function estructuraDisponible()
    {
        return $this->tablaExiste('aliados_asignaciones') &&
            $this->tablaExiste('aliados_convocatorias_envios');
    }

    public function contactosDisponibles()
    {
        return $this->tablaExiste('aliados_contactos');
    }

    public function consentimientoWhatsappDisponible()
    {
        return $this->contactosDisponibles() &&
            $this->columnaExiste(
                'aliados_contactos',
                'autorizado_whatsapp'
            );
    }

    public function obtenerResumenTerritorial($usuarioId, $esAdministrador = false)
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = "SELECT
                    s.estado_id,
                    COUNT(DISTINCT s.id) AS total_aliados,
                    COUNT(DISTINCT CASE
                        WHEN s.municipio_id IS NOT NULL AND s.municipio_id > 0
                        THEN s.municipio_id
                        ELSE NULL
                    END) AS total_municipios_aliados
                FROM seguimientos_vinculacion s
                INNER JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                LEFT JOIN aliados_asignaciones aa
                    ON aa.seguimiento_id = s.id
                WHERE s.activo = 1
                  AND p.convenio_formalizado_at IS NOT NULL";

        $tipos = '';
        $parametros = [];

        if (!$esAdministrador) {
            $sql .= " AND aa.cuenta_clave_usuario_id = ?
                      AND aa.activo = 1";
            $tipos = 'i';
            $parametros[] = (int)$usuarioId;
        }

        $sql .= " GROUP BY s.estado_id";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerListado($usuarioId, $esAdministrador = false, $filtros = [])
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = $this->consultaBase() . "
                WHERE s.activo = 1
                  AND p.convenio_formalizado_at IS NOT NULL";

        $tipos = '';
        $parametros = [];

        if (!$esAdministrador) {
            $sql .= " AND aa.cuenta_clave_usuario_id = ?
                      AND aa.activo = 1";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $buscar = trim((string)($filtros['buscar'] ?? ''));
        $estadoId = (int)($filtros['estado_id'] ?? 0);
        $municipioId = (int)($filtros['municipio_id'] ?? 0);
        $analistaId = (int)($filtros['analista_id'] ?? 0);

        if ($buscar !== '') {
            $sql .= " AND (
                        s.nombre_entidad LIKE ?
                        OR s.contacto_nombre LIKE ?
                        OR s.correo_verificado LIKE ?
                        OR s.correo_fuente LIKE ?
                    )";
            $termino = '%' . $buscar . '%';
            $tipos .= 'ssss';
            array_push($parametros, $termino, $termino, $termino, $termino);
        }

        if ($estadoId > 0) {
            $sql .= " AND s.estado_id = ?";
            $tipos .= 'i';
            $parametros[] = $estadoId;
        }

        if ($municipioId > 0) {
            $sql .= " AND s.municipio_id = ?";
            $tipos .= 'i';
            $parametros[] = $municipioId;
        }

        if ($analistaId > 0) {
            $sql .= " AND s.analista_id = ?";
            $tipos .= 'i';
            $parametros[] = $analistaId;
        }

        $sql .= " ORDER BY
                    p.convenio_formalizado_at DESC,
                    s.nombre_entidad ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerAliado($seguimientoId, $usuarioId, $esAdministrador = false)
    {
        if (!$this->estructuraDisponible()) {
            return null;
        }

        $sql = $this->consultaBase() . "
                WHERE s.id = ?
                  AND s.activo = 1
                  AND p.convenio_formalizado_at IS NOT NULL";
        $tipos = 'i';
        $parametros = [(int)$seguimientoId];

        if (!$esAdministrador) {
            $sql .= " AND aa.cuenta_clave_usuario_id = ?
                      AND aa.activo = 1";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerConvocatoriasDisponibles($estadoId)
    {
        $estadoId = (int)$estadoId;
        if ($estadoId <= 0) {
            return [];
        }

        $sql = "SELECT DISTINCT
                    c.id,
                    c.titulo,
                    c.imagen,
                    c.enlace_registro,
                    c.categoria,
                    c.tipo_convocatoria,
                    c.subtipo_convocatoria,
                    c.fecha_inicio,
                    c.fecha_termino
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE ce.estado_id = ?
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()
                ORDER BY
                    c.fecha_termino ASC,
                    c.fecha_inicio DESC,
                    c.titulo ASC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerConvocatoriaAplicable($convocatoriaId, $estadoId)
    {
        $sql = "SELECT
                    c.id,
                    c.titulo,
                    c.imagen,
                    c.categoria,
                    c.tipo_convocatoria,
                    c.subtipo_convocatoria,
                    c.fecha_inicio,
                    c.fecha_termino
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE c.id = ?
                  AND ce.estado_id = ?
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $convocatoriaId = (int)$convocatoriaId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $convocatoriaId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerHistorial($seguimientoId, $usuarioId, $esAdministrador = false)
    {
        if (!$this->obtenerAliado($seguimientoId, $usuarioId, $esAdministrador)) {
            return [];
        }

        $sql = "SELECT
                    envios.id,
                    envios.convocatoria_id,
                    envios.canal,
                    envios.destinatario,
                    envios.asunto,
                    envios.mensaje,
                    envios.convocatoria_titulo,
                    envios.convocatoria_imagen,
                    envios.convocatoria_enlace_registro,
                    envios.convocatoria_fecha_inicio,
                    envios.convocatoria_fecha_termino,
                    envios.estado_envio,
                    envios.proveedor,
                    envios.error_detalle,
                    envios.enviado_at,
                    CONCAT_WS(' ', u.nombre, u.apellidos) AS enviado_por_nombre
                FROM aliados_convocatorias_envios envios
                INNER JOIN usuarios u
                    ON u.id = envios.cuenta_clave_usuario_id
                WHERE envios.seguimiento_id = ?
                ORDER BY envios.enviado_at DESC, envios.id DESC";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerUltimoEnvioExitoso($seguimientoId, $convocatoriaId, $canal)
    {
        $sql = "SELECT id, enviado_at, destinatario
                FROM aliados_convocatorias_envios
                WHERE seguimiento_id = ?
                  AND convocatoria_id = ?
                  AND canal = ?
                  AND estado_envio = 'ENVIADO'
                ORDER BY enviado_at DESC, id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $convocatoriaId = (int)$convocatoriaId;
        $canal = strtoupper(trim((string)$canal));
        $stmt->bind_param('iis', $seguimientoId, $convocatoriaId, $canal);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function registrarEnvio($datos)
    {
        $sql = "INSERT INTO aliados_convocatorias_envios (
                    seguimiento_id,
                    convocatoria_id,
                    cuenta_clave_usuario_id,
                    canal,
                    destinatario,
                    asunto,
                    mensaje,
                    convocatoria_titulo,
                    convocatoria_imagen,
                    convocatoria_enlace_registro,
                    convocatoria_fecha_inicio,
                    convocatoria_fecha_termino,
                    estado_envio,
                    proveedor,
                    error_detalle,
                    enviado_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)($datos['seguimiento_id'] ?? 0);
        $convocatoriaId = (int)($datos['convocatoria_id'] ?? 0);
        $usuarioId = (int)($datos['usuario_id'] ?? 0);
        $canal = (string)($datos['canal'] ?? 'CORREO');
        $destinatario = (string)($datos['destinatario'] ?? '');
        $asunto = (string)($datos['asunto'] ?? '');
        $mensaje = (string)($datos['mensaje'] ?? '');
        $titulo = (string)($datos['convocatoria_titulo'] ?? '');
        $imagen = (string)($datos['convocatoria_imagen'] ?? '');
        $enlaceRegistro = (string)($datos['convocatoria_enlace_registro'] ?? '');
        $fechaInicio = (string)($datos['convocatoria_fecha_inicio'] ?? '');
        $fechaTermino = (string)($datos['convocatoria_fecha_termino'] ?? '');
        $estadoEnvio = (string)($datos['estado_envio'] ?? 'ENVIADO');
        $proveedor = (string)($datos['proveedor'] ?? '');
        $error = (string)($datos['error_detalle'] ?? '');

        $stmt->bind_param(
            'iiissssssssssss',
            $seguimientoId,
            $convocatoriaId,
            $usuarioId,
            $canal,
            $destinatario,
            $asunto,
            $mensaje,
            $titulo,
            $imagen,
            $enlaceRegistro,
            $fechaInicio,
            $fechaTermino,
            $estadoEnvio,
            $proveedor,
            $error
        );

        return $stmt->execute();
    }

    public function obtenerContactosDifusion(array $aliado)
    {
        $contactos = [];
        $normalizados = [];
        $consentimientoDisponible =
            $this->consentimientoWhatsappDisponible();

        if ($this->contactosDisponibles()) {
            $consentimientoSelect = $consentimientoDisponible
                ? ",
                        autorizado_whatsapp,
                        autorizado_whatsapp_at"
                : ",
                        0 AS autorizado_whatsapp,
                        NULL AS autorizado_whatsapp_at";

            $sql = "SELECT
                        id,
                        numero,
                        numero_normalizado,
                        etiqueta,
                        origen,
                        confirmado_whatsapp,
                        preferido_difusion" .
                        $consentimientoSelect . ",
                        created_at,
                        updated_at
                    FROM aliados_contactos
                    WHERE seguimiento_id = ?
                      AND activo = 1
                    ORDER BY
                        preferido_difusion DESC,
                        confirmado_whatsapp DESC,
                        updated_at DESC,
                        id DESC";

            $stmt = $this->connection->prepare($sql);
            $seguimientoId = (int)($aliado['seguimiento_id'] ?? 0);
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();
            $resultadoContactos = $stmt->get_result();

            while ($fila = $resultadoContactos->fetch_assoc()) {
                $normalizado = $this->normalizarNumero(
                    (string)($fila['numero_normalizado'] ?? $fila['numero'] ?? '')
                );
                if ($normalizado !== '') {
                    $normalizados[$normalizado] = true;
                }

                $contactos[] = [
                    'id' => (int)$fila['id'],
                    'numero' => (string)$fila['numero'],
                    'numero_normalizado' => $normalizado,
                    'etiqueta' => (string)$fila['etiqueta'],
                    'origen' => (string)$fila['origen'],
                    'origen_label' => $this->etiquetaOrigenContacto($fila['origen']),
                    'confirmado_whatsapp' => (int)$fila['confirmado_whatsapp'] === 1,
                    'autorizado_whatsapp' => (int)($fila['autorizado_whatsapp'] ?? 0) === 1,
                    'autorizado_whatsapp_at' => (string)($fila['autorizado_whatsapp_at'] ?? ''),
                    'preferido_difusion' => (int)$fila['preferido_difusion'] === 1,
                    'editable' => true
                ];
            }
        }

        $fuentes = [
            [
                'numero' => trim((string)($aliado['whatsapp_verificado'] ?? '')),
                'etiqueta' => 'WhatsApp verificado',
                'origen' => 'WHATSAPP_VERIFICADO',
                'confirmado_whatsapp' => true
            ],
            [
                'numero' => trim((string)($aliado['telefono_verificado'] ?? '')),
                'etiqueta' => 'Teléfono verificado',
                'origen' => 'TELEFONO_VERIFICADO',
                'confirmado_whatsapp' => false
            ],
            [
                'numero' => trim((string)($aliado['telefono_fuente'] ?? '')),
                'etiqueta' => 'Teléfono de origen',
                'origen' => 'TELEFONO_FUENTE',
                'confirmado_whatsapp' => false
            ]
        ];

        foreach ($fuentes as $fuente) {
            $numero = (string)$fuente['numero'];
            $normalizado = $this->normalizarNumero($numero);

            if ($numero === '' || $normalizado === '' || isset($normalizados[$normalizado])) {
                continue;
            }

            $normalizados[$normalizado] = true;
            $contactos[] = [
                'id' => 0,
                'numero' => $numero,
                'numero_normalizado' => $normalizado,
                'etiqueta' => (string)$fuente['etiqueta'],
                'origen' => (string)$fuente['origen'],
                'origen_label' => (string)$fuente['etiqueta'],
                'confirmado_whatsapp' => (bool)$fuente['confirmado_whatsapp'],
                'autorizado_whatsapp' => false,
                'autorizado_whatsapp_at' => '',
                'preferido_difusion' => false,
                'editable' => false
            ];
        }

        return $contactos;
    }

    public function guardarContactoDifusion($seguimientoId, $usuarioId, array $datos)
    {
        if (!$this->contactosDisponibles()) {
            throw new RuntimeException('La estructura de contactos de Aliados no está disponible.');
        }

        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $contactoId = (int)($datos['id'] ?? 0);
        $numero = trim((string)($datos['numero'] ?? ''));
        $numeroNormalizado = $this->normalizarNumero($numero);
        $etiqueta = trim((string)($datos['etiqueta'] ?? 'Difusión'));
        $origen = strtoupper(trim((string)($datos['origen'] ?? 'CUENTA_CLAVE')));
        $confirmadoWhatsapp = !empty($datos['confirmado_whatsapp']) ? 1 : 0;
        $autorizadoWhatsapp = !empty($datos['autorizado_whatsapp']) ? 1 : 0;
        $preferido = !empty($datos['preferido_difusion']) ? 1 : 0;
        $consentimientoDisponible =
            $this->consentimientoWhatsappDisponible();

        if ($autorizadoWhatsapp === 1) {
            $confirmadoWhatsapp = 1;
        }

        if (!$consentimientoDisponible) {
            $autorizadoWhatsapp = 0;
        }

        if ($etiqueta === '') {
            $etiqueta = 'Difusión';
        }

        if (!in_array($origen, [
            'CUENTA_CLAVE',
            'WHATSAPP_VERIFICADO',
            'TELEFONO_VERIFICADO',
            'TELEFONO_FUENTE'
        ], true)) {
            $origen = 'CUENTA_CLAVE';
        }

        $this->connection->begin_transaction();

        try {
            if ($preferido === 1) {
                $stmtPreferido = $this->connection->prepare(
                    "UPDATE aliados_contactos
                     SET preferido_difusion = 0,
                         actualizado_por = ?
                     WHERE seguimiento_id = ?
                       AND activo = 1"
                );
                $stmtPreferido->bind_param('ii', $usuarioId, $seguimientoId);
                $stmtPreferido->execute();
            }

            if ($contactoId > 0) {
                $stmtPropio = $this->connection->prepare(
                    "SELECT id
                     FROM aliados_contactos
                     WHERE id = ?
                       AND seguimiento_id = ?
                     LIMIT 1"
                );
                $stmtPropio->bind_param('ii', $contactoId, $seguimientoId);
                $stmtPropio->execute();

                if ($stmtPropio->get_result()->num_rows === 0) {
                    throw new RuntimeException('El contacto no pertenece a este aliado.');
                }

                $stmtExistente = $this->connection->prepare(
                    "SELECT id
                     FROM aliados_contactos
                     WHERE seguimiento_id = ?
                       AND numero_normalizado = ?
                       AND id <> ?
                     LIMIT 1"
                );
                $stmtExistente->bind_param(
                    'isi',
                    $seguimientoId,
                    $numeroNormalizado,
                    $contactoId
                );
                $stmtExistente->execute();

                if ($stmtExistente->get_result()->num_rows > 0) {
                    throw new RuntimeException('Ese número ya está registrado para este aliado.');
                }

                $stmt = $this->connection->prepare(
                    "UPDATE aliados_contactos
                     SET numero = ?,
                         numero_normalizado = ?,
                         etiqueta = ?,
                         origen = ?,
                         confirmado_whatsapp = ?,
                         preferido_difusion = ?,
                         activo = 1,
                         actualizado_por = ?
                     WHERE id = ?
                       AND seguimiento_id = ?"
                );
                $stmt->bind_param(
                    'ssssiiiii',
                    $numero,
                    $numeroNormalizado,
                    $etiqueta,
                    $origen,
                    $confirmadoWhatsapp,
                    $preferido,
                    $usuarioId,
                    $contactoId,
                    $seguimientoId
                );
                $stmt->execute();
            } else {
                $stmt = $this->connection->prepare(
                    "INSERT INTO aliados_contactos (
                        seguimiento_id,
                        numero,
                        numero_normalizado,
                        etiqueta,
                        origen,
                        confirmado_whatsapp,
                        preferido_difusion,
                        activo,
                        creado_por,
                        actualizado_por
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        numero = VALUES(numero),
                        etiqueta = VALUES(etiqueta),
                        origen = VALUES(origen),
                        confirmado_whatsapp = VALUES(confirmado_whatsapp),
                        preferido_difusion = VALUES(preferido_difusion),
                        activo = 1,
                        actualizado_por = VALUES(actualizado_por)"
                );
                $stmt->bind_param(
                    'issssiiii',
                    $seguimientoId,
                    $numero,
                    $numeroNormalizado,
                    $etiqueta,
                    $origen,
                    $confirmadoWhatsapp,
                    $preferido,
                    $usuarioId,
                    $usuarioId
                );
                $stmt->execute();
            }

            if ($consentimientoDisponible) {
                $stmtConsentimiento = $this->connection->prepare(
                    "UPDATE aliados_contactos
                     SET autorizado_whatsapp = ?,
                         autorizado_whatsapp_at = CASE
                            WHEN ? = 1
                                THEN COALESCE(autorizado_whatsapp_at, NOW())
                            ELSE NULL
                         END,
                         autorizado_whatsapp_por = CASE
                            WHEN ? = 1 THEN ?
                            ELSE NULL
                         END,
                         actualizado_por = ?
                     WHERE seguimiento_id = ?
                       AND numero_normalizado = ?
                       AND activo = 1"
                );

                $stmtConsentimiento->bind_param(
                    'iiiiiis',
                    $autorizadoWhatsapp,
                    $autorizadoWhatsapp,
                    $autorizadoWhatsapp,
                    $usuarioId,
                    $usuarioId,
                    $seguimientoId,
                    $numeroNormalizado
                );
                $stmtConsentimiento->execute();
            }

            $this->connection->commit();
            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    public function desactivarContactoDifusion($contactoId, $seguimientoId, $usuarioId)
    {
        if (!$this->contactosDisponibles()) {
            return false;
        }

        $sql = "UPDATE aliados_contactos
                SET activo = 0,
                    preferido_difusion = 0,
                    actualizado_por = ?
                WHERE id = ?
                  AND seguimiento_id = ?";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $contactoId = (int)$contactoId;
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('iii', $usuarioId, $contactoId, $seguimientoId);

        if (!$stmt->execute()) {
            return false;
        }

        return $stmt->affected_rows > 0;
    }

    public function obtenerUsuarioRemitente($usuarioId)
    {
        $sql = "SELECT id, nombre, apellidos, correo
                FROM usuarios
                WHERE id = ?
                  AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function contarConvocatoriasVigentesPorEstados($estadoIds)
    {
        $estadoIds = array_values(array_unique(array_filter(array_map('intval', $estadoIds))));
        if (empty($estadoIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($estadoIds), '?'));
        $sql = "SELECT COUNT(DISTINCT c.id) AS total
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE ce.estado_id IN ($placeholders)
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('i', count($estadoIds)), $estadoIds);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();
        return (int)($fila['total'] ?? 0);
    }

    private function consultaBase()
    {
        $contactoSelect = ",
                    NULL AS contacto_difusion_preferido,
                    0 AS contacto_difusion_confirmado_whatsapp,
                    0 AS contacto_difusion_autorizado_whatsapp,
                    0 AS tiene_whatsapp_confirmado_contacto,
                    0 AS tiene_whatsapp_autorizado_contacto,
                    '' AS contactos_difusion_busqueda,
                    NULL AS contacto_difusion_etiqueta";
        $contactoJoin = "";

        if ($this->contactosDisponibles()) {
            $consentimientoDisponible =
                $this->consentimientoWhatsappDisponible();

            $contactoAutorizadoSelect = $consentimientoDisponible
                ? ",
                    COALESCE(preferido.autorizado_whatsapp, 0) AS contacto_difusion_autorizado_whatsapp,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM aliados_contactos contacto_autorizado
                            WHERE contacto_autorizado.seguimiento_id = s.id
                              AND contacto_autorizado.activo = 1
                              AND contacto_autorizado.confirmado_whatsapp = 1
                              AND contacto_autorizado.autorizado_whatsapp = 1
                        )
                        THEN 1
                        ELSE 0
                    END AS tiene_whatsapp_autorizado_contacto"
                : ",
                    0 AS contacto_difusion_autorizado_whatsapp,
                    0 AS tiene_whatsapp_autorizado_contacto";

            $contactoSelect = ",
                    preferido.numero AS contacto_difusion_preferido,
                    COALESCE(preferido.confirmado_whatsapp, 0) AS contacto_difusion_confirmado_whatsapp" .
                    $contactoAutorizadoSelect . ",
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM aliados_contactos contacto_whatsapp
                            WHERE contacto_whatsapp.seguimiento_id = s.id
                              AND contacto_whatsapp.activo = 1
                              AND contacto_whatsapp.confirmado_whatsapp = 1
                        )
                        THEN 1
                        ELSE 0
                    END AS tiene_whatsapp_confirmado_contacto,
                    COALESCE((
                        SELECT GROUP_CONCAT(contacto_busqueda.numero SEPARATOR ' ')
                        FROM aliados_contactos contacto_busqueda
                        WHERE contacto_busqueda.seguimiento_id = s.id
                          AND contacto_busqueda.activo = 1
                    ), '') AS contactos_difusion_busqueda,
                    preferido.etiqueta AS contacto_difusion_etiqueta";
            $contactoJoin = "
                LEFT JOIN aliados_contactos preferido
                    ON preferido.id = (
                        SELECT contacto_preferido.id
                        FROM aliados_contactos contacto_preferido
                        WHERE contacto_preferido.seguimiento_id = s.id
                          AND contacto_preferido.activo = 1
                          AND contacto_preferido.preferido_difusion = 1
                        ORDER BY contacto_preferido.updated_at DESC, contacto_preferido.id DESC
                        LIMIT 1
                    )";
        }

        return "SELECT
                    s.id AS seguimiento_id,
                    s.estado_id,
                    e.nombre AS estado_nombre,
                    s.municipio_id,
                    COALESCE(m.nombre, '') AS municipio_nombre,
                    s.nombre_entidad,
                    s.tipo_entidad,
                    s.contacto_nombre,
                    s.contacto_cargo,
                    s.telefono_fuente,
                    s.telefono_verificado,
                    s.whatsapp_verificado,
                    COALESCE(
                        NULLIF(TRIM(s.correo_verificado), ''),
                        NULLIF(TRIM(s.correo_fuente), '')
                    ) AS correo_contacto,
                    COALESCE(
                        NULLIF(TRIM(s.whatsapp_verificado), ''),
                        ''
                    ) AS whatsapp_contacto" .
                    $contactoSelect . ",
                    s.analista_id,
                    CONCAT_WS(' ', analista.nombre, analista.apellidos) AS analista_nombre,
                    p.convenio_fecha,
                    p.convenio_notas,
                    p.convenio_formalizado_at,
                    aa.id AS aliado_asignacion_id,
                    aa.cuenta_clave_usuario_id,
                    CONCAT_WS(' ', cuenta.nombre, cuenta.apellidos) AS cuenta_clave_nombre,
                    ultimo.id AS ultimo_envio_id,
                    ultimo.convocatoria_titulo AS ultima_convocatoria_titulo,
                    ultimo.canal AS ultimo_envio_canal,
                    ultimo.enviado_at AS ultimo_envio_at
                FROM seguimientos_vinculacion s
                INNER JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                INNER JOIN estados e
                    ON e.id = s.estado_id
                LEFT JOIN municipios m
                    ON m.id = s.municipio_id
                INNER JOIN usuarios analista
                    ON analista.id = s.analista_id
                LEFT JOIN aliados_asignaciones aa
                    ON aa.seguimiento_id = s.id
                LEFT JOIN usuarios cuenta
                    ON cuenta.id = aa.cuenta_clave_usuario_id" .
                    $contactoJoin . "
                LEFT JOIN aliados_convocatorias_envios ultimo
                    ON ultimo.id = (
                        SELECT envio_reciente.id
                        FROM aliados_convocatorias_envios envio_reciente
                        WHERE envio_reciente.seguimiento_id = s.id
                          AND envio_reciente.estado_envio = 'ENVIADO'
                        ORDER BY envio_reciente.enviado_at DESC, envio_reciente.id DESC
                        LIMIT 1
                    )";
    }

    private function normalizarNumero($numero)
    {
        $digitos = preg_replace('/[^0-9]+/', '', (string)$numero);

        if (
            strlen($digitos) === 13 &&
            strpos($digitos, '521') === 0
        ) {
            $digitos = '52' . substr($digitos, 3);
        }

        return $digitos;
    }

    private function etiquetaOrigenContacto($origen)
    {
        $etiquetas = [
            'CUENTA_CLAVE' => 'Agregado por Cuenta Clave',
            'WHATSAPP_VERIFICADO' => 'WhatsApp verificado',
            'TELEFONO_VERIFICADO' => 'Teléfono verificado',
            'TELEFONO_FUENTE' => 'Teléfono de origen'
        ];

        $origen = strtoupper(trim((string)$origen));
        return $etiquetas[$origen] ?? 'Contacto de difusión';
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        if ($tabla === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $tabla . "'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function columnaExiste($tabla, $columna)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        $columna = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$columna);

        if ($tabla === '' || $columna === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM `" . $tabla . "` LIKE '" . $columna . "'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function vincularParametros($stmt, $tipos, $parametros)
    {
        if ($tipos === '') {
            return;
        }

        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
    }

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
