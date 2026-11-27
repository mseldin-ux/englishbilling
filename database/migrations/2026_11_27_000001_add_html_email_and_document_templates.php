<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->boolean('is_html')->default(false);
        });
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('is_html');
        });
    }
};
