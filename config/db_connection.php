<?php

require_once __DIR__ . '/config.php';

class Database
{
    private $host = DB_HOST;
    private $user = DB_USER;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;

    private $connection;

    public function connect()
    {
        $this->connection = new mysqli(
            $this->host,
            $this->user,
            $this->password,
            $this->database
        );

        if ($this->connection->connect_error) {
            die('Error de conexión a la base de datos: ' . $this->connection->connect_error);
        }

        $this->connection->set_charset('utf8mb4');

        return $this->connection;
    }
}