<?php

declare(strict_types=1);

namespace App\Models;

class User
{
    private ?int $userId;

    private string $username;

    private string $passwordHash;

    private string $realName;

    private string $mobile;

    private ?string $email;

    private string $role;

    private string $status;

    private ?string $lastLoginAt;

    private string $createdAt;

    private string $updatedAt;

    public function __construct(
        ?int $userId,
        string $username,
        string $passwordHash,
        string $realName,
        string $mobile,
        ?string $email,
        string $role,
        string $status,
        ?string $lastLoginAt,
        string $createdAt,
        string $updatedAt
    ) 
    {
        $this->userId = $userId;
        $this->username = $username;
        $this->passwordHash = $passwordHash;
        $this->realName = $realName;
        $this->mobile = $mobile;
        $this->email = $email;
        $this->role = $role;
        $this->status = $status;
        $this->lastLoginAt = $lastLoginAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRealName(): string
    {
        return $this->realName;
    }

    public function getMobile(): string
    {
        return $this->mobile;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getRoleLabel(): string
    {
        return match ($this->role) {
            'admin' => '管理员',
            'user' => '普通用户',
            default => '未知角色',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'active' => '正常',
            'disabled' => '已停用',
            default => '未知状态',
        };
    }
}