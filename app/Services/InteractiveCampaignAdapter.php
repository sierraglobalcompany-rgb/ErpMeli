<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Alias heredado sin superficie ejecutora propia.
 *
 * Las extensiones antiguas pueden seguir comprobando este contrato, pero la
 * ejecución pertenece exclusivamente a ManualCampaignAdapter::processExact()
 * desde el flujo manual exacto vigente.
 */
interface InteractiveCampaignAdapter extends ManualCampaignAdapter
{
}
