<?php

require_once __DIR__ . '/../../config/db_connection.php';

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
            !$this->existePermisoPorCodigo('territorios.actualizar_ficha');
        $permisosDataTerritorialNuevos =
            !$this->existePermisoPorCodigo('data_territorial.ver') ||
            !$this->existePermisoPorCodigo('data_territorial.actualizar_oficial');
        $permisosSeguimientoVinculacionBaseFaltantes =
            !$this->existePermisoPorCodigo('seguimientos_vinculacion.supervisar') ||
            !$this->existePermisoPorCodigo('seguimientos_vinculacion.comentar') ||
            !$this->rolTienePermisoActivo(
                'Cuenta Clave',
                'seguimientos_vinculacion.supervisar'
            ) ||
            !$this->rolTienePermisoActivo(
                'Cuenta Clave',
                'seguimientos_vinculacion.comentar'
            );

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

        $permisosGenericosActivos =
            $this->existenPermisosGenericosSeguimientosActivos();
        $sinPermisosActivosSistema =
            !$this->existenRelacionesPermisosActivas();

        if ($permisosGenericosActivos || $sinPermisosActivosSistema) {
            $this->sincronizarPermisosBasePorRol();
        }

        $this->desactivarPermisosGenericosSeguimientos();

        if ($permisosTerritorioNuevos) {
            $this->asignarPermisosInicialesTerritorios();
        }

        if ($permisosDataTerritorialNuevos) {
            $this->asignarPermisosInicialesDataTerritorial();
        }

        if ($permisosSeguimientoVinculacionBaseFaltantes) {
            $this->asignarPermisosInicialesSeguimientoVinculacion();
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
        if ((int)$rolId === 1) {
            $this->asegurarPermisosAdministrador();
        }

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

    public function actualizarPermisosRol($rolId, $permisosIds)
    {
        if ((int)$rolId === 1) {
            $this->asegurarPermisosAdministrador();
            return true;
        }

        $permisosIds = array_values(array_unique(array_map('intval', $permisosIds)));

        if (!$this->validarPermisosExistentes($permisosIds)) {
            return false;
        }

        $this->connection->begin_transaction();

        try {
            $sqlEliminar = "DELETE FROM rol_permisos
                            WHERE rol_id = ?";
            $stmtEliminar = $this->connection->prepare($sqlEliminar);
            $stmtEliminar->bind_param("i", $rolId);
            $stmtEliminar->execute();

            if (!empty($permisosIds)) {
                $sqlInsertar = "INSERT INTO rol_permisos (
                                    rol_id,
                                    permiso_id
                                ) VALUES (?, ?)";
                $stmtInsertar = $this->connection->prepare($sqlInsertar);

                foreach ($permisosIds as $permisoId) {
                    $stmtInsertar->bind_param("ii", $rolId, $permisoId);
                    $stmtInsertar->execute();
                }
            }

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function asegurarPermisosAdministrador()
    {
        $sql = "INSERT IGNORE INTO rol_permisos (
                    rol_id,
                    permiso_id
                )
                SELECT 1, permisos.id
                FROM permisos
                WHERE permisos.estado = 1";

        return $this->connection->query($sql);
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
                'reportes.ver',
                'reportes.exportar'
            ],
            'Asesor de Ventas' => [
                'prospectos.ver_propios',
                'prospectos.editar',
                'seguimientos_comerciales.ver_propios',
                'seguimientos_comerciales.crear',
                'seguimientos_comerciales.editar_propios'
            ],
            'Analista de Datos' => [
                'organizaciones.ver',
                'organizaciones.crear',
                'organizaciones.editar',
                'organizaciones.validar',
                'oficios.ver',
                'oficios.generar',
                'oficios.enviar',
                'reuniones.ver',
                'reuniones.solicitar',
                'seguimientos_vinculacion.ver',
                'convenios.ver'
            ],
            'Finanzas' => [
                'pagos.ver',
                'pagos.validar',
                'reportes.ver'
            ],
            'Cuenta Clave' => [
                'organizaciones.ver',
                'oficios.ver',
                'reuniones.ver',
                'reuniones.gestionar',
                'convenios.ver',
                'convenios.gestionar',
                'difusion.ver',
                'difusion.crear',
                'difusion.enviar',
                'difusion.gestionar',
                'seguimientos_vinculacion.ver',
                'seguimientos_vinculacion.supervisar',
                'seguimientos_vinculacion.comentar',
                'reportes.ver'
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
                'territorios.ver',
                'territorios.actualizar_ficha'
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
                'seguimientos_vinculacion.ver'
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
            ['modulo' => 'Territorios', 'codigo' => 'territorios.editar', 'nombre' => 'Editar territorios', 'descripcion' => 'Actualizar información general de los territorios.'],
            ['modulo' => 'Territorios', 'codigo' => 'territorios.asignar', 'nombre' => 'Asignar responsables', 'descripcion' => 'Gestionar responsables territoriales por estado.'],
            ['modulo' => 'Territorios', 'codigo' => 'territorios.actualizar_ficha', 'nombre' => 'Actualizar ficha territorial', 'descripcion' => 'Actualizar información de investigación de los territorios.'],
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
            ['modulo' => 'Difusión', 'codigo' => 'difusion.ver', 'nombre' => 'Ver difusión', 'descripcion' => 'Consultar campañas, convocatorias o ligas de registro.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.crear', 'nombre' => 'Crear difusión', 'descripcion' => 'Registrar nuevas convocatorias o ligas de registro.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.enviar', 'nombre' => 'Enviar difusión', 'descripcion' => 'Enviar convocatorias o ligas a instituciones autorizadas.'],
            ['modulo' => 'Difusión', 'codigo' => 'difusion.gestionar', 'nombre' => 'Gestionar difusión', 'descripcion' => 'Administrar el proceso de difusión institucional.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.ver', 'nombre' => 'Ver reportes', 'descripcion' => 'Consultar reportes.'],
            ['modulo' => 'Reportes', 'codigo' => 'reportes.exportar', 'nombre' => 'Exportar reportes', 'descripcion' => 'Exportar información del sistema.'],
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
