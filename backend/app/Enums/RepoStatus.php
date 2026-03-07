<?php

namespace App\Enums;

enum RepoStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Degraded = 'degraded';
    case Unknown = 'unknown';
}
