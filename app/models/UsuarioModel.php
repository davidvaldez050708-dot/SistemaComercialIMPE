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
                    id,
                    nombre,
                    apellidos,
                    correo,
                    usuario,
                    password,
                    estado,
                    rol_id,
                    requiere_cambio_password,
                    password_temporal_expira
                FROM usuarios
                WHERE id = ?
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
}