<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

interface OrganizationScoped
{
    public function isPlatformOwned(): bool;
}
