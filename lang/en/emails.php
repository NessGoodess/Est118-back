<?php

return [
    'verify' => [
        'subject' => 'Email verification',
        'title'   => 'Email verification',
        'intro'   => 'An account has been created with this email address in the institutional system.',
        'content' => 'To activate your account and continue with access to the system, it is necessary to verify your email address.',
        'action'  => 'Verify email',
        'alt'     => 'If the button does not work, copy and paste this URL into your browser:',
        'title-warning' => 'Important:',
        'warning' => 'If you did not request this registration or do not recognize this action, you can ignore this message. The link has a limited validity period.',
        'footer'  => 'This is an automated email, please do not reply.',
    ],

    'pre_enrollment' => [
        'subject' => 'Pre-enrollment Registered!',
        'header' => [
            'title' => 'Pre-enrollment Registered!',
            'school' => 'Technical Secondary School No. 118',
        ],
        'greeting' => 'Dear :name,',
        'intro' => 'We confirm that we have successfully received the pre-enrollment application for:',
        'student_data' => [
            'title' => 'Student Information',
            'name' => 'Name',
            'curp' => 'CURP',
        ],
        'folio' => [
            'label' => 'Your application folio number is:',
        ],
        'warning' => [
            'title' => 'Important:',
            'content' => 'Please keep this folio number, as you will need it to track your application and complete the enrollment process.',
        ],
        'next_steps' => [
            'title' => 'Next Steps',
            'steps' => [
                'Keep the PDF receipt attached to this email',
                'Wait for the school’s acceptance confirmation',
                'Once accepted, complete the enrollment process by submitting the required documentation',
            ],
        ],
        'footer' => [
            'auto' => 'This is an automated email, please do not reply to this message.',
            'contact' => 'If you have any questions, please contact our offices.',
        ],
    ],
];
