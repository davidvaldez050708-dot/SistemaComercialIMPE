"""Transformaciones acotadas para las variantes auditadas de Registro en Hostinger."""
def once(text, old, new):
    if text.count(old) != 1:
        raise ValueError('Marcador de Registro ambiguo o ausente: '+old[:70])
    return text.replace(old,new,1)

def controller(text):
    text=once(text,"require_once __DIR__ . '/../models/FormularioRegistroModel.php';", "require_once __DIR__ . '/../models/FormularioRegistroModel.php';\nrequire_once __DIR__ . '/../helpers/HorarioContacto.php';")
    start=text.index('public function guardarRegistro()'); end=text.index('public function ',start+20)
    action=text[start:end]
    action=once(action, "$datos = $this->normalizarRegistro($_POST);", """$datos = $this->normalizarRegistro($_POST);
        try {
            $datos['horario_contacto'] = HorarioContacto::desdeEntrada($_POST);
        } catch (DomainException $error) {
            $this->responderJson(['ok'=>false, 'mensaje'=>$error->getMessage()], 422);
        }""")
    return text[:start]+action+text[end:]

def model(text):
    text=once(text,"require_once __DIR__ . '/../helpers/ProgramaEducativo.php';", "require_once __DIR__ . '/../helpers/ProgramaEducativo.php';\nrequire_once __DIR__ . '/../helpers/HorarioContacto.php';")
    text=once(text,'        $sql = "INSERT INTO {$tabla} (',"""        $horario = $esInscripcion ? null : HorarioContacto::paraGuardar($datos['horario_contacto'] ?? null);
        $conHorario = !$esInscripcion && $this->connection->query("SHOW COLUMNS FROM formulario_registros WHERE Field='horario_contacto'")->num_rows > 0;
        if ($horario !== null && !$conHorario) {
            throw new DomainException('El horario de contacto aún no está disponible. Intenta nuevamente más tarde.');
        }
        $columnaHorario = $conHorario ? 'horario_contacto,' : '';
        $valorHorario = $conHorario ? '?,' : '';
        $argumentosHorario = $conHorario ? [$horario] : [];

        $sql = "INSERT INTO {$tabla} (""")
    text=once(text,'                    origen,\n                    created_at,','                    origen,\n                    {$columnaHorario}\n                    created_at,')
    # El bind de ubicación de PR114 conserva sus tres parámetros adicionales.
    text=once(text,'?, ?, NOW(), NOW())";', '?, ?, {$valorHorario} NOW(), NOW())";')
    for types in ['ssssssssssiiis','ssssssssssiisssis']:
        if "            '"+types+"'," in text:
            text=once(text,"            '"+types+"',", "            '"+types+"' . ($conHorario ? 's' : ''),")
            break
    else: raise ValueError('Bind no auditado')
    return once(text,'            $origen\n        );','            $origen,\n            ...$argumentosHorario\n        );')

def view(text, public):
    if public:
        text=once(text,'</head>',"    <link rel=\"stylesheet\" href=\"<?= BASE_URL ?>public/css/horario_contacto.css?v=<?= filemtime(ROOT_PATH . '/public/css/horario_contacto.css') ?>\">\n</head>")
        marker='''                                Los correos electrónicos deben coincidir.
                            </div>
                        </div>'''
        text=once(text,marker,marker+"\n                        <?php $horarioContactoPublico=true; require __DIR__ . '/partials/horario_contacto.php'; ?>")
        return once(text,'</body>',"    <script src=\"<?= BASE_URL ?>public/javascript/horario_contacto.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/horario_contacto.js') ?>\"></script>\n</body>")
    marker='''                            Los correos electrónicos no coinciden.
                        </div>
                    </div>'''
    text=once(text,marker,marker+"\n                    <?php $horarioContactoPublico=false; require __DIR__ . '/partials/horario_contacto.php'; ?>")
    return text+"\n<link rel=\"stylesheet\" href=\"<?= BASE_URL ?>public/css/horario_contacto.css?v=<?= filemtime(ROOT_PATH . '/public/css/horario_contacto.css') ?>\">\n<script src=\"<?= BASE_URL ?>public/javascript/horario_contacto.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/horario_contacto.js') ?>\"></script>\n"
