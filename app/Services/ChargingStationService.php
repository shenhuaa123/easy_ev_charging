<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Validator;
use App\Models\ChargingStation;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use mysqli;
use RuntimeException;
use Throwable;

class ChargingStationService
{
    private const ALLOWED_CHARGER_TYPES = [
        'ac',
        'dc',
    ];

    private const ALLOWED_STATUSES = [
        'active',
        'maintenance',
        'inactive',
    ];

    private mysqli $connection;
    private ChargingStationRepository $stationRepository;
    private LocationRepository $locationRepository;
    private AdminAuthorizationService $adminAuthorizationService;

    public function __construct(
        mysqli $connection,
        ChargingStationRepository $stationRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository
    ){
        $this->connection = $connection;
        $this->stationRepository = $stationRepository;
        $this->locationRepository = $locationRepository;
        $this->adminAuthorizationService = new AdminAuthorizationService(
            $userRepository
        );
    }

    /**
     * 新增充电桩。
     */
    public function create(int $operatorUserId, array $data): array
    {
        $authorizationError = 
            $this->adminAuthorizationService->validate(
                $operatorUserId
            );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
                'errors' => [],
                'station_id' => null,
            ];
        }

        $validator = new Validator();

        $stationCode = strtoupper(trim((string) ($data['station_code'] ?? '')));
        $stationName = trim((string) ($data['station_name'] ?? ''));
        $locationId = $data['location_id'] ?? null;
        $chargerType = strtolower(trim((string) ($data['charger_type'] ?? '')));
        $powerKw = trim((string) ($data['power_kw'] ?? ''));
        $hourlyRate = trim((string) ($data['hourly_rate'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'active'));

        $validator->lengthBetween(
            'station_code',
            $stationCode,
            '充电桩编号',
            3,
            40
        );

        if(
            !$validator->hasError('station_code')
            && !preg_match('/^[A-Z0-9-]+$/', $stationCode)
        ){
            $validator->addError(
                'station_code',
                '充电桩编号只能包含大写英文字母、数字和连字符。'
            );
        }

        $validator->lengthBetween(
            'station_name',
            $stationName,
            '充电桩名称',
            2,
            100
        );

        $validator->positiveInteger(
            'location_id',
            $locationId,
            '所属充电站点'
        );

        if(!in_array($chargerType, self::ALLOWED_CHARGER_TYPES, true)){
            $validator->addError(
                'charger_type',
                '充电类型不合法。'
            );
        }

        $this->validatePositiveDecimal(
            $validator,
            'power_kw',
            $powerKw,
            '充电功率'
        );

        $validator->nonNegativeMoney(
            'hourly_rate',
            $hourlyRate,
            '每小时费用'
        );

        if(!in_array($status, self::ALLOWED_STATUSES, true)){
            $validator->addError(
                'status',
                '充电桩状态不合法。'
            );
        }

        if(
            !$validator->hasError('station_code')
            && $this->stationRepository->findByCode($stationCode) !== null
        ){
            $validator->addError(
                'station_code',
                '该充电桩编号已被使用。'
            );
        }

        if(!$validator->hasError('location_id')){
            $location = $this->locationRepository->findById((int)$locationId);

            if($location === null){
                $validator->addError('location_id', '所选择的充电站点不存在。');
            }elseif($location->isInactive()){
                $validator->addError('location_id', '已停用的充电站点不能新增充电桩。');
            }elseif($status === 'active' && !$location->isActive()){
                $validator->addError(
                    'status',
                    '所属站点未处于运营状态，不能将新充电桩设置为可用。'
                );
            }
        }

        if($validator->hasErrors()){
            return [
                'success' => false,
                'errors' => $validator->getErrors(),
                'station_id' => null,
            ];
        }

        $now = date('Y-m-d H:i:s');

        $station = new ChargingStation(
            null,
            $stationCode,
            $stationName,
            (int) $locationId,
            $chargerType,
            $powerKw,
            $hourlyRate,
            $status,
            $now,
            $now
        );

        $stationId = $this->stationRepository->create($station);

        return [
            'success' => true,
            'errors' => [],
            'station_id' => $stationId,
        ];
    }

    /**
     * 更新充电桩资料。
     */
    public function update(
        int $operatorUserId,
        int $stationId,
        array $data
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
                'errors' => [],
            ];
        }

        if($stationId <= 0){
            return [
                'success' => false,
                'message' => '充电桩编号不合法。',
                'errors' => [],
            ];
        }

        $station = $this->stationRepository->findById($stationId);

        if($station === null){
            return [
                'success' => false,
                'message' => '未找到指定的充电桩。',
                'errors' => [],
            ];
        }

        $stationCode = strtoupper(trim((string)($data['station_code'] ?? '')));
        $stationName = trim((string)($data['station_name'] ?? ''));
        $locationId = filter_var(
            $data['location_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );
        $chargerType = trim((string)($data['charger_type'] ?? ''));
        $powerKw = trim((string)($data['power_kw'] ?? ''));
        $hourlyRate = trim((string)($data['hourly_rate'] ?? ''));

        $errors = [];

        if($stationCode === ''){
            $errors['station_code'][] = '请输入充电桩编号。';
        }elseif(strlen($stationCode) < 3 || strlen($stationCode) > 40){
            $errors['station_code'][] = '充电桩编号长度必须在3到40个字符之间。';
        }elseif(!preg_match('/^[A-Z0-9-]+$/', $stationCode)){
            $errors['station_code'][] = '充电桩编号只能包含大写英文字母、数字和连字符。';
        }

        if($stationName === ''){
            $errors['station_name'][] = '请输入充电桩名称。';
        }elseif(
            mb_strlen($stationName, 'UTF-8') < 2
            || mb_strlen($stationName, 'UTF-8') > 100
        ){
            $errors['station_name'][] = '充电桩名称长度必须在2到100个字符之间。';
        }

        if($locationId === false || $locationId === null){
            $errors['location_id'][] = '请选择有效的所属充电站点。';
        }

        if(!in_array($chargerType, self::ALLOWED_CHARGER_TYPES, true)){
            $errors['charger_type'][] = '请选择有效的充电类型。';
        }

        if($powerKw === ''){
            $errors['power_kw'][] = '请输入充电功率。';
        }elseif(!preg_match('/^\d+(?:\.\d{1,2})?$/', $powerKw)){
            $errors['power_kw'][] = '充电功率必须是大于0的数字，并且最多保留两位小数。';
        }elseif((float)$powerKw <= 0){
            $errors['power_kw'][] = '充电功率必须大于0。';
        }elseif((float)$powerKw > 1000){
            $errors['power_kw'][] = '充电功率不能超过1000千瓦。';
        }

        if($hourlyRate === ''){
            $errors['hourly_rate'][] = '请输入每小时费用。';
        }elseif(!preg_match('/^\d+(?:\.\d{1,2})?$/', $hourlyRate)){
            $errors['hourly_rate'][] = '每小时费用必须是大于或等于0的金额，并且最多保留两位小数。';
        }elseif((float)$hourlyRate < 0){
            $errors['hourly_rate'][] = '每小时费用不能小于0。';
        }elseif((float)$hourlyRate > 99999999.99){
            $errors['hourly_rate'][] = '每小时费用超出系统允许范围。';
        }

        if($errors !== []){
            return [
                'success' => false,
                'message' => '充电桩资料验证失败。',
                'errors' => $errors,
            ];
        }

        $location = $this->locationRepository->findById((int)$locationId);

        if($location === null){
            return [
                'success' => false,
                'message' => '充电桩资料验证失败。',
                'errors' => [
                    'location_id' => [
                        '所选充电站点不存在。',
                    ],
                ],
            ];
        }

        if(
            $this->stationRepository->existsByCodeExceptId(
                $stationCode,
                $stationId
            )
        ){
            return [
                'success' => false,
                'message' => '充电桩资料验证失败。',
                'errors' => [
                    'station_code' => [
                        '该充电桩编号已被其他设备使用。',
                    ],
                ],
            ];
        }

        $stationCodeChanged = $station->getStationCode() !== $stationCode;
        $stationNameChanged = $station->getStationName() !== $stationName;
        $locationChanged = $station->getLocationId() !== (int)$locationId;
        $chargerTypeChanged = $station->getChargerType() !== $chargerType;
        $powerChanged = $this->normalizeDecimal($station->getPowerKw())
            !== $this->normalizeDecimal($powerKw);
        $hourlyRateChanged = $this->normalizeDecimal($station->getHourlyRate())
            !== $this->normalizeDecimal($hourlyRate);

        if($locationChanged && $station->getStatus() === 'active' && !$location->isActive()){
            return [
                'success' => false,
                'message' => '充电桩资料验证失败。',
                'errors' => [
                    'location_id' => [
                        '可用状态的充电桩不能转移到未运营的站点，请先修改设备状态。',
                    ],
                ],
            ];
        }

        $restrictedFieldChanged = $stationCodeChanged
            || $locationChanged
            || $chargerTypeChanged
            || $powerChanged;

        if(
            $restrictedFieldChanged
            && $this->stationRepository->hasActiveChargeRecord($stationId)
        ){
            return [
                'success' => false,
                'message' => '该充电桩当前正在使用，不能修改设备编号、所属站点、充电类型或功率。',
                'errors' => [],
            ];
        }

        $hasChanged = $stationCodeChanged
            || $stationNameChanged
            || $locationChanged
            || $chargerTypeChanged
            || $powerChanged
            || $hourlyRateChanged;

        if(!$hasChanged){
            return [
                'success' => false,
                'message' => '充电桩资料没有发生变化。',
                'errors' => [],
            ];
        }

        $updatedStation = new ChargingStation(
            $stationId,
            $stationCode,
            $stationName,
            (int)$locationId,
            $chargerType,
            $powerKw,
            $hourlyRate,
            $station->getStatus(),
            $station->getCreatedAt(),
            $station->getUpdatedAt()
        );

        $wasUpdated = $this->stationRepository->update($updatedStation);

        if(!$wasUpdated){
            return [
                'success' => false,
                'message' => '充电桩资料未能更新，请稍后重试。',
                'errors' => [],
            ];
        }

        return [
            'success' => true,
            'message' => '充电桩资料更新成功。',
            'errors' => [],
        ];
    }

    /**
     * 修改充电桩状态。
     */
    public function updateStatus(
        int $operatorUserId,
        int $stationId,
        string $newStatus
    ): array {
        $authorizationError = 
            $this->adminAuthorizationService->validate(
                $operatorUserId
            );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
            ];
        }

        if($stationId <= 0){
            return [
                'success' => false,
                'message' => '充电桩编号不合法。',
            ];
        }

        $newStatus = trim($newStatus);

        if(!in_array($newStatus, self::ALLOWED_STATUSES, true)){
            return [
                'success' => false,
                'message' => '充电桩状态不合法。',
            ];
        }

        /*
        * 先读取一次，只用于取得所属站点编号。
        * 真正的状态判断会在加锁后重新执行。
        */
        $stationSnapshot = $this->stationRepository->findById($stationId);

        if($stationSnapshot === null){
            return [
                'success' => false,
                'message' => '未找到指定的充电桩。',
            ];
        }

        $locationId = $stationSnapshot->getLocationId();

        if(!$this->connection->begin_transaction()){
            throw new RuntimeException('充电桩状态更新事务启动失败。');
        }

        try{
            /*
            * 必须先锁站点，再锁充电桩。
            * 开始充电同样采用这一顺序。
            */
            if(!$this->locationRepository->lockByIdForUpdate($locationId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩所属站点不存在。',
                ];
            }

            if(!$this->stationRepository->lockByIdForUpdate($stationId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电桩。',
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
                    'message' => '充电桩资料已经发生变化，请刷新页面后重试。',
                ];
            }

            $location = $this->locationRepository->findById($locationId);

            if($location === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩所属站点不存在。',
                ];
            }

            if($station->getStatus() === $newStatus){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '充电桩当前已经是该状态。',
                ];
            }

            if($location->getStatus() === 'maintenance' && $newStatus === 'active'){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '所属站点正在维护，不能将充电桩调整为可用状态。',
                ];
            }

            if($location->getStatus() === 'inactive' && $newStatus !== 'inactive'){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '所属站点已停用，充电桩只能保持停用状态。',
                ];
            }

            if(
                in_array($newStatus, ['maintenance', 'inactive'], true)
                && $this->stationRepository->hasActiveChargeRecord($stationId)
            ){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该充电桩当前正在使用，不能进入维护或停用状态。',
                ];
            }

            $wasUpdated = $this->stationRepository->updateStatus(
                $stationId,
                $newStatus
            );

            if(!$wasUpdated){
                throw new RuntimeException('充电桩状态更新失败。');
            }

            if(!$this->connection->commit()){
                throw new RuntimeException('充电桩状态更新事务提交失败。');
            }

            return [
                'success' => true,
                'message' => '充电桩状态修改成功。',
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '充电桩状态修改流程异常。', [
                'operator_user_id' => $operatorUserId,
                'station_id' => $stationId,
                'new_status' => $newStatus,
            ]);

            throw new RuntimeException(
                '充电桩状态修改失败。',
                0,
                $exception
            );
        }
    }

    /**
     * 验证必须大于0、最多保留两位小数的数值。
     */
    private function validatePositiveDecimal(
        Validator $validator,
        string $field,
        string $value,
        string $label
    ): void {
        if(!$validator->required($field, $value, $label)){
            return;
        }

        if(!preg_match('/^\d+(\.\d{1,2})?$/', $value)){
            $validator->addError($field, $label . '必须是大于0的数字，并且最多保留两位小数。');
            return;
        }

        if((float) $value <= 0){
            $validator->addError($field, $label . '必须大于0。');
            return;
        }

        if((float)$value > 1000){
            $validator->addError($field, $label . '不能超过1000千瓦。');
        }
    }

    /**
     * 统一DECIMAL数值格式，用于比较编辑前后的数值。
     */
    private function normalizeDecimal(string $value): string
    {
        return number_format(
            (float)$value,
            2,
            '.',
            ''
        );
    }
}