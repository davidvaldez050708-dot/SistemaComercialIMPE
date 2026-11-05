<?php
/** Oficio DOCX terminado: copia temporal + conversión Word, sin marcadores ni REDMEX. */
final class OficioDocumentoFinalPdfService
{
    public function convertir($rutaDocx)
    {
        $root = dirname(__DIR__, 2);
        $allowed = realpath($root . '/storage/templates/oficios_personalizados');
        $source = realpath((string)$rutaDocx);
        if (!$allowed || !$source || !is_file($source) ||
            strpos($source, $allowed . DIRECTORY_SEPARATOR) !== 0 ||
            !preg_match('/^generacion_\d{8}_\d{6}_[a-f0-9]{12}\.docx$/D', basename($source))) {
            return $this->fail('No se encontró el Word terminado.', 'WORD_FINAL_SOURCE');
        }
        $custom = trim((string)getenv('IMPE_OFICIO_FINAL_LIBREOFFICE'));
        $choices = $custom !== '' ? [$custom] :
            ['/usr/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/soffice'];
        $office = null;
        foreach ($choices as $bin) {
            if (is_file($bin) && is_executable($bin) && !is_link($bin)) { $office = $bin; break; }
        }
        if (!$office || !function_exists('proc_open')) {
            return $this->fail(
                'El Word se conservó, pero Hostinger no tiene habilitado un motor Word a PDF. No se utilizará el formato REDMEX ni una conversión que omita imágenes.',
                'WORD_FINAL_ENGINE_MISSING'
            );
        }
        $base = $root . '/storage/tmp/oficios-final';
        if ((!is_dir($base) && !@mkdir($base, 0700, true)) || !is_writable($base)) {
            return $this->fail('No fue posible preparar el convertidor.', 'WORD_FINAL_TEMP');
        }
        $tmp = $base . '/convert_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700)) {
            return $this->fail('No fue posible preparar el convertidor.', 'WORD_FINAL_TEMP');
        }
        try {
            $target = $tmp . '/' . pathinfo($source, PATHINFO_FILENAME) . '.pdf';
            $command = [$office, '-env:UserInstallation=file://' . $tmp . '/profile',
                '--headless', '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $tmp, $source];
            $pipes = [];
            $process = @proc_open($command,
                [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $tmp);
            if (!is_resource($process)) {
                return $this->fail('El motor de conversión no pudo iniciar.', 'WORD_FINAL_START');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $start = microtime(true);
            $exit = -1;
            do {
                stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = (int)$status['exitcode']; break; }
                if (microtime(true) - $start > 45) {
                    proc_terminate($process); $exit = 124; break;
                }
                usleep(100000);
            } while (true);
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit === -1) { $exit = $closed; }
            if ($exit !== 0 || !is_file($target) || filesize($target) < 1000 ||
                filesize($target) > 35*1024*1024) {
                return $this->fail('No se pudo convertir el Word conservando su diseño.', 'WORD_FINAL_CONVERSION');
            }
            $pdf = file_get_contents($target);
            if (!is_string($pdf) || strncmp($pdf,'%PDF-',5) !== 0 ||
                preg_match('/%%EOF\s*$/D',substr($pdf,-1024)) !== 1) {
                return $this->fail('El convertidor devolvió un PDF inválido.', 'WORD_FINAL_PDF');
            }
            return ['ok'=>true, 'contenido_pdf'=>$pdf, 'conversor'=>'LIBREOFFICE_WORD_FINAL'];
        } catch (Throwable $error) {
            return $this->fail('Falló la conversión del Word.', 'WORD_FINAL_EXCEPTION');
        } finally {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir() && !$item->isLink()) { @rmdir($item->getPathname()); }
                else { @unlink($item->getPathname()); }
            }
            @rmdir($tmp);
        }
    }
    private function fail($message, $code)
    {
        return ['ok'=>false, 'mensaje'=>$message, 'mensaje_tecnico'=>$code];
    }
}
