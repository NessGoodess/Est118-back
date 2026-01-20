<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmación de Preinscripción</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #1a5f7a;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #1a5f7a;
            margin: 0;
            font-size: 24px;
        }

        .folio-badge {
            background-color: #1a5f7a;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            display: inline-block;
            margin: 15px 0;
            font-weight: bold;
            font-size: 16px;
        }

        .content {
            margin: 20px 0;
        }

        .info-section {
            background-color: #f8f9fa;
            border-left: 4px solid #1a5f7a;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
        }

        .info-section h3 {
            margin: 0 0 10px 0;
            color: #1a5f7a;
        }

        .next-steps {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .next-steps h3 {
            color: #1a5f7a;
            margin-top: 0;
        }

        .next-steps ol {
            margin: 10px 0;
            padding-left: 20px;
        }

        .next-steps li {
            margin: 8px 0;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 14px;
        }

        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }

        .warning strong {
            color: #856404;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>¡Preinscripción Registrada!</h1>
            <p>Escuela Secundaria Técnica No. 118</p>
        </div>

        <div class="content">
            <p>Estimado(a) <strong>{{ $preEnrollment->guardian_first_name }} {{ $preEnrollment->guardian_last_name }}</strong>,</p>

            <p>Le confirmamos que hemos recibido exitosamente la solicitud de preinscripción para:</p>

            <div class="info-section">
                <h3>Datos del Estudiante</h3>
                <p><strong>Nombre:</strong> {{ $preEnrollment->first_name }} {{ $preEnrollment->last_name }} {{ $preEnrollment->second_last_name }}</p>
                <p><strong>CURP:</strong> {{ $preEnrollment->curp }}</p>
            </div>

            <div style="text-align: center;">
                <p>Su número de folio es:</p>
                <div class="folio-badge">{{ $preEnrollment->folio }}</div>
            </div>

            <div class="warning">
                <strong>Importante:</strong> Guarde este folio, ya que lo necesitará para dar seguimiento a su solicitud y completar el proceso de inscripción.
            </div>

            <div class="next-steps">
                <h3>Próximos Pasos</h3>
                <ol>
                    <li>Conserve el comprobante PDF adjunto a este correo</li>
                    <li>Espere la confirmación de aceptación por parte de la escuela</li>
                    <li>Una vez aceptado, complete el proceso de inscripción presentando la documentación requerida</li>
                </ol>
            </div>
        </div>

        <div class="footer">
            <p>Este es un correo automático, por favor no responda a este mensaje.</p>
            <p>Si tiene alguna duda, comuníquese a nuestras oficinas.</p>
            <p>&copy; {{ date('Y') }} Escuela Secundaria Técnica No. 118</p>
        </div>
    </div>
</body>

</html>