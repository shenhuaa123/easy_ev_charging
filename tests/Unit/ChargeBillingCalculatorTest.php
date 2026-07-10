<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ChargeBillingCalculator;
use RuntimeException;
use Tests\TestCase;

final class ChargeBillingCalculatorTest extends TestCase
{
    public function testCalculateUsesMinimumOneMinute(): void
    {
        $calculator = new ChargeBillingCalculator();

        $result = $calculator->calculate(
            '2026-01-01 10:00:00',
            '2026-01-01 10:00:00',
            '12.00'
        );

        $this->assertSame(1, $result['billable_minutes']);
        $this->assertSame('0.20', $result['total_cost']);
    }

    public function testCalculateRoundsPartialMinuteUp(): void
    {
        $calculator = new ChargeBillingCalculator();

        $result = $calculator->calculate(
            '2026-01-01 10:00:00',
            '2026-01-01 10:01:01',
            '60.00'
        );

        $this->assertSame(2, $result['billable_minutes']);
        $this->assertSame('2.00', $result['total_cost']);
    }

    public function testCalculateUsesHourlyRateAndBillableMinutes(): void
    {
        $calculator = new ChargeBillingCalculator();

        $result = $calculator->calculate(
            '2026-01-01 10:00:00',
            '2026-01-01 11:30:00',
            '12.00'
        );

        $this->assertSame(90, $result['billable_minutes']);
        $this->assertSame('18.00', $result['total_cost']);
    }

    public function testCalculateRejectsEndTimeBeforeStartTime(): void
    {
        $calculator = new ChargeBillingCalculator();

        $this->assertThrows(
            RuntimeException::class,
            static function() use ($calculator): void {
                $calculator->calculate(
                    '2026-01-01 11:00:00',
                    '2026-01-01 10:00:00',
                    '12.00'
                );
            }
        );
    }

    public function testCalculateRejectsInvalidHourlyRate(): void
    {
        $calculator = new ChargeBillingCalculator();

        $this->assertThrows(
            RuntimeException::class,
            static function() use ($calculator): void {
                $calculator->calculate(
                    '2026-01-01 10:00:00',
                    '2026-01-01 11:00:00',
                    '-1.00'
                );
            }
        );
    }
}