<?php

namespace Hasyirin\KPI\Tests\TestSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletingTask extends Model
{
    use SoftDeletes;

    protected $fillable = ['title'];
}
