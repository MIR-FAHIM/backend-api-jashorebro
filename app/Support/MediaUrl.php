<?php

namespace App\Support;

class MediaUrl
{
    public const LIVE_BASE_URL = 'https://backend.jashorebro.com';

    /**
     * Determine the canonical base URL for media assets.
     */
    public static function getBaseUrl(): string
    {
        // 1. If running in HTTP request context and host is not localhost, use request host
        if (app()->bound('request')) {
            try {
                $request = request();
                if ($request) {
                    $host = $request->getSchemeAndHttpHost();
                    if (! empty($host) && ! str_contains($host, 'localhost') && ! str_contains($host, '127.0.0.1')) {
                        return rtrim($host, '/');
                    }
                }
            } catch (\Throwable) {
                // Ignore console/context errors
            }
        }

        // 2. Check APP_URL config if configured to non-localhost
        $appUrl = config('app.url');
        if (! empty($appUrl) && ! str_contains($appUrl, 'localhost') && ! str_contains($appUrl, '127.0.0.1')) {
            return rtrim((string) $appUrl, '/');
        }

        // 3. Fallback to confirmed live production base URL
        return self::LIVE_BASE_URL;
    }

    /**
     * Resolve any image or asset path to a full live URL.
     * Replaces localhost/127.0.0.1 references and formats relative storage paths.
     */
    public static function resolve(?string $url, ?string $filePath = null): ?string
    {
        $target = ! empty($url) ? $url : $filePath;

        if (empty($target)) {
            return null;
        }

        $target = trim((string) $target);
        $base = self::getBaseUrl();

        // 1. Remove double storage prefixes if present
        $target = preg_replace('#^https?://[^/]+/storage/storage/#i', "{$base}/storage/", $target);
        $target = preg_replace('#^/storage/storage/#i', '/storage/', $target);
        $target = preg_replace('#^storage/storage/#i', 'storage/', $target);

        // 2. If URL contains localhost or 127.0.0.1 (with or without port)
        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/storage/.*)$#i', $target, $matches)) {
            return $base . $matches[1];
        }

        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/(?:uploads|images|products|categories|shops|avatars|covers)/.*)$#i', $target, $matches)) {
            return "{$base}/storage" . $matches[1];
        }

        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?/(.+)$#i', $target, $matches)) {
            return "{$base}/" . ltrim($matches[1], '/');
        }

        // 3. If already absolute HTTP/HTTPS URL
        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }

        // 4. Strip public/ prefix if any
        if (str_starts_with($target, 'public/')) {
            $target = substr($target, 7);
        } elseif (str_starts_with($target, '/public/')) {
            $target = substr($target, 8);
        }

        // 5. If relative storage path (/storage/... or storage/...)
        if (str_starts_with($target, '/storage/')) {
            return $base . $target;
        }

        if (str_starts_with($target, 'storage/')) {
            return "{$base}/{$target}";
        }

        // 6. If relative file path like products/... or categories/... or avatars/...
        if (preg_match('#^(?:products|categories|shops|avatars|covers|uploads|images)/#i', $target)) {
            return "{$base}/storage/{$target}";
        }

        return "{$base}/storage/" . ltrim($target, '/');
    }
}
