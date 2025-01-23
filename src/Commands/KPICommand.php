<?php

namespace Hasyirin\KPI\Commands;

use Illuminate\Console\Command;

class KPICommand extends Command
{
    public $signature = 'laravel-kpi';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
