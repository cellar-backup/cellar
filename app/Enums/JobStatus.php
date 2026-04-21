<?php

namespace App\Enums;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
