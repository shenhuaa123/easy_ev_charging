<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Session;
use Tests\TestCase;

final class CsrfTest extends TestCase
{
    private Session $session;

    public function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];

        $this->session = new class extends Session {
            public function start(): void
            {

            }
        };
    }

    public function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function testTokenGeneratesStableHexToken(): void
    {
        $csrf = new Csrf($this->session);

        $firstToken = $csrf->token();
        $secondToken = $csrf->token();

        $this->assertSame($firstToken, $secondToken);
        $this->assertTrue(
            preg_match('/\A[a-f0-9]{64}\z/D', $firstToken) === 1
        );
    }

    public function testValidateAcceptsCorrectTokenAndRejectsWrongToken(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();

        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate(str_repeat('0', 64)));
    }

    public function testValidateRejectsNullAndArrayTokens(): void
    {
        $csrf = new Csrf($this->session);
        $csrf->token();

        $this->assertFalse($csrf->validate(null));
        $this->assertFalse($csrf->validate(['malicious']));
    }

    public function testRegenerateReplacesCurrentToken(): void
    {
        $csrf = new Csrf($this->session);

        $oldToken = $csrf->token();
        $newToken = $csrf->regenerate();

        $this->assertTrue($oldToken !== $newToken);
        $this->assertTrue($csrf->validate($newToken));
        $this->assertFalse($csrf->validate($oldToken));
    }
}