<?php
require_once __DIR__ . '/FormularioInscripcionValidacion.php';

final class FormularioInscripcionPantalla
{
    public static function preparar(array $programa, bool $publico): void
    {
        $GLOBALS['inscripcionCompletaLista'] = false;
        try {
            $db = (new Database())->connect();
            $n = $db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('formulario_inscripciones','formulario_inscripciones_completas') AND ENGINE='InnoDB'")->fetch_assoc();
            $GLOBALS['inscripcionCompletaLista'] = (int)$n['n'] === 2;
        } catch (Throwable $e) { /* La revisión visual continúa disponible sin habilitar el envío. */ }
        $_SESSION['csrf_inscripcion_completa'] = $_SESSION['csrf_inscripcion_completa'] ?? bin2hex(random_bytes(32));
        $tokens = $_SESSION['inscripcion_completa_tokens'] ?? [];
        $tokens = array_filter($tokens, static fn($t) => $t['vence'] > time());
        // Varias pestañas no invalidan mutuamente sus formularios.
        if (count($tokens) >= 20) array_shift($tokens);
        $token = bin2hex(random_bytes(32));
        $tokens[$token] = ['programa'=>$programa['slug'],'publico'=>$publico,'vence'=>time()+7200];
        $_SESSION['inscripcion_completa_tokens'] = $tokens;
        $GLOBALS['inscripcionCompletaTokenActual'] = $token;
    }

    public static function variables(bool $publico): array
    {
        return [
            'inscripcionPublica'=>$publico,
            'inscripcionRecepcionLista'=>$GLOBALS['inscripcionCompletaLista'] ?? false,
            'inscripcionToken'=>$GLOBALS['inscripcionCompletaTokenActual'],
            'inscripcionAction'=>$publico ? BASE_URL.'ya-estas-inscrito/?accion=guardar'
                : BASE_URL.'index.php?controller=formulario&action=guardarInscripcionCompleta',
            'inscripcionMunicipiosUrl'=>$publico ? BASE_URL.'ya-estas-inscrito/?accion=municipios'
                : BASE_URL.'index.php?controller=formulario&action=municipios'
        ];
    }

    /** Sólo se invoca después de autorizar el acceso interno de Formularios. */
    public static function descargarComprobante(): void
    {
        $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) {http_response_code(404); return;}
        $db = (new Database())->connect(false);
        $s = $db->prepare('SELECT comprobante_archivo,comprobante_mime FROM formulario_inscripciones_completas WHERE inscripcion_id=?');
        $s->bind_param('i',$id); $s->execute(); $row = $s->get_result()->fetch_assoc();
        if (!$row || !preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png)$/D',$row['comprobante_archivo'])) {http_response_code(404); return;}
        $ruta = dirname(ROOT_PATH).'/impe-inscripciones-private/'.$row['comprobante_archivo'];
        if (!is_file($ruta) || is_link($ruta)) {http_response_code(404); return;}
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: '.$row['comprobante_mime']);
        header('Content-Disposition: attachment; filename="comprobante-inscripcion-'.$id.'.'.pathinfo($ruta,PATHINFO_EXTENSION).'"');
        header('Content-Length: '.filesize($ruta));
        readfile($ruta);
    }

    public static function guardar(bool $publico): void
    {
        header('Cache-Control: no-store, private');
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Método no permitido.', 405);
            $csrf = $_POST['csrf_inscripcion'] ?? null;
            if (!is_string($csrf) || empty($_SESSION['csrf_inscripcion_completa']) || !hash_equals($_SESSION['csrf_inscripcion_completa'], $csrf)) throw new DomainException('Recarga el formulario para renovar la sesión.',419);
            $token = $_POST['solicitud_token'] ?? null;
            $permiso = is_string($token) ? ($_SESSION['inscripcion_completa_tokens'][$token] ?? null) : null;
            if (!$permiso || $permiso['vence'] < time() || $permiso['publico'] !== $publico || $permiso['programa'] !== ($_POST['programa_slug'] ?? null)) throw new DomainException('La captura caducó. Abre nuevamente el formulario.',419);
            foreach ($_POST as $valor) if (!is_string($valor)) throw new DomainException('La captura contiene un dato no válido.',422);
            $db = (new Database())->connect(false);
            // Resolver nombres del catálogo; el cliente nunca autoriza un municipio mediante texto libre.
            $estado = filter_var($_POST['entidad_federativa'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $municipio = filter_var($_POST['municipio'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $s = $db->prepare('SELECT e.nombre entidad_federativa,m.nombre municipio FROM estados e JOIN municipios m ON m.estado_id=e.id WHERE e.id=? AND m.id=? AND e.estado=1 AND m.estado=1');
            $s->bind_param('ii',$estado,$municipio); $s->execute(); $localidad = $s->get_result()->fetch_assoc();
            if (!$localidad) throw new DomainException('Selecciona un estado y municipio válidos.',422);
            $entrada = array_merge($_POST, $localidad);
            $resultado = FormularioInscripcionValidacion::validar($entrada, $permiso['programa']);
            if (!$resultado['ok']) {
                http_response_code(422); echo json_encode(['ok'=>false,'mensaje'=>'Revisa los campos señalados.','errores'=>$resultado['errores']],JSON_UNESCAPED_UNICODE); return;
            }
            $archivo = $_FILES['comprobante'] ?? [];
            $evidencia = FormularioInscripcionValidacion::comprobante($archivo);
            // Lista cerrada: no guardar tokens CSRF, confirmaciones duplicadas ni claves arbitrarias.
            $datos = [];
            foreach (['nombre','apellidos','fecha_nacimiento','genero','curp','correo','movil','nombre_padres','calle','numero','ciudad','codigo_postal','municipio','entidad_federativa','empresa_labora','cargo_puesto','perfil_evaluacion','fecha_inscripcion'] as $campo) $datos[$campo] = trim((string)($entrada[$campo] ?? ''));
            // Bachillerato General no solicita Perfil de evaluación: tampoco se
            // persiste esa clave vacía dentro de los datos de la inscripción.
            if ($permiso['programa'] === 'bachillerato-286') {
                unset($datos['perfil_evaluacion']);
            }
            $datos['curp'] = strtoupper($datos['curp']);
            $datos['programa_slug'] = $permiso['programa']; $datos['estado_id'] = (int)$estado; $datos['municipio_id'] = (int)$municipio;
            require_once __DIR__ . '/FormularioInscripcionRepository.php';
            $repo = new FormularioInscripcionRepository($db, dirname(ROOT_PATH).'/impe-inscripciones-private');
            $id = $repo->guardar($datos,$archivo,$evidencia,FormularioInscripcionValidacion::legales($permiso['programa']),$token,$publico ? null : (int)$_SESSION['usuario_id']);
            echo json_encode(['ok'=>true,'registro_id'=>$id,'mensaje'=>'Inscripción guardada correctamente.'],JSON_UNESCAPED_UNICODE);
        } catch (DomainException $e) {
            http_response_code($e->getCode()>=400 && $e->getCode()<600 ? $e->getCode() : 422);
            echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            // No enviar ni registrar CURP, comprobantes o mensajes SQL con datos personales.
            error_log('[inscripcion_completa] '.get_class($e).' código '.(int)$e->getCode());
            http_response_code(503); echo json_encode(['ok'=>false,'mensaje'=>'No se pudo guardar la inscripción. Conserva el formulario e intenta nuevamente.']);
        }
    }
}
