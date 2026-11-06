<?php
/**
 * Catálogo estable para el selector interno de los cinco programas.
 * Los slugs se conservan para compatibilidad con los enlaces compartidos.
 *
 * Los nombres coinciden con los perfiles admitidos hoy por los controladores
 * de registro e inscripción para evitar cambios accidentales de persistencia.
 */
final class FormularioInscripcionProgramas
{
    public static function todos(): array
    {
        return [
            [
                'slug' => 'bachillerato-en-2-anos',
                'nombre' => 'Bachillerato en 2 años',
                'icono' => 'bi-mortarboard',
            ],
            [
                'slug' => 'bachillerato-286',
                'nombre' => 'Bachillerato General',
                'icono' => 'bi-patch-check',
            ],
            [
                'slug' => 'ingles',
                'nombre' => 'Inglés',
                'icono' => 'bi-translate',
            ],
            [
                'slug' => 'titulacion-ejecutivas',
                'nombre' => 'Titulación - Ejecutivas',
                'icono' => 'bi-briefcase',
            ],
            [
                'slug' => 'titulacion-experiencia-laboral',
                'nombre' => 'Titulación por experiencia laboral',
                'icono' => 'bi-award',
            ],
        ];
    }

    public static function porSlug(string $slug): ?array
    {
        foreach (self::todos() as $programa) {
            if (hash_equals($programa['slug'], $slug)) {
                return $programa;
            }
        }

        return null;
    }
}
