<?php

namespace Hasyirin\KPI;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class KPIServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-kpi')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations([
                'create_laravel_kpi_table',
            ]);
    }
}
