<?php

require_once __DIR__ . '/config.php';

class Database
{
    private $connection;

    /**
     * Hostinger limita la creación de nuevas conexiones MySQL por segundo.
     * Por defecto utilizar una conexión persistente reutilizable por PHP-FPM,
     * también para los endpoints AJAX y servicios que usan Database.
     * Los consumidores que requieran una sesión independiente pueden
     * solicitar explícitamente connect(false).
     */
    public function connect(bool $persistente = true)
    {
        try {
            // Reducir las conexiones nuevas en toda la aplicación, no solamente
            // en Llamadas. No se cambian host, usuario, contraseña ni BD.
            $host = (string)DB_HOST;
            if ($persistente && !str_starts_with($host, 'p:')) {
                $host = 'p:' . $host;
            }
            $this->connection = new mysqli(
                $host,
                DB_USER,
                DB_PASSWORD,
                DB_NAME
            );

            if ($this->connection->connect_errno) {
                throw new RuntimeException($this->connection->connect_error);
            }

            if (!$this->connection->set_charset('utf8mb4')) {
                throw new RuntimeException('No se pudo configurar UTF-8 en MySQL.');
            }

            return $this->connection;
        } catch (Throwable $e) {
            // Nunca enviar al navegador datos de la conexión MySQL.
            error_log('[impe_mysql] ' . $e->getMessage());
            throw new RuntimeException(
                'No fue posible conectar con la base de datos.',
                0,
                $e
            );
        }
    }
}
