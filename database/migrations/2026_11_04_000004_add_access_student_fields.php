<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_access_statuses', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('status', 32);
        });
        foreach ([
            1 => 'applying', 2 => 'in process', 3 => 'enrolled', 4 => 'withdrew',
            5 => 'expelled', 6 => 'leave of absence', 7 => 'graduated', 8 => 'incomplete',
            9 => 'extension', 10 => 'grad-ASSOC', 11 => 'audit completed',
        ] as $id => $status) {
            DB::table('student_access_statuses')->insert(compact('id', 'status'));
        }
        Schema::table('students', function (Blueprint $table) {
            foreach (['maiden_name', 'address_line_2', 'associate', 'phonelabel', 'phone2label'] as $field) {
                $table->string($field)->nullable();
            }
            $table->string('phone2', 32)->nullable();
            $table->date('birth_date')->nullable();
            $table->dateTime('access_change_date', 6)->nullable();
            foreach (['self_pay', 'combination_payment', 'self_directed', 'comp_college'] as $field) {
                $table->boolean($field)->nullable();
            }
            $table->unsignedTinyInteger('access_status_code')->nullable();
            $table->foreign('access_status_code')->references('id')->on('student_access_statuses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['access_status_code']);
            $table->dropColumn([
                'maiden_name', 'address_line_2', 'associate', 'phonelabel', 'phone2label',
                'phone2', 'birth_date', 'access_change_date', 'self_pay',
                'combination_payment', 'self_directed', 'comp_college', 'access_status_code',
            ]);
        });
        Schema::dropIfExists('student_access_statuses');
    }
};
