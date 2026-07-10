<?php

declare(strict_types=1);

namespace App\Models;

class ChargingStation 
{
    private ?int $stationId;

    private string $stationCode;

    private string $stationName;

    private int $locationId;

    private string $chargerType;

    private string $powerKw;

    private string $hourlyRate;

    private string $status;

    private string $createdAt;

    private string $updatedAt;

    public function __construct(
        ?int $stationId,
        string $stationCode,
        string $stationName,
        int $locationId,
        string $chargerType,
        string $powerKw,
        string $hourlyRate,
        string $status,
        string $createdAt,
        string $updatedAt
    ){
        $this->stationId = $stationId;
        $this->stationCode = $stationCode;
        $this->stationName = $stationName;
        $this->locationId = $locationId;
        $this->chargerType = $chargerType;
        $this->powerKw = $powerKw;
        $this->hourlyRate = $hourlyRate;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getStationId(): ?int
    {
        return $this->stationId;
    }

    public function getStationCode(): string
    {
        return $this->stationCode;
    }

    public function getStationName(): string
    {
        return $this->stationName;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getChargerType(): string
    {
        return $this->chargerType;
    }

    public function getPowerKw(): string
    {
        return $this->powerKw;
    }

    public function getHourlyRate(): string
    {
        return $this->hourlyRate;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isUnderMaintenance(): bool
    {
        return $this->status === 'maintenance';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    public function isAcCharger(): bool
    {
        return $this->chargerType === 'ac';
    }

    public function isDcCharger(): bool
    {
        return $this->chargerType === 'dc';
    }

    public function getChargerTypeLabel(): string
    {
        return match($this->chargerType){
            'ac' => '交流充电桩',
            'dc' => '直流充电桩',
            default => '未知充电类型',
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status){
            'active' => '可用',
            'maintenance' => '维护中',
            'inactive' => '已停用',
            default => '未知状态',
        };
    }

    public function getPowerKwLabel(): string
    {
        return $this->powerKw . '千瓦';
    }

    public function getHourlyRateLabel(): string
    {
        return '￥' . number_format((float)$this->hourlyRate, 2) . '／小时';
    }
}