@php
    /** @var \App\Enums\UserRole|null $role */
    /** @var array $modules */
    /** @var array $overrides */
    $wirePrefix = $wirePrefix ?? 'overrides';
    $service = app(\App\Services\PermissionService::class);
@endphp

@foreach ($modules as $moduleLabel => $moduleKeys)
    @php
        $moduleKeys = array_intersect($moduleKeys, array_keys($overrides));
    @endphp

    @if (count($moduleKeys) > 0)
        <div class="card-os overflow-hidden mb-4" wire:key="grid-module-{{ $moduleLabel }}-{{ $wirePrefix }}">
            <div class="px-4 py-2 border-b border-surface-800 bg-surface-900/50">
                <h3 class="text-xs font-semibold text-accent-400 uppercase tracking-wider">{{ $moduleLabel }}</h3>
            </div>
            <table class="min-w-full divide-y divide-surface-800 text-xs">
                <thead class="bg-surface-900/30">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-surface-400 uppercase tracking-wider">Page</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-surface-400 uppercase tracking-wider">Role Default</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-surface-400 uppercase tracking-wider">Override</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-surface-400 uppercase tracking-wider">Effective</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-800">
                    @foreach ($moduleKeys as $key)
                        @php
                            $override = $overrides[$key] ?? 'inherit';
                            $isOverridden = $override !== 'inherit';
                            $roleDefault = $role ? $service->check($role, $key) : null;
                            $effective = match ($override) {
                                'inherit' => $roleDefault,
                                'deny' => null,
                                default => $override,
                            };
                        @endphp
                        <tr wire:key="grid-row-{{ $key }}-{{ $wirePrefix }}" class="{{ $isOverridden ? 'bg-accent-500/5' : '' }}">
                            <td class="px-4 py-2 whitespace-nowrap text-surface-200">{{ $key }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($roleDefault === 'full')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-success-500/15 text-success-500 ring-success-500/30">Full</span>
                                @elseif ($roleDefault === 'view')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-accent-500/15 text-accent-400 ring-accent-500/30">View</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-surface-800 text-surface-400 ring-surface-700">Deny</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <select
                                    wire:model.live="{{ $wirePrefix }}.{{ $key }}"
                                    class="block rounded-md border-0 bg-surface-800 py-1 pl-2 pr-7 text-surface-200 text-xs ring-1 ring-inset ring-surface-700 focus:ring-2 focus:ring-accent-500"
                                >
                                    <option value="inherit">Inherit</option>
                                    <option value="full">Full</option>
                                    <option value="view">View</option>
                                    <option value="deny">Deny</option>
                                </select>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($effective === 'full')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-success-500/15 text-success-500 ring-success-500/30">Full</span>
                                @elseif ($effective === 'view')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-accent-500/15 text-accent-400 ring-accent-500/30">View</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-red-500/15 text-red-400 ring-red-500/30">Deny</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach
