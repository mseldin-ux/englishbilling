<?php

namespace App\Workflows;

final class TemplateDefinition
{
    /**
     * @param  array<string, string>  $placeholders  placeholder name => description
     * @param  array<int, string>  $required  placeholders that must appear in the body
     * @param  'email'|'letter'  $kind  letters are rendered to PDF; their "subject" is the file name
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly array $placeholders,
        public readonly array $required,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $kind = 'email',
    ) {}
}
