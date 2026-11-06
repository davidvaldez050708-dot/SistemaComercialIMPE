<?php
/** Extiende las inscripciones existentes, sin transformar los registros históricos. */
final class FormularioInscripcionRepository
{
    private mysqli $db;
    private string $directorio;
    public function __construct(mysqli $db, string $directorio)
    {
        $this->db = $db;
        $this->directorio = $directorio;
    }

    public function guardar(array $datos, array $archivo, array $evidencia, array $legales, string $token, ?int $usuario): int
    {
        // Ambas tablas deben ser transaccionales. No crear ni migrar tablas desde una petición.
        $tablas = $this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('formulario_inscripciones','formulario_inscripciones_completas')")->fetch_all(MYSQLI_ASSOC);
        if (count($tablas) !== 2 || array_filter($tablas, static fn($t) => strtoupper($t['ENGINE']) !== 'INNODB')) {
            throw new DomainException('La recepción de inscripciones está pendiente de habilitación.', 503);
        }
        $s = $this->db->prepare('SELECT id FROM municipios WHERE id=? AND estado_id=? AND estado=1 AND EXISTS(SELECT 1 FROM estados WHERE estados.id=municipios.estado_id AND estados.estado=1)');
        $s->bind_param('ii', $datos['municipio_id'], $datos['estado_id']); $s->execute();
        if (!$s->get_result()->num_rows) throw new DomainException('El municipio no corresponde al estado seleccionado.', 422);
        $ruta = null;
        $this->db->begin_transaction();
        try {
            $s = $this->db->prepare('SELECT inscripcion_id FROM formulario_inscripciones_completas WHERE solicitud_token=? FOR UPDATE');
            $s->bind_param('s', $token); $s->execute();
            if ($previa = $s->get_result()->fetch_assoc()) { $this->db->commit(); return (int)$previa['inscripcion_id']; }
            $duplicado = hash('sha256', $datos['programa_slug'].'|'.$datos['curp']);
            $s = $this->db->prepare('SELECT inscripcion_id FROM formulario_inscripciones_completas WHERE identidad_programa=? FOR UPDATE');
            $s->bind_param('s', $duplicado); $s->execute();
            if ($s->get_result()->num_rows) throw new DomainException('Ya recibimos una inscripción con esta CURP para el programa. Comunícate con el equipo para revisarla.', 409);
            if (!is_dir($this->directorio) && !mkdir($this->directorio, 0700, true) && !is_dir($this->directorio)) throw new RuntimeException('No se pudo preparar el almacén privado.');
            $nombre = bin2hex(random_bytes(24)) . '.' . $evidencia['extension'];
            $ruta = $this->directorio . DIRECTORY_SEPARATOR . $nombre;
            if (!move_uploaded_file($archivo['tmp_name'], $ruta) || !chmod($ruta, 0600)) throw new RuntimeException('No se pudo conservar el comprobante privado.');
            $origen = $usuario ? 'MARKETING' : 'PUBLICO';
            $s = $this->db->prepare('INSERT INTO formulario_inscripciones (nombre,apellido,fecha_nacimiento,movil,correo,perfil_interes,lugar_laboras,cargo_puesto,estado_id,municipio_id,creado_por,origen,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $programa = FormularioInscripcionProgramas::porSlug($datos['programa_slug']);
            $s->bind_param('ssssssssiiis', $datos['nombre'],$datos['apellidos'],$datos['fecha_nacimiento'],$datos['movil'],$datos['correo'],$programa['nombre'],$datos['empresa_labora'],$datos['cargo_puesto'],$datos['estado_id'],$datos['municipio_id'],$usuario,$origen);
            $s->execute(); $id = (int)$this->db->insert_id;
            $contenido = json_encode($datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $terminos = hash('sha256', $legales['terminos']); $privacidad = hash('sha256', $legales['privacidad']);
            $s = $this->db->prepare('INSERT INTO formulario_inscripciones_completas (inscripcion_id,programa_slug,solicitud_token,identidad_programa,datos,comprobante_archivo,comprobante_mime,comprobante_sha256,comprobante_tamano,terminos_sha256,privacidad_sha256,aceptado_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $s->bind_param('isssssssiss', $id,$datos['programa_slug'],$token,$duplicado,$contenido,$nombre,$evidencia['mime'],$evidencia['sha256'],$evidencia['tamano'],$terminos,$privacidad);
            $s->execute(); $this->db->commit(); return $id;
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($ruta !== null && is_file($ruta)) unlink($ruta);
            throw $error;
        }
    }
}
