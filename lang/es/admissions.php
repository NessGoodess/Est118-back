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

     'ToStudent' => [
        "already_student" => 'Esta preinscripción ya ha sido convertida a estudiante',
        "documents_not_ready" => 'Los docuemntos deben estar marcados como completos',
        "payment_not_validated" => 'El pago debe estar validado antes de inscribir',
        "rejected" => 'No se puede inscribir una preinscripción rechazada',
        "profile_exists" => 'Ya existe una persona/alumno registrado con el CURP del aspirante',
        "no_academic_year" => 'No hay un ciclo escolar seleccionado o activo',
        "group_not_in_academic_year" => 'El grupo no pertenece al ciclo escolar indicado',
        "no_catalog_1_grade" => 'No se encontró el grado «1°» en el catálogo.',
        "no_group_1_grade" => 'No existe un grupo provisional de 1° para este ciclo. Crea los grupos o envía class_group_id.',
        "already_enrolled" => 'El estudiante ya tiene matricula activa en ese ciclo.',
     ]
];
