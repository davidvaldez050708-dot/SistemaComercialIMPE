<?php

/** Consulta de solo lectura; el controlador exige sesión y autorización internacional. */
class AliadoInternacionalModel
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db = $db; }

    public function estructuraGestion(): bool
    {
        return $this->db->query("SHOW COLUMNS FROM formulario_aliados LIKE 'pais_codigo'")->num_rows > 0;
    }

    public function obtenerAliados(): array
    {
        if ($this->estructuraGestion()) {
            return $this->consultar("SELECT a.*, post.convenio_fecha,post.convenio_referencia,post.convenio_notas, (SELECT GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' / ') FROM formulario_aliado_programas ap INNER JOIN formulario_programas p ON p.codigo=ap.programa_codigo AND p.activo=1 WHERE ap.aliado_id=a.id) AS programas FROM formulario_aliados a LEFT JOIN seguimientos_vinculacion_post_envio post ON post.seguimiento_id=a.seguimiento_id WHERE a.pais_codigo <> 'MX' OR (a.pais_codigo IS NULL AND a.seguimiento_id IS NULL) ORDER BY a.nombre");
        }
        // Misma autorización que los formularios: únicamente programas activos.
        // La subconsulta evita GROUP BY sobre el token y funciona con ONLY_FULL_GROUP_BY.
        return $this->consultar(
            "SELECT a.id, a.nombre, a.ubicacion, a.slug, a.token, a.activo,
                    (SELECT GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' / ')
                     FROM formulario_aliado_programas ap
                     INNER JOIN formulario_programas p ON p.codigo = ap.programa_codigo AND p.activo = 1
                     WHERE ap.aliado_id = a.id) AS programas
             FROM formulario_aliados a
             WHERE a.seguimiento_id IS NULL ORDER BY a.nombre"
        );
    }

    public function obtenerRespuestas(): array
    {
        // tipo + registro_id identifican la tabla de origen: los IDs pueden coincidir.
        // No filtrar por activo: las respuestas históricas siguen siendo consultables.
        $condicion=$this->estructuraGestion()?"(a.pais_codigo <> 'MX' OR (a.pais_codigo IS NULL AND a.seguimiento_id IS NULL))":"a.seguimiento_id IS NULL";
        $ubicacion = $this->db->query("SHOW COLUMNS FROM formulario_registros LIKE 'estado_provincia'")->num_rows
            ? 'COALESCE(r.pais_nombre,i.pais_nombre) AS pais_nombre, COALESCE(r.estado_provincia,i.estado_provincia) AS estado_provincia, COALESCE(r.ciudad,i.ciudad) AS ciudad,'
            : 'NULL AS pais_nombre, NULL AS estado_provincia, NULL AS ciudad,';
        return $this->consultar(
            "SELECT {$ubicacion} a.nombre AS aliado, d.tipo, d.aliado_ubicacion,
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
             WHERE {$condicion}
             ORDER BY d.created_at DESC, d.id DESC LIMIT 150"
        );
    }

    private function consultar(string $sql): array
    {
        $resultado = $this->db->query($sql);
        if (!$resultado) { throw new RuntimeException('No fue posible consultar los aliados internacionales.'); }
        return $resultado->fetch_all(MYSQLI_ASSOC);
    }
}
