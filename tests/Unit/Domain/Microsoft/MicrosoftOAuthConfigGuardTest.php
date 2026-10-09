<?php

namespace Tests\Unit\Domain\Microsoft;

use App\Domain\Microsoft\MicrosoftOAuthConfigGuard;
use RuntimeException;
use Tests\TestCase;

class MicrosoftOAuthConfigGuardTest extends TestCase
{
    public function test_rejects_sso_callback_as_mail_redirect(): void
    {
        config([
            'microsoft.redirect_uri' => 'https://api.example.com/api/v1/auth/sso/microsoft/callback',
            'services.sso.microsoft.redirect_uri' => 'https://api.example.com/api/v1/auth/sso/microsoft/callback',
        ]);

        $this->expectException(RuntimeException::class);
        MicrosoftOAuthConfigGuard::assertMailRedirectUri();
    }

    public function test_accepts_distinct_mail_redirect(): void
    {
        config([
            'microsoft.redirect_uri' => 'https://api.example.com/api/v1/integrations/microsoft/callback',
            'services.sso.microsoft.redirect_uri' => 'https://api.example.com/api/v1/auth/sso/microsoft/callback',
        ]);

        MicrosoftOAuthConfigGuard::assertMailRedirectUri();
        $this->assertTrue(true);
    }
}
