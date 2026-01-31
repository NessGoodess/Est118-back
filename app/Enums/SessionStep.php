<?php

namespace App\Enums;

enum SessionStep: string
{
    case START = 'start';
    case ENTER_CURP = 'enter_curp';
    case CONFIRM = 'confirm';
    case COMPLETED = 'completed';
}
