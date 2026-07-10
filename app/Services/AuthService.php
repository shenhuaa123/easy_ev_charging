<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Models\User;
use App\Repositories\UserRepository;
use RuntimeException;

class AuthService
{
    private const GENERIC_LOGIN_ERROR = '用户名或密码错误。';

    private const DUMMY_PASSWORD_HASH = 
        '$2y$10$N6hz/swKL5t3kEB1xZTCVeF9IQ1D6gv/2YOnew47falddBtHAEyye';

    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository) 
    {
        $this->userRepository = $userRepository;
    }

    public function register(array $data): array
    {
        $validator = new Validator();

        $username = trim((string)($data['username'] ?? ''));
        $realName = trim((string)($data['real_name'] ?? ''));
        $mobile = trim((string)($data['mobile'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $passwordConfirmation = (string)($data['password_confirmation'] ?? '');

        $validator->username('username', $username, '用户名');
        $validator->realName('real_name', $realName, '真实姓名');
        $validator->mobile('mobile', $mobile, '手机号码');
        $validator->email('email', $email, '电子邮箱');
        $validator->password('password', $password, '密码');

        if($password !== $passwordConfirmation){
            $validator->addError('password_confirmation', '两次输入的密码不一致。');
        }

        if(
            !$validator->hasError('username')
            && $this->userRepository->findByUsername($username) !== null
        ){
            $validator->addError('username', '该用户名已被使用。');
        }

        if(
            !$validator->hasError('mobile')
            && $this->userRepository->findByMobile($mobile) !== null
        ){
            $validator->addError('mobile', '该手机号码已被使用。');
        }

        if(
            $email !== ''
            && !$validator->hasError('email')
            && $this->userRepository->findByEmail($email) !== null
        ){
            $validator->addError('email', '该电子邮箱已被使用。');
        }

        if($validator->hasErrors()){
            return [
                'success' => false,
                'errors' => $validator->getErrors(),
                'user_id' => null,
            ];
        }

        $passwordHash = $this->hashPassword($password);

        $now = date('Y-m-d H:i:s');

        $user = new User(
            null,
            $username,
            $passwordHash,
            $realName,
            $mobile,
            $email === '' ? null : $email,
            'user',
            'active',
            null,
            $now,
            $now
        );

        $userId = $this->userRepository->create($user);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
        ];
    }

    public function login(string $username, string $password): array
    {
        $username = trim($username);

        if($username === '' || $password === ''){
            return [
                'success' => false,
                'message' => '请输入用户名和密码。',
                'user' => null,
            ];
        }

        $user = $this->userRepository->findByUsername($username);
        $passwordHash = $user?->getPasswordHash() ?? self::DUMMY_PASSWORD_HASH;
        $passwordValid = password_verify($password, $passwordHash);

        if($user === null || !$passwordValid || !$user->isActive()){
            return [
                'success' => false,
                'message' => self::GENERIC_LOGIN_ERROR,
                'user' => null,
            ];
        }

        $userId = $user->getUserId();

        if($userId === null){
            throw new RuntimeException('用户编号不存在。');
        }

        if(password_needs_rehash($user->getPasswordHash(), PASSWORD_DEFAULT)){
            $newPasswordHash = $this->hashPassword($password);

            if(!$this->userRepository->updatePasswordHash($userId, $newPasswordHash)){
                throw new RuntimeException('登录密码哈希升级失败。');
            }

            $user = $this->userRepository->findById($userId);

            if($user === null){
                throw new RuntimeException('密码哈希升级后无法重新读取用户信息。');
            }
        }

        $this->userRepository->updateLastLoginAt($userId);

        return [
            'success' => true,
            'message' => '登录成功。',
            'user' => $user,
        ];
    }

    private function hashPassword(string $password): string
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if($passwordHash === false){
            throw new RuntimeException('密码加密失败。');
        }

        return $passwordHash;
    }
}