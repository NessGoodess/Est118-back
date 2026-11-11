<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-student warning threshold
    |--------------------------------------------------------------------------
    |
    | When linking or merging a tutor that already has more linked students than
    | this value, the API requires an explicit confirm_multi_student flag and
    | exposes a printable PDF of the tutor's current linkages.
    | Default 2 means the warning starts at 3 linked students.
    |
    */
    'multi_student_warn_threshold' => (int) env('GUARDIAN_MULTI_STUDENT_WARN_THRESHOLD', 2),

];
