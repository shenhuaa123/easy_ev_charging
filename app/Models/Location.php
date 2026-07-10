<?php

declare(strict_types=1);

namespace App\Models;

class Location
{
    private ?int $locationId;

    private string $locationCode;

    private string $locationName;

    private string $province;

    private string $city;

    private string $district;

    private string $detailedAddress;

    private ?string $description;

    private ?string $longitude;

    private ?string $latitude;

    private string $status;

    private string $createdAt;

    private string $updatedAt;

    public function __construct(
        ?int $locationId,
        string $locationCode,
        string $locationName,
        string $province,
        string $city,
        string $district,
        string $detailedAddress,
        ?string $description,
        ?string $longitude,
        ?string $latitude,
        string $status,
        string $createdAt,
        string $updatedAt
    ) 
    {
        $this->locationId = $locationId;
        $this->locationCode = $locationCode;
        $this->locationName = $locationName;
        $this->province = $province;
        $this->city = $city;
        $this->district = $district;
        $this->detailedAddress = $detailedAddress;
        $this->description = $description;
        $this->longitude = $longitude;
        $this->latitude = $latitude;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getLocationCode(): string
    {
        return $this->locationCode;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getProvince(): string
    {
        return $this->province;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getDistrict(): string
    {
        return $this->district;
    }

    public function getDetailedAddress(): string
    {
        return $this->detailedAddress;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
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

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'active' => '运营中',
            'maintenance' => '维护中',
            'inactive' => '已停用',
            default => '未知状态',
        };
    }

    public function getFullAddress(): string
    {
        $fullAddress = $this->province;

        if($this->city !== $this->province){
            $fullAddress .= $this->city;
        }

        $fullAddress .= $this->district;
        $fullAddress .= $this->detailedAddress;

        return $fullAddress;
    }
}