<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gradeditems', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('program_id');
            $table->unsignedTinyInteger('itemorder')->nullable();
            $table->string('gradeitem', 256);
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->index(['program_id', 'itemorder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gradeditems');
    }
};
