<?php

namespace App\Support;

final class SecretRedactor
{
    public static function message(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        foreach ([config('ota.weblate.token'), config('ota.gitlab.token'), config('ota.github_app.private_key'), config('services.openidconnect.client_secret')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace([$secret, rawurlencode($secret)], '[redacted]', $message);
            }
        }

        return mb_substr($message, 0, 4000);
    }
}
