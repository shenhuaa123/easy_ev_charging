<?php

declare(strict_types=1);

namespace App\Models;

class ChargeRecord
{
    private ?int $chargeRecordId;
    private string $orderNumber;
    private int $userId;
    private int $stationId;
    private string $checkInAt;
    private ?string $checkOutAt;
    private string $hourlyRateSnapshot;
    private ?int $billableMinutes;
    private ?string $totalCost;
    private string $status;
    private ?string $remark;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(
        ?int $chargeRecordId,
        string $orderNumber,
        int $userId,
        int $stationId,
        string $checkInAt,
        ?string $checkOutAt,
        string $hourlyRateSnapshot,
        ?int $billableMinutes,
        ?string $totalCost,
        string $status,
        ?string $remark,
        string $createdAt,
        string $updatedAt
    ){
        $this->chargeRecordId = $chargeRecordId;
        $this->orderNumber = $orderNumber;
        $this->userId = $userId;
        $this->stationId = $stationId;
        $this->checkInAt = $checkInAt;
        $this->checkOutAt = $checkOutAt;
        $this->hourlyRateSnapshot = $hourlyRateSnapshot;
        $this->billableMinutes = $billableMinutes;
        $this->totalCost = $totalCost;
        $this->status = $status;
        $this->remark = $remark;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getChargeRecordId(): ?int
    {
        return $this->chargeRecordId;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStationId(): int
    {
        return $this->stationId;
    }

    public function getCheckInAt(): string
    {
        return $this->checkInAt;
    }

    public function getCheckOutAt(): ?string
    {
        return $this->checkOutAt;
    }

    public function getHourlyRateSnapshot(): string
    {
        return $this->hourlyRateSnapshot;
    }

    public function getBillableMinutes(): ?int
    {
        return $this->billableMinutes;
    }

    public function getTotalCost(): ?string
    {
        return $this->totalCost;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function isCharging(): bool
    {
        return $this->status === 'charging';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isAbnormal(): bool
    {
        return $this->status === 'abnormal';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getStatusLabel(): string
    {
        return match($this->status){
            'charging' => '充电中',
            'completed' => '已完成',
            'abnormal' => '异常结束',
            'cancelled' => '已取消',
            default => '未知状态',
        };
    }

    public function getHourlyRateSnapshotLabel(): string
    {
        return '￥' . number_format((float) $this->hourlyRateSnapshot, 2) . '／小时';
    }

    public function getBillableMinutesLabel(): string
    {
        if($this->billableMinutes === null){
            return '尚未结算';
        }

        if($this->billableMinutes < 60){
            return $this->billableMinutes . ' 分钟';
        }

        $hours = intdiv($this->billableMinutes, 60);
        $remainingMinutes = $this->billableMinutes % 60;

        if($remainingMinutes === 0){
            return $hours . ' 小时';
        }

        return $hours . ' 小时 ' . $remainingMinutes . ' 分钟';
    }

    public function getTotalCostLabel(): string
    {
        if($this->totalCost === null){
            return '尚未结算';
        }

        return '￥' . number_format((float) $this->totalCost, 2);
    }
}