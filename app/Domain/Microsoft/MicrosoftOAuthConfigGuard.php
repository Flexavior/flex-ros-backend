<?php

namespace App\Domain\Microsoft;

use RuntimeException;

final class MicrosoftOAuthConfigGuard
{
    public static function assertMailRedirectUri(): void
    {
        $mail = rtrim((string) config('microsoft.redirect_uri'), '/');
        $sso = rtrim((string) config('services.sso.microsoft.redirect_uri'), '/');

        if ($mail === '') {
            throw new RuntimeException('AZURE_REDIRECT_URI is not configured.');
        }

        if (!str_contains($mail, '/integrations/microsoft/callback')) {
            throw new RuntimeException(
                'AZURE_REDIRECT_URI must point to the mail integration callback '.
                '(…/api/v1/integrations/microsoft/callback), not the SSO login callback.'
            );
        }

        if ($sso !== '' && $mail === $sso) {
            throw new RuntimeException(
                'AZURE_REDIRECT_URI must differ from SSO_MICROSOFT_REDIRECT_URI. '.
                'Register both redirect URIs in Azure and set each env var correctly.'
            );
        }
    }
}
