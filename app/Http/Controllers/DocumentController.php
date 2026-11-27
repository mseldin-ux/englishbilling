<?php

namespace App\Http\Controllers;

use App\Billing\BillingDocument;
use App\Billing\DocumentTemplates;
use App\Billing\PdfRenderer;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function invoice(Request $request, Student $student, int $invoice, PdfRenderer $renderer)
    {
        $data = $this->snapshot($student, $invoice);

        return $this->respond($request, $data, 'invoice-'.$invoice, $renderer);
    }

    public function statement(Request $request, Student $student, PdfRenderer $renderer)
    {
        return $this->respond($request, $this->snapshot($student), 'statement-'.$student->id, $renderer);
    }

    private function snapshot(Student $student, ?int $invoiceId = null): array
    {
        return DB::transaction(function () use ($student, $invoiceId) {
            $locked = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $invoice = $invoiceId === null ? null : $locked->invoices()->select(Invoice::METADATA_COLUMNS)->findOrFail($invoiceId);

            return BillingDocument::data($locked, $invoice);
        });
    }

    private function respond(Request $request, array $data, string $name, PdfRenderer $renderer)
    {
        if (str_ends_with($request->route()->getName(), 'pdf')) {
            return response($renderer->render($data), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $template = DocumentTemplates::saved($data['invoice'] ? 'invoice' : 'statement');
        if ($template) {
            $nonce = base64_encode(random_bytes(16));

            return response()->view('documents.custom', [...$data, 'pdf' => false, 'templateBody' => $template->body, 'cspNonce' => $nonce])
                ->header('Cache-Control', 'private, no-store')
                ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        }

        return response()->view('documents.billing', [...$data, 'pdf' => false])
            ->header('Cache-Control', 'private, no-store');
    }
}
