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
     'created_success' => 'Pre-enrollment application received successfully',

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
        'already_converted' => 'This pre-enrollment has already been converted to a student.',
        'pre_enrollment_not_found' => 'The pre-enrollment no longer exists.',
        'rejected_application' => 'A rejected pre-enrollment cannot be enrolled.',
        'review_required' => 'Accept the application first to move it to “In process”.',
        'documents_incomplete' => 'Documents must be marked as complete.',
        'payment_not_validated' => 'Payment must be validated before enrollment can be completed.',
        'data_incomplete' => 'Applicant is missing required data (name, CURP, or previous school).',
        'exam_required' => 'An admission exam score is required before enrollment.',
        'curp_required' => 'The applicant does not have a captured CURP.',
        'student_profile_exists' => 'A student is already registered with the provided CURP.',
        'no_academic_year' => 'There is no active or selected academic year.',
        'academic_year_not_found' => 'The selected academic year does not exist.',
        'late_intake_disabled' => 'Late admissions are disabled in the intake policy.',
        'late_group_required' => 'For late admission you must choose the group manually.',
        'class_group_not_found' => 'The selected class group does not exist.',
        'group_not_in_academic_year' => 'The selected group does not belong to the indicated academic year.',
        'first_grade_not_found' => 'Grade «1°» was not found in the catalog.',
        'default_group_not_found' => 'There is no provisional 1° group for this academic year. Create the groups or send class_group_id.',
        'already_enrolled' => 'The student already has an active enrollment in this academic year.',
        'conversion_state_incomplete' => 'The pre-enrollment is marked as converted, but its original student or enrollment could not be found.',
        'conversion_conflict' => 'The conversion conflicted with a record created concurrently. Refresh the application and try again.',
        'persistence_error' => 'The enrollment could not be saved. Please try again.',
        'conversion_failed' => 'An unexpected error occurred while creating the enrollment.',
        'converted_success' => 'Student enrolled successfully.',
        'replayed_success' => 'The enrollment already existed; the original result was returned.',
    ],

];
