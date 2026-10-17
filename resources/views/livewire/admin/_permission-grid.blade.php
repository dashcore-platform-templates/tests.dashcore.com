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
            <div class="px-4 py-2 border-b border-(--ds-border) bg-(--ds-surface)">
                <h3 class="text-xs font-semibold text-(--ds-accent-content) uppercase tracking-wider">{{ $moduleLabel }}</h3>
            </div>
            <table class="min-w-full divide-y divide-(--ds-border) text-xs">
                <thead class="bg-(--ds-surface)">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-(--ds-text-muted) uppercase tracking-wider">Page</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-(--ds-text-muted) uppercase tracking-wider">Role Default</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-(--ds-text-muted) uppercase tracking-wider">Override</th>
                        <th scope="col" class="px-4 py-2 text-left font-medium text-(--ds-text-muted) uppercase tracking-wider">Effective</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--ds-border)">
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
                        <tr wire:key="grid-row-{{ $key }}-{{ $wirePrefix }}" class="{{ $isOverridden ? 'bg-(--ds-accent)' : '' }}">
                            <td class="px-4 py-2 whitespace-nowrap text-(--ds-text-strong)">{{ $key }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($roleDefault === 'full')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-(--ds-success-soft) text-(--ds-success) ring-(--ds-success)">Full</span>
                                @elseif ($roleDefault === 'view')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-(--ds-accent-soft) text-(--ds-accent-content) ring-(--ds-accent)">View</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-(--ds-sunken) text-(--ds-text-muted) ring-(--ds-border)">Deny</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <select
                                    wire:model.live="{{ $wirePrefix }}.{{ $key }}"
                                    class="block rounded-md border-0 bg-(--ds-sunken) py-1 pl-2 pr-7 text-(--ds-text-strong) text-xs ring-1 ring-inset ring-(--ds-border) focus:ring-2 focus:ring-(--ds-accent)"
                                >
                                    <option value="inherit">Inherit</option>
                                    <option value="full">Full</option>
                                    <option value="view">View</option>
                                    <option value="deny">Deny</option>
                                </select>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($effective === 'full')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-(--ds-success-soft) text-(--ds-success) ring-(--ds-success)">Full</span>
                                @elseif ($effective === 'view')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-(--ds-accent-soft) text-(--ds-accent-content) ring-(--ds-accent)">View</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-medium ring-1 ring-inset bg-red-500/15 text-(--ds-danger) ring-red-500/30">Deny</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach
