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
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #2d469b;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #2d469b;
            margin: 0;
            font-size: 24px;
        }

        .folio-badge {
            background-color: #2d469b;
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
            border-left: 4px solid #2d469b;
            padding: 15px;
            margin: 15px 0;
            border-radius: 0 5px 5px 0;
        }

        .info-section h3 {
            margin: 0 0 10px 0;
            color: #2d469b;
        }

        .next-steps {
            background-color: rgba(136, 188, 255, 0.226);
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .next-steps h3 {
            color: #2d469b;
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
            background-color: rgba(231, 231, 231, 0.26);
            text-align: center;
            margin-top: 30px;
            padding-top: 10px;
            padding-bottom: 10px;
            border-top: 1px solid #ddd;
            color: #666;
            font-size: 11px;
        }

        .warning {
            background-color: rgba(243, 225, 164, 0.1);
            border: 1px solid #ffd453;
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
            <h1>{{ __('emails.pre_enrollment.header.title') }}</h1>
            <p>{{ __('emails.pre_enrollment.header.school') }}</p>
        </div>

        <div class="content">
            <p>{{ __('emails.pre_enrollment.greeting', ['name' => $preEnrollment->guardian_first_name . ' ' . $preEnrollment->guardian_last_name]) }}</p>

            <p>{{ __('emails.pre_enrollment.intro') }}</p>

            <div class="info-section">
                <h3>{{ __('emails.pre_enrollment.student_data.title') }}</h3>
                <p><strong>{{ __('emails.pre_enrollment.student_data.name') }}:</strong> {{ $preEnrollment->first_name }} {{ $preEnrollment->last_name }} {{ $preEnrollment->second_last_name }}</p>
                <p><strong>{{ __('emails.pre_enrollment.student_data.curp') }}:</strong> {{ $preEnrollment->curp }}</p>
            </div>

            <div style="text-align: center;">
                <p>{{ __('emails.pre_enrollment.folio.label') }}</p>
                <div class="folio-badge">{{ $preEnrollment->folio }}</div>
            </div>

            <div class="warning">
                <strong>{{ __('emails.pre_enrollment.warning.title') }}</strong> {{ __('emails.pre_enrollment.warning.content') }}
            </div>

            <div class="next-steps">
                <h3>{{ __('emails.pre_enrollment.next_steps.title') }}</h3>
                <ol>
                    <li>{{ __('emails.pre_enrollment.next_steps.steps.0') }}</li>
                    <li>{{ __('emails.pre_enrollment.next_steps.steps.1') }}</li>
                    <li>{{ __('emails.pre_enrollment.next_steps.steps.2') }}</li>
                </ol>
            </div>
        </div>

        <div class="footer">
            <p>{{ __('emails.pre_enrollment.footer.auto') }}</p>
            <p>{{ __('emails.pre_enrollment.footer.contact') }}</p>
            <p>&copy; {{ date('Y') }} {{ __('emails.pre_enrollment.header.school') }} </p>
        </div>
    </div>
</body>

</html>