<?php
    public function __construct(?mysqli $connection = null)
    {
        $this->connection = $connection ?? (new Database())->connect();
    }