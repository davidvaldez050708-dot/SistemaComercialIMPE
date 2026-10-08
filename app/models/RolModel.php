<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../helpers/AdminRolePolicy.php';

class RolModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function inicializarPermisosSistema()
    {
        $permisos = $this->obtenerCatalogoInicialPermisos();
        $permisosTerritorioNuevos =
            !$this->existePermisoPorCodigo('territorios.ver') ||
            !$this->existePermisoPorCodigo('territorios.asignar');
        $permisosDataTerritorialNuevos =
            !$this->existePermisoPorCodigo('data_territorial.ver') ||
            !$this->existePermisoPorCodigo('data_territorial.actualizar_oficial');
        /*
         * La inicialización solo detecta permisos nuevos del catálogo.
         * Nunca vuelve a conceder una relación rol-permiso que un Administrador
         * haya revocado expresamente desde Roles y permisos.
         */
        $permisosSeguimientoVinculacionNuevos =
            !$this->existePermisoPorCodigo('seguimientos_vinculacion.operar_propios') ||
            !$this->existePermisoPorCodigo('seguimientos_vinculacion.supervisar') ||
            !$this->existePermisoPorCodigo('seguimientos_vinculacion.comentar');
        $permisoReporteConvocatoriasNuevo =
            !$this->existePermisoPorCodigo('reportes.convocatorias');
        $permisosAliadosNuevos =
            !$this->existePermisoPorCodigo('aliados.ver') ||
            !$this->existePermisoPorCodigo('aliados.ver_historial') ||
            !$this->existePermisoPorCodigo('aliados.gestionar_contactos') ||
            !$this->existePermisoPorCodigo('aliados.compartir_correo');
        $permisosWhatsappNuevos =
            !$this->existePermisoPorCodigo('whatsapp.ver') ||
            !$this->existePermisoPorCodigo('whatsapp.enviar') ||
            !$this->existePermisoPorCodigo('whatsapp.gestionar_conversaciones') ||
            !$this->existePermisoPorCodigo('whatsapp.gestionar_cuentas');
        $permisosTelefoniaNuevos =
            !$this->existePermisoPorCodigo('telefonia.usar') ||
            !$this->existePermisoPorCodigo('telefonia.salientes') ||
            !$this->existePermisoPorCodigo('telefonia.recibir') ||
            !$this->existePermisoPorCodigo('telefonia.transferir');

        $sql = "INSERT INTO permisos (
                    modulo,
                    codigo,
                    nombre,
                    descripcion,
                    estado
                ) VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    modulo = VALUES(modulo),
                    nombre = VALUES(nombre),
                    descripcion = VALUES(descripcion),
                    estado = 1";

        $stmt = $this->connection->prepare($sql);

        foreach ($permisos as $permiso) {
            $stmt->bind_param(
                "ssss",
                $permiso['modulo'],
                $permiso['codigo'],
                $permiso['nombre'],
                $permiso['descripcion']
            );

            $stmt->execute();
        }

        $sinPermisosActivosSistema =
            !$this->existenRelacionesPermisosActivas();

        /*
         * La plantilla base solo se usa al crear una instalación sin relaciones.
         * Encontrar permisos legacy nunca debe reconstruir roles existentes,
         * porque eso pisaría decisiones tomadas por el Administrador.
         */
        if ($sinPermisosActivosSistema) {
            $this->sincronizarPermisosBasePorRol();
        }

        $this->desactivarPermisosGenericosSeguimientos();
        $this->desactivarPermisosTerritorioObsoletos();
        $this->desactivarPermisosSinModuloActivo();

        if ($permisosTerritorioNuevos) {
            $this->asignarPermisosInicialesTerritorios();
        }

        if ($permisosDataTerritorialNuevos) {
            $this->asignarPermisosInicialesDataTerritorial();
        }

        if ($permisosSeguimientoVinculacionNuevos) {
            $this->asignarPermisosInicialesSeguimientoVinculacion();
        }

        if ($permisoReporteConvocatoriasNuevo) {
            $this->asignarPermisosInicialesReporteConvocatorias();
        }

        if ($permisosAliadosNuevos) {
            $this->asignarPermisosInicialesAliados();
        }

        if ($permisosWhatsappNuevos) {
            $this->asignarPermisosInicialesWhatsapp();
        }

        if ($permisosTelefoniaNuevos) {
            $this->asignarPermisosInicialesTelefonia();
        }

        $this->asegurarPermisosAdministrador();
    }

    public function obtenerRoles()
    {
        $sql = "SELECT
                    id,
                    nombre,
                    descripcion,
                    estado,
                    created_at,
                    updated_at
                FROM roles
                ORDER BY id";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function buscarRolPorId($id)
    {
        $sql = "SELECT
                    id,
                    nombre,
                    descripcion,
                    estado,
                    created_at,
                    updated_at
                FROM roles
                WHERE id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function existeNombreRol($nombre, $idExcluir = null)
    {
        $sql = "SELECT id
                FROM roles
                WHERE nombre = ?";

        $parametros = [$nombre];
        $tipos = 's';

        if ($idExcluir !== null) {
            $sql .= " AND id <> ?";
            $parametros[] = (int)$idExcluir;
            $tipos .= 'i';
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function crearRol($datos)
    {
        $sql = "INSERT INTO roles (
                    nombre,
                    descripcion,
                    estado
                ) VALUES (?, ?, ?)";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ssi",
            $datos['nombre'],
            $datos['descripcion'],
            $datos['estado']
        );

        if (!$stmt->execute()) {
            return false;
        }

        return $this->connection->insert_id;
    }

    public function actualizarRol($id, $datos)
    {
        $sql = "UPDATE roles
                SET nombre = ?,
                    descripcion = ?,
                    estado = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ssii",
            $datos['nombre'],
            $datos['descripcion'],
            $datos['estado'],
            $id
        );

        return $stmt->execute();
    }

    public function cambiarEstadoRol($id, $estado)
    {
        $sql = "UPDATE roles
                SET estado = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("ii", $estado, $id);

        return $stmt->execute();
    }

    public function obtenerPermisos()
    {
        $sql = "SELECT
                    id,
                    modulo,
                    codigo,
                    nombre,
                    descripcion,
                    estado
                FROM permisos
                WHERE estado = 1
                ORDER BY modulo, id";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerPermisosAgrupados()
    {
        $permisos = $this->obtenerPermisos();
        $agrupados = [];

        foreach ($permisos as $permiso) {
            $modulo = $permiso['modulo'];

            if (!isset($agrupados[$modulo])) {
                $agrupados[$modulo] = [];
            }

            $agrupados[$modulo][] = $permiso;
        }

        return $agrupados;
    }

    public function obtenerPermisosPorRol($rolId)
    {
        $sql = "SELECT permisos.id, permisos.codigo
                FROM rol_permisos
                INNER JOIN permisos
                    ON permisos.id = rol_permisos.permiso_id
                WHERE rol_permisos.rol_id = ?
                    AND permisos.estado = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $rolId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $permisos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $permisos[(int)$fila['id']] = $fila['codigo'];
        }

        return $permisos;
    }

    public function obtenerCodigosPermisosPorRol($rolId)
    {
        $sql = "SELECT permisos.codigo
                FROM rol_permisos
                INNER JOIN permisos
                    ON permisos.id = rol_permisos.permiso_id
                WHERE rol_permisos.rol_id = ?
                    AND permisos.estado = 1
                ORDER BY permisos.codigo";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $rolId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $codigos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $codigos[] = $fila['codigo'];
        }

        return $codigos;
    }

    public function actualizarPermisosRol($rolId, $permisosIds, $actorUsuarioId = 0)
    {
        if ((int)$rolId === 1) {
            /*
             * Administrador es un rol protegido. Sus permisos se mantienen desde
             * el catálogo del sistema y no pueden editarse desde la interfaz.
             */
            $this->asegurarPermisosAdministrador();
            return true;
        }

        $permisosIds = array_values(array_unique(array_map('intval', $permisosIds)));
        $permisosIds = $this->normalizarDependenciasPermisos($permisosIds);
        $permisosIds = $this->retirarPermisosSoloAdministrador(
            (int)$rolId,
            $permisosIds
        );

        if (!$this->validarPermisosExistentes($permisosIds)) {
            return false;
        }

        $permisosAnteriores = $this->obtenerIdsPermisosRol($rolId);

        $this->connection->begin_transaction();

        try {
            $sqlEliminar = "DELETE FROM rol_permisos
                            WHERE rol_id = ?";
            $stmtEliminar = $this->connection->prepare($sqlEliminar);
            $stmtEliminar->bind_param("i", $rolId);

            if (!$stmtEliminar->execute()) {
                throw new RuntimeException('No fue posible limpiar los permisos anteriores.');
            }

            if (!empty($permisosIds)) {
                $sqlInsertar = "INSERT INTO rol_permisos (
                                    rol_id,
                                    permiso_id
                                ) VALUES (?, ?)";
                $stmtInsertar = $this->connection->prepare($sqlInsertar);

                foreach ($permisosIds as $permisoId) {
                    $stmtInsertar->bind_param("ii", $rolId, $permisoId);

                    if (!$stmtInsertar->execute()) {
                        throw new RuntimeException('No fue posible asignar uno de los permisos.');
                    }
                }
            }

            $this->registrarAuditoriaPermisos(
                (int)$rolId,
                (int)$actorUsuarioId,
                $permisosAnteriores,
                $permisosIds
            );

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function registrarAuditoriaEventoRol($rolId, $actorUsuarioId, $accion)
    {
        $tabla = $this->connection->query(
            "SHOW TABLES LIKE 'auditoria_roles_permisos'"
        );

        if (!$tabla || $tabla->num_rows === 0) {
            return true;
        }

        $rolId = (int)$rolId;
        $actorUsuarioId = (int)$actorUsuarioId;
        $accion = strtoupper(trim((string)$accion));

        if ($rolId <= 0 || $accion === '') {
            return false;
        }

        $sql = "INSERT INTO auditoria_roles_permisos (
                    rol_id,
                    actor_usuario_id,
                    accion,
                    created_at
                ) VALUES (?, NULLIF(?, 0), ?, NOW())";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iis', $rolId, $actorUsuarioId, $accion);

        return $stmt->execute();
    }

    public function asegurarPermisosAdministrador()
    {
        $restringidos =
            permisosOperativosRestringidosAdministrador();

        $this->connection->begin_transaction();

        try {
            if (!empty($restringidos)) {
                $marcadores = implode(
                    ',',
                    array_fill(0, count($restringidos), '?')
                );
                $tipos = str_repeat('s', count($restringidos));

                $sqlRetirar =
                    "DELETE rp
                     FROM rol_permisos rp
                     INNER JOIN permisos p
                        ON p.id = rp.permiso_id
                     WHERE rp.rol_id = 1
                       AND p.codigo IN ($marcadores)";

                $stmtRetirar = $this->connection->prepare(
                    $sqlRetirar
                );
                $this->vincularParametros(
                    $stmtRetirar,
                    $tipos,
                    $restringidos
                );
                $stmtRetirar->execute();

                $sqlAsignar =
                    "INSERT IGNORE INTO rol_permisos (
                        rol_id,
                        permiso_id
                     )
                     SELECT 1, p.id
                     FROM permisos p
                     WHERE p.estado = 1
                       AND p.codigo NOT IN ($marcadores)";

                $stmtAsignar = $this->connection->prepare(
                    $sqlAsignar
                );
                $this->vincularParametros(
                    $stmtAsignar,
                    $tipos,
                    $restringidos
                );
                $stmtAsignar->execute();
            } else {
                $this->connection->query(
                    "INSERT IGNORE INTO rol_permisos (
                        rol_id,
                        permiso_id
                     )
                     SELECT 1, p.id
                     FROM permisos p
                     WHERE p.estado = 1"
                );
            }

            $this->connection->commit();
            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log(
                'No fue posible normalizar permisos del Administrador: ' .
                $error->getMessage()
            );
            return false;
        }
    }

    private function sincronizarPermisosBasePorRol()
    {
        $asignaciones = [
            'Coordinador Comercial' => [
                'prospectos.ver_todos',
                'prospectos.editar',
                'prospectos.asignar',
                'seguimientos_comerciales.ver_todos',
                'seguimientos_comerciales.crear',
                'seguimientos_comerciales.editar',
                'whatsapp.ver',
                'whatsapp.enviar',
                'whatsapp.gestionar_conversaciones',
                'reportes.ver',
                'reportes.exportar'
            ],
            'Asesor de Ventas' => [
                'prospectos.ver_propios',
                'prospectos.editar',
                'seguimientos_comerciales.ver_propios',
                'seguimientos_comerciales.crear',
                'seguimientos_comerciales.editar_propios',
                'whatsapp.ver',
                'whatsapp.enviar'
            ],
            'Analista de Datos' => [
                'oficios.ver',
                'oficios.generar',
                'oficios.enviar',
                'reuniones.ver',
                'reuniones.solicitar',
                'seguimientos_vinculacion.ver',
                'seguimientos_vinculacion.crear',
                'seguimientos_vinculacion.operar_propios',
                'convenios.ver',
                'convenios.gestionar',
                'reportes.ver',
                'reportes.exportar',
                'reportes.seguimiento.cartera',
                'reportes.seguimiento.actividad',
                'reportes.seguimiento.institucion',
                'reportes.territorial'
            ],
            'Finanzas' => [
                'pagos.ver',
                'pagos.validar',
                'reportes.ver'
            ],
            'Cuenta Clave' => [
                'oficios.ver',
                'reuniones.ver',
                'reuniones.gestionar',
                'convenios.ver',
                'seguimientos_vinculacion.ver',
                'seguimientos_vinculacion.supervisar',
                'seguimientos_vinculacion.comentar',
                'convocatorias.ver',
                'aliados.ver',
                'aliados.ver_historial',
                'aliados.gestionar_contactos',
                'aliados.compartir_correo',
                'whatsapp.ver',
                'whatsapp.enviar',
                'reportes.ver',
                'reportes.exportar',
                'reportes.seguimiento.cartera',
                'reportes.seguimiento.actividad',
                'reportes.seguimiento.institucion',
                'reportes.territorial'
            ],
            'Marketing' => [
                'convocatorias.ver',
                'convocatorias.crear',
                'convocatorias.editar',
                'convocatorias.gestionar',
                'convocatorias.descargar',
                'convocatorias.cambiar_estado',
                'seguimientos_vinculacion.ver',
                'reportes.ver',
                'reportes.convocatorias',
                'reportes.exportar'
            ]
        ];

        $sqlEliminar = "DELETE rol_permisos
                        FROM rol_permisos
                        INNER JOIN roles
                            ON roles.id = rol_permisos.rol_id
                        INNER JOIN permisos
                            ON permisos.id = rol_permisos.permiso_id
                        WHERE roles.nombre = ?
                            AND permisos.estado = 1";

        $sqlInsertar = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?";

        $stmtEliminar = $this->connection->prepare($sqlEliminar);
        $stmtInsertar = $this->connection->prepare($sqlInsertar);

        $this->connection->begin_transaction();

        try {
            foreach ($asignaciones as $nombreRol => $codigos) {
                $stmtEliminar->bind_param("s", $nombreRol);

                if (!$stmtEliminar->execute()) {
                    throw new Exception('No fue posible preparar permisos del rol.');
                }

                foreach ($codigos as $codigo) {
                    $stmtInsertar->bind_param("ss", $codigo, $nombreRol);

                    if (!$stmtInsertar->execute()) {
                        throw new Exception('No fue posible asignar permisos del rol.');
                    }
                }
            }

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());
        }
    }

    private function existenPermisosGenericosSeguimientosActivos()
    {
        $codigos = [
            'seguimientos.ver',
            'seguimientos.crear',
            'seguimientos.editar'
        ];
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));

        $sql = "SELECT COUNT(*) AS total
                FROM permisos
                WHERE estado = 1
                    AND codigo IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('s', count($codigos)), $codigos);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'] > 0;
    }

    private function existenRelacionesPermisosActivas()
    {
        $sql = "SELECT COUNT(*) AS total
                FROM rol_permisos
                INNER JOIN permisos
                    ON permisos.id = rol_permisos.permiso_id
                WHERE permisos.estado = 1";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return (int)$fila['total'] > 0;
    }

    private function desactivarPermisosGenericosSeguimientos()
    {
        $codigos = [
            'seguimientos.ver',
            'seguimientos.crear',
            'seguimientos.editar'
        ];
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));

        $sql = "UPDATE permisos
                SET estado = 0
                WHERE codigo IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('s', count($codigos)), $codigos);

        return $stmt->execute();
    }

    private function desactivarPermisosTerritorioObsoletos()
    {
        $codigos = [
            'territorios.editar',
            'territorios.actualizar_ficha'
        ];
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));

        $sql = "UPDATE permisos
                SET estado = 0
                WHERE codigo IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('s', count($codigos)), $codigos);

        return $stmt->execute();
    }

    private function desactivarPermisosSinModuloActivo()
    {
        /*
         * Estos permisos pertenecen a módulos previstos o legacy que todavía
         * no tienen una superficie funcional completa. Se mantienen en el
         * catálogo histórico, pero no se muestran ni autorizan hasta que el
         * módulo correspondiente exista.
         */
        $codigos = [
            'prospectos.ver_todos',
            'prospectos.ver_propios',
            'prospectos.editar',
            'prospectos.asignar',
            'seguimientos_comerciales.ver_todos',
            'seguimientos_comerciales.ver_propios',
            'seguimientos_comerciales.crear',
            'seguimientos_comerciales.editar',
            'seguimientos_comerciales.editar_propios',
            'seguimientos_vinculacion.editar',
            'pagos.ver',
            'pagos.validar',
            'organizaciones.ver',
            'organizaciones.crear',
            'organizaciones.editar',
            'organizaciones.validar',
            'difusion.ver',
            'difusion.crear',
            'difusion.enviar',
            'difusion.gestionar',
            'respaldos.generar',
            'respaldos.restaurar',
            'configuracion.ver',
            'configuracion.editar'
        ];
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));

        $sql = "UPDATE permisos
                SET estado = 0
                WHERE codigo IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('s', count($codigos)), $codigos);

        return $stmt->execute();
    }

    private function existePermisoPorCodigo($codigo)
    {
        $sql = "SELECT id
                FROM permisos
                WHERE codigo = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("s", $codigo);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function rolTienePermisoActivo($nombreRol, $codigoPermiso)
    {
        $sql = "SELECT rol_permisos.rol_id
                FROM rol_permisos
                INNER JOIN roles
                    ON roles.id = rol_permisos.rol_id
                INNER JOIN permisos
                    ON permisos.id = rol_permisos.permiso_id
                WHERE roles.nombre = ?
                    AND roles.estado = 1
                    AND permisos.codigo = ?
                    AND permisos.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("ss", $nombreRol, $codigoPermiso);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function asignarPermisosInicialesTerritorios()
    {
        $asignaciones = [
            'Analista de Datos' => [
                'territorios.ver'
            ],
            'Cuenta Clave' => [
                'territorios.ver'
            ]
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?";

        $stmt = $this->connection->prepare($sql);

        foreach ($asignaciones as $nombreRol => $codigos) {
            foreach ($codigos as $codigo) {
                $stmt->bind_param("ss", $codigo, $nombreRol);
                $stmt->execute();
            }
        }
    }

    private function asignarPermisosInicialesDataTerritorial()
    {
        $asignaciones = [
            'Analista de Datos' => [
                'data_territorial.ver',
                'data_territorial.editar',
                'data_territorial.gestionar_secretarias',
                'data_territorial.gestionar_municipios',
                'data_territorial.gestionar_indicadores',
                'data_territorial.actualizar_oficial'
            ],
            'Cuenta Clave' => [
                'data_territorial.ver'
            ]
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?";

        $stmt = $this->connection->prepare($sql);

        foreach ($asignaciones as $nombreRol => $codigos) {
            foreach ($codigos as $codigo) {
                $stmt->bind_param("ss", $codigo, $nombreRol);
                $stmt->execute();
            }
        }
    }

    private function asignarPermisosInicialesSeguimientoVinculacion()
    {
        $asignaciones = [
            'Analista de Datos' => [
                'seguimientos_vinculacion.ver',
                'seguimientos_vinculacion.crear',
                'seguimientos_vinculacion.operar_propios'
            ],
            'Cuenta Clave' => [
                'seguimientos_vinculacion.ver',
                'seguimientos_vinculacion.supervisar',
                'seguimientos_vinculacion.comentar'
            ]
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?";

        $stmt = $this->connection->prepare($sql);

        foreach ($asignaciones as $nombreRol => $codigos) {
            foreach ($codigos as $codigo) {
                $stmt->bind_param("ss", $codigo, $nombreRol);
                $stmt->execute();
            }
        }
    }

    private function asignarPermisosInicialesAliados()
    {
        $nombreRol = 'Cuenta Clave';
        $codigos = [
            'aliados.ver',
            'aliados.ver_historial',
            'aliados.gestionar_contactos',
            'aliados.compartir_correo',
            'convocatorias.ver'
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?
                  AND permisos.estado = 1";

        $stmt = $this->connection->prepare($sql);

        foreach ($codigos as $codigo) {
            $stmt->bind_param("ss", $codigo, $nombreRol);
            $stmt->execute();
        }
    }

    private function asignarPermisosInicialesWhatsapp()
    {
        $asignaciones = [
            'Cuenta Clave' => [
                'whatsapp.ver',
                'whatsapp.enviar'
            ],
            'Asesor de Ventas' => [
                'whatsapp.ver',
                'whatsapp.enviar'
            ],
            'Coordinador Comercial' => [
                'whatsapp.ver',
                'whatsapp.enviar',
                'whatsapp.gestionar_conversaciones'
            ]
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?
                  AND permisos.estado = 1";

        $stmt = $this->connection->prepare($sql);

        foreach ($asignaciones as $nombreRol => $codigos) {
            foreach ($codigos as $codigo) {
                $stmt->bind_param("ss", $codigo, $nombreRol);
                $stmt->execute();
            }
        }
    }

    private function asignarPermisosInicialesTelefonia()
    {
        $asignaciones = [
            'Analista de Datos' => [
                'telefonia.usar',
                'telefonia.salientes',
                'telefonia.recibir'
            ],
            'Asesor de Ventas' => [
                'telefonia.usar',
                'telefonia.salientes',
                'telefonia.recibir'
            ],
            'Marketing' => [
                'telefonia.usar',
                'telefonia.recibir',
                'telefonia.transferir'
            ]
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?
                  AND permisos.estado = 1";

        $stmt = $this->connection->prepare($sql);

        foreach ($asignaciones as $nombreRol => $codigos) {
            foreach ($codigos as $codigo) {
                $stmt->bind_param("ss", $codigo, $nombreRol);
                $stmt->execute();
            }
        }
    }

    private function asignarPermisosInicialesReporteConvocatorias()
    {
        $nombreRol = 'Marketing';
        $codigos = [
            'convocatorias.ver',
            'reportes.ver',
            'reportes.convocatorias',
            'reportes.exportar'
        ];

        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT roles.id, permisos.id
                FROM roles
                INNER JOIN permisos
                    ON permisos.codigo = ?
                WHERE roles.nombre = ?
                  AND permisos.estado = 1";

        $stmt = $this->connection->prepare($sql);

        foreach ($codigos as $codigo) {
            $stmt->bind_param("ss", $codigo, $nombreRol);
            $stmt->execute();
        }
    }

    private function normalizarDependenciasPermisos($permisosIds)
    {
        if (empty($permisosIds)) {
            return [];
        }

        $dependencias = [
            'usuarios.crear' => 'usuarios.ver',
            'usuarios.editar' => 'usuarios.ver',
            'usuarios.cambiar_estado' => 'usuarios.ver',
            'roles.crear' => 'roles.ver',
            'roles.editar' => 'roles.ver',
            'roles.cambiar_estado' => 'roles.ver',
            'roles.asignar_permisos' => 'roles.ver',
            'territorios.asignar' => 'territorios.ver',
            'data_territorial.editar' => 'data_territorial.ver',
            'data_territorial.actualizar_oficial' => 'data_territorial.ver',
            'data_territorial.gestionar_secretarias' => 'data_territorial.ver',
            'data_territorial.gestionar_municipios' => 'data_territorial.ver',
            'data_territorial.gestionar_indicadores' => 'data_territorial.ver',
            'seguimientos_vinculacion.crear' => 'seguimientos_vinculacion.ver',
            'seguimientos_vinculacion.editar' => 'seguimientos_vinculacion.ver',
            'seguimientos_vinculacion.operar_propios' => 'seguimientos_vinculacion.ver',
            'seguimientos_vinculacion.supervisar' => 'seguimientos_vinculacion.ver',
            'seguimientos_vinculacion.comentar' => 'seguimientos_vinculacion.ver',
            'oficios.generar' => 'oficios.ver',
            'oficios.enviar' => 'oficios.ver',
            'reuniones.solicitar' => 'reuniones.ver',
            'reuniones.gestionar' => 'reuniones.ver',
            'convocatorias.crear' => 'convocatorias.ver',
            'convocatorias.editar' => 'convocatorias.ver',
            'convocatorias.gestionar' => 'convocatorias.ver',
            'convocatorias.descargar' => 'convocatorias.ver',
            'convocatorias.cambiar_estado' => 'convocatorias.ver',
            'aliados.ver_historial' => 'aliados.ver',
            'aliados.gestionar_contactos' => 'aliados.ver',
            'aliados.compartir_correo' => 'aliados.ver',
            'whatsapp.enviar' => 'whatsapp.ver',
            'whatsapp.gestionar_conversaciones' => 'whatsapp.ver',
            'whatsapp.gestionar_cuentas' => 'whatsapp.ver',
            'telefonia.salientes' => 'telefonia.usar',
            'telefonia.recibir' => 'telefonia.usar',
            'telefonia.transferir' => 'telefonia.recibir',
            'telefonia.configurar' => 'telefonia.usar',
            'reportes.exportar' => 'reportes.ver',
            'reportes.seguimiento.cartera' => 'reportes.ver',
            'reportes.seguimiento.actividad' => 'reportes.ver',
            'reportes.seguimiento.institucion' => 'reportes.ver',
            'reportes.territorial' => 'reportes.ver',
            'reportes.convocatorias' => 'reportes.ver',
            'reportes.usuarios' => 'reportes.ver'
        ];
        $dependenciasAdicionales = [
            'reportes.seguimiento.cartera' => [
                'seguimientos_vinculacion.ver'
            ],
            'reportes.seguimiento.actividad' => [
                'seguimientos_vinculacion.ver'
            ],
            'reportes.seguimiento.institucion' => [
                'seguimientos_vinculacion.ver'
            ],
            'reportes.territorial' => [
                'data_territorial.ver'
            ],
            'aliados.compartir_correo' => [
                'convocatorias.ver'
            ]
        ];

        $resultado = $this->connection->query(
            "SELECT id, codigo
             FROM permisos
             WHERE estado = 1"
        );

        $idPorCodigo = [];
        $codigoPorId = [];

        while ($fila = $resultado->fetch_assoc()) {
            $id = (int)$fila['id'];
            $codigo = (string)$fila['codigo'];
            $idPorCodigo[$codigo] = $id;
            $codigoPorId[$id] = $codigo;
        }

        $seleccionados = array_fill_keys(array_map('intval', $permisosIds), true);

        /*
         * El Reporte de Convocatorias forma parte del Centro de Reportes.
         * Todo rol que conserve reportes.ver debe conservar también esta familia,
         * sin que eso le conceda acceso operativo al módulo de Convocatorias.
         */
        if (
            isset($idPorCodigo['reportes.ver']) &&
            isset($idPorCodigo['reportes.convocatorias']) &&
            isset($seleccionados[(int)$idPorCodigo['reportes.ver']])
        ) {
            $seleccionados[(int)$idPorCodigo['reportes.convocatorias']] = true;
        }

        foreach (array_keys($seleccionados) as $permisoId) {
            $codigo = $codigoPorId[(int)$permisoId] ?? '';

            foreach ($dependenciasAdicionales[$codigo] ?? [] as $codigoPadre) {
                if (isset($idPorCodigo[$codigoPadre])) {
                    $seleccionados[(int)$idPorCodigo[$codigoPadre]] = true;
                }
            }
        }

        if (
            isset($idPorCodigo['convocatorias.gestionar']) &&
            isset($seleccionados[$idPorCodigo['convocatorias.gestionar']])
        ) {
            foreach (
                [
                    'convocatorias.ver',
                    'convocatorias.crear',
                    'convocatorias.editar',
                    'convocatorias.descargar',
                    'convocatorias.cambiar_estado'
                ] as $codigoGestion
            ) {
                if (isset($idPorCodigo[$codigoGestion])) {
                    $seleccionados[(int)$idPorCodigo[$codigoGestion]] = true;
                }
            }
        }

        $cambio = true;

        while ($cambio) {
            $cambio = false;

            foreach (array_keys($seleccionados) as $permisoId) {
                $codigo = $codigoPorId[(int)$permisoId] ?? '';
                $padre = $dependencias[$codigo] ?? '';

                if ($padre === '' || !isset($idPorCodigo[$padre])) {
                    continue;
                }

                $padreId = (int)$idPorCodigo[$padre];

                if (!isset($seleccionados[$padreId])) {
                    $seleccionados[$padreId] = true;
                    $cambio = true;
                }
            }
        }

        $ids = array_map('intval', array_keys($seleccionados));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function obtenerIdsPermisosRol($rolId)
    {
        $sql = "SELECT permiso_id
                FROM rol_permisos
                WHERE rol_id = ?
                ORDER BY permiso_id";

        $stmt = $this->connection->prepare($sql);
        $rolId = (int)$rolId;
        $stmt->bind_param('i', $rolId);
        $stmt->execute();

        $ids = [];
        $resultado = $stmt->get_result();

        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['permiso_id'];
        }

        return $ids;
    }

    private function registrarAuditoriaPermisos(
        $rolId,
        $actorUsuarioId,
        $anteriores,
        $nuevos
    ) {
        $tabla = $this->connection->query(
            "SHOW TABLES LIKE 'auditoria_roles_permisos'"
        );

        if (!$tabla || $tabla->num_rows === 0) {
            return;
        }

        $anteriores = array_values(array_unique(array_map('intval', $anteriores)));
        $nuevos = array_values(array_unique(array_map('intval', $nuevos)));
        sort($anteriores, SORT_NUMERIC);
        sort($nuevos, SORT_NUMERIC);

        if ($anteriores === $nuevos) {
            return;
        }

        $agregados = array_values(array_diff($nuevos, $anteriores));
        $removidos = array_values(array_diff($anteriores, $nuevos));

        $sql = "INSERT INTO auditoria_roles_permisos (
                    rol_id,
                    actor_usuario_id,
                    accion,
                    permisos_anteriores,
                    permisos_nuevos,
                    permisos_agregados,
                    permisos_removidos,
                    created_at
                ) VALUES (?, NULLIF(?, 0), 'ACTUALIZAR_PERMISOS', ?, ?, ?, ?, NOW())";

        $stmt = $this->connection->prepare($sql);
        $anterioresJson = json_encode($anteriores, JSON_UNESCAPED_UNICODE);
        $nuevosJson = json_encode($nuevos, JSON_UNESCAPED_UNICODE);
        $agregadosJson = json_encode($agregados, JSON_UNESCAPED_UNICODE);
        $removidosJson = json_encode($removidos, JSON_UNESCAPED_UNICODE);
        $rolId = (int)$rolId;
        $actorUsuarioId = (int)$actorUsuarioId;

        $stmt->bind_param(
            'iissss',
            $rolId,
            $actorUsuarioId,
            $anterioresJson,
            $nuevosJson,
            $agregadosJson,
            $removidosJson
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('No fue posible registrar la auditoría de permisos.');
        }
    }

    private function retirarPermisosSoloAdministrador($rolId, $permisosIds)
    {
        if ((int)$rolId === 1 || empty($permisosIds)) {
            return $permisosIds;
        }

        $resultado = $this->connection->query(
            "SELECT id
             FROM permisos
             WHERE codigo IN (
                'reportes.usuarios',
                'whatsapp.gestionar_cuentas',
                'telefonia.configurar'
             )"
        );

        if (!$resultado || $resultado->num_rows === 0) {
            return $permisosIds;
        }

        $permisosSoloAdministrador = [];

        while ($permiso = $resultado->fetch_assoc()) {
            $permisosSoloAdministrador[(int)$permiso['id']] = true;
        }

        return array_values(array_filter(
            $permisosIds,
            static function ($id) use ($permisosSoloAdministrador) {
                return !isset(
                    $permisosSoloAdministrador[(int)$id]
                );
            }
        ));
    }

    private function validarPermisosExistentes($permisosIds)
    {
        if (empty($permisosIds)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($permisosIds), '?'));
        $tipos = str_repeat('i', count($permisosIds));

        $sql = "SELECT COUNT(*) AS total
                FROM permisos
                WHERE estado = 1
                    AND id IN ($placeholders)";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $permisosIds);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'] === count($permisosIds);
    }

    private function obtenerCatalogoInicialPermisos()
    {
        return [
            ['modulo' => 'Usuarios', 'codigo' => 'usuarios.ver', 'nombre' => 'Ver usuarios', 'descripcion' => 'Consultar usuarios registrados.'],
            ['modulo' => 'Usuarios', 'codigo' => 'usuarios.crear', 'nombre' => 'Crear usuarios', 'descripcion' => 'Registrar nuevas cuentas de usuario.'],
            ['modulo' => 'Usuarios', 'codigo' => 'usuarios.editar', 'nombre' => 'Editar usuarios', 'descripcion' => 'Actualizar datos de usuario.'],
            ['modulo' => 'Usuarios', 'codigo' => 'usuarios.cambiar_estado', 'nombre' => 'Activar / desactivar usuarios', 'descripcion' => 'Modificar el estado de una cuenta.'],
            ['modulo' => 'Territorios', 'codigo' => 'territorios.ver', 'nombre' => 'Ver territorios', 'descripcion' => 'Consultar estados y responsables territoriales.'],
            ['modulo' => 'Territorios', 'codigo' => 'territorios.asignar', 'nombre' => 'Asignar responsables', 'descripcion' => 'Gestionar responsables territoriales por estado.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.ver', 'nombre' => 'Consultar información territorial', 'descripcion' => 'Consultar la información territorial de los estados asignados.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.editar', 'nombre' => 'Editar información territorial', 'descripcion' => 'Actualizar información territorial de los estados asignados.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.actualizar_oficial', 'nombre' => 'Actualizar información oficial', 'descripcion' => 'Actualizar las fuentes oficiales de información territorial para los Estados registrados.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.gestionar_secretarias', 'nombre' => 'Gestionar secretarías', 'descripcion' => 'Registrar y actualizar secretarías estatales.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.gestionar_municipios', 'nombre' => 'Gestionar municipios', 'descripcion' => 'Registrar y actualizar información municipal.'],
            ['modulo' => 'Información territorial', 'codigo' => 'data_territorial.gestionar_indicadores', 'nombre' => 'Gestionar indicadores educativos', 'descripcion' => 'Registrar y actualizar indicadores educativos territoriales.'],
            ['modulo' => 'Roles y permisos', 'codigo' => 'roles.ver', 'nombre' => 'Ver roles', 'descripcion' => 'Consultar roles y permisos.'],
            ['modulo' => 'Roles y permisos', 'codigo' => 'roles.crear', 'nombre' => 'Crear roles', 'descripcion' => 'Registrar nuevos perfiles de acceso.'],
            ['modulo' => 'Roles y permisos', 'codigo' => 'roles.editar', 'nombre' => 'Editar roles', 'descripcion' => 'Actualizar datos de roles.'],
            ['modulo' => 'Roles y permisos', 'codigo' => 'roles.cambiar_estado', 'nombre' => 'Activar / desactivar roles', 'descripcion' => 'Modificar estado de roles.'],
            ['modulo' => 'Roles y permisos', 'codigo' => 'roles.asignar_permisos', 'nombre' => 'Asignar permisos', 'descripcion' => 'Administrar permisos por rol.'],
            ['modulo' => 'Prospectos', 'codigo' => 'prospectos.ver_todos', 'nombre' => 'Ver todos los prospectos', 'descripcion' => 'Consultar prospectos de todos los equipos.'],
            ['modulo' => 'Prospectos', 'codigo' => 'prospectos.ver_propios', 'nombre' => 'Ver prospectos propios', 'descripcion' => 'Consultar prospectos asignados al usuario.'],
            ['modulo' => 'Prospectos', 'codigo' => 'prospectos.editar', 'nombre' => 'Editar prospectos', 'descripcion' => 'Actualizar información de prospectos.'],
            ['modulo' => 'Prospectos', 'codigo' => 'prospectos.asignar', 'nombre' => 'Asignar prospectos', 'descripcion' => 'Asignar prospectos a usuarios o equipos.'],
            ['modulo' => 'Seguimientos comerciales', 'codigo' => 'seguimientos_comerciales.ver_todos', 'nombre' => 'Ver todos los seguimientos comerciales', 'descripcion' => 'Consultar seguimientos comerciales de todos los equipos.'],
            ['modulo' => 'Seguimientos comerciales', 'codigo' => 'seguimientos_comerciales.ver_propios', 'nombre' => 'Ver seguimientos comerciales propios', 'descripcion' => 'Consultar seguimientos comerciales asignados al usuario.'],
            ['modulo' => 'Seguimientos comerciales', 'codigo' => 'seguimientos_comerciales.crear', 'nombre' => 'Crear seguimientos comerciales', 'descripcion' => 'Registrar nuevos seguimientos comerciales.'],
            ['modulo' => 'Seguimientos comerciales', 'codigo' => 'seguimientos_comerciales.editar', 'nombre' => 'Editar seguimientos comerciales', 'descripcion' => 'Actualizar cualquier seguimiento comercial.'],
            ['modulo' => 'Seguimientos comerciales', 'codigo' => 'seguimientos_comerciales.editar_propios', 'nombre' => 'Editar seguimientos comerciales propios', 'descripcion' => 'Actualizar solo seguimientos comerciales asignados al usuario.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.ver', 'nombre' => 'Ver seguimientos de vinculación', 'descripcion' => 'Consultar seguimientos de vinculación institucional.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.crear', 'nombre' => 'Crear seguimientos de vinculación', 'descripcion' => 'Registrar seguimientos de vinculación institucional.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.editar', 'nombre' => 'Editar seguimientos de vinculación', 'descripcion' => 'Actualizar seguimientos de vinculación institucional.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.operar_propios', 'nombre' => 'Operar seguimientos propios', 'descripcion' => 'Ejecutar la ruta operativa únicamente en seguimientos donde el usuario es el Analista responsable.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.supervisar', 'nombre' => 'Supervisar seguimientos de vinculación', 'descripcion' => 'Revisar los seguimientos de los Analistas asociados.'],
            ['modulo' => 'Seguimientos de vinculación', 'codigo' => 'seguimientos_vinculacion.comentar', 'nombre' => 'Comentar seguimientos de vinculación', 'descripcion' => 'Agregar observaciones internas para los Analistas asociados.'],
            ['modulo' => 'Finanzas', 'codigo' => 'pagos.ver', 'nombre' => 'Ver pagos', 'descripcion' => 'Consultar pagos registrados.'],
            ['modulo' => 'Finanzas', 'codigo' => 'pagos.validar', 'nombre' => 'Validar pagos', 'descripcion' => 'Validar pagos e inscripciones.'],
            ['modulo' => 'Organizaciones', 'codigo' => 'organizaciones.ver', 'nombre' => 'Ver organizaciones', 'descripcion' => 'Consultar organizaciones.'],
            ['modulo' => 'Organizaciones', 'codigo' => 'organizaciones.crear', 'nombre' => 'Crear organizaciones', 'descripcion' => 'Registrar organizaciones.'],
            ['modulo' => 'Organizaciones', 'codigo' => 'organizaciones.editar', 'nombre' => 'Editar organizaciones', 'descripcion' => 'Actualizar organizaciones.'],
            ['modulo' => 'Organizaciones', 'codigo' => 'organizaciones.validar', 'nombre' => 'Validar organizaciones', 'descripcion' => 'Validar información institucional.'],
            ['modulo' => 'Oficios', 'codigo' => 'oficios.ver', 'nombre' => 'Ver oficios', 'descripcion' => 'Consultar oficios.'],
            ['modulo' => 'Oficios', 'codigo' => 'oficios.generar', 'nombre' => 'Generar oficios', 'descripcion' => 'Generar documentos oficiales.'],
            ['modulo' => 'Oficios', 'codigo' => 'oficios.enviar', 'nombre' => 'Enviar oficios', 'descripcion' => 'Enviar oficios a destinatarios.'],
            ['modulo' => 'Reuniones', 'codigo' => 'reuniones.ver', 'nombre' => 'Ver reuniones', 'descripcion' => 'Consultar reuniones.'],
            ['modulo' => 'Reuniones', 'codigo' => 'reuniones.solicitar', 'nombre' => 'Solicitar reuniones', 'descripcion' => 'Registrar solicitudes de reunión para seguimiento institucional.'],
            ['modulo' => 'Reuniones', 'codigo' => 'reuniones.gestionar', 'nombre' => 'Gestionar reuniones', 'descripcion' => 'Administrar reuniones.'],
            ['modulo' => 'Convenios', 'codigo' => 'convenios.ver', 'nombre' => 'Ver convenios', 'descripcion' => 'Consultar convenios.'],
            ['modulo' => 'Convenios', 'codigo' => 'convenios.gestionar', 'nombre' => 'Gestionar convenios', 'descripcion' => 'Administrar convenios.'],
            ['modulo' => 'Aliados', 'codigo' => 'aliados.ver', 'nombre' => 'Ver aliados', 'descripcion' => 'Consultar instituciones con convenio formalizado dentro del alcance autorizado.'],
            ['modulo' => 'Aliados', 'codigo' => 'aliados.ver_historial', 'nombre' => 'Ver historial de aliados', 'descripcion' => 'Consultar el historial de convocatorias compartidas con aliados autorizados.'],
            ['modulo' => 'Aliados', 'codigo' => 'aliados.gestionar_contactos', 'nombre' => 'Gestionar contactos de aliados', 'descripcion' => 'Agregar y administrar números de difusión sin modificar los datos originales del seguimiento.'],
            ['modulo' => 'Aliados', 'codigo' => 'aliados.compartir_correo', 'nombre' => 'Compartir convocatorias por correo', 'descripcion' => 'Enviar convocatorias vigentes por correo a aliados autorizados.'],
            ['modulo' => 'WhatsApp', 'codigo' => 'whatsapp.ver', 'nombre' => 'Ver conversaciones de WhatsApp', 'descripcion' => 'Consultar conversaciones de WhatsApp autorizadas.'],
            ['modulo' => 'WhatsApp', 'codigo' => 'whatsapp.enviar', 'nombre' => 'Enviar mensajes por WhatsApp', 'descripcion' => 'Enviar mensajes mediante cuentas de WhatsApp Business autorizadas.'],
            ['modulo' => 'WhatsApp', 'codigo' => 'whatsapp.gestionar_conversaciones', 'nombre' => 'Supervisar conversaciones de WhatsApp', 'descripcion' => 'Consultar las conversaciones del equipo dentro del alcance autorizado.'],
            ['modulo' => 'WhatsApp', 'codigo' => 'whatsapp.gestionar_cuentas', 'nombre' => 'Gestionar cuentas de WhatsApp', 'descripcion' => 'Configurar números empresariales y asignarlos a usuarios. Exclusivo del Administrador.'],
            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.usar', 'nombre' => 'Usar telefonía', 'descripcion' => 'Acceder al motor WebRTC y a la extensión PBX asignada.'],
            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.salientes', 'nombre' => 'Realizar llamadas salientes', 'descripcion' => 'Originar llamadas desde el CRM mediante la extensión PBX asignada.'],
            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.recibir', 'nombre' => 'Recibir llamadas', 'descripcion' => 'Mantener la extensión disponible para llamadas entrantes y recibir avisos de llamadas en el CRM.'],
            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.transferir', 'nombre' => 'Transferir llamadas', 'descripcion' => 'Transferir una llamada activa a otra extensión o área autorizada.'],
            ['modulo' => 'Telefonía', 'codigo' => 'telefonia.configurar', 'nombre' => 'Configurar telefonía', 'descripcion' => 'Asignar y administrar extensiones PBX de los usuarios. Exclusivo del Administrador.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.ver', 'nombre' => 'Ver convocatorias', 'descripcion' => 'Consultar convocatorias registradas.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.crear', 'nombre' => 'Crear convocatorias', 'descripcion' => 'Registrar nuevas convocatorias.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.editar', 'nombre' => 'Editar convocatorias', 'descripcion' => 'Actualizar información de convocatorias.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.gestionar', 'nombre' => 'Administrar convocatorias', 'descripcion' => 'Habilita alcance global, alertas y las capacidades administrativas de convocatorias.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.descargar', 'nombre' => 'Descargar imágenes', 'descripcion' => 'Descargar la imagen asociada a una convocatoria.'],
            ['modulo' => 'Convocatorias', 'codigo' => 'convocatorias.cambiar_estado', 'nombre' => 'Activar / desactivar convocatorias', 'descripcion' => 'Modificar el estado lógico de una convocatoria.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.ver', 'nombre' => 'Ver difusión', 'descripcion' => 'Consultar campañas, convocatorias o ligas de registro.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.crear', 'nombre' => 'Crear difusión', 'descripcion' => 'Registrar nuevas convocatorias o ligas de registro.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.enviar', 'nombre' => 'Enviar difusión', 'descripcion' => 'Enviar convocatorias o ligas a instituciones autorizadas.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.gestionar', 'nombre' => 'Gestionar difusión', 'descripcion' => 'Administrar el proceso de difusión institucional.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.ver', 'nombre' => 'Ver reportes', 'descripcion' => 'Consultar el Centro de Reportes.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.seguimiento.cartera', 'nombre' => 'Reporte de cartera de seguimiento', 'descripcion' => 'Generar reportes sobre el estado actual de la cartera dentro del alcance autorizado.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.seguimiento.actividad', 'nombre' => 'Reporte de actividad de seguimiento', 'descripcion' => 'Generar reportes de actividad e interacciones dentro del alcance autorizado.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.seguimiento.institucion', 'nombre' => 'Reporte de institución', 'descripcion' => 'Generar el expediente ejecutivo de una institución dentro del alcance autorizado.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.territorial', 'nombre' => 'Reporte de información territorial', 'descripcion' => 'Generar reportes de información territorial sobre territorios autorizados.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.convocatorias', 'nombre' => 'Reporte de convocatorias', 'descripcion' => 'Consultar y generar el reporte ejecutivo del módulo de Convocatorias.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.usuarios', 'nombre' => 'Reporte administrativo de usuarios', 'descripcion' => 'Generar el reporte transversal de usuarios. Exclusivo del Administrador.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.exportar', 'nombre' => 'Exportar reportes', 'descripcion' => 'Exportar a PDF los reportes que el rol tiene autorizados.'],
            ['modulo' => 'Respaldos', 'codigo' => 'respaldos.generar', 'nombre' => 'Generar respaldos', 'descripcion' => 'Crear respaldos del sistema.'],
            ['modulo' => 'Respaldos', 'codigo' => 'respaldos.restaurar', 'nombre' => 'Restaurar respaldos', 'descripcion' => 'Restaurar información desde respaldo.'],
            ['modulo' => 'Configuración', 'codigo' => 'configuracion.ver', 'nombre' => 'Ver configuración', 'descripcion' => 'Consultar configuración del sistema.'],
            ['modulo' => 'Configuración', 'codigo' => 'configuracion.editar', 'nombre' => 'Editar configuración', 'descripcion' => 'Actualizar configuración del sistema.']
        ];
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
