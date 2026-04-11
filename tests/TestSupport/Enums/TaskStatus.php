<?php

namespace Hasyirin\KPI\Tests\TestSupport\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
