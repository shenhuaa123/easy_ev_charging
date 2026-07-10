<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

final class ChargeBillingCalculator
{
    /**
     * 根据充电时间和费率快照计算结算结果。
     *
     * @return array{
     *     billable_minutes: int,
     *     total_cost: string
     * }
     */
    public function calculate(string $checkInAt, string $checkOutAt, string $hourlyRate): array
    {
        $billableMinutes = $this->calculateBillableMinutes(
            $checkInAt,
            $checkOutAt
        );

        return [
            'billable_minutes' => $billableMinutes,
            'total_cost' => $this->calculateTotalCost(
                $hourlyRate,
                $billableMinutes
            ),
        ];
    }

    /**
     * 根据开始和结束时间计算计费分钟数。
     *
     * 按实际秒数向上取整，不足一分钟按一分钟收费。
     */
    private function calculateBillableMinutes(string $checkInAt, string $checkOutAt): int
    {
        $checkInDateTime = new DateTimeImmutable($checkInAt);
        $checkOutDateTime = new DateTimeImmutable($checkOutAt);

        $durationSeconds = $checkOutDateTime->getTimestamp()
            - $checkInDateTime->getTimestamp();

        if($durationSeconds < 0){
            throw new RuntimeException('充电结束时间不能早于开始时间。');
        }

        return max(1, (int)ceil($durationSeconds / 60));
    }

    /**
     * 根据费率快照和计费分钟数计算费用。
     */
    private function calculateTotalCost(string $hourlyRate, int $billableMinutes): string
    {
        if(!is_numeric($hourlyRate) || (float)$hourlyRate < 0){
            throw new RuntimeException('订单费率快照不合法。');
        }

        if($billableMinutes <= 0){
            throw new RuntimeException('计费分钟数必须大于0。');
        }

        $totalCost = (float)$hourlyRate * $billableMinutes / 60;

        return number_format(
            round($totalCost, 2, PHP_ROUND_HALF_UP),
            2,
            '.',
            ''
        );
    }
}