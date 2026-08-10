<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults (fallback)
    |--------------------------------------------------------------------------
    |
    | Runtime values live in attendance_settings (editable via web).
    | This file is used when seeding that row or if the table is empty.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Zona horaria oficial de asistencia general
    |--------------------------------------------------------------------------
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', 'America/Mexico_City'),

    /*
    |--------------------------------------------------------------------------
    | Horario de entrada y tolerancia
    |--------------------------------------------------------------------------
    |
    | late_after = entry_time + tolerance_minutes
    | Ejemplo: 07:00 + 10 min => retardo a partir de 07:10
    |
    */
    'entry_time' => env('ATTENDANCE_ENTRY_TIME', '07:00'),

    'tolerance_minutes' => (int) env('ATTENDANCE_TOLERANCE_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Salida más temprana permitida
    |--------------------------------------------------------------------------
    */
    'exit_earliest' => env('ATTENDANCE_EXIT_EARLIEST', '13:30'),

    /*
    |--------------------------------------------------------------------------
    | Cierre de la ventana de entrada (ausencias virtuales del día)
    |--------------------------------------------------------------------------
    |
    | Antes de esta hora, un alumno sin lectura se considera "pending".
    | Después, se considera "absent" (sin persistir fila).
    |
    */
    'entry_window_closes_at' => env('ATTENDANCE_ENTRY_WINDOW_CLOSES_AT', '12:00'),

];
