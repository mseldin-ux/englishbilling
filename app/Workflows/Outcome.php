<?php

namespace App\Workflows;

use Carbon\CarbonInterface;

/** Result of reaching a stage. */
final class Outcome
{
    /**
     * @param  'done'|'waiting'|'scheduled'|'skipped'  $kind
     * @param  array<string, mixed>  $data  merged into the stage's stored data
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $data = [],
        public readonly ?string $note = null,
        public readonly ?CarbonInterface $at = null,
        public readonly ?array $email = null,
    ) {}

    /** @param  array{recipients: array<int, string>, subject: string, body: string}|null  $email */
    public static function done(array $data = [], ?array $email = null): self
    {
        return new self('done', $data, email: $email);
    }

    public static function waiting(?string $note = null, array $data = []): self
    {
        return new self('waiting', $data, $note);
    }

    public static function scheduled(CarbonInterface $at, ?string $note = null, array $data = []): self
    {
        return new self('scheduled', $data, $note, $at);
    }

    public static function skipped(string $note, array $data = []): self
    {
        return new self('skipped', $data, $note);
    }
}
