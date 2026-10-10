<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\WorkforceDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

/**
 * Leave attachments: optional upload on the request form, stored on the
 * private disk, streamed back with leave.index visibility rules.
 */
class LeaveAttachmentTest extends TestCase
{
    use CreatesOrganizations;
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, Role> */
    private array $roles;

    private User $employeeUser;

    private Employee $employee;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');

        $ctx = $this->makeOrganization('Attach '.uniqid());
        $this->organization = $ctx['organization'];
        $this->roles = $ctx['roles'];
        app(WorkforceDefaults::class)->apply($this->organization);

        $this->employeeUser = $this->makeUser($this->organization, 'employee', $this->roles);
        $this->employee = $this->makeEmployee($this->organization, $this->employeeUser, 'EMP-ATT');
        app(LeaveService::class)->ensureBalances($this->employee);

        $this->type = LeaveType::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('code', 'ANNUAL')
            ->firstOrFail();

        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Medical appointment',
        ], $extra);
    }

    private function leaveWithAttachment(): LeaveRequest
    {
        $file = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test attachment body');

        $this->actingAs($this->employeeUser)
            ->post(route('leave.store'), $this->payload(['attachment' => $file]))
            ->assertRedirect(route('leave.index'));

        return LeaveRequest::withoutGlobalScopes()->sole();
    }

    public function test_upload_stores_the_file_and_shows_it_on_the_index(): void
    {
        $leave = $this->leaveWithAttachment();

        $this->assertNotNull($leave->attachment_path);
        $this->assertStringStartsWith('leave-attachments/'.$this->organization->id.'/', $leave->attachment_path);
        Storage::disk('local')->assertExists($leave->attachment_path);

        $this->actingAs($this->employeeUser)
            ->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Attachment');
    }

    public function test_request_succeeds_without_an_attachment(): void
    {
        $this->actingAs($this->employeeUser)
            ->post(route('leave.store'), $this->payload())
            ->assertRedirect(route('leave.index'));

        $this->assertNull(LeaveRequest::withoutGlobalScopes()->sole()->attachment_path);
    }

    public function test_invalid_or_oversized_files_are_rejected(): void
    {
        $exe = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $this->actingAs($this->employeeUser)
            ->from(route('leave.create'))
            ->post(route('leave.store'), $this->payload(['attachment' => $exe]))
            ->assertSessionHasErrors('attachment');

        $huge = UploadedFile::fake()->create('huge.pdf', 3000, 'application/pdf');

        $this->actingAs($this->employeeUser)
            ->from(route('leave.create'))
            ->post(route('leave.store'), $this->payload(['attachment' => $huge]))
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->count());
    }

    public function test_owner_and_admin_can_download_the_attachment(): void
    {
        $leave = $this->leaveWithAttachment();

        $owner = $this->actingAs($this->employeeUser)
            ->get(route('leave.attachment', $leave))
            ->assertOk()
            ->assertDownload('leave-request-'.$leave->id.'.pdf');

        $this->assertStringContainsString('%PDF-1.4', $owner->streamedContent());

        $admin = $this->makeUser($this->organization, 'company_admin', $this->roles);

        $this->actingAs($admin)
            ->get(route('leave.attachment', $leave))
            ->assertOk()
            ->assertDownload('leave-request-'.$leave->id.'.pdf');
    }

    public function test_visibility_mirrors_the_leave_index_rules(): void
    {
        $leave = $this->leaveWithAttachment();

        // Another plain employee in the same org cannot download it.
        $stranger = $this->makeUser($this->organization, 'employee', $this->roles);
        $this->makeEmployee($this->organization, $stranger, 'EMP-STRANGER');

        $this->actingAs($stranger)
            ->get(route('leave.attachment', $leave))
            ->assertForbidden();

        // An approve-only manager sees the request only for their team.
        $outsiderManager = $this->makeUser($this->organization, 'manager', $this->roles);
        $this->actingAs($outsiderManager)
            ->get(route('leave.attachment', $leave))
            ->assertForbidden();

        $teamManager = $this->makeUser($this->organization, 'manager', $this->roles);
        $this->employee->update(['manager_id' => $teamManager->id]);

        $this->actingAs($teamManager)
            ->get(route('leave.attachment', $leave))
            ->assertOk();
    }

    public function test_download_is_tenant_isolated_and_404s_without_a_file(): void
    {
        $leave = $this->leaveWithAttachment();

        $otherCtx = $this->makeOrganization('Other Attach');
        $foreignAdmin = $this->makeUser($otherCtx['organization'], 'company_admin', $otherCtx['roles']);

        $this->actingAs($foreignAdmin)
            ->get(route('leave.attachment', $leave))
            ->assertNotFound();

        $this->employeeUser->refresh();
        $noFile = LeaveRequest::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->type->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'days' => 1,
            'reason' => 'No file attached',
            'status' => LeaveRequest::PENDING,
        ]);

        $this->actingAs($this->employeeUser)
            ->get(route('leave.attachment', $noFile))
            ->assertNotFound();
    }
}
