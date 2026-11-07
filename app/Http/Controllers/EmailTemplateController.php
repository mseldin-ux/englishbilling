<?php

namespace App\Http\Controllers;

use App\Workflows\EmailTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmailTemplateController extends Controller
{
    public function index()
    {
        $templates = [];
        foreach (EmailTemplates::definitions() as $key => $definition) {
            $templates[] = ['definition' => $definition, 'template' => EmailTemplates::get($key)->load('editor')];
        }

        return view('templates.index', ['templates' => $templates]);
    }

    public function edit(Request $request, string $key)
    {
        $definition = $this->definition($key);
        $template = EmailTemplates::get($key)->load('editor');
        $subject = (string) old('subject', $request->session()->get('preview.subject', $template->subject));
        $body = (string) old('body', $request->session()->get('preview.body', $template->body));
        $preview = $request->session()->has('preview')
            ? EmailTemplates::render($key, EmailTemplates::sample($key), $template->replicate()->fill(['subject' => $subject, 'body' => $body]))
            : EmailTemplates::render($key, EmailTemplates::sample($key), $template);

        return view('templates.edit', ['definition' => $definition, 'template' => $template, 'subject' => $subject,
            'body' => $body, 'preview' => $preview, 'previewing' => $request->session()->has('preview')]);
    }

    public function update(Request $request, string $key)
    {
        $this->definition($key);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'action' => ['nullable', 'in:save,preview,reset'],
        ]);
        $data['subject'] = trim(preg_replace('/\s+/', ' ', $data['subject']));
        if (($data['action'] ?? 'save') === 'reset') {
            $definition = EmailTemplates::definition($key);
            $data['subject'] = $definition->subject;
            $data['body'] = $definition->body;
        }
        $problems = EmailTemplates::problems($key, $data['subject'], $data['body']);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
        if (($data['action'] ?? 'save') === 'preview') {
            return redirect()->route('templates.edit', $key)->with('preview', ['subject' => $data['subject'], 'body' => $data['body']]);
        }
        $template = EmailTemplates::get($key);
        $template->forceFill(['subject' => $data['subject'], 'body' => str_replace("\r\n", "\n", $data['body']),
            'updated_by' => $request->user()->id])->save();
        Log::info('Email template updated.', ['key' => $key, 'user_id' => $request->user()->id, 'reset' => ($data['action'] ?? null) === 'reset']);

        return redirect()->route('templates.edit', $key)
            ->with('status', ($data['action'] ?? null) === 'reset' ? 'Template reset to the original text.' : 'Template saved. New emails will use it.');
    }

    private function definition(string $key)
    {
        abort_unless(array_key_exists($key, EmailTemplates::definitions()), 404);

        return EmailTemplates::definition($key);
    }
}
