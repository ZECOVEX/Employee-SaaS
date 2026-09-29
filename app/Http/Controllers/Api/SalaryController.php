<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryRecordResource;
use App\Models\Employee;
use App\Models\SalaryRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SalaryController extends Controller
{
    /** The caller's effective salary record (salary.view OR salary.view_own). */
    public function mine(Request $request): SalaryRecordResource
    {
        if (! Gate::allows('salary.view') && ! Gate::allows('salary.view_own')) {
            abort(403);
        }

        $user = $request->user();
        $employee = Employee::where('user_id', $user->id)->firstOrFail();

        $record = SalaryRecord::effectiveFor(
            (int) $user->organization_id,
            $employee->id,
            now($user->organization?->timezone ?? 'UTC')->toDateString(),
        );

        abort_unless($record !== null, 404, 'No salary record for this employee.');

        return new SalaryRecordResource($record);
    }
}
