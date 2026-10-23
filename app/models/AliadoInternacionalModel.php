<?php

/** Consulta de solo lectura; el controlador exige sesión, permiso y Administración. */
class AliadoInternacionalModel
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db = $db; }

    public function obtenerAliados(): array
    {
        // Misma autorización que los formularios: únicamente programas activos.
        // La subconsulta evita GROUP BY sobre el token y funciona con ONLY_FULL_GROUP_BY.
        return $this->consultar(
            "SELECT a.id, a.nombre, a.ubicacion, a.slug, a.token, a.activo,
                    (SELECT GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' / ')
                     FROM formulario_aliado_programas ap
                     INNER JOIN formulario_programas p ON p.codigo = ap.programa_codigo AND p.activo = 1
                     WHERE ap.aliado_id = a.id) AS programas
             FROM formulario_aliados a
             WHERE a.seguimiento_id IS NULL " . $this->excluirRegistro() . " ORDER BY a.nombre"
        );
    }

    public function obtenerRespuestas(): array
    {
        // tipo + registro_id identifican la tabla de origen: los IDs pueden coincidir.
        // No filtrar por activo: las respuestas históricas siguen siendo consultables.
        return $this->consultar(
            "SELECT a.nombre AS aliado, d.tipo, d.aliado_ubicacion,
                    p.nombre AS programa, d.created_at, d.registro_id,
                    COALESCE(r.nombre, i.nombre) AS nombre,
                    COALESCE(r.apellido, i.apellido) AS apellido,
                    COALESCE(r.correo, i.correo) AS correo,
                    COALESCE(r.movil, i.movil) AS movil
             FROM formulario_aliado_procedencias d
             INNER JOIN formulario_aliados a ON a.id = d.aliado_id
             INNER JOIN formulario_programas p ON p.codigo = d.programa_codigo
             LEFT JOIN formulario_registros r ON d.tipo = 'registro' AND r.id = d.registro_id
             LEFT JOIN formulario_inscripciones i ON d.tipo = 'inscripcion' AND i.id = d.registro_id
             WHERE a.seguimiento_id IS NULL " . $this->excluirRegistro() . "
             ORDER BY d.created_at DESC, d.id DESC LIMIT 150"
        );
    }

    private function excluirRegistro(): string
    {
        return $this->db->query("SHOW TABLES LIKE 'formulario_aliado_registro'")->num_rows
            ? ' AND NOT EXISTS (SELECT 1 FROM formulario_aliado_registro exclusivo WHERE exclusivo.aliado_id=a.id) ' : '';
    }
    private function consultar(string $sql): array
    {
        $resultado = $this->db->query($sql);
        if (!$resultado) { throw new RuntimeException('No fue posible consultar los aliados internacionales.'); }
        return $resultado->fetch_all(MYSQLI_ASSOC);
    }
}
