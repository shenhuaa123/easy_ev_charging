<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AdminAuthorizationService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function validate(int $operatorUserId): ?string
    {
        if($operatorUserId <= 0){
            return '管理员编号不合法。';
        }

        $operator = $this->userRepository->findById($operatorUserId);

        if($operator === null){
            return '当前管理员账户不存在。';
        }

        if(!$operator->isAdmin()){
            return '当前账户没有管理员操作权限。';
        }

        if(!$operator->isActive()){
            return '当前管理员账户已被停用。';
        }

        return null;
    }
}