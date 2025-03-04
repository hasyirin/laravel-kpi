<?php

use Hasyirin\KPI\Models\Movement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('laravel_kpi_table', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Movement::class, 'parent_id')
                ->index()
                ->nullable()
                ->constrained((new Movement)->getTable())
                ->cascadeOnDelete();

            $table->foreignIdFor(Movement::class, 'previous_id')
                ->index()
                ->nullable()
                ->constrained((new Movement)->getTable())
                ->cascadeOnDelete();

            $table->morphs('movable');

            $table->nullableMorphs('sender');
            $table->nullableMorphs('receiver');

            $table->string('status');

            $table->decimal('period', places: 4)->unsigned()->nullable();
            $table->decimal('hours', places: 4)->unsigned()->nullable();

            $table->text('notes')->nullable();
            $table->json('properties');

            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }
};
