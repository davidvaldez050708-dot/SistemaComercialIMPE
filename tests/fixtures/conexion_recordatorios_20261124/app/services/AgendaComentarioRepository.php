<?php
    public function __construct($db = null)
    {
        $this->db = $db ?: (new Database())->connect();
    }