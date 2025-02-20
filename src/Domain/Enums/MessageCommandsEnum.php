<?php

namespace App\Domain\Enums;

enum MessageCommandsEnum: string
{
    case Preparation = 'PREPARATION';
    case Request = 'REQUEST';
    case Interview = 'INTERVIEW';
    case Result = 'RESULT';
    case Error = 'ERROR';
}
