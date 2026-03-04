<?php

namespace App\Enums;

enum EngineType: string
{
    case Borg = 'borg';
    case Restic = 'restic';
}
