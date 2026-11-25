<?php
    public function __construct(?mysqli $db=null,?DateTimeImmutable $ahora=null)
    {
        $this->db=$db??(new Database())->connect();
        $this->ahora=$ahora??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }