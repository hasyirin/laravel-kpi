<?php

namespace Hasyirin\KPI;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class KPIServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-kpi')
            ->hasConfigFile()
            ->hasMigrations([
                'create_movements_table',
                'create_holidays_table',
                'add_parent_child_to_movements_table',
            ]);
    }
}
