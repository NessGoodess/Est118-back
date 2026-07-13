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
     'created_success' => 'Preinscripción creada exitosamente',

     'no_active_cycle' => 'No hay un ciclo activo',

     /**
      * cambiar por:
      *
      * 'already_active' => 'La temporada ya se encuentra activa.',
      * 'active_season_exists' => 'Ya existe una temporada activa.',
      * 'not_available' => 'Las preinscripciones no están disponibles.',
      * 'cannot_activate_closed' => 'No se puede activar una temporada cerrada.',
      * 'already_closed' => 'La temporada ya está cerrada.',
      * 'starts_at' => 'Las preinscripciones iniciarán el :date.',
      * 'ended' => 'Las preinscripciones han finalizado.',
      * 'not_started' => 'Las preinscripciones aún no han iniciado.',
      * 'closed_successfully' => 'La temporada fue cerrada correctamente.',
      * 'activated_successfully' => 'La temporada fue activada correctamente.',
      * 'only_closed_can_be_reopened' => 'Solo se puede reabrir una temporada cerrada.',
      * 'reopened_successfully' => 'La temporada fue reabierta correctamente.',
      * 'cannot_delete' => 'Solo se pueden eliminar ciclos en borrador que nunca hayan sido activados.',
      * 'deleted_successfully' => 'El ciclo fue eliminado correctamente.',
      * 'processing_error' => 'Ocurrió un error al procesar la preinscripción. Intente nuevamente más tarde o comuníquese con la institución.',
      * 'created_successfully' => 'La preinscripción fue creada correctamente.',
      * 'no_active_cycle' => 'No hay un ciclo escolar activo.',
      */

     'to_student' => [
        'already_converted' => 'Esta preinscripción ya fue convertida en estudiante.',
        'documents_incomplete' => 'Los documentos deben estar marcados como completos.',
        'payment_not_validated' => 'El pago debe estar validado antes de completar la inscripción.',
        'rejected_application' => 'No se puede inscribir una preinscripción rechazada.',
        'student_profile_exists' => 'Ya existe un alumno registrado con el CURP proporcionado.',
        'no_academic_year' => 'No hay un ciclo escolar activo o seleccionado.',
        'group_not_in_academic_year' => 'El grupo seleccionado no pertenece al ciclo escolar indicado.',
        'first_grade_not_found' => 'No se encontró el grado «1°» en el catálogo.',
        'default_group_not_found' => 'No existe un grupo provisional de 1° para este ciclo escolar. Cree los grupos o envíe class_group_id.',
        'already_enrolled' => 'El estudiante ya cuenta con una matrícula activa en este ciclo escolar.',
    ],

];
