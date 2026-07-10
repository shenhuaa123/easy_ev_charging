<?php

declare(strict_types=1);

namespace App\Core;

class Validator
{
    /**
     * 保存所有验证错误。
     */
    private array $errors = [];

    /**
     * 验证必填字段。
     */
    public function required(string $field, mixed $value, string $label): bool 
    {
        if(
            $value === null
            || (
                is_string($value)
                && trim($value) === ''
            )
        ){
            $this->addError($field, $label . '不能为空。');

            return false;
        }

        return true;
    }

    /**
     * 验证用户名。
     *
     * 只允许英文字母和数字，长度为3到30个字符。
     */
    public function username(string $field, string $value, string $label = '用户名'): bool 
    {
        $value = trim($value);

        if(!$this->required($field, $value, $label)){
            return false;
        }

        $length = strlen($value);

        if($length < 3 || $length > 30){
            $this->addError($field, $label . '长度必须在3到30个字符之间。');
            return false;
        }

        if(!preg_match('/^[A-Za-z0-9]+$/', $value)){
            $this->addError($field, $label . '只能包含英文字母和数字。');
            return false;
        }

        return true;
    }

    /**
     * 验证真实姓名。
     *
     * 只允许汉字，长度为2到20个字符。
     */
    public function realName(string $field, string $value, string $label = '真实姓名'): bool
    {
        $value = trim($value);

        if(!$this->required($field, $value, $label)){
            return false;
        }

        $length = mb_strlen($value, 'UTF-8');

        if($length < 2 || $length > 20){
            $this->addError($field, $label . '长度必须在2到20个字符之间。');
            return false;
        }

        if(!preg_match('/^[\p{Han}]+$/u', $value)){
            $this->addError($field, $label . '只能包含汉字。');
            return false;
        }

        return true;
    }

    /**
     * 验证中国大陆手机号码。
     */
    public function mobile(string $field, string $value, string $label = '手机号码'): bool 
    {
        $value = trim($value);

        if(!$this->required($field, $value, $label)){
            return false;
        }

        if(!preg_match('/^1[3-9]\d{9}$/', $value)){
            $this->addError($field, '请输入正确的中国大陆手机号码。');

            return false;
        }

        return true;
    }

    /**
     * 验证电子邮箱。
     *
     * 邮箱可以为空；不为空时才检查格式。
     */
    public function email(string $field, ?string $value, string $label = '电子邮箱'): bool
    {
        if($value === null || trim($value) === ''){
            return true;
        }

        $value = trim($value);

        if(strlen($value) > 100){
            $this->addError($field, $label . '不能超过100个字符。');
            return false;
        }

        if(filter_var($value, FILTER_VALIDATE_EMAIL) === false){
            $this->addError($field, $label . '格式不正确。');
            return false;
        }

        return true;
    }

    /**
     * 验证密码强度。
     *
     * 密码必须：
     * - 长度为8到20个字符
     * - 至少包含一个大写英文字母
     * - 至少包含一个小写英文字母
     * - 至少包含一个数字
     * - 至少包含一个特殊字符
     * - 不能包含空格、汉字或其他文字
     */
    public function password(string $field, string $value, string $label = '密码'): bool
    {
        if(!$this->required($field, $value, $label)){
            return false;
        }

        if(!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9])[!-~]{8,20}$/', $value)){
            $this->addError(
                $field,
                $label . '必须为8到20个字符，至少包含一个大写英文字母、一个小写英文字母、一个数字和一个特殊字符，且不能包含空格、汉字或其他文字。'
            );

            return false;
        }

        return true;
    }

    /**
     * 验证正整数。
     */
    public function positiveInteger(string $field, mixed $value, string $label): bool 
    {
        if(!$this->required($field, $value, $label)){
            return false;
        }

        $validatedValue = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if($validatedValue === false){
            $this->addError($field, $label . '必须是大于0的整数。');

            return false;
        }

        return true;
    }

    /**
     * 验证非负金额。
     *
     * 允许：
     * - 0
     * - 正数
     * - 最多两位小数
     */
    public function nonNegativeMoney(string $field, mixed $value, string $label): bool 
    {
        if(!$this->required($field, $value, $label)){
            return false;
        }

        $stringValue = trim((string) $value);

        if(!preg_match('/^\d+(\.\d{1,2})?$/', $stringValue)){
            $this->addError($field, $label . '必须是大于或等于0的金额，并且最多保留两位小数。');

            return false;
        }

        return true;
    }

    /**
     * 验证HTML日期输入值。
     */
    public static function isDateInput(string $date): bool
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $dateTime !== false
            && $dateTime->format('Y-m-d') === $date;
    }

    /**
     * 验证字符串长度。
     */
    public function lengthBetween(
        string $field,
        string $value,
        string $label,
        int $minimum,
        int $maximum
    ): bool {
        $value = trim($value);

        if(!$this->required($field, $value, $label)){
            return false;
        }

        $length = mb_strlen($value, 'UTF-8');

        if($length < $minimum || $length > $maximum){
            $this->addError(
                $field,
                $label
                . '长度必须在'
                . $minimum
                . '到'
                . $maximum
                . '个字符之间。'
            );

            return false;
        }

        return true;
    }

    /**
     * 添加一条错误信息。
     */
    public function addError(string $field, string $message): void
    {
        if(!isset($this->errors[$field])){
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * 判断是否存在任意错误。
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * 判断某个字段是否存在错误。
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * 获取全部错误。
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 获取某个字段的全部错误。
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * 获取某个字段的第一条错误。
     */
    public function getFirstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}