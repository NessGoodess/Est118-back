<?php

/**
 * Messages for the admission settings
 */

return [
    'active' => 'La temporada ya está activa',
    'exist' => 'Ya existe una temporada activa',
    'not_available' => 'Las preinscripciones no están disponibles',
    
    'dont_active' => 'No se puede activar una temporada cerrada',
    'dont_close' => 'La temporada ya está cerrada',
    
    'start_date' => 'Las preinscripciones inician el :date',
    'end_date' => 'Las preinscripciones han finalizado',
    
    'closed_success' => 'Temporada cerrada correctamente',
    'active_success' => 'Temporada activada correctamente',

    'not_started' => 'Las preinscripciones aún no han iniciado',
     'ended' => 'Las preinscripciones han finalizado',

     'only_closed_can_be_reopened' => 'Solo se puede reabrir una temporada cerrada',
     'reopened_success' => 'Temporada reabierta correctamente',

     'not_deleted' => 'Solo se pueden eliminar ciclos en borrador que nunca han sido activados',
     'deleted_success' => 'Ciclo eliminado correctamente',

     'error_processing' => 'Ocurrió un error al procesar su preinscripción. Intente nuevamente más tarde o porfavor comuníquese con la institución.',
     'created_success' => 'Solicitud de preinscripción recibida correctamente',

     'no_active_cycle' => 'No hay un ciclo activo',

     'to_student' => [
        'already_converted' => 'Esta preinscripción ya fue convertida en estudiante.',
        'pre_enrollment_not_found' => 'La preinscripción ya no existe.',
        'rejected_application' => 'No se puede inscribir una preinscripción rechazada.',
        'review_required' => 'Primero acepta la solicitud para moverla a «En proceso».',
        'documents_incomplete' => 'Los documentos deben estar marcados como completos.',
        'payment_not_validated' => 'El pago debe estar validado antes de completar la inscripción.',
        'data_incomplete' => 'Faltan datos mínimos del aspirante (nombre, CURP o escuela de procedencia).',
        'exam_required' => 'Se requiere calificación de examen de admisión antes de inscribir.',
        'curp_required' => 'El aspirante no tiene CURP capturada.',
        'student_profile_exists' => 'Ya existe un alumno registrado con el CURP proporcionado.',
        'no_academic_year' => 'No hay un ciclo escolar activo o seleccionado.',
        'academic_year_not_found' => 'El ciclo escolar seleccionado no existe.',
        'late_intake_disabled' => 'Los ingresos tardíos están deshabilitados en la política de admisión.',
        'late_group_required' => 'En ingreso tardío debes elegir el grupo manualmente.',
        'class_group_not_found' => 'El grupo seleccionado no existe.',
        'group_not_in_academic_year' => 'El grupo seleccionado no pertenece al ciclo escolar indicado.',
        'first_grade_not_found' => 'No se encontró el grado «1°» en el catálogo.',
        'default_group_not_found' => 'No existe un grupo provisional de 1° para este ciclo escolar. Cree los grupos o envíe class_group_id.',
        'already_enrolled' => 'El estudiante ya cuenta con una matrícula activa en este ciclo escolar.',
        'conversion_state_incomplete' => 'La preinscripción figura como inscrita, pero no se encontró el alumno o la matrícula original.',
        'conversion_conflict' => 'La inscripción entró en conflicto con un registro creado simultáneamente. Vuelve a consultar la solicitud.',
        'persistence_error' => 'No se pudo guardar la inscripción. Intenta nuevamente.',
        'conversion_failed' => 'Ocurrió un error inesperado al crear la inscripción.',
        'converted_success' => 'Estudiante inscrito correctamente.',
        'replayed_success' => 'La inscripción ya existía; se devolvió el resultado original.',
     ],
];
