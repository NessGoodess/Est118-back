<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Folio de Preinscripción - {{ $folio }}</title>
    <style>
        @page {
            margin: 20mm;
            size: letter;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.6;
            color: #2d3748;
            position: relative;
        }

        /* Marca de agua con logo */
        .watermark {
            position: fixed;
            top: 10%;
            left: 10%;
            width: 80%;
            opacity: 0.1;
            z-index: -1;
        }


        /* Container principal con borde */
        .document-container {
            border: 0px solid #1e40af;
            border-radius: 8px;
            padding: 30px 40px;
            min-height: 240mm;
        }

        /* Header con logo y título */
        .header {
            text-align: center;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .logo {
            width: 100px;
            height: 100px;
        }

        .school-name {
            font-size: 15pt;
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Sección de saludo */
        .greeting-section {
            margin: 5px 0;
            padding: 10px;
            background-color: #f8fafc;
            border-left: 2px solid #3b82f6;
        }

        .greeting-line {
            margin-bottom: 4px;
        }

        .recipient-name {
            font-weight: bold;
            color: #1e40af;
            font-size: 12pt;
            text-transform: uppercase;
        }

        .recipient-role {
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            font-size: 10pt;
        }

        /* Main message */
        .main-message {
            margin: 5px 0;
            text-align: justify;
            line-height: 1.8;
        }

        .main-message p {
            margin: 0;
        }

        .student-name {
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
        }

        /* Folio Box*/
        .folio-box {
            background-color: #eef5fe;
            border: 1px solid #2563eb;
            color: #2563eb;
            padding: 10px;
            padding-bottom: 2px;
            text-align: center;
            border-radius: 6px;
            margin: 10px 0px;
            box-shadow: 0 4px 6px rgba(30, 64, 175, 0.2);
        }

        .folio-label {
            font-size: 9pt;
            opacity: 0.9;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .folio-number {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 4px;
            margin: 8px 0;
        }

        .folio-sublabel {
            font-size: 8pt;
            opacity: 0.8;
            margin-top: 5px;
        }

        /* Sección de siguiente paso */
        .next-steps-section {
            border: none;
            border-radius: 6px;
            padding: 5px 10px;
            margin: 10px 0;
        }

        .next-steps-title {
            font-size: 13pt;
            font-weight: bold;
            color: black;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .next-steps-title::before {
            content: "➤ ";
            margin-right: 8px;
            font-size: 10pt;
        }

        .instructions {
            line-height: 1.6;
            color: #1f2937;
        }

        .highlight {
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            color: black;
        }

        /* Caja de horario */
        .schedule-box {
            background-color: #f5f5f5;
            border: 1px solid #9c9c9c;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 4px;
            text-align: center;
        }

        .schedule-box strong {
            color: #000000;
            font-size: 11pt;
        }

        /* Tabla de información */
        .info-table {
            width: 100%;
            margin: 10px 0;
            border-collapse: collapse;
        }

        .info-table tr {
            border-bottom: 1px solid #c7c7c7;
        }

        .info-table td {
            padding: 6px;
        }

        .info-table .label-col {
            font-weight: bold;
            color: #475569;
            width: 40%;
            background-color: #f8fafc;
        }

        .info-table .value-col {
            color: #1f2937;
        }

        /* Información de contacto */
        .contact-info {
            background-color: #f1f5f9;
            padding: 12px;
            border-radius: 4px;
            margin: 20px 0;
            font-size: 9pt;
            text-align: center;
            color: #475569;
        }

        .contact-info strong {
            color: #1e40af;
        }

        /* Footer */
        .footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 0px solid #e2e8f0;
            text-align: center;
        }

        .thanks {
            font-size: 11pt;
            font-style: italic;
            color: #64748b;

        }

        .institution {
            font-size: 13pt;
            font-weight: bold;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .decoration-line {
            border-top: 1px solid #9c9c9c;
        }

        /* Nota legal */
        .legal-note {
            background-color: #f8fafc;
            border-left: 3px solid #ef4444;
            padding: 8px 12px 0px 12px;
            margin: 10px 0;
            margin-bottom: 0;
            font-size: 9pt;
            color: #7f1d1d;
        }

        /* Sección de fecha */
        .generation-info {
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
            border-top: 0px solid #e2e8f0;
            padding-top: 0px;
        }


        .corner-decoration {
            position: absolute;
            width: 40px;
            height: 40px;
            border: 3px solid #3b82f6;
        }

        .corner-decoration.top-left {
            top: 15px;
            left: 15px;
            border-right: none;
            border-bottom: none;
        }

        .corner-decoration.top-right {
            top: 15px;
            right: 15px;
            border-left: none;
            border-bottom: none;
        }

        .corner-decoration.bottom-left {
            bottom: 15px;
            left: 15px;
            border-right: none;
            border-top: none;
        }

        .corner-decoration.bottom-right {
            bottom: 15px;
            right: 15px;
            border-left: none;
            border-top: none;
        }

        /* QR Code placeholder */
        .qr-section {
            text-align: center;
            margin: 20px 0;
        }

        .qr-box {
            display: inline-block;
            border: 2px solid #cbd5e1;
            padding: 8px;
            border-radius: 4px;
        }

        .qr-label {
            font-size: 8pt;
            color: #64748b;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <div class="watermark">
        <img src="{{ $logoBase64 }}" style="width:100%">
    </div>
    <div class="corner-decoration top-left"></div>
    <div class="corner-decoration top-right"></div>
    <div class="corner-decoration bottom-left"></div>
    <div class="corner-decoration bottom-right"></div>

    <div class="document-container">

        <div class="header">
            <table width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td width="80" align="center" valign="middle">
                        <img src="{{ $logoBase64 }}" width="50">

                    </td>
                    <td valign="middle">
                        <div class="school-name">
                            Escuela Secundaria Técnica Núm. 118
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="greeting-section">
            <div class="greeting-line">
                Estimado(a) <span class="recipient-name">{{ strtoupper($data->guardianName) }}</span>
            </div>
            <div class="greeting-line">
                <span class="recipient-role">Padre de Familia y/o Tutor</span>
            </div>
        </div>

        <div class="main-message">
            <p>
                Está a un paso de concluir el trámite de preinscripción, el número asignado para la preinscripción del
                aspirante es:
            </p>
        </div>

        <div class="folio-box">
            <div class="folio-label">FOLIO DE PREINSCRIPCIÓN</div>
            <div class="folio-number">{{ $folio }}</div>
            <div class="folio-sublabel">Conserve este número para futuras referencias</div>
        </div>


        <table class="info-table">
            <tr>
                <td class="label-col">Aspirante:</td>
                <td class="value-col">{{ strtoupper($data->studentName) }}</td>
            </tr>
            <tr>
                <td class="label-col">Fecha de registro:</td>
                <td class="value-col">{{ $data->createdAt }}</td>
            </tr>
            <tr>
                <td class="label-col">Estado:</td>
                <td class="value-col"><strong style="color: #f59e0b;">PENDIENTE DE PAGO</strong></td>
            </tr>
        </table>

        <div class="next-steps-section">
            <div class="next-steps-title">Siguiente Paso</div>
            <div class="instructions">
                <p style="margin-bottom: 5px;">
                    Para concluir este procedimiento deberá:
                </p>
                <ol style="margin-left: 20px; margin-bottom: 1px;">
                    <li>Presentar este documento <strong>impreso</strong> en el área de contraloría</li>
                    <li>Cubrir la cuota de recuperación de <span class="highlight">$150.00 MXN</span></li>
                </ol>
            </div>
        </div>

        <div class="schedule-box">
            <p>
                <strong>Horario de Atención en Contraloría:</strong><br>
                Lunes a Viernes de 7:15 a 9:30 hrs. y de 10:00 a 13:30 hrs.
            </p>
        </div>

        <div class="decoration-line"></div>
        <!-- Nota legal -->
        <div class="legal-note">
            <strong>IMPORTANTE:</strong> Este documento es un comprobante.
            Debe ser presentado para completar el proceso de inscripción.
            El folio tiene validez únicamente para el ciclo escolar {{ date('Y') }}-{{ date('Y') + 1 }}.
        </div>

        <div class="footer">
            <div class="thanks">Agradecemos su preferencia y confianza</div>
            <div class="institution">Escuela Secundaria Técnica Núm. 118</div>
        </div>
    </div>
</body>

</html>