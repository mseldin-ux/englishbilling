<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceDeadline('10th', '16th');
    }

    public function down(): void
    {
        $this->replaceDeadline('16th', '10th');
    }

    private function replaceDeadline(string $from, string $to): void
    {
        foreach (DB::table('email_templates')->whereIn('key', ['course-completion-invitation', 'final-grades-reminder'])->get() as $template) {
            $body = str_replace([
                "Certificates are ordered on the {$from} of each month.",
                "Please complete the Final Grades form by the {$from} of the month for the certificate to be ordered.",
            ], [
                "Certificates are ordered on the {$to} of each month.",
                "Please complete the Final Grades form by the {$to} of the month for the certificate to be ordered.",
            ], $template->body);
            if ($body !== $template->body) {
                DB::table('email_templates')->where('id', $template->id)->update(['body' => $body, 'updated_at' => now()]);
            }
        }
    }
};
