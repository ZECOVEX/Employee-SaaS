<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Leave Request</h2>
            <a href="{{ route('leave.show', $leaveRequest) }}" class="text-sm text-gray-600 hover:underline">Back to request</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow sm:rounded-lg p-6 text-sm">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <dt class="text-gray-500">Employee</dt>
                        <dd class="mt-1 text-gray-900">{{ $leaveRequest->employee?->user?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1">
                            <span class="px-2 py-1 rounded text-xs font-semibold {{ $leaveRequest->status === \App\Models\LeaveRequest::APPROVED ? 'bg-green-100 text-green-800' : ($leaveRequest->status === \App\Models\LeaveRequest::REJECTED ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') }}">{{ $leaveRequest->status }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Current range</dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $leaveRequest->start_date->format('M j, Y') }} – {{ $leaveRequest->end_date->format('M j, Y') }}
                            ({{ $leaveRequest->days }} day{{ $leaveRequest->days === 1 ? '' : 's' }})
                        </dd>
                    </div>
                </dl>
            </div>

            @if ($leaveRequest->status === \App\Models\LeaveRequest::APPROVED)
                <div class="rounded-md bg-blue-50 p-4 text-sm text-blue-800">
                    This request is approved. Saving re-checks the balance — reductions refund days, extensions must fit the remaining balance — moves the LEAVE attendance stamps to the new dates, and notifies the employee.
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800">
                        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                    </div>
                @endif

                <form action="{{ route('leave.update', $leaveRequest) }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="leave_type_id" value="Leave type" />
                        <select id="leave_type_id" name="leave_type_id" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}" @selected(old('leave_type_id', $leaveRequest->leave_type_id) == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('leave_type_id')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="start_date" value="Start date" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full"
                                          :value="old('start_date', $leaveRequest->start_date->toDateString())" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="end_date" value="End date" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full"
                                          :value="old('end_date', $leaveRequest->end_date->toDateString())" required />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>
                    <p class="text-xs text-gray-400">Days are recalculated from the range — weekends are excluded.</p>

                    <div>
                        <x-input-label for="reason" value="Reason" />
                        <textarea id="reason" name="reason" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason', $leaveRequest->reason) }}</textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>

                    <x-input-error :messages="$errors->get('leave')" class="mt-2" />

                    <button type="submit" class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Save changes
                    </button>
                    <a href="{{ route('leave.show', $leaveRequest) }}" class="ms-3 text-sm text-gray-500 hover:underline">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
