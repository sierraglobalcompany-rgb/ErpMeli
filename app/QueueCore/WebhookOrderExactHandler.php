<?php
declare(strict_types=1);

namespace App\QueueCore;

final class WebhookOrderExactHandler extends AbstractWebhookExactHandler
{
    public function __construct(
        WebhookTriggerService $triggers,
        ?WebhookExactGateway $gateway = null
    ) {
        parent::__construct('order', $triggers, $gateway ?? new MeliWebhookExactGateway());
    }
}
