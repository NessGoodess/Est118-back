<?php

namespace App\Enums;

enum TeacherStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ON_LEAVE = 'on_leave';
    case RETIRED = 'retired';
    case SUSPENDED = 'suspended';
    case SUBSTITUTE = 'substitute';
    case TRAINING = 'training';
    case PENDING_DOCUMENTS = 'pending_documents';
}
