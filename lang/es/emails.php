<?php

return [
    'verify' => [
        'subject' => 'Verificación de Correo Electrónico',
        'title' => 'Verificación de Correo Electrónico',
        'intro' => 'Se ha creado una cuenta con esta dirección de correo electrónico en el sistema institucional',
        'content' => 'Para activar su cuenta y continuar con el acceso al sistema, es necesario verificar su dirección de correo electrónico.',
        'action' => 'Verificar Correo Electrónico',
        'alt' => 'Si el botón no funciona, copie y pegue el siguiente enlace en su navegador:',
        'title-warning' => 'Importante:',
        'warning' => 'Si usted no solicitó este registro o no reconoce esta acción, puede ignorar este mensaje. El enlace tiene una vigencia limitada.',
        'footer' => 'Este es un correo automático, por favor no responda a este mensaje.',
    ],

    'pre_enrollment' => [
        'subject' => '¡Preinscripción Registrada!',
        'header' => [
            'title' => '¡Preinscripción Registrada!',
            'school' => 'Escuela Secundaria Técnica No. 118',
        ],
        'greeting' => 'Estimado(a) :name,',
        'intro' => 'Le confirmamos que hemos recibido exitosamente la solicitud de preinscripción para:',
        'student_data' => [
            'title' => 'Datos del Estudiante',
            'name' => 'Nombre',
            'curp' => 'CURP',
        ],
        'folio' => [
            'label' => 'Su número de folio es:',
        ],
        'warning' => [
            'title' => 'Importante:',
            'content' => 'Guarde este folio, ya que lo necesitará para dar seguimiento a su solicitud y completar el proceso de inscripción.',
        ],
        'next_steps' => [
            'title' => 'Próximos Pasos',
            'steps' => [
                'Conserve el comprobante PDF adjunto a este correo',
                'Espere la confirmación de aceptación por parte de la escuela',
                'Una vez aceptado, complete el proceso de inscripción presentando la documentación requerida',
            ],
        ],
        'footer' => [
            'auto' => 'Este es un correo automático, por favor no responda a este mensaje.',
            'contact' => 'Si tiene alguna duda, comuníquese a nuestras oficinas.',
        ],
    ],
];
