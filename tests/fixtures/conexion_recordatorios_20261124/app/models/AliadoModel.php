<?php
    public function __construct(?mysqli $connection = null)
    {
        $database = new Database();
        $this->connection = $connection ?? $database->connect();
    }