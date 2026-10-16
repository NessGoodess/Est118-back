<?php

namespace App\Enums;

enum PersonPhotoKind: string
{
    case Teachers = 'teachers';
    case Staff = 'staff';
    case Guardians = 'guardians';
}
