@php
    $employee = $employee ?? null;
    $isCreate = $employee === null;
    $user = $employee?->user;
@endphp

@if ($isCreate)
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name" value="Full name" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" value="Confirm password" />
            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required />
        </div>
        <div>
            <x-input-label for="employee_code" value="Employee code" />
            <x-text-input id="employee_code" name="employee_code" type="text" class="mt-1 block w-full" :value="old('employee_code', 'EMP-')" required />
            <x-input-error :messages="$errors->get('employee_code')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="role_id" value="Role" />
            <select id="role_id" name="role_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">Employee (default)</option>
                @foreach ($roles ?? [] as $role)
                    <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
@else
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name" value="Full name" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user?->name)" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user?->email)" required />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="photo" value="Profile photo" />
            <input id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp"
                   class="mt-1 block w-full text-sm text-gray-600 file:me-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200" />
            <p class="mt-1 text-xs text-gray-500">JPG, PNG or WebP — up to 2 MB.</p>
            <x-input-error :messages="$errors->get('photo')" class="mt-2" />
            @if ($employee?->photo_path)
                <img src="{{ Storage::disk('public')->url($employee->photo_path) }}" alt="" class="mt-2 w-16 h-16 rounded-full object-cover" />
            @endif
        </div>
    </div>
@endif

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
    <div>
        <x-input-label for="department_id" value="Department" />
        <select id="department_id" name="department_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            <option value="">None</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected((int) old('department_id', $employee?->department_id) === $department->id)>
                    {{ $department->name }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('department_id')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="position_id" value="Position" />
        <select id="position_id" name="position_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            <option value="">None</option>
            @foreach ($positions as $position)
                <option value="{{ $position->id }}" @selected((int) old('position_id', $employee?->position_id) === $position->id)>
                    {{ $position->title }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="manager_id" value="Manager" />
        <select id="manager_id" name="manager_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            <option value="">None</option>
            @foreach ($managers as $manager)
                <option value="{{ $manager->id }}" @selected((int) old('manager_id', $employee?->manager_id) === $manager->id)>
                    {{ $manager->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="status" value="Status" />
        <select id="status" name="status" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            @foreach (['active', 'inactive', 'terminated'] as $status)
                <option value="{{ $status }}" @selected(old('status', $employee?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="employment_type" value="Employment type" />
        <select id="employment_type" name="employment_type" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            @foreach (['full_time', 'part_time', 'contract', 'intern'] as $type)
                <option value="{{ $type }}" @selected(old('employment_type', $employee?->employment_type ?? 'full_time') === $type)>
                    {{ ucfirst(str_replace('_', ' ', $type)) }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="phone" value="Phone" />
        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $employee?->phone)" />
    </div>
    <div>
        <x-input-label for="joining_date" value="Joining date" />
        <x-text-input id="joining_date" name="joining_date" type="date" class="mt-1 block w-full" :value="old('joining_date', $employee?->joining_date?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="work_location" value="Work location" />
        <x-text-input id="work_location" name="work_location" type="text" class="mt-1 block w-full" :value="old('work_location', $employee?->work_location)" />
    </div>
    <div>
        <x-input-label for="date_of_birth" value="Date of birth" />
        <x-text-input id="date_of_birth" name="date_of_birth" type="date" class="mt-1 block w-full" :value="old('date_of_birth', $employee?->date_of_birth?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="address" value="Address" />
        <x-text-input id="address" name="address" type="text" class="mt-1 block w-full" :value="old('address', $employee?->address)" />
    </div>
    <div>
        <x-input-label for="emergency_contact_name" value="Emergency contact" />
        <x-text-input id="emergency_contact_name" name="emergency_contact_name" type="text" class="mt-1 block w-full" :value="old('emergency_contact_name', $employee?->emergency_contact_name)" />
    </div>
    <div>
        <x-input-label for="emergency_contact_phone" value="Emergency phone" />
        <x-text-input id="emergency_contact_phone" name="emergency_contact_phone" type="text" class="mt-1 block w-full" :value="old('emergency_contact_phone', $employee?->emergency_contact_phone)" />
    </div>
</div>

@if ($isCreate)
    @php($roleIds = collect($roles ?? [])->pluck('id'))
    <script>
        // ensure roles collection available in form create mode via PHP only
    </script>
@endif
