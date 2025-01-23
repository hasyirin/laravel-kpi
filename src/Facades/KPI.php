<?php

namespace Hasyirin\KPI\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hasyirin\KPI\KPI
 */
class KPI extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hasyirin\KPI\KPI::class;
    }
}
