<?php

namespace App\Enums;

enum SessionStep: string
{
    case START = 'start';
    case SELECT_GRADE = 'select_grade';
    case SELECT_GROUP = 'select_group';
    case ENTER_CURP = 'enter_curp';
    case CONFIRM = 'confirm';
    case COMPLETED = 'completed';
}
