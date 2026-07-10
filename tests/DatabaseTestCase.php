<?php

declare(strict_types=1);

namespace Tests;

use App\Models\ChargingStation;
use App\Models\Location;
use App\Models\User;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use App\Services\ChargeRecordService;
use mysqli;
use RuntimeException;

abstract class DatabaseTestCase extends TestCase
{
    protected mysqli $connection;

    public function setUp(): void
    {
        parent::setUp();

        $config = $this->loadDatabaseConfig();
        $this->resetTestDatabase($config);

        $this->connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            (int)$config['port']
        );

        if($this->connection->connect_error){
            throw new RuntimeException('测试数据库连接失败：' . $this->connection->connect_error);
        }

        if(!$this->connection->set_charset($config['charset'])){
            throw new RuntimeException('测试数据库字符集设置失败。');
        }
    }

    public function tearDown(): void
    {
        if(isset($this->connection)){
            $this->connection->close();
        }

        parent::tearDown();
    }

    protected function createChargeRecordService(): ChargeRecordService
    {
        return new ChargeRecordService(
            $this->connection,
            new ChargeRecordRepository($this->connection),
            new ChargingStationRepository($this->connection),
            new LocationRepository($this->connection),
            new UserRepository($this->connection)
        );
    }

    protected function createTestUser(
        int $index,
        string $role = 'user',
        string $status = 'active'
    ): int {
        $repository = new UserRepository($this->connection);
        $now = date('Y-m-d H:i:s');

        $user = new User(
            null,
            sprintf('tu%04d', $index),
            password_hash('Aa123456!', PASSWORD_DEFAULT),
            '测试用户',
            sprintf('135%08d', $index),
            sprintf('tu%04d@example.com', $index),
            $role,
            $status,
            null,
            $now,
            $now
        );

        return $repository->create($user);
    }

    protected function createTestLocation(
        int $index,
        string $status = 'active'
    ): int {
        $repository = new LocationRepository($this->connection);
        $now = date('Y-m-d H:i:s');

        $location = new Location(
            null,
            sprintf('TLOC%03d', $index),
            '测试站点' . $index,
            '上海市',
            '上海市',
            '浦东新区',
            '测试路' . $index . '号',
            null,
            null,
            null,
            $status,
            $now,
            $now
        );

        return $repository->create($location);
    }

    protected function createTestStation(
        int $index,
        int $locationId,
        string $status = 'active',
        string $chargerType = 'dc'
    ): int {
        $repository = new ChargingStationRepository($this->connection);
        $now = date('Y-m-d H:i:s');

        $station = new ChargingStation(
            null,
            sprintf('TST%03d', $index),
            '测试充电桩' . $index,
            $locationId,
            $chargerType,
            '60.00',
            '12.00',
            $status,
            $now,
            $now
        );

        return $repository->create($station);
    }

    private function loadDatabaseConfig():array
    {
        $configPath = __DIR__ . '/database_test.php';

        if(!is_file($configPath)){
            throw new RuntimeException('缺少测试数据库配置文件：tests/database_test.php');
        }

        $config = require $configPath;

        foreach([
            'host',
            'username',
            'password',
            'database',
            'port',
            'charset',
            'schema_file'
        ] as $key){
            if(!array_key_exists($key, $config)){
                throw new RuntimeException('测试数据库配置缺少字段：' . $key);
            }
        }

        if($config['database'] === 'easy_ev_charging_system'){
            throw new RuntimeException('测试数据库不能使用正式数据库easy_ev_charging_system。');
        }

        if(!str_ends_with((string)$config['database'], '_test')){
            throw new RuntimeException('测试数据库名称必须以 _test 结尾，避免误删正式库。');
        }

        if(!is_file($config['schema_file'])){
            throw new RuntimeException('找不到数据库结构文件：' . $config['schema_file']);
        }

        return $config;
    }

    private function resetTestDatabase(array $config): void
    {
        $schemaSql = file_get_contents($config['schema_file']);

        if($schemaSql === false){
            throw new RuntimeException('读取数据库结构文件失败。');
        }

        $schemaSql = str_replace(
            'easy_ev_charging_system',
            (string)$config['database'],
            $schemaSql
        );

        $adminConnection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            '',
            (int)$config['port']
        );

        if($adminConnection->connect_error){
            throw new RuntimeException('测试数据库管理连接失败：' . $adminConnection->connect_error);
        }

        if(!$adminConnection->set_charset($config['charset'])){
            $adminConnection->close();
            throw new RuntimeException('测试数据库管理连接字符集设置失败。');
        }

        if(!$adminConnection->multi_query($schemaSql)){
            $error = $adminConnection->error;
            $adminConnection->close();
            throw new RuntimeException('初始化测试数据库失败：' . $error);
        }

        do{
            $result = $adminConnection->store_result();

            if($result !== false){
                $result->free();
            }

            if(!$adminConnection->more_results()){
                break;
            }

            if(!$adminConnection->next_result()){
                $error = $adminConnection->error;
                $adminConnection->close();
                throw new RuntimeException('执行测试数据库脚本失败：' . $error);
            }
        }while(true);

        $adminConnection->close();
    }
}