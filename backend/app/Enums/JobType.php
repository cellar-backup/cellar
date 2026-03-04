<?php

namespace App\Enums;

enum JobType: string
{
    case Backup = 'backup';
    case Restore = 'restore';
    case Export = 'export';
    case Prune = 'prune';
    case Verify = 'verify';
}
