<?php

namespace Hasyirin\KPI\Tests\TestSupport\Enums;

enum TaskStage: int
{
    case Draft = 1;
    case Review = 2;
    case OnHold = 3;
}
