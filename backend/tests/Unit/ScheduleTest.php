<?php

namespace App\Tests\Unit;

use App\Cast\PanelException;
use App\Cast\Panels;
use App\Cast\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    private static function at(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    public function testDaytimeSchedule(): void
    {
        $weekdays = Panels::schedule(['days' => [5, 1, 2, 3, 4, 1], 'from' => '08:00', 'until' => '12:00']);
        self::assertSame([1, 2, 3, 4, 5], $weekdays['days']);
        // 2026-09-28 is a Monday
        self::assertTrue(Schedule::isActive($weekdays, self::at('2026-09-28 08:00')));
        self::assertTrue(Schedule::isActive($weekdays, self::at('2026-09-28 11:59')));
        self::assertFalse(Schedule::isActive($weekdays, self::at('2026-09-28 12:00')));
        self::assertFalse(Schedule::isActive($weekdays, self::at('2026-09-27 10:00')));
        self::assertTrue(Schedule::isActive(null, self::at('2026-09-27 10:00')));
    }

    public function testOvernightAndWholeDaySchedules(): void
    {
        $night = Panels::schedule(['days' => [5], 'from' => '22:00', 'until' => '06:00']);
        self::assertTrue(Schedule::isActive($night, self::at('2026-10-02 23:00')));
        self::assertTrue(Schedule::isActive($night, self::at('2026-10-03 05:59')));
        self::assertFalse(Schedule::isActive($night, self::at('2026-10-03 06:00')));
        self::assertFalse(Schedule::isActive($night, self::at('2026-10-01 23:00')));

        $sunday = Panels::schedule(['days' => [7], 'from' => '00:00', 'until' => '00:00']);
        self::assertSame('24:00', $sunday['until']);
        self::assertTrue(Schedule::isActive($sunday, self::at('2026-10-04 23:59')));
        self::assertFalse(Schedule::isActive($sunday, self::at('2026-10-05 00:00')));
    }

    public function testNextChange(): void
    {
        $panels = [
            ['schedule' => Panels::schedule(['days' => [1], 'from' => '08:00', 'until' => '12:00'])],
            ['schedule' => null],
            ['schedule' => Panels::schedule(['days' => [1], 'from' => '18:30', 'until' => '20:00'])],
        ];
        self::assertSame('2026-09-28T12:00:00+02:00', Schedule::nextChange($panels, self::at('2026-09-28 10:00'))?->format(\DATE_ATOM));
        self::assertSame('2026-09-28T18:30:00+02:00', Schedule::nextChange($panels, self::at('2026-09-28 12:00'))?->format(\DATE_ATOM));
        self::assertSame('2026-09-29T08:00:00+02:00', Schedule::nextChange($panels, self::at('2026-09-28 21:00'))?->format(\DATE_ATOM));
        self::assertNull(Schedule::nextChange([['schedule' => null]], self::at('2026-09-28 21:00')));
    }

    public function testInvalidSchedules(): void
    {
        foreach ([['days' => []], ['days' => [8]], ['from' => '25:00', 'until' => '10:00'], ['from' => '10:00', 'until' => '10:00']] as $invalid) {
            try {
                Panels::schedule($invalid);
                self::fail('Accepted: '.json_encode($invalid));
            } catch (PanelException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
