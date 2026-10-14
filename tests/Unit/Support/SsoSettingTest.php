<?php

namespace Tests\Unit\Support;

use App\Support\SsoSetting;
use PHPUnit\Framework\TestCase;

class SsoSettingTest extends TestCase
{
    public function test_coerce_bool_handles_scalars_and_legacy_array_shapes(): void
    {
        $this->assertTrue(SsoSetting::coerceBool(true));
        $this->assertFalse(SsoSetting::coerceBool(false));
        $this->assertTrue(SsoSetting::coerceBool('true'));
        $this->assertTrue(SsoSetting::coerceBool('1'));
        $this->assertFalse(SsoSetting::coerceBool('0'));
        $this->assertFalse(SsoSetting::coerceBool([]));
        $this->assertTrue(SsoSetting::coerceBool([1]));
    }
}
