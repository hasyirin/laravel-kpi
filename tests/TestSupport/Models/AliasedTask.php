<?php

namespace Hasyirin\KPI\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;

class AliasedTask extends Model
{
    protected $table = 'aliased_tasks';

    protected $primaryKey = 'uuid';

    protected $fillable = ['title'];
}
