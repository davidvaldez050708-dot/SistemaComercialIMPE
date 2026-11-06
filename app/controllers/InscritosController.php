<?php
require_once __DIR__ . '/../helpers/InscritosPermisos.php';
require_once __DIR__ . '/../helpers/FormularioPermisos.php';
require_once __DIR__ . '/../services/FormularioInscripcionProgramas.php';
require_once __DIR__ . '/../models/InscritosModel.php';

/**
 * Módulo de lectura, totalmente separado de Prospectos y de la captura.
 * Las acciones nunca están disponibles sin autenticación y permiso válido.
 */
final class InscritosController
{
    public function index(): void
    {
        InscritosPermisos::exigir(InscritosPermisos::VER);
        $tituloPagina = 'Inscritos';
        $subtituloPagina = 'Consulta las inscripciones recibidas por programa.';
        $opcionActiva = 'inscritos';
        $programasInscritos = FormularioInscripcionProgramas::todos();
        $puedeExportarInscritos = InscritosPermisos::puede(InscritosPermisos::EXPORTAR);
        $puedeVerComprobantesInscritos = FormularioPermisos::puede(FormularioPermisos::COMPROBANTES);
        require __DIR__ . '/../views/layout/dashboard_head.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require __DIR__ . '/../views/layout/topbar.php';
        require __DIR__ . '/../views/inscritos/index.php';
        require __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function listar(): void
    {
        InscritosPermisos::exigir(InscritosPermisos::VER, true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        try {
            $filtros = InscritosModel::filtros($_GET);
            $listado = (new InscritosModel())->listar($filtros);
            echo json_encode(['ok'=>true] + $listado,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (DomainException $e) {
            $this->errorJson($e->getMessage(), $e->getCode()>=400?$e->getCode():422);
        } catch (Throwable $e) {
            error_log('[inscritos_listar] ' . get_class($e));
            $this->errorJson('No se pudo consultar la bandeja de inscripciones. Comprueba que la recepción de inscripciones esté instalada.',503);
        }
    }

    public function detalle(): void
    {
        InscritosPermisos::exigir(InscritosPermisos::VER, true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if (!$id) $this->errorJson('Folio de inscripción inválido.',422);
        try {
            $registro=(new InscritosModel())->detalle((int)$id);
            if (!$registro) $this->errorJson('No se encontró esta inscripción.',404);
            $registro['puede_descargar_comprobante'] =
                FormularioPermisos::puede(FormularioPermisos::COMPROBANTES);
            echo json_encode(['ok'=>true,'registro'=>$registro],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            error_log('[inscritos_detalle] '.get_class($e));
            $this->errorJson('No se pudo consultar la ficha del inscrito.',503);
        }
    }

    public function exportar(): void
    {
        if (!InscritosPermisos::puede(InscritosPermisos::EXPORTAR)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo 'No tienes permiso para exportar inscripciones.';
            return;
        }
        try {
            $filtros = InscritosModel::filtros($_GET);
            $filas = (new InscritosModel())->exportar($filtros);
            require_once __DIR__ . '/../services/InscritosExcelService.php';
            $archivo = InscritosExcelService::crear($filas,$filtros);
        } catch (DomainException $e) {
            http_response_code($e->getCode()>=400?$e->getCode():422);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo $e->getMessage();
            return;
        } catch (Throwable $e) {
            error_log('[inscritos_exportar] '.get_class($e));
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo 'No se pudo generar el archivo Excel. Verifica el soporte ZIP y el módulo de inscripciones.';
            return;
        }

        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="inscritos-'
                .date('Ymd-His').'.xlsx"');
            header('Content-Length: '.filesize($archivo));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($archivo);
        } finally {
            @unlink($archivo);
        }
    }

    private function errorJson(string $mensaje, int $estado): void
    {
        http_response_code($estado);
        echo json_encode(['ok'=>false,'mensaje'=>$mensaje],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
