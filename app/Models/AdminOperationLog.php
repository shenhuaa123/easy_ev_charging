<?php

declare(strict_types=1);

namespace App\Models;

class AdminOperationLog
{
    private ?int $adminOperationLogId;
    private int $operatorUserId;
    private string $action;
    private string $targetType;
    private ?int $targetId;
    private string $result;
    private ?string $detail;
    private ?string $ipAddress;
    private ?string $userAgent;
    private string $createdAt;

    public function __construct(
        ?int $adminOperationLogId,
        int $operatorUserId,
        string $action,
        string $targetType,
        ?int $targetId,
        string $result,
        ?string $detail,
        ?string $ipAddress,
        ?string $userAgent,
        string $createdAt
    ){
        $this->adminOperationLogId = $adminOperationLogId;
        $this->operatorUserId = $operatorUserId;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->result = $result;
        $this->detail = $detail;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->createdAt = $createdAt;
    }

    public function getAdminOperationLogId(): ?int
    {
        return $this->adminOperationLogId;
    }

    public function getOperatorUserId(): int
    {
        return $this->operatorUserId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getResultLabel(): string
    {
        return match($this->result){
            'success' => '成功',
            'failure' => '失败',
            default => '未知结果',
        };
    }

    public function getTargetTypeLabel(): string
    {
        return match($this->targetType){
            'user' => '用户',
            'location' => '充电站点',
            'station' => '充电桩',
            'charge_record' => '充电订单',
            'location_review' => '站点评价',
            'admin_operation_log' => '操作日志',
            'dashboard_statistics' => '统计数据',
            default => '其他对象',
        };
    }

    public function getActionLabel(): string
    {
        return match($this->action){
            'admin_login_success' => '管理员登录',
            'admin_logout' => '管理员退出',
            'admin_profile_update' => '管理员修改个人资料',
            'admin_password_change' => '管理员修改密码',

            'user_profile_update' => '修改用户资料',
            'user_password_reset' => '重置用户密码',
            'user_status_update' => '修改用户状态',

            'location_create' => '新增充电站点',
            'location_update' => '编辑充电站点',
            'location_status_update' => '修改充电站点状态',

            'station_create' => '新增充电桩',
            'station_update' => '编辑充电桩',
            'station_status_update' => '修改充电桩状态',

            'charge_record_abnormal_finish' => '异常结束订单',

            'location_review_reply' => '回复站点评价',
            'location_review_status_update' => '修改评价状态',

            'charge_record_export' => '导出订单数据',
            'admin_operation_log_export' => '导出操作日志',
            'dashboard_statistics_export' => '导出统计数据',
            
            default => $this->action,
        };
    }
}