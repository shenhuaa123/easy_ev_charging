<?php

declare(strict_types=1);

if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

fwrite(STDOUT, '请输入需要生成哈希的密码：');

$passwordInput = fgets(STDIN);

if($passwordInput === false){
    fwrite(STDERR, '读取密码失败。' . PHP_EOL);
    exit(1);
}

$password = rtrim($passwordInput, "\r\n");

if($password === ''){
    fwrite(STDERR, '密码不能为空。' . PHP_EOL);
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if($passwordHash === false){
    fwrite(STDERR, '密码哈希生成失败。' . PHP_EOL);
    exit(1);
}

if(!password_verify($password, $passwordHash)){
    fwrite(STDERR, '生成后的密码哈希验证失败。' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, PHP_EOL . '密码哈希：' . PHP_EOL);
fwrite(STDOUT, $passwordHash . PHP_EOL);