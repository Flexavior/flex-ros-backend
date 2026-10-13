<?php

namespace App\Domain\Crm;

use App\Models\Lead;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class LeadConversionService
{
    public function __construct(
        protected ClientIdGenerator $clientIdGenerator,
        protected ChecklistService $checklistService
    ) {
    }

    /**
     * Convert a lead into a Customer:
     * - generates the Client ID (YYMMDD_C{n}) unless supplied
     * - maps selected products/services
     * - instantiates dynamic stage checklists from System Settings
     * - marks the lead as converted
     */
    /**
     * @param  array<int, float|string|null>  $agreedPricesByProductId  product_service_id => agreed_price
     * @param  array<int, float|string|null>  $agreedUnitsByProductId  product_service_id => quantity (unit_price = agreed_price / unit)
     */
    public function convert(
        Lead $lead,
        array $productServiceIds = [],
        ?int $userId = null,
        ?string $clientId = null,
        array $agreedPricesByProductId = [],
        array $agreedUnitsByProductId = [],
    ): Customer
    {
        if ($lead->status === Lead::STATUS_CONVERTED) {
            throw new \InvalidArgumentException('Lead is already converted.');
        }

        $resolvedClientId = $clientId ?: $this->clientIdGenerator->generate();
        if ($clientId) {
            $this->clientIdGenerator->validateNewFormat($clientId);
            $this->clientIdGenerator->assertUnique($clientId);
        }

        return DB::transaction(function () use ($lead, $productServiceIds, $userId, $resolvedClientId, $agreedPricesByProductId, $agreedUnitsByProductId) {
            $customer = Customer::create([
                'client_id' => $resolvedClientId,
                'name' => $lead->name,
                'company' => $lead->company,
                'organization_type' => $lead->organization_type,
                'customer_segment' => $lead->customer_segment,
                'industry' => $lead->industry,
                'geo_location' => $lead->geo_location,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'status' => 'onboarding',
                'lead_id' => $lead->id,
                'owner_id' => $lead->owner_id,
                'created_by' => $userId ?? auth()->id(),
            ]);

            // Map products/services (pivot)
            $pivotRows = [];
            foreach (array_unique($productServiceIds) as $pid) {
                $pid = (int) $pid;
                $price = $agreedPricesByProductId[$pid] ?? $agreedPricesByProductId[(string) $pid] ?? null;
                $unitRaw = $agreedUnitsByProductId[$pid] ?? $agreedUnitsByProductId[(string) $pid] ?? null;
                $unit = ($unitRaw !== null && $unitRaw !== '') ? (float) $unitRaw : null;
                $agreed = ($price !== null && $price !== '') ? (float) $price : null;
                $unitPrice = ($agreed !== null && $unit !== null && $unit > 0)
                    ? round($agreed / $unit, 2)
                    : null;
                $pivotRows[] = [
                    'customer_id' => $customer->id,
                    'product_service_id' => $pid,
                    'agreed_price' => $agreed,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($pivotRows) {
                DB::table('customer_products')->insert($pivotRows);
            }

            // Instantiate dynamic checklists for onboarding/contract stages
            $this->checklistService->instantiateForCustomer($customer, 'onboarding');
            $this->checklistService->instantiateForCustomer($customer, 'contract_sign_off');

            // Close the lead
            $lead->update([
                'status' => Lead::STATUS_CONVERTED,
                'customer_id' => $customer->id,
            ]);

            return $customer;
        });
    }
}
