<?php

declare(strict_types=1);

namespace App\Models;

class LocationReview
{
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN = 'hidden';

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    private ?int $locationReviewId;
    private int $userId;
    private int $locationId;
    private int $chargeRecordId;
    private int $rating;
    private string $content;
    private ?string $adminReply;
    private ?int $replyAdminUserId;
    private ?string $repliedAt;
    private string $status;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(
        ?int $locationReviewId,
        int $userId,
        int $locationId,
        int $chargeRecordId,
        int $rating,
        string $content,
        ?string $adminReply,
        ?int $replyAdminUserId,
        ?string $repliedAt,
        string $status,
        string $createdAt,
        string $updatedAt
    ){
        $this->locationReviewId = $locationReviewId;
        $this->userId = $userId;
        $this->locationId = $locationId;
        $this->chargeRecordId = $chargeRecordId;
        $this->rating = $rating;
        $this->content = $content;
        $this->adminReply = $adminReply;
        $this->replyAdminUserId = $replyAdminUserId;
        $this->repliedAt = $repliedAt;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getLocationReviewId(): ?int
    {
        return $this->locationReviewId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getChargeRecordId(): int
    {
        return $this->chargeRecordId;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getAdminReply(): ?string
    {
        return $this->adminReply;
    }

    public function getReplyAdminUserId(): ?int
    {
        return $this->replyAdminUserId;
    }

    public function getRepliedAt(): ?string
    {
        return $this->repliedAt;
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

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_VISIBLE;
    }

    public function isHidden(): bool
    {
        return $this->status === self::STATUS_HIDDEN;
    }

    public function hasAdminReply(): bool
    {
        return $this->adminReply !== null
            && trim($this->adminReply) !== '';
    }

    public function getStatusLabel(): string
    {
        return match($this->status){
            self::STATUS_VISIBLE => '公开显示',
            self::STATUS_HIDDEN => '已隐藏',
            default => '未知状态',
        };
    }

    public function getRatingLabel(): string
    {
        return str_repeat('★', $this->rating)
            . str_repeat('☆', self::MAX_RATING - $this->rating);
    }

    public function getRatingText(): string
    {
        return $this->rating . '星';
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_VISIBLE => '公开显示',
            self::STATUS_HIDDEN => '已隐藏',
        ];
    }

    public static function getRatingOptions(): array
    {
        return [
            5 => '5星，非常满意',
            4 => '4星，比较满意',
            3 => '3星，一般',
            2 => '2星，不太满意',
            1 => '1星，非常不满意',
        ];
    }
}