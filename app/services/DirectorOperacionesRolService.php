<?php
/** Cambio de nombre del rol histórico; no altera usuarios ni permisos. */
final class DirectorOperacionesRolService
{
    public const NOMBRE = 'Director de Operaciones';
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    public function comprobar(): array
    {
        $rol = $this->db->query('SELECT id,nombre,estado FROM roles WHERE id=2')->fetch_assoc();
        if (!$rol || !in_array($rol['nombre'], ['Coordinador Comercial', 'Coordinadora Comercial', self::NOMBRE], true)) {
            throw new RuntimeException('El rol 2 no coincide con la versión comprobada. No se modificó.');
        }
        $s = $this->db->prepare('SELECT id FROM roles WHERE nombre=? AND id<>2');
        $nombre = self::NOMBRE; $s->bind_param('s', $nombre); $s->execute();
        if ($s->get_result()->num_rows) throw new RuntimeException('Ya existe otro rol con el nombre Director de Operaciones.');
        return $rol;
    }

    public function renombrar(string $esperado, string $nuevo): void
    {
        if (!in_array($nuevo, ['Coordinador Comercial', 'Coordinadora Comercial', self::NOMBRE], true)) {
            throw new RuntimeException('Nombre de restauración inválido.');
        }
        $this->db->begin_transaction();
        try {
            $rol = $this->db->query('SELECT nombre FROM roles WHERE id=2 FOR UPDATE')->fetch_assoc();
            if (!$rol || $rol['nombre'] !== $esperado) throw new RuntimeException('El rol cambió durante la operación.');
            $this->comprobar();
            $s = $this->db->prepare('UPDATE roles SET nombre=? WHERE id=2');
            $s->bind_param('s', $nuevo); $s->execute();
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
