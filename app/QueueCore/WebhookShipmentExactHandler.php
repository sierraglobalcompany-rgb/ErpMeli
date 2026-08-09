<?php
declare(strict_types=1);

namespace App\QueueCore;

final class WebhookShipmentExactHandler extends AbstractWebhookExactHandler
{
    public function __construct(
        WebhookTriggerService $triggers,
        ?WebhookExactGateway $gateway = null
    ) {
        parent::__construct('shipment', $triggers, $gateway ?? new MeliWebhookExactGateway());
    }
}
