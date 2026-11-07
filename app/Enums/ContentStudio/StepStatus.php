<?php

namespace App\Enums\ContentStudio;

enum StepStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
