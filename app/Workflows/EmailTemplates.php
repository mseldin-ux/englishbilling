<?php

namespace App\Workflows;

use App\Billing\PdfRenderer;
use App\Models\EmailTemplate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EmailTemplates
{
    /**
     * Every template used by a registered workflow, keyed by template key.
     *
     * @return array<string, TemplateDefinition>
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (array_keys(Workflows::all()) as $workflow) {
            foreach (Workflows::steps($workflow) as $step) {
                foreach ($step->templates() as $definition) {
                    $definitions[$definition->key] = $definition;
                }
            }
        }

        return $definitions;
    }

    public static function definition(string $key): TemplateDefinition
    {
        return self::definitions()[$key] ?? throw new InvalidArgumentException("Unknown email template {$key}.");
    }

    /** The saved template, created from its default text the first time it is needed. */
    public static function get(string $key): EmailTemplate
    {
        $definition = self::definition($key);

        return EmailTemplate::firstOrCreate(['key' => $key], ['subject' => $definition->subject, 'body' => $definition->body]);
    }

    /**
     * Problems with a subject/body pair: unknown or missing required placeholders.
     *
     * @return array<string, array<int, string>>
     */
    public static function problems(string $key, string $subject, string $body): array
    {
        $definition = self::definition($key);
        $problems = [];
        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            $unknown = array_diff(self::placeholdersIn($text), array_keys($definition->placeholders));
            if ($unknown !== []) {
                $problems[$field][] = 'Unknown placeholder(s): '.implode(', ', array_map(fn ($name) => '{'.$name.'}', $unknown))
                    .'. Use only the placeholders listed on this page.';
            }
        }
        $missing = array_diff($definition->required, self::placeholdersIn($body));
        if ($missing !== []) {
            $problems['body'][] = 'The body must include '.implode(', ', array_map(fn ($name) => '{'.$name.'}', $missing)).'.';
        }

        return $problems;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array{subject: string, text: string, html: string}
     */
    public static function render(string $key, array $values, ?EmailTemplate $template = null): array
    {
        $template ??= self::get($key);
        $fill = fn (string $text, callable $escape) => preg_replace_callback('/\{([a-z_]+)\}/',
            fn ($match) => array_key_exists($match[1], $values) ? $escape((string) $values[$match[1]]) : $match[0], $text);
        $text = $fill(str_replace("\r\n", "\n", $template->body), fn ($value) => $value);
        $subject = trim(preg_replace('/\s+/', ' ', $fill($template->subject, fn ($value) => $value)));
        $html = $fill(e(str_replace("\r\n", "\n", $template->body)), fn ($value) => e($value));
        $html = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~', '<a href="$1">$1</a>', $html);

        return ['subject' => $subject, 'text' => $text,
            'html' => '<div style="font-family: Arial, sans-serif; font-size: 15px; line-height: 1.45;">'.nl2br($html).'</div>'];
    }

    /** Renders a letter template to a PDF with the CGMS letterhead. */
    public static function letter(string $key, array $values): array
    {
        $message = self::render($key, $values);
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $message['subject']), '-') ?: 'letter';

        return ['name' => $name.'.pdf', 'mime' => 'application/pdf',
            'data' => app(PdfRenderer::class)->renderView('workflows.letter-pdf', ['text' => $message['text']])];
    }

    /** @return array<string, string> */
    public static function sample(string $key): array
    {
        $values = [];
        foreach (self::definition($key)->placeholders as $name => $description) {
            $values[$name] = match (true) {
                str_ends_with($name, '_link') => 'https://example.org/'.Str::slug($name),
                default => '['.$description.']',
            };
        }

        return $values;
    }

    /** @return array<int, string> */
    private static function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }
}
