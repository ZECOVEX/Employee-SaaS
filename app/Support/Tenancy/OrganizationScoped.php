<?php

namespace App\Support\Tenancy;

interface OrganizationScoped
{
    public function isPlatformOwned(): bool;
}
