<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;
use mysqli;
use RuntimeException;
use Throwable;

class UserService
{
    private mysqli $connection;
    private UserRepository $userRepository;
    private ChargeRecordRepository $recordRepository;

    public function __construct(
        mysqli $connection,
        UserRepository $userRepository,
        ChargeRecordRepository $recordRepository
    ){
        $this->connection = $connection;
        $this->userRepository = $userRepository;
        $this->recordRepository = $recordRepository;
    }

    /**
     * 管理员修改用户账户状态。
     */
    public function updateStatus(
        int $operatorUserId,
        int $targetUserId,
        string $newStatus
    ): array {
        if($operatorUserId <= 0 || $targetUserId <= 0){
            return [
                'success' => false,
                'message' => '管理员编号或目标用户编号不合法。',
            ];
        }

        if(!in_array($newStatus, ['active', 'disabled'], true)){
            return [
                'success' => false,
                'message' => '用户账户状态不合法。',
            ];
        }

        $operator = $this->userRepository->findById($operatorUserId);

        if($operator === null){
            return [
                'success' => false,
                'message' => '当前管理员账户不存在。',
            ];
        }

        if(!$operator->isAdmin()){
            return [
                'success' => false,
                'message' => '当前账户没有用户管理权限。',
            ];
        }

        if(!$operator->isActive()){
            return [
                'success' => false,
                'message' => '当前管理员账户已被停用。',
            ];
        }

        if($operatorUserId === $targetUserId){
            return [
                'success' => false,
                'message' => '管理员不能修改自己的账户状态。',
            ];
        }

        if(!$this->connection->begin_transaction()){
            throw new RuntimeException('用户账户状态更新事务启动失败。');
        }

        try{
            if(!$this->userRepository->lockByIdForUpdate($targetUserId)){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定用户。',
                ];
            }

            $targetUser = $this->userRepository->findById($targetUserId);

            if($targetUser === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '未找到指定用户。',
                ];
            }

            if($targetUser->isAdmin()){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '当前功能不允许修改其他管理员的账户状态。',
                ];
            }

            if($targetUser->getStatus() === $newStatus){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '用户账户状态没有发生变化。',
                ];
            }

            if(
                $newStatus === 'disabled'
                && $this->recordRepository->findActiveByUserId($targetUserId) !== null
            ){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '该用户当前仍有进行中的充电订单，不能停用账户。',
                ];
            }

            $wasUpdated = $this->userRepository->updateStatus(
                $targetUserId,
                $newStatus
            );

            if(!$wasUpdated){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '用户账户状态未能更新，请稍后重试。',
                ];
            }

            if(!$this->connection->commit()){
                throw new RuntimeException('用户账户状态更新事务提交失败。');
            }

            return [
                'success' => true,
                'message' => $newStatus === 'active'
                    ? '用户账户已启用。'
                    : '用户账户已停用。',
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '用户账户状态修改流程异常。', [
                'operator_user_id' => $operatorUserId,
                'target_user_id' => $targetUserId,
                'new_status' => $newStatus,
            ]);

            throw new RuntimeException(
                '用户账户状态更新失败。',
                0,
                $exception
            );
        }
    }

    /**
     * 管理员更新普通用户资料。
     */
    public function updateManagedProfile(
        int $operatorUserId,
        int $targetUserId,
        array $data
    ): array {
        if($operatorUserId <= 0 || $targetUserId <= 0){
            return [
                'success' => false,
                'message' => '管理员编号或目标用户编号不合法。',
                'errors' => [],
            ];
        }

        $operator = $this->userRepository->findById($operatorUserId);

        if($operator === null){
            return [
                'success' => false,
                'message' => '当前管理员账户不存在。',
                'errors' => [],
            ];
        }

        if(!$operator->isAdmin()){
            return [
                'success' => false,
                'message' => '当前账户没有用户资料管理权限。',
                'errors' => [],
            ];
        }

        if(!$operator->isActive()){
            return [
                'success' => false,
                'message' => '当前管理员账户已被停用。',
                'errors' => [],
            ];
        }

        $targetUser = $this->userRepository->findById($targetUserId);

        if($targetUser === null){
            return [
                'success' => false,
                'message' => '未找到指定用户。',
                'errors' => [],
            ];
        }

        if($targetUser->isAdmin()){
            return [
                'success' => false,
                'message' => '当前功能不允许修改管理员账户资料。',
                'errors' => [],
            ];
        }

        $username = trim((string)($data['username'] ?? ''));
        $realName = trim((string)($data['real_name'] ?? ''));
        $mobile = trim((string)($data['mobile'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));

        $validator = new Validator();

        $validator->username('username', $username, '用户名');
        $validator->realName('real_name', $realName, '真实姓名');
        $validator->mobile('mobile', $mobile, '手机号码');
        $validator->email('email', $email, '电子邮箱');

        $errors = $validator->getErrors();

        if($errors !== []){
            return [
                'success' => false,
                'message' => '用户资料验证失败。',
                'errors' => $errors,
            ];
        }

        if(
            $this->userRepository->existsByUsernameExceptId(
                $username,
                $targetUserId
            )
        ){
            return [
                'success' => false,
                'message' => '用户资料验证失败。',
                'errors' => [
                    'username' => [
                        '该用户名已被其他用户使用。',
                    ],
                ],
            ];
        }

        if(
            $this->userRepository->existsByMobileExceptId(
                $mobile,
                $targetUserId
            )
        ){
            return [
                'success' => false,
                'message' => '用户资料验证失败。',
                'errors' => [
                    'mobile' => [
                        '该手机号码已被其他用户使用。',
                    ],
                ],
            ];
        }

        $email = $email === '' ? null : $email;

        if(
            $email !== null
            && $this->userRepository->existsByEmailExceptId(
                $email,
                $targetUserId
            )
        ){
            return [
                'success' => false,
                'message' => '用户资料验证失败。',
                'errors' => [
                    'email' => [
                        '该电子邮箱已被其他用户使用。',
                    ],
                ],
            ];
        }

        $usernameChanged = $targetUser->getUsername() !== $username;
        $realNameChanged = $targetUser->getRealName() !== $realName;
        $mobileChanged = $targetUser->getMobile() !== $mobile;
        $emailChanged = $targetUser->getEmail() !== $email;

        $hasChanged = $usernameChanged
            || $realNameChanged
            || $mobileChanged
            || $emailChanged;

        if(!$hasChanged){
            return [
                'success' => false,
                'message' => '用户资料没有发生变化。',
                'errors' => [],
            ];
        }

        $wasUpdated = $this->userRepository->updateManagedProfile(
            $targetUserId,
            $username,
            $realName,
            $mobile,
            $email
        );

        if(!$wasUpdated){
            return [
                'success' => false,
                'message' => '用户资料未能更新，请稍后重试。',
                'errors' => [],
            ];
        }

        $message = '用户资料更新成功。';

        if($usernameChanged){
            $message .= ' 用户名已修改，请通知用户使用新用户名登录。';
        }

        return [
            'success' => true,
            'message' => $message,
            'errors' => [],
        ];
    }

    /**
     * 管理员重置普通用户登录密码。
     */
    public function resetManagedPassword(
        int $operatorUserId,
        int $targetUserId,
        array $data
    ): array {
        if($operatorUserId <= 0 || $targetUserId <= 0){
            return [
                'success' => false,
                'message' => '管理员编号或目标用户编号不合法。',
                'errors' => [],
            ];
        }

        $operator = $this->userRepository->findById($operatorUserId);

        if($operator === null){
            return [
                'success' => false,
                'message' => '当前管理员账户不存在。',
                'errors' => [],
            ];
        }

        if(!$operator->isAdmin()){
            return [
                'success' => false,
                'message' => '当前账户没有重置用户密码的权限。',
                'errors' => [],
            ];
        }

        if(!$operator->isActive()){
            return [
                'success' => false,
                'message' => '当前管理员账户已被停用。',
                'errors' => [],
            ];
        }

        $targetUser = $this->userRepository->findById($targetUserId);

        if($targetUser === null){
            return [
                'success' => false,
                'message' => '未找到指定用户。',
                'errors' => [],
            ];
        }

        if(
            $targetUser->isAdmin()
            || $operatorUserId === $targetUserId
        ){
            return [
                'success' => false,
                'message' => '当前功能不允许重置管理员账户密码。',
                'errors' => [],
            ];
        }

        $newPassword = (string)($data['new_password'] ?? '');
        $newPasswordConfirmation = (string)(
            $data['new_password_confirmation'] ?? ''
        );
        $confirmed = (string)($data['confirmed'] ?? '');

        $validator = new Validator();
        $validator->password('new_password', $newPassword, '新密码');

        $errors = $validator->getErrors();

        if($newPasswordConfirmation === ''){
            $errors['new_password_confirmation'][] = '请再次输入新的登录密码。';
        }elseif($newPassword !== $newPasswordConfirmation){
            $errors['new_password_confirmation'][] = '两次输入的新密码不一致。';
        }

        if($confirmed !== '1'){
            $errors['confirmed'][] = '请确认已经核实目标用户并同意重置密码。';
        }

        if($errors !== []){
            return [
                'success' => false,
                'message' => '用户密码重置验证失败。',
                'errors' => $errors,
            ];
        }

        if(
            password_verify(
                $newPassword,
                $targetUser->getPasswordHash()
            )
        ){
            return [
                'success' => false,
                'message' => '用户密码重置验证失败。',
                'errors' => [
                    'new_password' => [
                        '新密码不能与用户当前密码相同。',
                    ],
                ],
            ];
        }

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        if($passwordHash === false){
            return [
                'success' => false,
                'message' => '新密码加密失败，请稍后重试。',
                'errors' => [],
            ];
        }

        $wasUpdated = $this->userRepository->updatePasswordHash(
            $targetUserId,
            $passwordHash
        );

        if(!$wasUpdated){
            return [
                'success' => false,
                'message' => '用户密码未能重置，请稍后重试。',
                'errors' => [],
            ];
        }

        return [
            'success' => true,
            'message' => '用户登录密码重置成功，请通过安全方式通知用户。',
            'errors' => [],
        ];
    }

    /**
     * 普通用户修改个人资料。
     */
    public function updateProfile(int $userId, array $data): array
    {
        if($userId <= 0){
            return [
                'success' => false,
                'message' => '用户编号不合法。',
                'errors' => [],
            ];
        }

        $user = $this->userRepository->findById($userId);

        if($user === null){
            return [
                'success' => false,
                'message' => '当前用户不存在。',
                'errors' => [],
            ];
        }

        if(!$user->isActive()){
            return [
                'success' => false,
                'message' => '当前账户已被停用，不能修改个人资料。',
                'errors' => [],
            ];
        }

        $realName = trim((string)($data['real_name'] ?? ''));
        $mobile = trim((string)($data['mobile'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));

        $validator = new Validator();

        $validator->realName('real_name', $realName, '真实姓名');
        $validator->mobile('mobile', $mobile, '手机号码');
        $validator->email('email', $email, '电子邮箱');

        $errors = $validator->getErrors();

        if($errors !== []){
            return [
                'success' => false,
                'message' => '个人资料验证失败。',
                'errors' => $errors,
            ];
        }

        if(
            $this->userRepository->existsByMobileExceptId(
                $mobile,
                $userId
            )
        ){
            return [
                'success' => false,
                'message' => '个人资料验证失败。',
                'errors' => [
                    'mobile' => [
                        '该手机号码已被其他用户使用。',
                    ],
                ],
            ];
        }

        $email = $email === '' ? null : $email;

        if(
            $email !== null
            && $this->userRepository->existsByEmailExceptId(
                $email,
                $userId
            )
        ){
            return [
                'success' => false,
                'message' => '个人资料验证失败。',
                'errors' => [
                    'email' => [
                        '该电子邮箱已被其他用户使用。',
                    ],
                ],
            ];
        }

        $hasChanged = $user->getRealName() !== $realName
            || $user->getMobile() !== $mobile
            || $user->getEmail() !== $email;

        if(!$hasChanged){
            return [
                'success' => false,
                'message' => '个人资料没有发生变化。',
                'errors' => [],
            ];
        }

        $wasUpdated = $this->userRepository->updateProfile(
            $userId,
            $realName,
            $mobile,
            $email
        );

        if(!$wasUpdated){
            return [
                'success' => false,
                'message' => '个人资料未能更新，请稍后重试。',
                'errors' => [],
            ];
        }

        return [
            'success' => true,
            'message' => '个人资料更新成功。',
            'errors' => [],
        ];
    }

    /**
     * 用户修改登录密码。
     */
    public function changePassword(int $userId, array $data): array
    {
        if($userId <= 0){
            return [
                'success' => false,
                'message' => '用户编号不合法。',
                'errors' => [],
            ];
        }

        $user = $this->userRepository->findById($userId);

        if($user === null){
            return [
                'success' => false,
                'message' => '当前用户不存在。',
                'errors' => [],
            ];
        }

        if(!$user->isActive()){
            return [
                'success' => false,
                'message' => '当前账户已被停用，不能修改密码。',
                'errors' => [],
            ];
        }

        $currentPassword = (string)($data['current_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');
        $newPasswordConfirmation = (string)(
            $data['new_password_confirmation'] ?? ''
        );

        $errors = [];

        if($currentPassword === ''){
            $errors['current_password'][] = '请输入当前密码。';
        }

        $validator = new Validator();
        $validator->password('new_password', $newPassword, '新密码');

        if($validator->hasError('new_password')){
            $errors['new_password'] = $validator->getFieldErrors('new_password');
        }

        if($newPasswordConfirmation === ''){
            $errors['new_password_confirmation'][] = '请再次输入新密码。';
        }elseif($newPassword !== $newPasswordConfirmation){
            $errors['new_password_confirmation'][] = '两次输入的新密码不一致。';
        }

        if($errors !== []){
            return [
                'success' => false,
                'message' => '密码修改验证失败。',
                'errors' => $errors,
            ];
        }

        if(
            !password_verify(
                $currentPassword,
                $user->getPasswordHash()
            )
        ){
            return [
                'success' => false,
                'message' => '密码修改验证失败。',
                'errors' => [
                    'current_password' => [
                        '当前密码不正确。',
                    ],
                ],
            ];
        }

        if(
            password_verify(
                $newPassword,
                $user->getPasswordHash()
            )
        ){
            return [
                'success' => false,
                'message' => '密码修改验证失败。',
                'errors' => [
                    'new_password' => [
                        '新密码不能与当前密码相同。',
                    ],
                ],
            ];
        }

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        if($passwordHash === false){
            return [
                'success' => false,
                'message' => '新密码加密失败，请稍后重试。',
                'errors' => [],
            ];
        }

        $wasUpdated = $this->userRepository->updatePasswordHash(
            $userId,
            $passwordHash
        );

        if(!$wasUpdated){
            return [
                'success' => false,
                'message' => '密码未能更新，请稍后重试。',
                'errors' => [],
            ];
        }

        return [
            'success' => true,
            'message' => '登录密码修改成功。',
            'errors' => [],
        ];
    }
}