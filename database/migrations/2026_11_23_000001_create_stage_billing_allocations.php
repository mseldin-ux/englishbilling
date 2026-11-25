<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_stage_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreignId('stage_enrollment_id')->constrained('student_stage_enrollments')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unique(['invoice_id', 'stage_enrollment_id'], 'invoice_stage_allocation_unique');
        });
        Schema::create('transaction_stage_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('studenttransactions')->cascadeOnDelete();
            $table->foreignId('stage_enrollment_id')->constrained('student_stage_enrollments')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unique(['transaction_id', 'stage_enrollment_id'], 'transaction_stage_allocation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_stage_allocations');
        Schema::dropIfExists('invoice_stage_allocations');
    }
};
