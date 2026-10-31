<?php

namespace Hasyirin\KPI\Events;

use Hasyirin\KPI\Models\Movement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Passed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Movement $current, public ?Movement $previous = null) {}
}
