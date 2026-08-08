<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
        public string $method,
        public string $path,
        public ?int $userId,
        public int $accountId,
        public int $companyId
    ) {
    }

    public static function fromGlobals(?int $userId = null): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $requestId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')) ?: '';
        if ($requestId === '') {
            $requestId = 'REQ-' . bin2hex(random_bytes(8));
        }
        return new self(
            $requestId,
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $userId,
            max(0, (int) ($_REQUEST['account_id'] ?? 0)),
            max(0, (int) ($_REQUEST['company_id'] ?? 0))
        );
    }
}
