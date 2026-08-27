<?php

require_once __DIR__ . '/config.php';

class Database
{
    private $connection;

    public function connect()
    {
        $this->connection = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME
        );

        if ($this->connection->connect_error) {
            die(
                'Error de conexión: ' .
                $this->connection->connect_error
            );
        }

        $this->connection->set_charset('utf8mb4');

        return $this->connection;
    }
}