<?php

namespace Hasyirin\KPI;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Hasyirin\KPI\Commands\KPICommand;

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
            ->hasMigration('create_laravel_kpi_table')
            ->hasCommand(KPICommand::class);
    }
}