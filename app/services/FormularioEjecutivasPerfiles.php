<?php
/**
 * Los diez perfiles de evaluación de Titulación - Ejecutivas (Acuerdo 286).
 * Catálogo cerrado, obtenido de las opciones institucionales aportadas.
 * No crea enlaces, no guarda datos ni consulta la BD.
 */
final class FormularioEjecutivasPerfiles
{
    public static function todos(): array
    {
        return [
            'Ingeniería computacional A286',
            'Ingeniería industrial A286',
            'Licenciatura en administración A286',
            'Licenciatura en ciencias de la comunicación A286',
            'Licenciatura en ciencias políticas y administración pública A286',
            'Licenciatura en contaduría A286',
            'Licenciatura en derecho A286',
            'Licenciatura en educación preescolar A286',
            'Licenciatura en mercadotecnia A286',
            'Licenciatura en pedagogía A286',
        ];
    }

    public static function admite(string $perfil): bool
    {
        return in_array($perfil, self::todos(), true);
    }
}
