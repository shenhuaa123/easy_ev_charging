<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use mysqli;
use RuntimeException;

class UserRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 在当前事务中锁定指定用户记录。
     */
    public function lockByIdForUpdate(int $userId): bool
    {
        $sql = '
            SELECT user_id
            FROM users
            WHERE user_id = ?
            FOR UPDATE
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户记录锁定SQL预处理失败。');
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('锁定用户记录失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    public function findById(int $userId): ?User
    {
        $sql = '
            SELECT
                user_id,
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status,
                last_login_at,
                created_at,
                updated_at
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '用户查询SQL预处理失败。'
            );
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '根据用户编号查询用户失败。'
            );
        }

        $result = $statement->get_result();

        $userData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($userData === null){
            return null;
        }

        return $this->mapToUser($userData);
    }

    public function findByUsername(string $username): ?User
    {
        $sql = '
            SELECT
                user_id,
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status,
                last_login_at,
                created_at,
                updated_at
            FROM users
            WHERE username = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '用户名查询SQL预处理失败。'
            );
        }

        $statement->bind_param('s', $username);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '根据用户名查询用户失败。'
            );
        }

        $result = $statement->get_result();

        $userData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($userData === null){
            return null;
        }

        return $this->mapToUser($userData);
    }

    public function findByMobile(string $mobile): ?User
    {
        $sql = '
            SELECT
                user_id,
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status,
                last_login_at,
                created_at,
                updated_at
            FROM users
            WHERE mobile = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '手机号查询SQL预处理失败。'
            );
        }

        $statement->bind_param('s', $mobile);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '根据手机号查询用户失败。'
            );
        }

        $result = $statement->get_result();

        $userData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($userData === null){
            return null;
        }

        return $this->mapToUser($userData);
    }

    public function findByEmail(string $email): ?User
    {
        $sql = '
            SELECT
                user_id,
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status,
                last_login_at,
                created_at,
                updated_at
            FROM users
            WHERE email = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '邮箱查询SQL预处理失败。'
            );
        }

        $statement->bind_param('s', $email);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '根据邮箱查询用户失败。'
            );
        }

        $result = $statement->get_result();

        $userData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($userData === null){
            return null;
        }

        return $this->mapToUser($userData);
    }

    public function create(User $user): int
    {
        $sql = '
            INSERT INTO users (
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '用户新增SQL预处理失败。'
            );
        }

        $username = $user->getUsername();
        $passwordHash = $user->getPasswordHash();
        $realName = $user->getRealName();
        $mobile = $user->getMobile();
        $email = $user->getEmail();
        $role = $user->getRole();
        $status = $user->getStatus();

        $statement->bind_param(
            'sssssss',
            $username,
            $passwordHash,
            $realName,
            $mobile,
            $email,
            $role,
            $status
        );

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '创建用户失败。'
            );
        }

        $userId = $statement->insert_id;

        $statement->close();

        return $userId;
    }

    public function updateLastLoginAt(int $userId): bool
    {
        $sql = '
            UPDATE users
            SET last_login_at = NOW()
            WHERE user_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException(
                '登录时间更新SQL预处理失败。'
            );
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException(
                '更新最近登录时间失败。'
            );
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 根据管理员筛选条件分页查询用户。
     * 
     * @return User[]
     */
    public function searchAdminList(array $filters, int $limit, int $offset): array
    {
        if($limit <= 0){
            throw new RuntimeException('每页用户数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('用户分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeAdminListFilters($filters);
        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $role = $filterValues['role'];
        $status = $filterValues['status'];
        $adminRole = 'admin';

        $sql = '
            SELECT
                user_id,
                username,
                password_hash,
                real_name,
                mobile,
                email,
                role,
                status,
                last_login_at,
                created_at,
                updated_at
            FROM users
            WHERE (
                ? = 0
                OR username LIKE ?
                OR real_name LIKE ?
                OR mobile LIKE ?
                OR email LIKE ?
            )
            AND (CHAR_LENGTH(?) = 0 OR role = ?)
            AND (CHAR_LENGTH(?) = 0 OR status = ?)
            ORDER BY
                CASE
                    WHEN role = ? THEN 1
                    ELSE 0
                END ASC,
                user_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户管理列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'isssssssssii',
            $hasKeyword,
            $keywordPattern,
            $keywordPattern,
            $keywordPattern,
            $keywordPattern,
            $role,
            $role,
            $status,
            $status,
            $adminRole,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询用户管理列表失败。');
        }

        $result = $statement->get_result();
        $users = [];

        while($userData = $result->fetch_assoc()){
            $users[] = $this->mapToUser($userData);
        }

        $result->free();
        $statement->close();

        return $users;
    }

    /**
     * 汇总管理员用户列表筛选结果。
     */
    public function getAdminListSummary(array $filters): array
    {
        $filterValues = $this->normalizeAdminListFilters($filters);
        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $role = $filterValues['role'];
        $status = $filterValues['status'];

        $sql = '
            SELECT
                COUNT(*) AS total_users,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status = "active" THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS active_users,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status = "disabled" THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS disabled_users,

                COALESCE(
                    SUM(
                        CASE
                            WHEN role = "admin" THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS admin_users,

                COALESCE(
                    SUM(
                        CASE
                            WHEN role = "user" THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS normal_users

            FROM users
            WHERE (
                ? = 0
                OR username LIKE ?
                OR real_name LIKE ?
                OR mobile LIKE ?
                OR email LIKE ?
            )
            AND (CHAR_LENGTH(?) = 0 OR role = ?)
            AND (CHAR_LENGTH(?) = 0 OR status = ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户管理列表汇总SQL预处理失败。');
        }

        $statement->bind_param(
            'issssssss',
            $hasKeyword,
            $keywordPattern,
            $keywordPattern,
            $keywordPattern,
            $keywordPattern,
            $role,
            $role,
            $status,
            $status
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('汇总用户管理列表失败。');
        }

        $result = $statement->get_result();
        $summaryData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return [
            'total_users' => (int)($summaryData['total_users'] ?? 0),
            'active_users' => (int)($summaryData['active_users'] ?? 0),
            'disabled_users' => (int)($summaryData['disabled_users'] ?? 0),
            'admin_users' => (int)($summaryData['admin_users'] ?? 0),
            'normal_users' => (int)($summaryData['normal_users'] ?? 0),
        ];
    }

    /**
     * 更新用户账户状态。
     */
    public function updateStatus(int $userId, string $status): bool
    {
        $sql = '
            UPDATE users
            SET status = ?
            WHERE user_id = ?
            AND status <> ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户账户状态更新SQL预处理失败。');
        }

        $statement->bind_param(
            'sis',
            $status,
            $userId,
            $status
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新用户账户状态失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 更新普通用户的个人资料。
     */
    public function updateProfile(
        int $userId,
        string $realName,
        string $mobile,
        ?string $email
    ): bool {
        $sql = '
            UPDATE users
            SET
                real_name = ?,
                mobile = ?,
                email = ?
            WHERE user_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户个人资料更新SQL预处理失败。');
        }

        $statement->bind_param(
            'sssi',
            $realName,
            $mobile,
            $email,
            $userId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新用户个人资料失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 更新用户密码哈希。
     */
    public function updatePasswordHash(int $userId, string $passwordHash): bool 
    {
        $sql = '
            UPDATE users
            SET password_hash = ?
            WHERE user_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户密码更新SQL预处理失败。');
        }

        $statement->bind_param('si', $passwordHash, $userId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新用户密码失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 判断手机号是否已被其他用户使用。
     */
    public function existsByMobileExceptId(string $mobile, int $excludedUserId): bool 
    {
        $sql = '
            SELECT 1
            FROM users
            WHERE mobile = ?
            AND user_id <> ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('手机号重复检查SQL预处理失败。');
        }

        $statement->bind_param('si', $mobile, $excludedUserId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查手机号是否重复失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 判断电子邮箱是否已被其他用户使用。
     */
    public function existsByEmailExceptId(string $email, int $excludedUserId): bool 
    {
        $sql = '
            SELECT 1
            FROM users
            WHERE email = ?
            AND user_id <> ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('电子邮箱重复检查SQL预处理失败。');
        }

        $statement->bind_param('si', $email, $excludedUserId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查电子邮箱是否重复失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 判断用户名是否已被其他用户使用。
     */
    public function existsByUsernameExceptId(string $username, int $excludedUserId): bool 
    {
        $sql = '
            SELECT 1
            FROM users
            WHERE username = ?
            AND user_id <> ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户名重复检查SQL预处理失败。');
        }

        $statement->bind_param('si', $username, $excludedUserId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查用户名是否重复失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 管理员更新普通用户资料。
     */
    public function updateManagedProfile(
        int $userId,
        string $username,
        string $realName,
        string $mobile,
        ?string $email
    ): bool {
        $sql = '
            UPDATE users
            SET
                username = ?,
                real_name = ?,
                mobile = ?,
                email = ?
            WHERE user_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员更新用户资料SQL预处理失败。');
        }

        $statement->bind_param(
            'ssssi',
            $username,
            $realName,
            $mobile,
            $email,
            $userId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('管理员更新用户资料失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 整理用户管理列表筛选条件。
     */
    private function normalizeAdminListFilters(array $filters): array
    {
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $role = trim((string)($filters['role'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));

        if(!in_array($role, ['admin', 'user'], true)){
            $role = '';
        }

        if(!in_array($status, ['active', 'disabled'], true)){
            $status = '';
        }

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',
            'role' => $role,
            'status' => $status,
        ];
    }

    private function mapToUser(array $userData): User
    {
        return new User(
            (int) $userData['user_id'],
            $userData['username'],
            $userData['password_hash'],
            $userData['real_name'],
            $userData['mobile'],
            $userData['email'],
            $userData['role'],
            $userData['status'],
            $userData['last_login_at'],
            $userData['created_at'],
            $userData['updated_at']
        );
    }
}