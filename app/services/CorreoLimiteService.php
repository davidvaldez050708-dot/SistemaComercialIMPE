<?php

require_once __DIR__ . '/CorreoEstadoPrivado.php';

final class CorreoLimiteService
{
    public static function tomar(string $purpose, int $user, int $max, int $window): bool
    {
        if ($user <= 0 || $max < 1 || $window < 1) { return false; }
        return CorreoEstadoPrivado::ejecutar('mail-rate', $purpose . ':' . $user, static function ($state, $save) use ($max, $window) {
            $now = time();
            $times = array_values(array_filter($state['times'] ?? [], static function ($at) use ($now, $window) { return is_int($at) && $at > $now - $window; }));
            if (count($times) >= $max) { return false; }
            $times[] = $now; $save(['times' => $times]);
            return true;
        });
    }
}
