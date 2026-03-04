<?php

namespace App\Enums;

enum JobType: string
{
    case Backup = 'backup';
    case Restore = 'restore';
    case Prune = 'prune';
    case Verify = 'verify';
}
