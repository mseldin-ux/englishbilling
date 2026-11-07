<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finalgrades', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('studentid');
            $table->foreign('studentid')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('program_id');
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
            $table->string('gradername')->nullable();
            $table->date('gradedate')->nullable();
            $table->text('gradenotes')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['studentid', 'program_id']);
            // Target of the composite key that keeps each grade's studentid equal to its sheet's.
            $table->unique(['id', 'studentid']);
        });
        Schema::create('finalgradesitems', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('finalgradeid');
            $table->unsignedInteger('studentid')->index();
            $table->foreign('studentid')->references('id')->on('students')->restrictOnDelete();
            $table->unsignedInteger('gradeditemid')->index();
            $table->foreign('gradeditemid')->references('id')->on('gradeditems')->restrictOnDelete();
            $table->string('grade', 64)->nullable();
            $table->unique(['finalgradeid', 'gradeditemid']);
            $table->foreign(['finalgradeid', 'studentid'])->references(['id', 'studentid'])->on('finalgrades')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
        Schema::create('finalgrades_history', function (Blueprint $table) {
            $table->id();
            // No foreign key: history is retained after a sheet is deleted.
            $table->unsignedInteger('finalgradeid')->index();
            $table->unsignedInteger('revision');
            $table->string('action', 16);
            $table->string('status', 16);
            $table->json('data');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finalgrades_history');
        Schema::dropIfExists('finalgradesitems');
        Schema::dropIfExists('finalgrades');
    }
};
