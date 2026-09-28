<?php

namespace App\Cast;

/**
 * Schedule of a panel: days (ISO, 1 = Monday) and hours, in the screen's time zone. "from" after "until" spans
 * midnight (22:00 → 06:00: from 22:00 on a listed day until 06:00 the next day). No schedule: always shown.
 *
 * @phpstan-type ScheduleData array{days: list<int>, from: string, until: string}
 */
final class Schedule
{
    /** @param ScheduleData|null $schedule */
    public static function isActive(?array $schedule, \DateTimeImmutable $local): bool
    {
        if (null === $schedule) {
            return true;
        }
        $day = (int) $local->format('N');
        $previous = 1 === $day ? 7 : $day - 1;
        $time = $local->format('H:i');
        $days = $schedule['days'];
        if ($schedule['from'] < $schedule['until']) {
            return \in_array($day, $days, true) && $time >= $schedule['from'] && $time < $schedule['until'];
        }

        return (\in_array($day, $days, true) && $time >= $schedule['from']) || (\in_array($previous, $days, true) && $time < $schedule['until']);
    }

    /**
     * The next time a schedule starts or ends, today or tomorrow (screen time zone), or null.
     *
     * @param list<array<string, mixed>> $panels
     */
    public static function nextChange(array $panels, \DateTimeImmutable $local): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($panels as $panel) {
            $schedule = $panel['schedule'] ?? null;
            if (!\is_array($schedule)) {
                continue;
            }
            foreach ([0, 1] as $offset) {
                foreach ([$schedule['from'], $schedule['until']] as $hm) {
                    [$h, $m] = array_map('intval', explode(':', $hm));
                    $at = $local->modify('+'.$offset.' day')->setTime($h, $m);
                    if ($at > $local && (null === $next || $at < $next)) {
                        $next = $at;
                    }
                }
            }
        }

        return $next;
    }
}
