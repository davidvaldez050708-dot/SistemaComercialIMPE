<?php
    public function __construct($repo = null, ?DateTimeImmutable $reloj = null)
    {
        $this->repo = $repo ?: new AgendaComentarioRepository();
        $this->reloj = $reloj;
    }