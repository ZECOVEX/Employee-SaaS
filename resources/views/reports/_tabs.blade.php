<div class="flex items-center gap-5 text-sm">
    <a href="{{ route('reports.attendance') }}"
       @class(['font-medium', 'text-indigo-600' => $active === 'attendance', 'text-gray-500 hover:text-gray-800' => $active !== 'attendance']) wire:navigate>
        Attendance
    </a>
    <a href="{{ route('reports.monthly') }}"
       @class(['font-medium', 'text-indigo-600' => $active === 'monthly', 'text-gray-500 hover:text-gray-800' => $active !== 'monthly']) wire:navigate>
        Monthly
    </a>
    @can('salary.view')
        <a href="{{ route('reports.salary') }}"
           @class(['font-medium', 'text-indigo-600' => $active === 'salary', 'text-gray-500 hover:text-gray-800' => $active !== 'salary']) wire:navigate>
            Salary
        </a>
    @endcan
</div>
