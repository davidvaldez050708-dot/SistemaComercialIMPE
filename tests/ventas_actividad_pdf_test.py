import os, pathlib, re, subprocess, tempfile
from pypdf import PdfReader

R=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as folder:
    for mode,title in [('pdf','Reporte de actividad telefónica'),('pdf_analista','Desempeño y Reconocimientos'),('pdf_desempeno','Desempeño de asesores')]:
        target=pathlib.Path(folder)/(mode+'.pdf')
        p=subprocess.run([os.environ.get('PHP_BINARY','php'),str(R/'tests/ventas_actividad_vista_fixture.php'),mode,'global',str(target)],capture_output=True)
        assert p.returncode==0 and b'Warning:' not in p.stdout and b'Warning:' not in p.stderr,p.stdout+p.stderr
        reader=PdfReader(target);text=' '.join(' '.join(x.extract_text().split()) for x in reader.pages)
        assert title in text and 'Página 1 de' in text
        if mode=='pdf':
            assert len(reader.pages)>1 and '00:19:17' in text and '00:00:13' in text and 'Duraciones pendientes: 1' in text
            assert 'Asesor de prueba sin actividad' in text and 'Información de seguimiento' in text
            assert text.count('Observación <script>no ejecutar</script> de prueba.')==89
        elif mode=='pdf_desempeno':
            assert 'Ranking del periodo' in text and 'Gráfica comparativa' in text and 'Destacados del periodo' in text
            assert 'Detalle de llamadas' not in text and 'Criterios de medición' in text
            assert 'Territorio' not in text and 'Participantes' in text
            assert '00:19:17' in text and '100.0' in text and 'Asesor de prueba sin actividad' in text
        else:
            assert 'Analista de prueba' in text and 'Llamadas válidas' in text
        for page in reader.pages: assert abs(float(page.mediabox.width)-595.28)<1
        print(mode,': contenido completo, A4, paginación y cifras OK')
