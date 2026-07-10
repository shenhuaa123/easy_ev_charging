<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\ChargeRecord;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;

class ChargeRecordService
{
    private mysqli $connection;
    private ChargeRecordRepository $recordRepository;
    private ChargingStationRepository $stationRepository;
    private LocationRepository $locationRepository;
    private UserRepository $userRepository;
    private AdminAuthorizationService $adminAuthorizationService;
    private ChargeBillingCalculator $billingCalculator;

    public function __construct(
        mysqli $connection,
        ChargeRecordRepository $recordRepository,
        ChargingStationRepository $stationRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository
    ){
        $this->connection = $connection;
        $this->recordRepository = $recordRepository;
        $this->stationRepository = $stationRepository;
        $this->locationRepository = $locationRepository;
        $this->userRepository = $userRepository;

        $this->adminAuthorizationService = new AdminAuthorizationService(
            $userRepository
        );

        $this->billingCalculator = new ChargeBillingCalculator();
    }

    /**
     * 开始充电。
     */
    public function startCharging(int $userId, int $stationId, ?string $remark = null): array
    {
        if($userId <= 0){
            return [
                'success' => false,
                'message' => '用户编号不合法。',
                'charge_record_id' => null,
            ];
        }

        if($stationId <= 0){
            return [
                'success' => false,
                'message' => '充电桩编号不合法。',
                'charge_record_id' => null,
            ];
        }

        $remark = $remark === null ? null : trim($remark);

        if($remark === ''){
            $remark = null;
        }

        if($remark !== null && mb_strlen($remark, 'UTF-8') > 500){
            return [
                'success' => false,
                'message' => '订单备注不能超过500个字符。',
                'charge_record_id' => null,
            ];
        }

        $this->connection->begin_transaction();

        try{
            if(!$this->userRepository->lockByIdForUpdate($userId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '当前用户不存在。',
                    'charge_record_id' => null,
                ];
            }

            $user = $this->userRepository->findById($userId);

            if($user === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '当前用户不存在。',
                    'charge_record_id' => null,
                ];
            }

            if(!$user->isActive()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '当前账户已被停用，不能开始充电。',
                    'charge_record_id' => null,
                ];
            }

            if($user->isAdmin()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '管理员账户不能创建普通用户充电订单。',
                    'charge_record_id' => null,
                ];
            }

            $activeUserRecord = $this->recordRepository->findActiveByUserId($userId);

            if($activeUserRecord !== null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '您当前已有正在进行的充电订单，不能重复开始充电。',
                    'charge_record_id' => null,
                ];
            }

            $stationSnapshot = $this->stationRepository->findById($stationId);

            if($stationSnapshot === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电桩。',
                    'charge_record_id' => null,
                ];
            }

            $locationId = $stationSnapshot->getLocationId();

            if(!$this->locationRepository->lockByIdForUpdate($locationId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩所属站点不存在。',
                    'charge_record_id' => null,
                ];
            }

            if(!$this->stationRepository->lockByIdForUpdate($stationId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电桩。',
                    'charge_record_id' => null,
                ];
            }

            $station = $this->stationRepository->findById($stationId);

            if(
                $station === null
                || $station->getLocationId() !== $locationId
            ){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '充电桩资料已经发生变化，请刷新后重试。',
                    'charge_record_id' => null,
                ];
            }

            if(!$station->isActive()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩当前不可用。',
                    'charge_record_id' => null,
                ];
            }

            $location = $this->locationRepository->findById($locationId);

            if($location === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩所属站点不存在。',
                    'charge_record_id' => null,
                ];
            }

            if(!$location->isActive()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩所属站点当前未运营。',
                    'charge_record_id' => null,
                ];
            }

            $activeStationRecord = $this->recordRepository->findActiveByStationId($stationId);

            if($activeStationRecord !== null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩当前正在使用，请选择其他充电桩。',
                    'charge_record_id' => null,
                ];
            }

            $checkInAt = date('Y-m-d H:i:s');
            $orderNumber = $this->generateOrderNumber();

            $record = new ChargeRecord(
                null,
                $orderNumber,
                $userId,
                $stationId,
                $checkInAt,
                null,
                $station->getHourlyRate(),
                null,
                null,
                'charging',
                $remark,
                $checkInAt,
                $checkInAt
            );

            $chargeRecordId = $this->recordRepository->create($record);

            $this->connection->commit();

            return [
                'success' => true,
                'message' => '充电已开始。',
                'charge_record_id' => $chargeRecordId,
                'order_number' => $orderNumber,
            ];
        }catch(mysqli_sql_exception $exception){
            $this->connection->rollback();

            if($exception->getCode() === 1062){
                return [
                    'success' => false,
                    'message' => '该用户或充电桩当前已存在进行中的订单，请刷新后重试。',
                    'charge_record_id' => null,
                ];
            }

            Logger::exception($exception, '开始充电数据库异常。', [
                'user_id' => $userId,
                'station_id' => $stationId,
            ]);

            throw new RuntimeException(
                '开始充电失败：' . $exception->getMessage(),
                0,
                $exception
            );
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '开始充电流程异常。', [
                'user_id' => $userId,
                'station_id' => $stationId,
            ]);

            throw new RuntimeException(
                '开始充电失败：' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * 用户正常结束自己的充电订单。
     */
    public function finishCharging(int $userId, int $chargeRecordId): array
    {
        if($userId <= 0 || $chargeRecordId <= 0){
            return [
                'success' => false,
                'message' => '用户编号或充电订单编号不合法。',
                'charge_record_id' => null,
            ];
        }

        $this->connection->begin_transaction();

        try{
            $record = $this->recordRepository->findById($chargeRecordId);

            if($record === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电订单。',
                    'charge_record_id' => null,
                ];
            }

            if($record->getUserId() !== $userId){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '您无权结束其他用户的充电订单。',
                    'charge_record_id' => null,
                ];
            }

            if(!$record->isCharging()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电订单已经结束，不能重复操作。',
                    'charge_record_id' => $chargeRecordId,
                ];
            }

            $checkOutAt = date('Y-m-d H:i:s');

            $settlement = $this->billingCalculator->calculate(
                $record->getCheckInAt(),
                $checkOutAt,
                $record->getHourlyRateSnapshot()
            );

            $billableMinutes = $settlement['billable_minutes'];
            $totalCost = $settlement['total_cost'];

            $wasCompleted = $this->recordRepository->complete(
                $chargeRecordId,
                $userId,
                $checkOutAt,
                $billableMinutes,
                $totalCost
            );

            if(!$wasCompleted){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '订单状态已经发生变化，请刷新后重试。',
                    'charge_record_id' => $chargeRecordId,
                ];
            }

            $this->connection->commit();

            return [
                'success' => true,
                'message' => '充电已结束，订单结算完成。',
                'charge_record_id' => $chargeRecordId,
                'check_out_at' => $checkOutAt,
                'billable_minutes' => $billableMinutes,
                'total_cost' => $totalCost,
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '结束充电流程异常。', [
                'user_id' => $userId,
                'charge_record_id' => $chargeRecordId,
            ]);

            throw new RuntimeException(
                '结束充电失败：' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * 管理员异常结束进行中的订单。
     */
    public function finishAbnormally(
        int $operatorUserId,
        int $chargeRecordId,
        string $remark
    ): array
    {
        $authorizationError = 
            $this->adminAuthorizationService->validate(
                $operatorUserId
            );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
                'charge_record_id' => $chargeRecordId > 0
                    ? $chargeRecordId
                    : null,
            ];
        }

        if($chargeRecordId <= 0){
            return [
                'success' => false,
                'message' => '充电订单编号不合法。',
                'charge_record_id' => null,
            ];
        }

        $remark = trim($remark);

        if($remark === ''){
            return [
                'success' => false,
                'message' => '异常结束订单时必须填写原因。',
                'charge_record_id' => $chargeRecordId,
            ];
        }

        if(mb_strlen($remark, 'UTF-8') > 500){
            return [
                'success' => false,
                'message' => '异常原因不能超过500个字符。',
                'charge_record_id' => $chargeRecordId,
            ];
        }

        $this->connection->begin_transaction();

        try{
            $record = $this->recordRepository->findById($chargeRecordId);

            if($record === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电订单。',
                    'charge_record_id' => null,
                ];
            }

            if(!$record->isCharging()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '只有充电中的订单才能异常结束。',
                    'charge_record_id' => $chargeRecordId,
                ];
            }

            $checkOutAt = date('Y-m-d H:i:s');

            $settlement = $this->billingCalculator->calculate(
                $record->getCheckInAt(),
                $checkOutAt,
                $record->getHourlyRateSnapshot()
            );

            $billableMinutes = $settlement['billable_minutes'];
            $totalCost = $settlement['total_cost'];

            $wasUpdated = $this->recordRepository->markAsAbnormal(
                $chargeRecordId,
                $checkOutAt,
                $billableMinutes,
                $totalCost,
                $remark
            );

            if(!$wasUpdated){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '订单状态已经发生变化，请刷新后重试。',
                    'charge_record_id' => $chargeRecordId,
                ];
            }

            $this->connection->commit();

            return [
                'success' => true,
                'message' => '订单已异常结束并完成结算。',
                'charge_record_id' => $chargeRecordId,
                'check_out_at' => $checkOutAt,
                'billable_minutes' => $billableMinutes,
                'total_cost' => $totalCost,
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '管理员异常结束订单流程异常。', [
                'operator_user_id' => $operatorUserId,
                'charge_record_id' => $chargeRecordId,
            ]);

            throw new RuntimeException(
                '异常结束订单失败：' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * 生成唯一的订单业务编号。
     */
    private function generateOrderNumber(): string
    {
        for($attempt = 0; $attempt < 5; $attempt++){
            $orderNumber = 'CR-'
                . date('YmdHis')
                . '-'
                . strtoupper(bin2hex(random_bytes(3)));

            if($this->recordRepository->findByOrderNumber($orderNumber) === null){
                return $orderNumber;
            }
        }

        throw new RuntimeException('生成唯一充电订单编号失败。');
    }
}