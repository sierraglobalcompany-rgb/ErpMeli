<?php
declare(strict_types=1);

namespace App\QueueCore;

final class WebhookPackExactHandler extends AbstractWebhookExactHandler
{
    public function __construct(
        WebhookTriggerService $triggers,
        ?WebhookExactGateway $gateway = null
    ) {
        parent::__construct('pack', $triggers, $gateway ?? new MeliWebhookExactGateway());
    }
}
