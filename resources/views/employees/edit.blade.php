<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Employee</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form action="{{ route('employees.update', $employee) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    @method('PUT')
                    @include('employees.form', ['employee' => $employee])
                    <div class="flex items-center gap-4 pt-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                            Save Changes
                        </button>
                        <a href="{{ route('employees.show', $employee) }}" class="text-sm text-gray-600 hover:underline">Cancel</a>
                    </div>
                </form>

                @can('employees.delete')
                    <form action="{{ route('employees.destroy', $employee) }}" method="POST" class="mt-8 border-t border-gray-100 pt-6"
                          onsubmit="return confirm('Permanently delete this employee and user account?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600 text-sm hover:underline">Delete Employee</button>
                    </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
