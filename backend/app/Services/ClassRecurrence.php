<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ClassRecurrence
{
    public function preview(array $template): array
    {
        $today = CarbonImmutable::now('Asia/Taipei')->startOfDay();
        $through = $today->addDays(90);
        $start = CarbonImmutable::parse($template['start_date'], 'Asia/Taipei')->startOfDay();
        if ($start->lt($today)) {
            $start = $today;
        }
        $end = $through;
        if (! empty($template['end_date'])) {
            $limit = CarbonImmutable::parse($template['end_date'], 'Asia/Taipei')->startOfDay();
            if ($limit->lt($end)) {
                $end = $limit;
            }
        }
        $occurrences = [];
        $skipped = [];
        $excludedDates = array_flip($template['excluded_dates'] ?? []);
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            foreach ($template['rules'] as $rule) {
                $match = match ($rule['frequency']) {
                    'weekly' => in_array($date->isoWeekday(), $rule['weekdays'], true),
                    'monthly_date' => $date->day === $rule['month_day'],
                    'monthly_weekday' => $date->isoWeekday() === $rule['weekday'] && ($rule['week_of_month'] === -1 ? $date->addWeek()->month !== $date->month : (int) ceil($date->day / 7) === $rule['week_of_month']),
                    default => false,
                };
                if ($match) {
                    $occurrence = ['rule_id' => $rule['id'], 'service_date' => $date->format('Y-m-d'), 'start_time' => $rule['start_time'], 'end_time' => $rule['end_time']];
                    if (isset($excludedDates[$occurrence['service_date']])) {
                        $skipped[] = $occurrence + ['reason' => '此班別已排除此日期。'];
                    } elseif (CarbonImmutable::parse($occurrence['service_date'].' '.$occurrence['end_time'], 'Asia/Taipei')->lte(CarbonImmutable::now('Asia/Taipei'))) {
                        $skipped[] = $occurrence + ['reason' => '時段已結束，未建立過去場次。'];
                    } else {
                        $occurrences[] = $occurrence;
                    }
                }
            }
        }
        usort($occurrences, fn ($a, $b) => [$a['service_date'], $a['start_time'], $a['rule_id']] <=> [$b['service_date'], $b['start_time'], $b['rule_id']]);

        return ['data' => $occurrences, 'skipped' => $skipped, 'through' => $end->format('Y-m-d')];
    }
}
