<?php

namespace Hasyirin\KPI\Tests\TestSupport\Models;

class StringKeyTask extends Task
{
    protected $table = 'tasks';

    protected $keyType = 'string';
}
