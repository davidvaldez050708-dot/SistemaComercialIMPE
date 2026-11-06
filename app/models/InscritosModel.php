<?php
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../services/FormularioInscripcionProgramas.php';

/**
 * Inscripciones completas recibidas de los cinco formularios (NO prospectos).
 * Todas las consultas leen tablas existentes; nunca realizan INSERT/UPDATE/SQL DDL.
 * No carga los datos JSON sensibles en el listado de la tabla.
 */
final class InscritosModel
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?: (new Database())->connect();
    }

    public static function filtros(array $entrada): array
    {
        $programa = isset($entrada['programa']) && is_string($entrada['programa'])
            ? trim($entrada['programa']) : '';
        if ($programa !== '' && !FormularioInscripcionProgramas::porSlug($programa)) {
            throw new DomainException('El programa seleccionado no es válido.', 422);
        }

        $busqueda = isset($entrada['buscar']) && is_string($entrada['buscar'])
            ? trim(preg_replace('/\s+/u', ' ', $entrada['buscar']) ?? '') : '';
        if (mb_strlen($busqueda, 'UTF-8') > 100) {
            throw new DomainException('La búsqueda debe tener como máximo 100 caracteres.', 422);
        }

        $desde = self::fecha($entrada['desde'] ?? '', 'inicial');
        $hasta = self::fecha($entrada['hasta'] ?? '', 'final');
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            throw new DomainException('La fecha inicial no puede ser posterior a la fecha final.', 422);
        }
        $pagina = filter_var($entrada['pagina'] ?? 1, FILTER_VALIDATE_INT);
        if ($pagina === false || $pagina < 1 || $pagina > 1000000) $pagina = 1;
        return [
            'programa'=>$programa,
            'buscar'=>$busqueda,
            'desde'=>$desde,
            'hasta'=>$hasta,
            'pagina'=>$pagina
        ];
    }

    private static function fecha($dato, string $etiqueta): string
    {
        if ($dato === null || $dato === '') return '';
        if (!is_string($dato) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dato)) {
            throw new DomainException('Selecciona una fecha ' . $etiqueta . ' válida.', 422);
        }
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $dato);
        if (!$fecha || $fecha->format('Y-m-d') !== $dato) {
            throw new DomainException('La fecha ' . $etiqueta . ' no es válida.', 422);
        }
        return $dato;
    }

    /** Convierte fechas del usuario (México) a límites UTC de registros. */
    private static function limiteUtc(string $fecha, bool $siguienteDia): string
    {
        $local = new DateTimeImmutable($fecha . ' 00:00:00', new DateTimeZone('America/Mexico_City'));
        if ($siguienteDia) $local = $local->modify('+1 day');
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** WHERE y parámetros compartidos exactamente entre listado y exportación. */
    private static function condicion(array $f): array
    {
        $clausulas = [];
        $params = [];
        $tipos = '';

        if ($f['programa'] !== '') {
            $clausulas[] = 'c.programa_slug=?';
            $params[] = $f['programa'];
            $tipos .= 's';
        }
        if ($f['desde'] !== '') {
            $clausulas[] = 'r.created_at>=?';
            $params[] = self::limiteUtc($f['desde'], false);
            $tipos .= 's';
        }
        if ($f['hasta'] !== '') {
            $clausulas[] = 'r.created_at<?';
            $params[] = self::limiteUtc($f['hasta'], true);
            $tipos .= 's';
        }
        if ($f['buscar'] !== '') {
            // El signo ! es el escape explícito de comodines LIKE.
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['buscar']) . '%';
            $clausulas[] = "(CONCAT_WS(' ',r.nombre,r.apellido) LIKE ? ESCAPE '!'
                OR r.nombre LIKE ? ESCAPE '!'
                OR r.apellido LIKE ? ESCAPE '!'
                OR r.correo LIKE ? ESCAPE '!'
                OR r.movil LIKE ? ESCAPE '!'
                OR JSON_UNQUOTE(JSON_EXTRACT(c.datos,'$.curp')) LIKE ? ESCAPE '!')";
            for ($i=0;$i<6;$i++) $params[] = $term;
            $tipos .= 'ssssss';
        }
        return [$clausulas ? ' WHERE ' . implode(' AND ', $clausulas) : '', $params, $tipos];
    }

    private function consultar(string $sql, array $params, string $tipos): mysqli_result
    {
        $consulta = $this->db->prepare($sql);
        if (!$consulta) throw new RuntimeException('No fue posible preparar la consulta de inscripciones.');
        if ($params) $consulta->bind_param($tipos, ...$params);
        $consulta->execute();
        $result = $consulta->get_result();
        if (!$result) throw new RuntimeException('No se pudo obtener el resultado de inscripciones.');
        return $result;
    }

    private static function fromSql(): string
    {
        return ' FROM formulario_inscripciones_completas c
                 INNER JOIN formulario_inscripciones r ON r.id=c.inscripcion_id ';
    }

    public function listar(array $filtros, int $porPagina = 25): array
    {
        $porPagina = max(1, min(100, $porPagina));
        [$where,$params,$types] = self::condicion($filtros);
        $total = (int)$this->consultar(
            'SELECT COUNT(*) total'.self::fromSql().$where,$params,$types
        )->fetch_assoc()['total'];
        $paginas = max(1, (int)ceil($total/$porPagina));
        $pagina = max(1, min((int)$filtros['pagina'], $paginas));
        $offset = ($pagina - 1)*$porPagina;
        $sql = 'SELECT r.id,r.nombre,r.apellido,r.correo,r.movil,r.origen,r.created_at,
                       c.programa_slug,c.aceptado_at,
                       JSON_UNQUOTE(JSON_EXTRACT(c.datos,\'$.perfil_evaluacion\')) AS perfil_evaluacion'
               .self::fromSql().$where
               .' ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?';
        $registros = $this->consultar($sql,array_merge($params,[$porPagina,$offset]),$types.'ii')
                          ->fetch_all(MYSQLI_ASSOC);
        return [
            'registros'=>$registros, 'total'=>$total,
            'pagina'=>$pagina, 'paginas'=>$paginas, 'por_pagina'=>$porPagina
        ];
    }

    public function detalle(int $id): ?array
    {
        if ($id < 1) return null;
        $sql = 'SELECT r.id,r.nombre,r.apellido,r.correo,r.movil,r.origen,r.created_at,
                       c.programa_slug,c.datos,c.aceptado_at,c.comprobante_mime,c.comprobante_tamano,
                       c.terminos_sha256,c.privacidad_sha256'
               .self::fromSql().' WHERE r.id=? LIMIT 1';
        $fila = $this->consultar($sql,[$id],'i')->fetch_assoc();
        if (!$fila) return null;
        $datos = json_decode((string)$fila['datos'],true);
        if (!is_array($datos)) $datos=[];
        $campos = [
            'nombre','apellidos','fecha_nacimiento','genero','curp',
            'nombre_padres','correo','movil','calle','numero','ciudad',
            'codigo_postal','municipio','entidad_federativa',
            'empresa_labora','cargo_puesto','perfil_evaluacion','fecha_inscripcion'
        ];
        $limpios = [];
        foreach ($campos as $key) {
            $valor = $datos[$key] ?? '';
            $limpios[$key] = is_scalar($valor) ? (string)$valor : '';
        }
        // La fila primaria prevalece frente a JSON desactualizado.
        foreach (['nombre','correo','movil'] as $key) $limpios[$key] = (string)$fila[$key];
        $limpios['apellidos'] = (string)$fila['apellido'];
        return [
            'id'=>(int)$fila['id'],
            'programa_slug'=>(string)$fila['programa_slug'],
            'origen'=>(string)$fila['origen'],
            'created_at'=>(string)$fila['created_at'],
            'aceptado_at'=>(string)$fila['aceptado_at'],
            'datos'=>$limpios,
            'comprobante_recibido'=>true,
            'comprobante_mime'=>(string)$fila['comprobante_mime'],
            'comprobante_tamano'=>(int)$fila['comprobante_tamano'],
            'terminos_registrados'=>strlen((string)$fila['terminos_sha256'])===64,
            'privacidad_registrada'=>strlen((string)$fila['privacidad_sha256'])===64
        ];
    }

    /**
     * Devuelve todas las filas que coinciden con los filtros (NO solo la página).
     * Se rechazan exportaciones demasiado grandes sin truncar silenciosamente.
     * JSON completo jamás se envía al navegador del listado.
     */
    public function exportar(array $filtros, int $maximo = 10000): array
    {
        [$where,$params,$types] = self::condicion($filtros);
        $total = (int)$this->consultar(
            'SELECT COUNT(*) total'.self::fromSql().$where,$params,$types
        )->fetch_assoc()['total'];
        if ($total > $maximo) {
            throw new DomainException('Hay más de '.number_format($maximo,0,'.',',')
                .' inscritos. Acota el programa o las fechas antes de exportar.',422);
        }
        $sql = 'SELECT r.id,r.nombre,r.apellido,r.correo,r.movil,r.origen,r.created_at,
                       c.programa_slug,c.datos,c.aceptado_at,c.comprobante_mime'
               .self::fromSql().$where.' ORDER BY r.created_at DESC,r.id DESC';
        return $this->consultar($sql,$params,$types)->fetch_all(MYSQLI_ASSOC);
    }
}
