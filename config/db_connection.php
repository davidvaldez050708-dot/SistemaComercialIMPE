<?php

require_once __DIR__ . '/config.php';

class Database
{
    private $connection;

    public function connect()
    {
        try {
            $this->connection = new mysqli(
                DB_HOST,
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
