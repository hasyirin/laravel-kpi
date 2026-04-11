<?php

namespace Hasyirin\KPI\Tests\TestSupport\Models;

use Hasyirin\KPI\Concerns\InteractsWithMovement;
use Hasyirin\KPI\Contracts\HasMovement;
use Illuminate\Database\Eloquent\Model;

class Task extends Model implements HasMovement
{
    use InteractsWithMovement;

    protected $fillable = ['title'];
}
