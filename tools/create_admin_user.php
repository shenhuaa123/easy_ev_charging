<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Core\Validator;
use App\Models\User;
use App\Repositories\UserRepository;

if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

if(!isset($database)){
    fwrite(STDERR, '数据库对象初始化失败。' . PHP_EOL);
    exit(1);
}

$userRepository = new UserRepository($database->getConnection());
$validator = new Validator();

fwrite(STDOUT, PHP_EOL . '=== 创建管理员账户 ===' . PHP_EOL);
fwrite(STDOUT, '说明：本工具只允许命令行运行，用于初始化后台管理员账户。' . PHP_EOL . PHP_EOL);

$username = prompt('请输入管理员用户名（3-30位英文字母或数字）：');
$realName = prompt('请输入管理员真实姓名（2-20位汉字）：');
$mobile = prompt('请输入管理员手机号：中国大陆11位手机号：');
$email = prompt('请输入管理员邮箱，可直接回车跳过：', false);
$password = prompt('请输入管理员密码（输入时会显示，8-20位，需含大小写字母、数字、特殊字符）：');
$passwordConfirmation = prompt('请再次输入管理员密码：');

if(!$validator->username('username', $username, '管理员用户名')){
    printErrors($validator->getFieldErrors('username'));
    exit(1);
}

if(!$validator->realName('real_name', $realName, '管理员真实姓名')){
    printErrors($validator->getFieldErrors('real_name'));
    exit(1);
}

if(!$validator->mobile('mobile', $mobile, '管理员手机号')){
    printErrors($validator->getFieldErrors('mobile'));
    exit(1);
}

$email = trim($email);
$emailValue = $email === '' ? null : $email;

if(!$validator->email('email', $emailValue, '管理员邮箱')){
    printErrors($validator->getFieldErrors('email'));
    exit(1);
}

if(!$validator->password('password', $password, '管理员密码')){
    printErrors($validator->getFieldErrors('password'));
    exit(1);
}

if($password !== $passwordConfirmation){
    fwrite(STDERR, '两次输入的管理员密码不一致。' . PHP_EOL);
    exit(1);
}

try{
    if($userRepository->findByUsername($username) !== null){
        fwrite(STDERR, '创建失败：该用户名已被使用。' . PHP_EOL);
        exit(1);
    }

    if($userRepository->findByMobile($mobile) !== null){
        fwrite(STDERR, '创建失败：该手机号已被使用。' . PHP_EOL);
        exit(1);
    }

    if($emailValue !== null && $userRepository->findByEmail($emailValue) !== null){
        fwrite(STDERR, '创建失败：该邮箱已被使用。' . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, PHP_EOL . '即将创建以下管理员账户：' . PHP_EOL);
    fwrite(STDOUT, '用户名：' . $username . PHP_EOL);
    fwrite(STDOUT, '真实姓名：' . $realName . PHP_EOL);
    fwrite(STDOUT, '手机号：' . maskMobile($mobile) . PHP_EOL);
    fwrite(STDOUT, '邮箱：' . ($emailValue ?? '未填写') . PHP_EOL);
    fwrite(STDOUT, '角色：管理员' . PHP_EOL);
    fwrite(STDOUT, '状态：正常' . PHP_EOL);

    if(!confirm('您确定要创建该管理员账户吗？(输入y或yes以确认，输入其他字符以取消)：')){
        fwrite(STDOUT, '已取消创建管理员账户。' . PHP_EOL);
        exit(0);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if($passwordHash === false){
        fwrite(STDERR, '创建失败：密码哈希生成失败。' . PHP_EOL);
        exit(1);
    }

    $now = date('Y-m-d H:i:s');

    $adminUser = new User(
        null,
        $username,
        $passwordHash,
        $realName,
        $mobile,
        $emailValue,
        'admin',
        'active',
        null,
        $now,
        $now
    );

    $userId = $userRepository->create($adminUser);

    Logger::app('CLI创建管理员账户成功。', [
        'user_id' => $userId,
        'username' => $username,
        'role' => 'admin',
    ]);

    fwrite(STDOUT, PHP_EOL . '管理员账户创建成功。' . PHP_EOL);
    fwrite(STDOUT, '用户编号：' . $userId . PHP_EOL);
    fwrite(STDOUT, '用户名：' . $username . PHP_EOL);
    fwrite(STDOUT, '角色：管理员' . PHP_EOL);
    fwrite(STDOUT, '状态：正常' . PHP_EOL);
}catch(Throwable $exception){
    Logger::exception($exception, 'CLI创建管理员账户失败。', [
        'username' => $username,
    ]);

    fwrite(STDERR, '创建管理员账户失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function prompt(string $message, bool $required = true): string
{
    while(true){
        fwrite(STDOUT, $message);

        $input = fgets(STDIN);

        if($input === false){
            fwrite(STDERR, '读取输入失败。' . PHP_EOL);
            exit(1);
        }

        $value = trim($input);

        if(!$required || $value !== ''){
            return $value;
        }

        fwrite(STDOUT, '该项不能为空，请重新输入。' . PHP_EOL);
    }
}

function confirm(string $message): bool
{
    fwrite(STDOUT, PHP_EOL . $message);

    $input = fgets(STDIN);

    if($input === false){
        return false;
    }

    $value = strtolower(trim($input));

    return $value === 'y' || $value === 'yes';
}

function printErrors(array $errors): void
{
    foreach($errors as $error){
        fwrite(STDERR, $error . PHP_EOL);
    }
}

function maskMobile(string $mobile): string
{
    if(strlen($mobile) !== 11){
        return $mobile;
    }

    return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
}