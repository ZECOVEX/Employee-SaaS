<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\OrganizationProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_billing_page_and_plan_switch_require_settings_manage(): void
    {
        $this->seedPlans();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Billing Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $employee = $this->makeUser($org, 'employee', $roles);
        $starter = Plan::where('slug', 'starter')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Billing')
            ->assertSee('Starter');

        $this->actingAs($employee)->get(route('billing.index'))->assertForbidden();
        $this->actingAs($employee)
            ->post(route('billing.plan'), ['plan_id' => $starter->id])
            ->assertForbidden();
    }

    public function test_employee_creation_is_blocked_when_the_plan_limit_is_reached(): void
    {
        $this->seedPlans();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Limit Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->makeEmployee($org, $this->makeUser($org, 'employee', $roles), 'EMP-1');

        $billing = app(BillingService::class);
        $billing->switchPlan($org, Plan::where('slug', 'free')->firstOrFail());
        // Tighten the free plan down to the current headcount.
        Plan::where('slug', 'free')->update(['employee_limit' => 1]);

        $payload = $this->employeePayload();

        $this->actingAs($admin)
            ->from(route('employees.create'))
            ->post(route('employees.store'), $payload)
            ->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors('employee_limit');

        $this->assertSame(1, Employee::withoutGlobalScopes()->where('organization_id', $org->id)->count());

        // Raising the cap lets the exact same payload through.
        Plan::where('slug', 'free')->update(['employee_limit' => 10]);

        $this->actingAs($admin)
            ->post(route('employees.store'), $payload)
            ->assertSessionHas('status', 'Employee created.');

        $this->assertSame(2, Employee::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    public function test_employee_creation_has_no_limit_without_a_plan_catalog(): void
    {
        // No seedPlans() → no plans/subscription → employeeLimit() is null.
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('No Catalog Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $this->makeEmployee($org, $this->makeUser($org, 'employee', $roles), 'EMP-1');

        $this->assertNull(app(BillingService::class)->employeeLimit($org));

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload())
            ->assertSessionHas('status', 'Employee created.');
    }

    public function test_switching_plans_updates_subscription_and_issues_an_invoice(): void
    {
        $this->seedPlans();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Switch Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);
        $business = Plan::where('slug', 'business')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('billing.plan'), ['plan_id' => $business->id])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('status');

        $subscription = Subscription::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $this->assertSame($business->id, (int) $subscription->plan_id);
        $this->assertSame('active', $subscription->status);

        $invoice = Invoice::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $this->assertSame(99.0, (float) $invoice->amount);
        $this->assertSame('open', $invoice->status);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'subscription.plan_changed',
        ]);

        $this->actingAs($admin)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee($invoice->number);

        // Re-selecting the current plan is a no-op (no duplicate invoice).
        $this->actingAs($admin)
            ->post(route('billing.plan'), ['plan_id' => $business->id])
            ->assertSessionHas('status');

        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    public function test_plan_changes_and_invoices_are_tenant_scoped(): void
    {
        $this->seedPlans();
        ['organization' => $orgA, 'roles' => $rolesA] = $this->makeOrganization('Alpha Ltd');
        ['organization' => $orgB, 'roles' => $rolesB] = $this->makeOrganization('Beta Ltd');
        $adminA = $this->makeUser($orgA, 'company_admin', $rolesA);
        $adminB = $this->makeUser($orgB, 'company_admin', $rolesB);
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $business = Plan::where('slug', 'business')->firstOrFail();

        app(BillingService::class)->switchPlan($orgB, $starter);

        $this->actingAs($adminA)
            ->post(route('billing.plan'), ['plan_id' => $business->id])
            ->assertRedirect();

        // B's subscription is untouched by A's switch.
        $subscriptionB = Subscription::withoutGlobalScopes()
            ->where('organization_id', $orgB->id)
            ->firstOrFail();
        $this->assertSame($starter->id, (int) $subscriptionB->plan_id);

        // A's invoice never appears on B's billing page.
        $invoiceA = Invoice::withoutGlobalScopes()
            ->where('organization_id', $orgA->id)
            ->firstOrFail();
        $this->actingAs($adminB)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertDontSee($invoiceA->number);
    }

    public function test_new_organizations_receive_the_free_plan_when_the_catalog_exists(): void
    {
        $this->seedPlans();

        $result = app(OrganizationProvisioner::class)->provision([
            'organization_name' => 'Fresh Co',
            'name' => 'Owner',
            'email' => 'owner-fresh@example.test',
            'password' => 'password',
        ]);

        $subscription = Subscription::withoutGlobalScopes()
            ->where('organization_id', $result['organization']->id)
            ->firstOrFail();

        $this->assertSame('free', $subscription->plan->slug);
        $this->assertTrue($subscription->isActive());
    }

    public function test_provisioning_without_a_catalog_creates_no_subscription(): void
    {
        $result = app(OrganizationProvisioner::class)->provision([
            'organization_name' => 'Bare Co',
            'name' => 'Owner',
            'email' => 'owner-bare@example.test',
            'password' => 'password',
        ]);

        $this->assertNull(
            Subscription::withoutGlobalScopes()
                ->where('organization_id', $result['organization']->id)
                ->first(),
        );
    }

    public function test_unlimited_plans_never_block_employee_creation(): void
    {
        $this->seedPlans();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Unlimited Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $billing = app(BillingService::class);
        $billing->switchPlan($org, Plan::where('slug', 'enterprise')->firstOrFail());

        $this->assertNull($billing->employeeLimit($org));
        $this->assertTrue($billing->canAddEmployee($org));

        $this->actingAs($admin)
            ->post(route('employees.store'), $this->employeePayload())
            ->assertSessionHas('status', 'Employee created.');
    }

    public function test_switching_to_an_unknown_plan_is_rejected(): void
    {
        $this->seedPlans();
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization('Validation Co');
        $admin = $this->makeUser($org, 'company_admin', $roles);

        $this->actingAs($admin)
            ->from(route('billing.index'))
            ->post(route('billing.plan'), ['plan_id' => 999999])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('plan_id');

        $this->assertSame(
            0,
            Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->count(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Hire',
            'email' => 'hire-'.uniqid().'@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'employee_code' => 'EMP-'.strtoupper(substr(uniqid(), -6)),
            'status' => 'active',
            'employment_type' => 'full_time',
        ], $overrides);
    }
}
