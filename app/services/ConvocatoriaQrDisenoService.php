<?php
/**
 * Diseño visual único para el QR de Registro de cada aliado.
 * Nunca crea/cambia el enlace, los programas ni un borrador de Convocatorias.
 */
require_once __DIR__ . '/AliadoRegistroService.php';

final class ConvocatoriaQrDisenoService
{
    private mysqli $db;
    private const TABLE = 'formulario_aliado_qr_diseno';
    private const MAX_IMAGE = 2097152;

    public function __construct(mysqli $db) { $this->db = $db; }

    private function query(string $sql, array $params = []): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($params) {
            $types = implode('', array_map(static fn($v) => is_int($v) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        $statement->execute();
        return $statement;
    }

    private function verificarTabla(): void
    {
        $table = $this->db->query("SHOW TABLES LIKE 'formulario_aliado_qr_diseno'");
        if (!$table || !$table->num_rows) {
            throw new DomainException('Falta instalar la migración del diseño QR. No se modificó ningún dato.', 503);
        }
    }

    /** Reutiliza el control de acceso por rol, territorio y aliado existente. */
    private function aliadoId(string $tipo, int $id): int
    {
        if (!in_array($tipo, ['manual','institucional'], true) || $id < 1) {
            throw new DomainException('Aliado inválido.', 422);
        }
        $access = new AliadoRegistroService($this->db);
        $access->opcionesConvocatoria($tipo, $id);
        $col = $tipo === 'manual' ? 'manual_id' : 'seguimiento_id';
        $statement = $this->query("SELECT aliado_id FROM formulario_aliado_registro WHERE $col=? LIMIT 1", [$id]);
        $row = $statement->get_result()->fetch_assoc();
        if (!$row) throw new DomainException('El aliado no tiene un perfil de Registro configurado.', 422);
        return (int)$row['aliado_id'];
    }

    private static function defaults(): array
    {
        return [
            'dark'=>'#223a84', 'light'=>'#ffffff', 'finder'=>'#223a84',
            'dots'=>'square', 'corners'=>'square', 'centers'=>'square',
            'size'=>'600', 'margin'=>'4', 'ec'=>'M', 'transparent'=>false,
            'logo'=>false, 'logoSize'=>20, 'source'=>'none', 'url'=>''
        ];
    }

    private static function canonical(array $payload): array
    {
        $defaults = self::defaults();
        $out = [];
        foreach (['dark','light','finder'] as $name) {
            $value = strtolower(trim((string)($payload[$name] ?? '')));
            if (!preg_match('/^#[0-9a-f]{6}$/D', $value)) {
                throw new DomainException('Color de QR inválido.', 422);
            }
            $out[$name] = $value;
        }
        $choices = [
            'dots'=>['square','dots','rounded','extra-rounded','classy','classy-rounded'],
            'corners'=>['square','dot','extra-rounded'], 'centers'=>['square','dot'],
            'size'=>['400','600','800','1000'], 'margin'=>['4','6','8'],
            'ec'=>['L','M','Q','H'], 'source'=>['none','file','url']
        ];
        foreach ($choices as $name=>$allowed) {
            $value = (string)($payload[$name] ?? $defaults[$name]);
            if (!in_array($value, $allowed, true)) {
                throw new DomainException('Opción de diseño no permitida: '.$name, 422);
            }
            $out[$name]=$value;
        }
        foreach (['transparent','logo'] as $name) {
            if (!is_bool($payload[$name] ?? null)) {
                throw new DomainException('Indicador de QR inválido.', 422);
            }
            $out[$name]=$payload[$name];
        }
        $size = $payload['logoSize'] ?? null;
        if (!is_int($size) || $size < 10 || $size > 30) {
            throw new DomainException('Tamaño de logotipo fuera de rango.', 422);
        }
        $out['logoSize']=$size;
        $out['url']='';
        if (!$out['logo']) $out['source']='none';
        if ($out['logo'] && $out['source']==='none') {
            throw new DomainException('Selecciona una imagen central para guardar el diseño.', 422);
        }
        if ($out['source']==='url') {
            $url = trim((string)($payload['url'] ?? ''));
            $parts = parse_url($url);
            if (strlen($url) > 1000 || !filter_var($url,FILTER_VALIDATE_URL) ||
                !is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' ||
                !empty($parts['user']) || !empty($parts['pass'])) {
                throw new DomainException('La URL de la imagen debe ser HTTPS y válida.', 422);
            }
            $out['url']=$url;
        }
        if ($out['logo']) $out['ec']='H';
        // El color de fondo no se usa al elegir transparencia, pero queda guardado.
        return $out;
    }

    private static function validarImagen(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException('No se pudo recibir el archivo del logotipo.', 422);
        }
        $bytes = (int)($file['size'] ?? 0);
        if ($bytes < 1 || $bytes > self::MAX_IMAGE || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new DomainException('Imagen inválida o mayor a 2 MB.', 422);
        }
        $data = file_get_contents($file['tmp_name']);
        if ($data === false || strlen($data) !== $bytes) {
            throw new DomainException('No se pudo verificar la imagen subida.', 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
            throw new DomainException('El logotipo debe ser PNG, JPG o WEBP.', 422);
        }
        $info = @getimagesizefromstring($data);
        if (!$info || ($info['mime']??'')!==$mime || $info[0] < 1 || $info[1] < 1 ||
            $info[0] > 4096 || $info[1] > 4096 || ($info[0]*$info[1]) > 12000000) {
            throw new DomainException('El archivo no es una imagen válida o su resolución es excesiva.', 422);
        }
        return [$data, $mime];
    }

    private static function formatear(?array $row): array
    {
        if (!$row) return [
            'version'=>0, 'config'=>self::defaults(), 'logo_data_uri'=>'', 'updated_at'=>null
        ];
        $config = json_decode((string)$row['diseno_json'],true);
        if (!is_array($config)) throw new DomainException('El diseño almacenado no es válido.', 500);
        $data = (string)($row['logo_binario'] ?? '');
        return [
            'version'=>(int)$row['version'], 'config'=>$config,
            'logo_data_uri'=>($config['logo']??false) && ($config['source']??'')==='file' && $data!==''
                ? 'data:'.$row['logo_mime'].';base64,'.base64_encode($data) : '',
            'updated_at'=>$row['updated_at']
        ];
    }

    public function consultar(string $tipo,int $id): array
    {
        $aliado=$this->aliadoId($tipo,$id);
        $this->verificarTabla();
        $statement=$this->query('SELECT diseno_json,logo_binario,logo_mime,version,updated_at FROM '.self::TABLE.' WHERE aliado_id=?',[$aliado]);
        return self::formatear($statement->get_result()->fetch_assoc() ?: null);
    }

    public function guardar(string $tipo,int $id,int $version,string $configJson,array $file): array
    {
        $aliado=$this->aliadoId($tipo,$id);
        $this->verificarTabla();
        if ($version < 0 || strlen($configJson)>4096) {
            throw new DomainException('Versión o diseño de QR inválidos.', 422);
        }
        try {
            $decoded=json_decode($configJson,true,32,JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('No se pudo interpretar el diseño del QR.', 422);
        }
        if (!is_array($decoded)) throw new DomainException('Diseño de QR inválido.', 422);
        $settings=self::canonical($decoded);
        $hasFile=(int)($file['error']??UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasFile && (!$settings['logo'] || $settings['source']!=='file')) {
            throw new DomainException('No se esperaba un archivo de imagen.', 422);
        }
        $uploaded=$hasFile ? self::validarImagen($file) : null;
        $this->db->begin_transaction();
        try {
            $statement=$this->query('SELECT diseno_json,logo_binario,logo_mime,version,updated_at FROM '.self::TABLE.' WHERE aliado_id=? FOR UPDATE',[$aliado]);
            $current=$statement->get_result()->fetch_assoc();
            $expected=$current?(int)$current['version']:0;
            if ($expected!==$version) {
                throw new DomainException('El QR fue modificado desde otra sesión. Recarga su diseño antes de guardar.',409);
            }
            $logo=null; $mime=null;
            if ($settings['logo'] && $settings['source']==='file') {
                if ($uploaded) { [$logo,$mime]=$uploaded; }
                elseif ($current && !empty($current['logo_binario']) &&
                    (json_decode((string)$current['diseno_json'],true)['source']??'')==='file') {
                    $logo=$current['logo_binario'];
                    $mime=$current['logo_mime'];
                } else {
                    throw new DomainException('Selecciona el archivo del logotipo para este diseño.',422);
                }
            }
            $json=json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $user=(int)($_SESSION['usuario_id']??0);
            if ($current) {
                $this->query('UPDATE '.self::TABLE.' SET diseno_json=?,logo_binario=?,logo_mime=?,version=version+1,actualizado_por=? WHERE aliado_id=?',
                    [$json,$logo,$mime,$user,$aliado]);
            } else {
                $this->query('INSERT INTO '.self::TABLE.' (aliado_id,diseno_json,logo_binario,logo_mime,version,actualizado_por) VALUES (?,?,?,?,1,?)',
                    [$aliado,$json,$logo,$mime,$user]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode()===1062) {
                throw new DomainException('El QR se modificó simultáneamente. Recarga el diseño antes de guardar.',409);
            }
            throw $error;
        }
        return $this->consultar($tipo,$id);
    }
}
