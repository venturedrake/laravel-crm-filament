<?php

namespace VentureDrake\LaravelCrmFilament\Tests\Stubs;

use VentureDrake\LaravelCrm\Traits\HasCrmPhones;

/**
 * A host user model with CRM phones, as `HasCrmAccess` gives a real host's.
 */
class UserWithPhones extends User
{
    use HasCrmPhones;
}
