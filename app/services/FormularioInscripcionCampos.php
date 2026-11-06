<?php
return [
    [
        'titulo' => 'Datos personales',
        'descripcion' => 'Captura los datos exactamente como aparecen en la constancia CURP y los documentos oficiales.',
        'icono' => 'bi-person-vcard',
        'campos' => [
            ['nombre','Nombre(s)','text','Ej. María Fernanda',80,'given-name',true],
            ['apellidos','Apellidos','text','Ej. García López',120,'family-name',true],
            ['fecha_nacimiento','Fecha de nacimiento','date','',10,'bday',true],
            ['genero','Sexo registrado en CURP','select','',1,'off',true],
            ['curp','CURP','text','18 caracteres',18,'off',true],
            ['nombre_padres','Nombre de los padres','text','Ej. SARA VAZQUEZ / CARLOS DIAZ',250,'off',true]
        ]
    ],
    [
        'titulo' => 'Datos de contacto',
        'descripcion' => 'El correo y el teléfono deben capturarse dos veces y coincidir.',
        'icono' => 'bi-telephone',
        'campos' => [
            ['correo','Correo electrónico','email','ejemplo@correo.com',190,'email',true],
            ['correo_confirmacion','Confirmar correo electrónico','email','Repite el correo',190,'off',true],
            ['movil','Número móvil','tel','10 dígitos',10,'tel-national',true],
            ['movil_confirmacion','Confirmar número móvil','tel','Repite el número',10,'off',true]
        ]
    ],
    [
        'titulo' => 'Domicilio',
        'descripcion' => 'Utiliza el domicilio que corresponde a tus documentos.',
        'icono' => 'bi-geo-alt',
        'campos' => [
            ['calle','Calle','text','Nombre completo de la calle',190,'address-line1',true],
            ['numero','Número','text','Número exterior o S/N',20,'address-line2',true],
            ['ciudad','Ciudad','text','Ciudad',120,'address-level2',true],
            ['codigo_postal','Código postal','text','5 dígitos',5,'postal-code',true],
            ['entidad_federativa','Entidad federativa','text','Estado',90,'address-level1',true],
            ['municipio','Municipio','text','Municipio o alcaldía',120,'off',true]
        ]
    ],
    [
        'titulo' => 'Información laboral y perfil',
        'descripcion' => 'Selecciona la licenciatura o ingeniería A286 para la evaluación.',
        'icono' => 'bi-mortarboard',
        'campos' => [
            ['empresa_labora','Empresa donde labora','text','Nombre de la empresa',180,'organization',true],
            ['cargo_puesto','Cargo o puesto','text','Opcional',160,'organization-title',false],
            ['perfil_evaluacion','Perfil de evaluación','select','',150,'off',true]
        ]
    ]
];
