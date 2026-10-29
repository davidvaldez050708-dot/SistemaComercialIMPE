"""Recursos públicos de la misma tipografía e iconos del sistema, sólo para QA local."""
import pathlib,re,urllib.request
def cargar_recursos(project):
    assets=pathlib.Path(project)/'assets'
    css=urllib.request.urlopen('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap',timeout=30).read().decode()
    for i,url in enumerate(dict.fromkeys(re.findall(r'url\((https://[^)]+)\)',css))):
        name='manrope-'+str(i)+'.woff2';(assets/name).write_bytes(urllib.request.urlopen(url,timeout=30).read());css=css.replace(url,name)
    (assets/'manrope.css').write_text(css,encoding='utf8')
    base='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/'
    icons=urllib.request.urlopen(base+'bootstrap-icons.css',timeout=30).read().decode()
    icons=re.sub(r'\./fonts/bootstrap-icons\.woff2[^"\)\s]*','bootstrap-icons.woff2',icons)
    (assets/'bootstrap-icons.css').write_text(icons,encoding='utf8')
    (assets/'bootstrap-icons.woff2').write_bytes(urllib.request.urlopen(base+'fonts/bootstrap-icons.woff2',timeout=30).read())
