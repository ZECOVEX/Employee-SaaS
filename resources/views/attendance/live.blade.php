<x-app-layout>
    @php
        $colors = [
            'PRESENT' => 'bg-green-100 text-green-800',
            'LATE' => 'bg-yellow-100 text-yellow-800',
            'ABSENT' => 'bg-red-100 text-red-800',
            'LEAVE' => 'bg-blue-100 text-blue-800',
            'HOLIDAY' => 'bg-purple-100 text-purple-800',
            'WEEKEND' => 'bg-gray-100 text-gray-600',
            'HALF_DAY' => 'bg-amber-100 text-amber-800',
            'REMOTE' => 'bg-teal-100 text-teal-800',
        ];
    @endphp
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Live Attendance</h2>
            <div class="space-x-2">
                <a href="{{ route('attendance.index') }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    All attendance
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-sm text-gray-500">
                            Today's board — updates live via server-sent events, with a polling fallback.
                        </p>
                        <p class="text-xs text-gray-400" id="live-updated">Updated {{ $updatedAt }}</p>
                    </div>

                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wider text-gray-500">
                                <th class="py-2 pr-4">Employee</th>
                                <th class="py-2 pr-4">Department</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2">Time</th>
                            </tr>
                        </thead>
                        <tbody id="live-body">
                            @forelse ($rows as $row)
                                <tr class="border-b last:border-0">
                                    <td class="py-2 pr-4">
                                        {{ $row['name'] }}
                                        <span class="text-xs text-gray-400">{{ $row['code'] }}</span>
                                    </td>
                                    <td class="py-2 pr-4">{{ $row['department'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="px-2 py-1 rounded text-xs font-semibold {{ $colors[$row['status']] ?? 'bg-gray-100 text-gray-600' }}">
                                            {{ $row['status'] === 'LEAVE' ? 'ON LEAVE' : $row['status'] }}
                                        </span>
                                        @if ($row['is_overtime'])
                                            <span class="ml-1 px-2 py-1 rounded text-xs font-semibold bg-orange-100 text-orange-800">
                                                OVERTIME {{ $row['overtime_label'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2">{{ $row['time'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr class="border-b">
                                    <td colspan="4" class="py-4 text-gray-500">No active employees yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                    <noscript>
                        <p class="mt-4 text-xs text-gray-400">Enable JavaScript for live updates. The snapshot above is current as of page load.</p>
                    </noscript>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const body = document.getElementById('live-body');
            const updated = document.getElementById('live-updated');
            const colors = @json($colors);
            let lastHash = @json($hash);
            let pollTimer = null;

            function cell(text) {
                const td = document.createElement('td');
                td.className = 'py-2 pr-4';
                td.textContent = text;
                return td;
            }

            function render(rows, at) {
                body.innerHTML = '';

                rows.forEach(function (r) {
                    const tr = document.createElement('tr');
                    tr.className = 'border-b last:border-0';

                    const name = document.createElement('td');
                    name.className = 'py-2 pr-4';
                    name.textContent = r.name;
                    const code = document.createElement('span');
                    code.className = 'text-xs text-gray-400';
                    code.textContent = ' ' + r.code;
                    name.appendChild(code);
                    tr.appendChild(name);

                    tr.appendChild(cell(r.department || '—'));

                    const status = document.createElement('td');
                    status.className = 'py-2 pr-4';
                    const badge = document.createElement('span');
                    badge.className = 'px-2 py-1 rounded text-xs font-semibold ' + (colors[r.status] || 'bg-gray-100 text-gray-600');
                    badge.textContent = r.status === 'LEAVE' ? 'ON LEAVE' : r.status;
                    status.appendChild(badge);
                    if (r.is_overtime) {
                        const overtime = document.createElement('span');
                        overtime.className = 'ml-1 px-2 py-1 rounded text-xs font-semibold bg-orange-100 text-orange-800';
                        overtime.textContent = 'OVERTIME ' + r.overtime_label;
                        status.appendChild(overtime);
                    }
                    tr.appendChild(status);

                    const time = cell(r.time || '—');
                    time.className = 'py-2';
                    tr.appendChild(time);

                    body.appendChild(tr);
                });

                if (at) {
                    updated.textContent = 'Updated ' + at;
                }
            }

            function startPolling() {
                if (pollTimer) {
                    return;
                }

                pollTimer = setInterval(function () {
                    fetch(@js($pollUrl) + '?after=' + encodeURIComponent(lastHash), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data.changed) {
                                lastHash = data.hash;
                                render(data.rows, data.at);
                            }
                        })
                        .catch(function () {});
                }, {{ (int) $pollInterval }} * 1000);
            }

            if (window.EventSource) {
                const source = new EventSource(@js($streamUrl));

                source.onmessage = function (event) {
                    try {
                        const data = JSON.parse(event.data);
                        lastHash = data.hash;
                        render(data.rows, data.at);
                    } catch (e) {}
                };

                // Reconnects on its own after a normal close; only fall back
                // to polling when the stream is permanently unavailable.
                source.onerror = function () {
                    if (source.readyState === EventSource.CLOSED) {
                        source.close();
                        startPolling();
                    }
                };
            } else {
                startPolling();
            }
        })();
    </script>
</x-app-layout>
