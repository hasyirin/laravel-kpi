<?php

namespace Hasyirin\KPI\Events;

use Hasyirin\KPI\Models\Movement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Completed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Movement $movement) {}
}
