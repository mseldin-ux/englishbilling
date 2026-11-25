<?php

namespace App\Billing;

use App\Models\Program;
use Illuminate\Validation\ValidationException;

final class ProgramStages
{
    /** @return array<int, Program> */
    public static function sequence(Program $program): array
    {
        $programs = Program::all()->keyBy('id');
        $current = $program;
        $visited = [];
        while (true) {
            if (isset($visited[$current->id])) {
                self::error('Stage programs cannot form a cycle.');
            }
            $visited[$current->id] = true;
            $previous = $programs->filter(fn ($row) => $row->staged && (int) $row->nextprog === $current->id);
            if ($previous->count() > 1) {
                self::error('Each stage program must have at most one preceding stage.');
            }
            if ($previous->isEmpty()) {
                break;
            }
            $current = $previous->first();
        }
        $certificate = $programs->get($program->certificate_program_id);
        if (! $certificate || $certificate->staged || ! $certificate->isCertificateLeading()) {
            self::error('Choose a non-staged, certificate-leading program for the shared certificate record.');
        }
        $sequence = [];
        while ($current) {
            if ($programs->filter(fn ($row) => $row->staged && (int) $row->nextprog === $current->id)->count() > 1) {
                self::error('Each stage program must have at most one preceding stage.');
            }
            if (isset($sequence[$current->id])) {
                self::error('Stage programs cannot form a cycle.');
            }
            if (! $current->staged || ! $current->isCertificateLeading()
                || $current->certificate_program_id !== $certificate->id) {
                self::error('All linked stages must be certificate-leading staged programs for the same certificate.');
            }
            $sequence[$current->id] = $current;
            if (count($sequence) > 3) {
                self::error('A program sequence can contain at most three stages.');
            }
            $next = (int) $current->nextprog;
            if ($next <= 0) {
                break;
            }
            $current = $programs->get($next);
            if (! $current) {
                self::error('The next stage program does not exist.');
            }
        }

        return array_values($sequence);
    }

    public static function position(Program $program): int
    {
        foreach (self::sequence($program) as $index => $stage) {
            if ($stage->id === $program->id) {
                return $index + 1;
            }
        }

        self::error('The selected program does not belong to its stage sequence.');
    }

    public static function validate(Program $program): void
    {
        if ($program->staged) {
            self::sequence($program);
        } elseif ($program->nextprog || $program->certificate_program_id) {
            self::error('Only staged programs may have a next stage or a shared certificate program.');
        }
        foreach (Program::where('nextprog', $program->id)->where('staged', true)->get() as $previous) {
            self::sequence($previous);
        }
        foreach (Program::where('certificate_program_id', $program->id)->where('staged', true)->get() as $stage) {
            self::sequence($stage);
        }
    }

    private static function error(string $message): never
    {
        throw ValidationException::withMessages(['program' => $message]);
    }
}
