<?php

namespace Hasyirin\KPI\Events;

use Hasyirin\KPI\Models\Movement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Passed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Movement $current, public ?Movement $previous = null) {}
}
