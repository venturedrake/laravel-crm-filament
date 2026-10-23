<?php

namespace VentureDrake\LaravelCrmFilament\Services;

use VentureDrake\LaravelCrm\Models\Customer;

/**
 * Local CustomerService shim — core CRM does not ship one. Its v1
 * CustomerController persisted directly, and core 2.5.0 removed that controller
 * and the `laravel-crm.customers.*` routes; the Customer model stays. Mirrors
 * the $request-fluent signature of the other core services so call sites can
 * route through ::create/::update via FormPayload.
 */
class CustomerService
{
    public function create($request)
    {
        return Customer::create([
            'name' => $request->name,
            'user_owner_id' => $request->user_owner_id,
        ]);
    }

    public function update(Customer $customer, $request)
    {
        $customer->update([
            'name' => $request->name,
            'user_owner_id' => $request->user_owner_id,
        ]);

        return $customer;
    }
}
