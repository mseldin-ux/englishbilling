<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Form editing locks are keyed by form kind (its table) and row id so every form type shares
 * one lock table. Rows are removed explicitly when a form is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('form_edit_locks', 'form_edit_locks_old');
        $this->create();
        DB::table('form_edit_locks')->insertUsing(['form_kind', 'form_id', 'session_id', 'token', 'acquired_at', 'last_activity'],
            DB::table('form_edit_locks_old')->selectRaw("'formsdata', formsdata_id, session_id, token, acquired_at, last_activity"));
        Schema::drop('form_edit_locks_old');
    }

    public function down(): void
    {
        Schema::drop('form_edit_locks');
        Schema::create('form_edit_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('formsdata_id')->primary();
            $table->foreign('formsdata_id')->references('id')->on('formsdata')->cascadeOnDelete();
            $table->string('session_id', 64)->index();
            $table->foreign('session_id')->references('id')->on('staff_sessions')->cascadeOnDelete();
            $table->string('token', 64);
            $table->unsignedBigInteger('acquired_at');
            $table->unsignedBigInteger('last_activity');
        });
    }

    private function create(): void
    {
        Schema::create('form_edit_locks', function (Blueprint $table) {
            $table->string('form_kind', 32);
            $table->unsignedBigInteger('form_id');
            $table->primary(['form_kind', 'form_id']);
            $table->string('session_id', 64)->index('form_edit_locks_session_index');
            $table->foreign('session_id', 'form_edit_locks_session_foreign')->references('id')->on('staff_sessions')->cascadeOnDelete();
            $table->string('token', 64);
            $table->unsignedBigInteger('acquired_at');
            $table->unsignedBigInteger('last_activity');
        });
    }
};
