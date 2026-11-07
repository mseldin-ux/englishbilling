<?php

namespace App\Billing;

final class ReportFormatting
{
    public static function date(?string $date): string
    {
        return $date === null ? '' : preg_replace('/\.0{6}$/', '', $date);
    }

    public static function name(?string $first, ?string $middle, ?string $last): string
    {
        $name = $first === null ? 'UNKNOWN' : $first.' ';
        if ($middle !== null && $middle !== '' && strtolower($middle) !== 'none') {
            $name .= $middle.' ';
        }

        return $name.($last ?? '');
    }

    public static function duration(int $seconds): string
    {
        $days = (int) floor($seconds / 86400);
        $remainder = $seconds - $days * 86400;
        $time = sprintf('%d:%02d:%02d', intdiv($remainder, 3600), intdiv($remainder % 3600, 60), $remainder % 60);

        return ($days === 0 ? '' : $days.' '.(abs($days) === 1 ? 'day' : 'days').', ').$time;
    }
}
