<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Reporte de vínculos del tutor</title>
    <style>
        @page { margin: 16mm; size: letter; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1a202c; }
        h1 { font-size: 14pt; margin: 0 0 4px; }
        h2 { font-size: 11pt; margin: 18px 0 6px; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; }
        .muted { color: #4a5568; font-size: 9pt; }
        .meta { margin-bottom: 12px; }
        .meta p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #cbd5e0; padding: 5px 6px; text-align: left; vertical-align: top; font-size: 9pt; }
        th { background: #edf2f7; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; background: #e2e8f0; font-size: 8pt; }
        .empty { color: #718096; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $school }}</h1>
    <p class="muted">Reporte de vínculos del tutor · generado {{ $generatedAt }}</p>

    <div class="meta">
        <p><strong>{{ $tutor['name'] ?? '—' }}</strong></p>
        <p>CURP: {{ $tutor['curp'] ?? '—' }}</p>
        <p>Tel: {{ $tutor['phone'] ?? '—' }} · Correo: {{ $tutor['email'] ?? '—' }}</p>
        <p>Alumnos ligados: {{ $tutor['linked_count'] ?? 0 }}
            (umbral de aviso: {{ $tutor['threshold'] ?? '—' }})</p>
    </div>

    @php
        $kindLabel = [
            'progenitor' => 'Progenitor',
            'pariente' => 'Pariente',
            'otro' => 'Otro',
        ];
        $renderTable = function (array $rows) use ($kindLabel) {
            if (count($rows) === 0) {
                echo '<p class="empty">Ninguno</p>';
                return;
            }
            echo '<table><thead><tr>';
            echo '<th>Alumno</th><th>CURP</th><th>Grado/grupo</th><th>Parentesco</th><th>Clasificación</th><th>Otros tutores</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $others = collect($row['other_guardians'] ?? [])
                    ->map(function ($g) {
                        $rel = $g['relationship'] ?: '—';
                        return ($g['name'] ?? '').' ('.$rel.', '.$g['role'].')';
                    })
                    ->implode('; ');
                echo '<tr>';
                echo '<td>'.e($row['name'] ?? '').'</td>';
                echo '<td>'.e($row['curp'] ?? '—').'</td>';
                echo '<td>'.e($row['grade_group'] ?: '—').'</td>';
                echo '<td>'.e($row['relationship'] ?: '—').'</td>';
                echo '<td>'.e($kindLabel[$row['relationship_kind'] ?? 'otro'] ?? 'Otro').'</td>';
                echo '<td>'.e($others !== '' ? $others : '—').'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        };
    @endphp

    <h2>Tutor principal de</h2>
    {!! $renderTable($primary) !!}

    <h2>Tutor secundario de</h2>
    {!! $renderTable($secondary) !!}

    <h2>Solo ligado (ni principal ni secundario)</h2>
    {!! $renderTable($linked) !!}

    <p class="muted" style="margin-top: 18px;">
        Clasificación: Madre/Padre = Progenitor; abuelos/tíos/primos/hermanos = Pariente;
        Otro o texto libre (p. ej. tutor contratado) = Otro.
    </p>
</body>
</html>
