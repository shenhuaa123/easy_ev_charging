<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredRejectsEmptyString(): void
    {
        $validator = new Validator();

        $result = $validator->required('username', '', '用户名');
        $errors = $validator->getErrors();

        $this->assertFalse($result);
        $this->assertArrayHasKey('username', $errors);
        $this->assertSame(['用户名不能为空。'], $errors['username']);
        $this->assertSame('用户名不能为空。', $validator->getFirstError('username'));
    }

    public function testUsernameAcceptsLettersAndNumbers(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', 'abc123', '用户名');

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testUsernameRejectsChineseCharacters(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', '用户123', '用户名');

        $this->assertFalse($result);
        $this->assertSame('用户名只能包含英文字母和数字。', $validator->getFirstError('username'));
    }

    public function testUsernameRejectsUnderscore(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', 'abc_123', '用户名');

        $this->assertFalse($result);
        $this->assertSame('用户名只能包含英文字母和数字。', $validator->getFirstError('username'));
    }

    public function testUsernameRejectsHyphen(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', 'abc-123', '用户名');

        $this->assertFalse($result);
        $this->assertSame('用户名只能包含英文字母和数字。', $validator->getFirstError('username'));
    }

    public function testUsernameRejectsSpace(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', 'abc 123', '用户名');

        $this->assertFalse($result);
        $this->assertSame('用户名只能包含英文字母和数字。', $validator->getFirstError('username'));
    }

    public function testUsernameRejectsTooShortValue(): void
    {
        $validator = new Validator();

        $result = $validator->username('username', 'ab', '用户名');

        $this->assertFalse($result);
        $this->assertSame('用户名长度必须在3到30个字符之间。', $validator->getFirstError('username'));
    }

    public function testMobileAcceptsValidChinaMobileNumber(): void
    {
        $validator = new Validator();

        $result = $validator->mobile('mobile', '13800138000', '手机号码');

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testMobileRejectsInvalidChinaMobileNumber(): void
    {
        $validator = new Validator();

        $result = $validator->mobile('mobile', '12800138000', '手机号码');

        $this->assertFalse($result);
        $this->assertTrue($validator->hasError('mobile'));
        $this->assertSame('请输入正确的中国大陆手机号码。', $validator->getFirstError('mobile'));
    }

    public function testEmailAllowsEmptyValue(): void
    {
        $validator = new Validator();

        $result = $validator->email('email', '', '电子邮箱');

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testEmailRejectsInvalidFormat(): void
    {
        $validator = new Validator();

        $result = $validator->email('email', 'wrong-email', '电子邮箱');

        $this->assertFalse($result);
        $this->assertSame('电子邮箱格式不正确。', $validator->getFirstError('email'));
    }

    public function testPasswordAcceptsStrongPassword(): void
    {
        $validator = new Validator();

        $result = $validator->password('password', 'Aa123456!', '密码');

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testPasswordRejectsWeakPassword(): void
    {
        $validator = new Validator();

        $result = $validator->password('password', '12345678', '密码');

        $this->assertFalse($result);
        $this->assertTrue($validator->hasError('password'));
        $this->assertStringContains('至少包含一个大写英文字母', $validator->getFirstError('password') ?? '');
    }

    public function testLengthBetweenCountsChineseCharactersCorrectly(): void
    {
        $validator = new Validator();

        $result = $validator->lengthBetween('location_name', '上海充电站', '站点名称', 2, 100);

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testNonNegativeMoneyAcceptsTwoDecimalPlaces(): void
    {
        $validator = new Validator();

        $result = $validator->nonNegativeMoney('hourly_rate', '12.50', '每小时费用');

        $this->assertTrue($result);
        $this->assertFalse($validator->hasErrors());
    }

    public function testNonNegativeMoneyRejectsMoreThanTwoDecimalPlaces(): void
    {
        $validator = new Validator();

        $result = $validator->nonNegativeMoney('hourly_rate', '12.555', '每小时费用');

        $this->assertFalse($result);
        $this->assertTrue($validator->hasError('hourly_rate'));
    }

    public function testIsDateInputAcceptsValidDate(): void
    {
        $this->assertTrue(Validator::isDateInput('2026-02-28'));
        $this->assertTrue(Validator::isDateInput('2024-02-29'));
    }

    public function testIsDateInputRejectsInvalidFormat(): void
    {
        $this->assertFalse(Validator::isDateInput('2026/02/28'));
        $this->assertFalse(Validator::isDateInput('26-02-28'));
        $this->assertFalse(Validator::isDateInput('2026-2-8'));
    }

    public function testIsDateInputRejectsImpossibleDate(): void
    {
        $this->assertFalse(Validator::isDateInput('2026-02-29'));
        $this->assertFalse(Validator::isDateInput('2026-02-31'));
        $this->assertFalse(Validator::isDateInput('2026-13-01'));
    }
}