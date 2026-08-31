<?php

require_once __DIR__ . '/../../config/db_connection.php';

class UsuarioModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function buscarPorUsuarioOCorreo($dato)
    {
        $sql = "SELECT 
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.correo,
                    usuarios.usuario,
                    usuarios.password,
                    usuarios.estado,
                    usuarios.rol_id,
                    usuarios.requiere_cambio_password,
                    usuarios.password_temporal_expira,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles 
                    ON usuarios.rol_id = roles.id
                WHERE (usuarios.usuario = ? OR usuarios.correo = ?)
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("ss", $dato, $dato);

        $stmt->execute();

        $resultado = $stmt->get_result();

        return $resultado->fetch_assoc();
    }

    public function actualizarUltimoAcceso($id)
    {
        $sql = "UPDATE usuarios 
                SET ultimo_acceso = NOW()
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("i", $id);

        return $stmt->execute();
    }

    public function establecerPasswordTemporal($usuarioId, $passwordHash, $fechaExpiracion)
    {
        $sql = "UPDATE usuarios
                SET password = ?,
                    requiere_cambio_password = 1,
                    password_temporal_expira = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param(
            "ssi",
            $passwordHash,
            $fechaExpiracion,
            $usuarioId
        );

        return $stmt->execute();
    }

    public function buscarPorId($id)
    {
        $sql = "SELECT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.telefono,
                    usuarios.foto_perfil,
                    usuarios.correo,
                    usuarios.usuario,
                    usuarios.password,
                    usuarios.estado,
                    usuarios.rol_id,
                    usuarios.ultimo_acceso,
                    usuarios.created_at,
                    usuarios.updated_at,
                    usuarios.requiere_cambio_password,
                    usuarios.password_temporal_expira,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles
                    ON usuarios.rol_id = roles.id
                WHERE usuarios.id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("i", $id);

        $stmt->execute();

        $resultado = $stmt->get_result();

        return $resultado->fetch_assoc();
    }

    public function actualizarPasswordDefinitivo(
        $usuarioId,
        $passwordHash
    ) {
        $sql = "UPDATE usuarios
                SET password = ?,
                    requiere_cambio_password = 0,
                    password_temporal_expira = NULL
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param(
            "si",
            $passwordHash,
            $usuarioId
        );

        return $stmt->execute();
    }

    public function contarUsuarios()
    {
        $sql = "SELECT
                    COUNT(*) AS registrados,
                    SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) AS inactivos
                FROM usuarios";

        $resultado = $this->connection->query($sql);

        return $resultado->fetch_assoc();
    }

    public function contarRoles()
    {
        $sql = "SELECT COUNT(*) AS total
                FROM roles";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return (int)$fila['total'];
    }

    public function obtenerUsuariosPorRol()
    {
        $sql = "SELECT
                    roles.id,
                    roles.nombre,
                    COUNT(usuarios.id) AS total
                FROM roles
                LEFT JOIN usuarios
                    ON usuarios.rol_id = roles.id
                GROUP BY roles.id, roles.nombre
                ORDER BY roles.id";

        $resultado = $this->connection->query($sql);

        $roles = [];

        while ($fila = $resultado->fetch_assoc()) {
            $roles[] = $fila;
        }

        return $roles;
    }

    public function obtenerUsuariosRecientes($limite = 5)
    {
        $limite = max(1, (int)$limite);

        $sql = "SELECT
                    usuarios.usuario,
                    usuarios.correo,
                    usuarios.estado,
                    usuarios.ultimo_acceso,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles
                    ON usuarios.rol_id = roles.id
                ORDER BY usuarios.created_at DESC, usuarios.id DESC
                LIMIT ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("i", $limite);

        $stmt->execute();

        $resultado = $stmt->get_result();

        $usuarios = [];

        while ($fila = $resultado->fetch_assoc()) {
            $usuarios[] = $fila;
        }

        return $usuarios;
    }

    public function obtenerUsuariosListado()
    {
        return $this->listarUsuariosConFiltros();
    }

    public function listarUsuariosConFiltros($filtros = [])
    {
        $buscar = trim($filtros['buscar'] ?? '');
        $rol = $filtros['rol'] ?? '';
        $estado = $filtros['estado'] ?? '';

        $condiciones = [];
        $parametros = [];
        $tipos = '';

        if ($buscar !== '') {
            $condiciones[] = "(
                usuarios.nombre LIKE ?
                OR usuarios.apellidos LIKE ?
                OR usuarios.usuario LIKE ?
                OR usuarios.correo LIKE ?
            )";

            $busqueda = '%' . $buscar . '%';
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $tipos .= 'ssss';
        }

        if ($rol !== '') {
            $condiciones[] = "usuarios.rol_id = ?";
            $parametros[] = (int)$rol;
            $tipos .= 'i';
        }

        if ($estado !== '') {
            $condiciones[] = "usuarios.estado = ?";
            $parametros[] = (int)$estado;
            $tipos .= 'i';
        }

        $sql = "SELECT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.telefono,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    usuarios.correo,
                    usuarios.rol_id,
                    usuarios.estado,
                    usuarios.ultimo_acceso,
                    usuarios.created_at,
                    usuarios.updated_at,
                    usuarios.requiere_cambio_password,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles
                    ON usuarios.rol_id = roles.id";

        if (!empty($condiciones)) {
            $sql .= " WHERE " . implode(" AND ", $condiciones);
        }

        $sql .= " ORDER BY usuarios.created_at DESC, usuarios.id DESC";

        $stmt = $this->connection->prepare($sql);

        $this->vincularParametros($stmt, $tipos, $parametros);

        $stmt->execute();

        $resultado = $stmt->get_result();

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerRoles()
    {
        $sql = "SELECT
                    id,
                    nombre,
                    estado
                FROM roles
                ORDER BY id";

        $resultado = $this->connection->query($sql);

        $roles = [];

        while ($fila = $resultado->fetch_assoc()) {
            $roles[] = $fila;
        }

        return $roles;
    }

    public function obtenerRolesActivos()
    {
        $sql = "SELECT
                    id,
                    nombre,
                    estado
                FROM roles
                WHERE estado = 1
                ORDER BY id";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function existeUsuario($usuario, $idExcluir = null)
    {
        $sql = "SELECT id
                FROM usuarios
                WHERE usuario = ?";

        $parametros = [$usuario];
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

    public function existeCorreo($correo, $idExcluir = null)
    {
        $sql = "SELECT id
                FROM usuarios
                WHERE correo = ?";

        $parametros = [$correo];
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

    public function existeRol($rolId)
    {
        $sql = "SELECT id
                FROM roles
                WHERE id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("i", $rolId);

        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function existeRolActivo($rolId)
    {
        $sql = "SELECT id
                FROM roles
                WHERE id = ?
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("i", $rolId);

        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function contarAdministradoresActivos()
    {
        $sql = "SELECT COUNT(*) AS total
                FROM usuarios
                WHERE rol_id = 1
                    AND estado = 1";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return (int)$fila['total'];
    }

    public function contarUsuariosConFotoPerfil($ruta)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM usuarios
                WHERE foto_perfil = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("s", $ruta);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    public function crearUsuario($datos)
    {
        $sql = "INSERT INTO usuarios (
                    nombre,
                    apellidos,
                    telefono,
                    foto_perfil,
                    correo,
                    usuario,
                    password,
                    rol_id,
                    estado,
                    requiere_cambio_password,
                    password_temporal_expira
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NULL)";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param(
            "sssssssi",
            $datos['nombre'],
            $datos['apellidos'],
            $datos['telefono'],
            $datos['foto_perfil'],
            $datos['correo'],
            $datos['usuario'],
            $datos['password_hash'],
            $datos['rol_id']
        );

        return $stmt->execute();
    }

    public function actualizarUsuario($id, $datos)
    {
        $sql = "UPDATE usuarios
                SET nombre = ?,
                    apellidos = ?,
                    telefono = ?,
                    foto_perfil = ?,
                    correo = ?,
                    usuario = ?,
                    rol_id = ?,
                    estado = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param(
            "ssssssiii",
            $datos['nombre'],
            $datos['apellidos'],
            $datos['telefono'],
            $datos['foto_perfil'],
            $datos['correo'],
            $datos['usuario'],
            $datos['rol_id'],
            $datos['estado'],
            $id
        );

        return $stmt->execute();
    }

    public function actualizarEstadoUsuario($id, $estado)
    {
        $sql = "UPDATE usuarios
                SET estado = ?
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);

        $stmt->bind_param("ii", $estado, $id);

        return $stmt->execute();
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
