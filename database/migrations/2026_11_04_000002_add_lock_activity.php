<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_edit_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('last_activity')->default(0);
        });
        DB::table('student_edit_locks')->update(['last_activity' => DB::raw('acquired_at')]);
    }

    public function down(): void
    {
        Schema::table('student_edit_locks', fn (Blueprint $table) => $table->dropColumn('last_activity'));
    }
};
