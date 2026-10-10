@php($department = $department ?? null)

<div>
    <x-input-label for="name" value="Name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                  :value="old('name', $department?->name)" required />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="code" value="Code" />
    <x-text-input id="code" name="code" type="text" class="mt-1 block w-full"
                  :value="old('code', $department?->code)" />
    <x-input-error :messages="$errors->get('code')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="description" value="Description" />
    <textarea id="description" name="description" rows="3"
              class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $department?->description) }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="manager_id" value="Department manager" />
    <select id="manager_id" name="manager_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
        <option value="">None</option>
        @foreach ($managers ?? [] as $manager)
            <option value="{{ $manager->id }}" @selected((int) old('manager_id', $department?->manager_id) === $manager->id)>
                {{ $manager->name }}
            </option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('manager_id')" class="mt-2" />
</div>
