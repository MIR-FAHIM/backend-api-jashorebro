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
        $base = self::getBaseUrl();

        // If URL is empty but file path is provided
        if (empty($url) && ! empty($filePath)) {
            $path = ltrim($filePath, '/');
            return "{$base}/storage/{$path}";
        }

        if (empty($url)) {
            return $url;
        }

        // If URL contains localhost or 127.0.0.1 (with or without port)
        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/storage/.*)$#i', $url, $matches)) {
            return $base . $matches[1];
        }

        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/(?:uploads|images|products|categories|shops)/.*)$#i', $url, $matches)) {
            return "{$base}/storage" . $matches[1];
        }

        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?/(.+)$#i', $url, $matches)) {
            return "{$base}/" . ltrim($matches[1], '/');
        }

        // If relative storage path (/storage/... or storage/...)
        if (str_starts_with($url, '/storage/')) {
            return $base . $url;
        }

        if (str_starts_with($url, 'storage/')) {
            return "{$base}/{$url}";
        }

        // If relative file path like products/... or categories/...
        if (preg_match('#^(?:products|categories|shops|avatars|uploads)/#i', $url)) {
            return "{$base}/storage/{$url}";
        }

        return $url;
    }
}
