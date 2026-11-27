<?php

namespace App\Http\Controllers;

use App\Billing\DocumentTemplates;
use App\Billing\PdfRenderer;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DocumentTemplateController extends Controller
{
    public function index()
    {
        return view('document-templates.index', [
            'templates' => DocumentTemplate::with('editor')->get()->keyBy('key'),
        ]);
    }

    public function edit(Request $request, string $key)
    {
        $this->checkKey($key);
        $template = DocumentTemplates::saved($key)?->load('editor');
        $body = (string) old('body', $request->session()->get("document_preview.$key", $template?->body ?? DocumentTemplates::original()));
        $preview = view('documents.custom', [...DocumentTemplates::sample($key),
            'pdf' => false, 'preview' => true, 'templateBody' => $body])->render();

        return response()->view('document-templates.edit', compact('key', 'template', 'body', 'preview'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $key)
    {
        $this->checkKey($key);
        $action = $request->validate(['action' => ['nullable', 'in:save,preview,reset']])['action'] ?? 'save';
        if ($action === 'reset') {
            DocumentTemplate::where('key', $key)->delete();
            $request->session()->forget("document_preview.$key");
            Log::info('Document template reset.', ['key' => $key, 'user_id' => $request->user()->id]);

            return redirect()->route('document-templates.edit', $key)->with('status', 'The original document layout has been restored.');
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:200000']]);
        $unknown = DocumentTemplates::unknown($data['body']);
        if ($unknown) {
            throw ValidationException::withMessages(['body' => 'Unknown placeholder(s): '.implode(', ', array_map(fn ($name) => '{'.$name.'}', $unknown)).'.']);
        }
        if ($action === 'preview') {
            $request->session()->put("document_preview.$key", $data['body']);

            return redirect()->route('document-templates.edit', $key)->with('status', 'Previewing unsaved changes.');
        }
        DocumentTemplate::updateOrCreate(['key' => $key], ['body' => $data['body'], 'updated_by' => $request->user()->id]);
        $request->session()->forget("document_preview.$key");
        Log::info('Document template updated.', ['key' => $key, 'user_id' => $request->user()->id]);

        return redirect()->route('document-templates.edit', $key)->with('status', 'Document template saved. New renders will use it.');
    }

    public function previewPdf(Request $request, string $key, PdfRenderer $renderer)
    {
        $this->checkKey($key);
        $body = $request->session()->get("document_preview.$key", DocumentTemplates::saved($key)?->body ?? DocumentTemplates::original());
        $pdf = $renderer->renderView('documents.custom', [...DocumentTemplates::sample($key),
            'preview' => true, 'templateBody' => $body]);

        return response($pdf, 200)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="sample-'.$key.'.pdf"')
            ->header('Cache-Control', 'private, no-store');
    }

    private function checkKey(string $key): void
    {
        abort_unless(in_array($key, DocumentTemplates::KEYS, true), 404);
    }
}
