<?php

declare(strict_types=1);

namespace App\Core;

use mysqli;
use RuntimeException;

class Database
{
    private mysqli $connection;

    public function __construct(array $config)
    {
        $this->connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );

        if($this->connection->connect_error){
            throw new RuntimeException('数据库连接失败，请检查配置和数据库服务器状态。');
        }

        if(!$this->connection->set_charset($config['charset'])){
            throw new RuntimeException('设置数据库字符集失败，请检查数据库配置。');
        }
    }

    public function getConnection(): mysqli
    {
        return $this->connection;
    }
}
