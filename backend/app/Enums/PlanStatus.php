<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';
    case Running = 'running';
    case Idle = 'idle';
}
