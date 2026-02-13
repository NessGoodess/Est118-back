<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de correo electrónico</title>

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
            max-width: 500px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header {
            display: flex;
            justify-content: space-evenly;
            align-items: center;
            text-align: center;
            border-bottom: 2px solid #2d469b;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .header .logo {
            max-width: 70px;
        }

        .header .title {
            width: 100%;
            text-align: center;
        }

        .header h1 {
            color: #2d469b;
            margin: 0;
            font-size: 22px;
        }

        .content {
            margin: 20px 0;
        }

        .action {
            text-align: center;
            margin: 30px 0;
        }

        .action a {
            background-color: #2d469b;
            color: #ffffff;
            padding: 12px 22px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            display: inline-block;
        }

        .action a:hover {
            background-color: #000000;
        }

        .action a:active {
            background-color: #ffffff;
            color: #2d469b;
        }

        .warning {
            background-color: rgba(243, 225, 164, 0.1);
            border: 1px solid #ffd145;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 14px;
        }

        .warning strong {
            color: #856404;
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
    </style>
</head>

<body>
    <div class="container">

        <div class="header">
            <!--<div class="logo">
                <img src="cid:logo_est118" alt="logo_est118" width="70" height="auto">
            </div>-->
            <div class="title">
                <h1>{{ $title }}</h1>
                <p>{{ $school_name }}</p>
            </div>
        </div>

        <div class="content">
            <p>
                {{ $intro }}
            </p>

            <p>
                {{ $content }}
            </p>

            <div class="action">
                <a href="{{ $url }}">
                    {{ $action }}
                </a>
            </div>

            <div class="warning">
                <strong>{{ $title_warning }}</strong><br>
                {{ $warning }}
            </div>
        </div>

        <div class="footer">
            <p>
                {{ $footer }}
            </p>
            <p>
                &copy; {{ date('Y') }} {{ $school_name }}
            </p>
        </div>

    </div>
</body>

</html>
