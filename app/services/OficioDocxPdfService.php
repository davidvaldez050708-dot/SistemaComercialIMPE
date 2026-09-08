<?php

class OficioDocxPdfService
{
    private $rootPath;
    private $templatePath;

    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->templatePath = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR .
            'oficio_general_redmex.docx';
    }

    public function diagnosticar()
    {
        $conversor = $this->detectarConversor();

        return [
            'ok' => is_file($this->templatePath) && (class_exists('ZipArchive') || class_exists('PharData')) && function_exists('proc_open') && ($conversor['ok'] ?? false),
            'template' => $this->templatePath,
            'template_existe' => is_file($this->templatePath),
            'zip_disponible' => class_exists('ZipArchive') || class_exists('PharData'),
            'proc_open_disponible' => function_exists('proc_open'),
            'conversor' => $conversor
        ];
    }

    public function generarPdf(array $vista)
    {
        if (!is_file($this->templatePath)) {
            return $this->error(
                'No se encontró la plantilla DOCX institucional del oficio.',
                'Falta storage/templates/oficio_general_redmex.docx.'
            );
        }

        if (!class_exists('ZipArchive') && !class_exists('PharData')) {
            return $this->error(
                'PHP necesita soporte para archivos ZIP para preparar el oficio.',
                'No están disponibles ZipArchive ni PharData.'
            );
        }

        if (!function_exists('proc_open')) {
            return $this->error(
                'PHP no puede ejecutar el conversor de documentos.',
                'proc_open no está disponible.'
            );
        }

        $folio = trim((string)($vista['folio'] ?? 'oficio'));
        $nombreBase = $this->nombreArchivoSeguro($folio);
        $directorioTemporal = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'oficios' .
            DIRECTORY_SEPARATOR . $nombreBase . '_' . bin2hex(random_bytes(4));

        if (!mkdir($directorioTemporal, 0775, true) && !is_dir($directorioTemporal)) {
            return $this->error(
                'No fue posible preparar el archivo temporal del oficio.',
                'No se pudo crear: ' . $directorioTemporal
            );
        }

        $rutaDocx = $directorioTemporal . DIRECTORY_SEPARATOR . $nombreBase . '.docx';

        try {
            if (!copy($this->templatePath, $rutaDocx)) {
                return $this->error(
                    'No fue posible copiar la plantilla institucional.',
                    'copy() falló para: ' . $rutaDocx
                );
            }

            $rellenado = $this->rellenarDocx(
                $rutaDocx,
                $this->construirReemplazos($vista)
            );

            if (!($rellenado['ok'] ?? false)) {
                return $rellenado;
            }

            $conversion = $this->convertirAPdf($rutaDocx, $directorioTemporal);

            if (!($conversion['ok'] ?? false)) {
                return $conversion;
            }

            $pdfTemporal = (string)$conversion['ruta_pdf'];
            $contenido = is_file($pdfTemporal) ? file_get_contents($pdfTemporal) : false;

            if ($contenido === false || $contenido === '') {
                return $this->error(
                    'El conversor no generó un PDF válido.',
                    'El PDF temporal no existe, está vacío o no se pudo leer.'
                );
            }

            return [
                'ok' => true,
                'contenido_pdf' => $contenido,
                'conversor' => (string)($conversion['conversor'] ?? '')
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el oficio desde la plantilla institucional.',
                $error->getMessage()
            );
        } finally {
            $this->eliminarDirectorio($directorioTemporal);
        }
    }

    private function construirReemplazos(array $vista)
    {
        return [
            '{{FOLIO}}' => trim((string)($vista['folio'] ?? '')),
            '{{FECHA}}' => trim((string)($vista['fecha'] ?? '')),
            '{{DESTINATARIO_NOMBRE}}' => $this->mayusculas($vista['destinatario_nombre'] ?? ''),
            '{{DESTINATARIO_CARGO}}' => $this->mayusculas($vista['destinatario_cargo'] ?? ''),
            '{{INSTITUCION}}' => $this->mayusculas($vista['institucion'] ?? ''),
            '{{ESTADO}}' => $this->mayusculas($vista['estado'] ?? ''),
            '{{ANALISTA_NOMBRE}}' => trim((string)($vista['analista_nombre'] ?? '')),
            '{{ANALISTA_CARGO}}' => 'Analista de Enlace Institucional',
            '{{ANALISTA_TELEFONO}}' => trim((string)($vista['analista_telefono'] ?? ''))
        ];
    }

    private function rellenarDocx($rutaDocx, array $reemplazos)
    {
        if (class_exists('ZipArchive')) {
            return $this->rellenarConZipArchive($rutaDocx, $reemplazos);
        }

        return $this->rellenarConPharData($rutaDocx, $reemplazos);
    }

    private function rellenarConZipArchive($rutaDocx, array $reemplazos)
    {
        $zip = new ZipArchive();
        $abierto = $zip->open($rutaDocx);

        if ($abierto !== true) {
            return $this->error(
                'No fue posible abrir la plantilla DOCX.',
                'ZipArchive::open devolvió: ' . (string)$abierto
            );
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if (!is_string($xml) || $xml === '') {
                return $this->error(
                    'La plantilla DOCX no contiene el documento principal.',
                    'word/document.xml no disponible.'
                );
            }

            $xml = $this->reemplazarTokensXml($xml, $reemplazos);

            if (is_array($xml)) {
                return $xml;
            }

            if (!$zip->addFromString('word/document.xml', $xml)) {
                return $this->error(
                    'No fue posible guardar los datos dentro del DOCX.',
                    'ZipArchive::addFromString falló.'
                );
            }
        } finally {
            $zip->close();
        }

        return ['ok' => true];
    }

    private function rellenarConPharData($rutaDocx, array $reemplazos)
    {
        try {
            $archivo = new PharData($rutaDocx);

            if (!isset($archivo['word/document.xml'])) {
                return $this->error(
                    'La plantilla DOCX no contiene el documento principal.',
                    'word/document.xml no disponible mediante PharData.'
                );
            }

            $xml = $archivo['word/document.xml']->getContent();
            $xml = $this->reemplazarTokensXml($xml, $reemplazos);

            if (is_array($xml)) {
                return $xml;
            }

            $archivo['word/document.xml'] = $xml;
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible modificar la plantilla DOCX.',
                $error->getMessage()
            );
        }

        return ['ok' => true];
    }

    private function reemplazarTokensXml($xml, array $reemplazos)
    {
        foreach ($reemplazos as $token => $valor) {
            $seguro = htmlspecialchars(
                (string)$valor,
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            );
            $xml = str_replace($token, $seguro, $xml);
        }

        if (preg_match('/\{\{[A-Z0-9_]+\}\}/', $xml, $coincidencia)) {
            return $this->error(
                'La plantilla contiene un campo que no pudo completarse.',
                'Token pendiente: ' . (string)($coincidencia[0] ?? 'desconocido')
            );
        }

        return $xml;
    }

    private function convertirAPdf($rutaDocx, $directorioSalida)
    {
        $conversor = $this->detectarConversor();

        if (!($conversor['ok'] ?? false)) {
            return $this->error(
                'No se encontró un conversor DOCX a PDF en este equipo.',
                (string)($conversor['detalle'] ?? 'Instala LibreOffice o Microsoft Word.')
            );
        }

        if (($conversor['tipo'] ?? '') === 'libreoffice') {
            return $this->convertirConLibreOffice(
                (string)$conversor['ruta'],
                $rutaDocx,
                $directorioSalida
            );
        }

        return $this->convertirConWord($rutaDocx, $directorioSalida);
    }

    private function detectarConversor()
    {
        $configurado = getenv('LIBREOFFICE_PATH');
        $candidatos = [];

        if ($configurado !== false && trim((string)$configurado) !== '') {
            $candidatos[] = trim((string)$configurado);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidatos[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
            $candidatos[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe';
        } else {
            $candidatos[] = '/usr/bin/libreoffice';
            $candidatos[] = '/usr/bin/soffice';
            $candidatos[] = '/snap/bin/libreoffice';
        }

        foreach (array_unique($candidatos) as $ruta) {
            if (is_file($ruta) && is_executable($ruta)) {
                return [
                    'ok' => true,
                    'tipo' => 'libreoffice',
                    'ruta' => $ruta,
                    'detalle' => 'LibreOffice disponible.'
                ];
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $powershell = getenv('SystemRoot');
            $rutaPowerShell = ($powershell ? rtrim($powershell, '\\/') : 'C:\\Windows') .
                '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';

            if (is_file($rutaPowerShell)) {
                return [
                    'ok' => true,
                    'tipo' => 'word',
                    'ruta' => $rutaPowerShell,
                    'detalle' => 'Se intentará convertir mediante Microsoft Word.'
                ];
            }
        }

        return [
            'ok' => false,
            'tipo' => '',
            'ruta' => '',
            'detalle' => 'No se encontró LibreOffice y no hay conversor de Word disponible.'
        ];
    }

    private function convertirConLibreOffice($ejecutable, $rutaDocx, $directorioSalida)
    {
        $comando = escapeshellarg($ejecutable) .
            ' --headless --convert-to pdf --outdir ' . escapeshellarg($directorioSalida) .
            ' ' . escapeshellarg($rutaDocx);
        $resultado = $this->ejecutar($comando);
        $rutaPdf = $directorioSalida . DIRECTORY_SEPARATOR .
            pathinfo($rutaDocx, PATHINFO_FILENAME) . '.pdf';

        if (($resultado['codigo'] ?? 1) !== 0 || !is_file($rutaPdf)) {
            return $this->error(
                'LibreOffice no pudo convertir el oficio a PDF.',
                trim((string)($resultado['salida'] ?? ''))
            );
        }

        return [
            'ok' => true,
            'ruta_pdf' => $rutaPdf,
            'conversor' => 'LIBREOFFICE'
        ];
    }

    private function convertirConWord($rutaDocx, $directorioSalida)
    {
        $rutaPdf = $directorioSalida . DIRECTORY_SEPARATOR .
            pathinfo($rutaDocx, PATHINFO_FILENAME) . '.pdf';
        $script = $directorioSalida . DIRECTORY_SEPARATOR . 'convertir_word.ps1';
        $contenido = <<<'POWERSHELL'
param(
    [Parameter(Mandatory=$true)][string]$Docx,
    [Parameter(Mandatory=$true)][string]$Pdf
)
$ErrorActionPreference = 'Stop'
$word = $null
$documento = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $documento = $word.Documents.Open($Docx, $false, $true)
    $documento.ExportAsFixedFormat($Pdf, 17)
}
finally {
    if ($documento -ne $null) { $documento.Close($false) }
    if ($word -ne $null) { $word.Quit() }
}
POWERSHELL;

        if (file_put_contents($script, $contenido) === false) {
            return $this->error(
                'No fue posible preparar el conversor de Microsoft Word.',
                'No se pudo crear el script temporal de PowerShell.'
            );
        }

        $windows = getenv('SystemRoot') ?: 'C:\\Windows';
        $powershell = rtrim($windows, '\\/') .
            '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $comando = escapeshellarg($powershell) .
            ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($script) .
            ' -Docx ' . escapeshellarg($rutaDocx) .
            ' -Pdf ' . escapeshellarg($rutaPdf);
        $resultado = $this->ejecutar($comando);

        if (($resultado['codigo'] ?? 1) !== 0 || !is_file($rutaPdf)) {
            return $this->error(
                'Microsoft Word no pudo convertir el oficio a PDF.',
                trim((string)($resultado['salida'] ?? ''))
            );
        }

        return [
            'ok' => true,
            'ruta_pdf' => $rutaPdf,
            'conversor' => 'MICROSOFT_WORD'
        ];
    }

    private function ejecutar($comando)
    {
        $descriptores = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $proceso = proc_open($comando, $descriptores, $pipes);

        if (!is_resource($proceso)) {
            return [
                'codigo' => 1,
                'salida' => 'proc_open no pudo iniciar el proceso.'
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($proceso);

        return [
            'codigo' => (int)$codigo,
            'salida' => trim((string)$stdout . PHP_EOL . (string)$stderr)
        ];
    }

    private function mayusculas($valor)
    {
        $valor = trim((string)$valor);

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($valor, 'UTF-8')
            : strtoupper($valor);
    }

    private function nombreArchivoSeguro($folio)
    {
        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$folio));
        $nombre = trim((string)$nombre, '_');

        return $nombre !== '' ? $nombre : 'oficio';
    }

    private function eliminarDirectorio($directorio)
    {
        if (!is_dir($directorio)) {
            return;
        }

        $elementos = scandir($directorio);

        if (!is_array($elementos)) {
            return;
        }

        foreach ($elementos as $elemento) {
            if ($elemento === '.' || $elemento === '..') {
                continue;
            }

            $ruta = $directorio . DIRECTORY_SEPARATOR . $elemento;

            if (is_dir($ruta)) {
                $this->eliminarDirectorio($ruta);
            } else {
                @unlink($ruta);
            }
        }

        @rmdir($directorio);
    }

    private function error($mensaje, $detalle)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'mensaje_tecnico' => $detalle
        ];
    }
}
