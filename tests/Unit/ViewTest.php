<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use Tests\TestCase;

final class ViewTest extends TestCase
{
    public function testFormatMinutesFormatsDurationText(): void
    {
        $this->assertSame('0 分钟', View::formatMinutes(0));
        $this->assertSame('59 分钟', View::formatMinutes(59));
        $this->assertSame('1 小时', View::formatMinutes(60));
        $this->assertSame('2 小时 5 分钟', View::formatMinutes(125));
    }
}