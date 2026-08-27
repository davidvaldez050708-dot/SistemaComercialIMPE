<?php

class HomeController
{
    public function index()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: index.php');
            exit;
        }

        require_once __DIR__ . '/../views/home/index.php';
    }
}