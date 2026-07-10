<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Validator;
use App\Models\Location;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use mysqli;
use RuntimeException;
use Throwable;

class LocationService
{
    private const ALLOWED_STATUSES = [
        'active',
        'maintenance',
        'inactive',
    ];

    private mysqli $connection;

    private LocationRepository $locationRepository;

    private ChargingStationRepository $stationRepository;
    
    private AdminAuthorizationService $adminAuthorizationService;

    public function __construct(
        mysqli $connection,
        LocationRepository $locationRepository,
        ChargingStationRepository $stationRepository,
        UserRepository $userRepository
    ){
        $this->connection = $connection;
        $this->locationRepository = $locationRepository;
        $this->stationRepository = $stationRepository;
        $this->adminAuthorizationService = new AdminAuthorizationService(
            $userRepository
        );
    }

    /**
     * 新增充电站点。
     */
    public function create(int $operatorUserId, array $data): array
    {
        $authorizationError = $this->adminAuthorizationService->validate(
            $operatorUserId
        );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
                'errors' => [],
                'location_id' => null,
            ];
        }

        $validator = new Validator();

        $locationCode = strtoupper(trim((string) ($data['location_code'] ?? '')));
        $locationName = trim((string) ($data['location_name'] ?? ''));
        $province = trim((string) ($data['province'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $district = trim((string) ($data['district'] ?? ''));
        $detailedAddress = trim((string) ($data['detailed_address'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $longitude = trim((string) ($data['longitude'] ?? ''));
        $latitude = trim((string) ($data['latitude'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'active'));

        $validator->lengthBetween(
            'location_code',
            $locationCode,
            '充电站点编号',
            3,
            50
        );

        if(
            !$validator->hasError('location_code')
            && !preg_match('/^[A-Z0-9-]+$/', $locationCode)
        ){
            $validator->addError(
                'location_code',
                '充电站点编号只能包含大写英文字母、数字和连字符。'
            );
        }

        $validator->lengthBetween(
            'location_name',
            $locationName,
            '充电站点名称',
            2,
            100
        );

        $validator->lengthBetween(
            'province',
            $province,
            '省级行政区',
            2,
            50
        );

        $validator->lengthBetween(
            'city',
            $city,
            '城市',
            2,
            50
        );

        $validator->lengthBetween(
            'district',
            $district,
            '区县',
            2,
            50
        );

        $validator->lengthBetween(
            'detailed_address',
            $detailedAddress,
            '详细地址',
            2,
            255
        );

        if($description !== '' && mb_strlen($description, 'UTF-8') > 500){
            $validator->addError(
                'description',
                '站点说明不能超过500个字符。'
            );
        }

        $this->validateCoordinates($validator, $longitude, $latitude);

        if(!in_array($status, self::ALLOWED_STATUSES, true)){
            $validator->addError(
                'status',
                '充电站点状态不合法。'
            );
        }

        if(
            !$validator->hasError('location_code')
            && $this->locationRepository->findByCode($locationCode) !== null
        ){
            $validator->addError(
                'location_code',
                '该充电站点编号已被使用。'
            );
        }

        if($validator->hasErrors()){
            return [
                'success' => false,
                'errors' => $validator->getErrors(),
                'location_id' => null,
            ];
        }

        $now = date('Y-m-d H:i:s');

        $location = new Location(
            null,
            $locationCode,
            $locationName,
            $province,
            $city,
            $district,
            $detailedAddress,
            $description === '' ? null : $description,
            $longitude === '' ? null : $longitude,
            $latitude === '' ? null : $latitude,
            $status,
            $now,
            $now
        );

        try{
            $locationId = $this->locationRepository->create($location);
        }catch(Throwable $exception){
            Logger::exception($exception, '新增充电站点流程异常。', [
                'operator_user_id' => $operatorUserId,
                'location_code' => $locationCode,
                'location_name' => $locationName,
                'status' => $status,
            ]);

            throw new RuntimeException(
                '新增充电站点失败。',
                0,
                $exception
            );
        }

        return [
            'success' => true,
            'errors' => [],
            'location_id' => $locationId,
        ];
    }

    /**
     * 更新充电站点资料。
     */
    public function update(
        int $operatorUserId,
        int $locationId,
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

        if($locationId <= 0){
            return [
                'success' => false,
                'message' => '充电站点编号不合法。',
                'errors' => [],
            ];
        }

        $location = $this->locationRepository->findById($locationId);

        if($location === null){
            return [
                'success' => false,
                'message' => '未找到指定的充电站点。',
                'errors' => [],
            ];
        }

        $locationName = trim((string) ($data['location_name'] ?? ''));
        $province = trim((string) ($data['province'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $district = trim((string) ($data['district'] ?? ''));
        $detailedAddress = trim((string) ($data['detailed_address'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $longitude = trim((string) ($data['longitude'] ?? ''));
        $latitude = trim((string) ($data['latitude'] ?? ''));

        $errors = [];

        if($locationName === ''){
            $errors['location_name'][] = '请输入充电站点名称。';
        }elseif(
            mb_strlen($locationName, 'UTF-8') < 2
            || mb_strlen($locationName, 'UTF-8') > 100
        ){
            $errors['location_name'][] = '充电站点名称长度必须在2到100个字符之间。';
        }

        if($province === ''){
            $errors['province'][] = '请输入省级行政区。';
        }elseif(mb_strlen($province, 'UTF-8') < 2 || mb_strlen($province, 'UTF-8') > 50){
            $errors['province'][] = '省级行政区名称长度必须在2到50个字符之间。';
        }

        if($city === ''){
            $errors['city'][] = '请输入城市名称。';
        }elseif(mb_strlen($city, 'UTF-8') < 2 || mb_strlen($city, 'UTF-8') > 50){
            $errors['city'][] = '城市名称长度必须在2到50个字符之间。';
        }

        if($district === ''){
            $errors['district'][] = '请输入区县名称。';
        }elseif(mb_strlen($district, 'UTF-8') < 2 || mb_strlen($district, 'UTF-8') > 50){
            $errors['district'][] = '区县名称长度必须在2到50个字符之间。';
        }

        if($detailedAddress === ''){
            $errors['detailed_address'][] = '请输入详细地址。';
        }elseif(
            mb_strlen($detailedAddress, 'UTF-8') < 2
            || mb_strlen($detailedAddress, 'UTF-8') > 255
        ){
            $errors['detailed_address'][] = '详细地址长度必须在2到255个字符之间。';
        }

        if($description !== '' && mb_strlen($description, 'UTF-8') > 500){
            $errors['description'][] = '站点说明不能超过500个字符。';
        }

        if(($longitude === '') !== ($latitude === '')){
            $errors['longitude'][] = '经度和纬度必须同时填写或同时留空。';
            $errors['latitude'][] = '经度和纬度必须同时填写或同时留空。';
        }else{
            if($longitude !== ''){
                if(!preg_match('/^-?(?:\d{1,3})(?:\.\d{1,7})?$/', $longitude)){
                    $errors['longitude'][] = '经度格式不正确，最多保留7位小数。';
                }elseif((float)$longitude < -180 || (float)$longitude > 180){
                    $errors['longitude'][] = '经度必须在-180到180之间。';
                }
            }

            if($latitude !== ''){
                if(!preg_match('/^-?(?:\d{1,2})(?:\.\d{1,7})?$/', $latitude)){
                    $errors['latitude'][] = '纬度格式不正确，最多保留7位小数。';
                }elseif((float)$latitude < -90 || (float)$latitude > 90){
                    $errors['latitude'][] = '纬度必须在-90到90之间。';
                }
            }
        }

        if($errors !== []){
            return [
                'success' => false,
                'message' => '站点资料验证失败。',
                'errors' => $errors,
            ];
        }

        if(
            $this->locationRepository->existsByNameAndAddressExceptId(
                $locationName,
                $province,
                $city,
                $district,
                $detailedAddress,
                $locationId
            )
        ){
            return [
                'success' => false,
                'message' => '站点资料验证失败。',
                'errors' => [
                    'location_name' => [
                        '已存在名称和地址完全相同的充电站点。',
                    ],
                ],
            ];
        }

        $description = $description === '' ? null : $description;
        $longitude = $longitude === '' ? null : $longitude;
        $latitude = $latitude === '' ? null : $latitude;

        $hasChanged = $location->getLocationName() !== $locationName
            || $location->getProvince() !== $province
            || $location->getCity() !== $city
            || $location->getDistrict() !== $district
            || $location->getDetailedAddress() !== $detailedAddress
            || $location->getDescription() !== $description
            || $location->getLongitude() !== $longitude
            || $location->getLatitude() !== $latitude;

        if(!$hasChanged){
            return [
                'success' => false,
                'message' => '站点资料没有发生变化。',
                'errors' => [],
            ];
        }

        $updatedLocation = new Location(
            $locationId,
            $location->getLocationCode(),
            $locationName,
            $province,
            $city,
            $district,
            $detailedAddress,
            $description,
            $longitude,
            $latitude,
            $location->getStatus(),
            $location->getCreatedAt(),
            $location->getUpdatedAt()
        );

        try{
            $wasUpdated = $this->locationRepository->update($updatedLocation);
        }catch(Throwable $exception){
            Logger::exception($exception, '编辑充电站点资料流程异常。', [
                'operator_user_id' => $operatorUserId,
                'location_id' => $locationId,
                'location_name' => $locationName,
            ]);

            throw new RuntimeException(
                '编辑充电站点资料失败。',
                0,
                $exception
            );
        }

        if(!$wasUpdated){
            Logger::error('编辑充电站点资料未影响任何记录。', [
                'operator_user_id' => $operatorUserId,
                'location_id' => $locationId,
                'location_name' => $locationName,
            ]);

            return [
                'success' => false,
                'message' => '充电站点资料未能更新，请稍后重试。',
                'errors' => [],
            ];
        }

        return [
            'success' => true,
            'message' => '充电站点资料更新成功。',
            'errors' => [],
        ];
    }

    /**
     * 修改充电站点状态。
     */
    public function updateStatus(
        int $operatorUserId,
        int $locationId,
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

        if($locationId <= 0){
            return [
                'success' => false,
                'message' => '充电站点编号不合法。',
            ];
        }

        $newStatus = trim($newStatus);

        if(!in_array($newStatus, self::ALLOWED_STATUSES, true)){
            return [
                'success' => false,
                'message' => '充电站点状态不合法。',
            ];
        }

        if(!$this->connection->begin_transaction()){
            throw new RuntimeException('充电站点状态更新事务启动失败。');
        }

        try{
            if(!$this->locationRepository->lockByIdForUpdate($locationId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电站点。',
                ];
            }

            $location = $this->locationRepository->findById($locationId);

            if($location === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定的充电站点。',
                ];
            }

            if($location->getStatus() === $newStatus){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '充电站点当前已经是该状态。',
                ];
            }

            if(
                in_array($newStatus, ['maintenance', 'inactive'], true)
                && $this->locationRepository->hasActiveChargeRecords($locationId)
            ){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该站点仍有用户正在充电，不能进入维护或停用状态。',
                ];
            }

            $wasUpdated = $this->locationRepository->updateStatus(
                $locationId,
                $newStatus
            );

            if(!$wasUpdated){
                throw new RuntimeException('充电站点状态未能更新。');
            }

            $updatedStationCount = 0;

            if($newStatus === 'maintenance'){
                $updatedStationCount = $this->stationRepository
                    ->updateActiveToMaintenanceByLocationId($locationId);
            }elseif($newStatus === 'inactive'){
                $updatedStationCount = $this->stationRepository
                    ->updateActiveOrMaintenanceToInactiveByLocationId($locationId);
            }

            if(!$this->connection->commit()){
                throw new RuntimeException('充电站点状态更新事务提交失败。');
            }

            if($newStatus === 'maintenance'){
                return [
                    'success' => true,
                    'message' => '充电站点已进入维护状态，'
                        . $updatedStationCount
                        . '台可用充电桩已同步调整为维护中。',
                ];
            }

            if($newStatus === 'inactive'){
                return [
                    'success' => true,
                    'message' => '充电站点已停用，'
                        . $updatedStationCount
                        . '台充电桩已同步调整为停用状态。',
                ];
            }

            return [
                'success' => true,
                'message' => '充电站点已恢复运营。所属充电桩状态保持不变，请根据设备实际情况逐台调整。',
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '充电站点状态修改流程异常。', [
                'operator_user_id' => $operatorUserId,
                'location_id' => $locationId,
                'new_status' => $newStatus,
            ]);

            throw new RuntimeException(
                '充电站点及所属充电桩状态更新失败。',
                0,
                $exception
            );
        }
    }

    /**
     * 检查经纬度。
     *
     * 经度和纬度必须同时填写或同时留空。
     */
    private function validateCoordinates(
        Validator $validator,
        string $longitude,
        string $latitude
    ): void {
        if($longitude === '' && $latitude === ''){
            return;
        }

        if($longitude === '' || $latitude === ''){
            $validator->addError(
                'coordinates',
                '经度和纬度必须同时填写。'
            );

            return;
        }

        if(
            !is_numeric($longitude)
            || (float) $longitude < -180
            || (float) $longitude > 180
        ){
            $validator->addError(
                'longitude',
                '经度必须是-180到180之间的数字。'
            );
        }

        if(
            !is_numeric($latitude)
            || (float) $latitude < -90
            || (float) $latitude > 90
        ){
            $validator->addError(
                'latitude',
                '纬度必须是-90到90之间的数字。'
            );
        }

        if(
            is_numeric($longitude)
            && !preg_match('/^-?\d{1,3}(\.\d{1,7})?$/', $longitude)
        ){
            $validator->addError(
                'longitude',
                '经度最多保留7位小数。'
            );
        }

        if(
            is_numeric($latitude)
            && !preg_match('/^-?\d{1,2}(\.\d{1,7})?$/', $latitude)
        ){
            $validator->addError(
                'latitude',
                '纬度最多保留7位小数。'
            );
        }
    }
}