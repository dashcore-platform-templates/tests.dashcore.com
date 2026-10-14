@php
    $input = 'block w-full px-3 py-2 text-sm bg-surface-800/80 border border-surface-700/60 text-surface-200 rounded-md shadow-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500 placeholder-surface-500';
@endphp

<div>
    <div class="mb-6">
        <a href="{{ $workflowId ? route('workflows.show', $workflowId) : route('workflows.index') }}" wire:navigate class="text-sm text-surface-400 hover:text-surface-200">&larr; {{ $workflowId ? 'Back to the workflow' : 'Workflows' }}</a>
        <h1 class="text-2xl font-bold text-surface-100 mt-2">{{ $workflowId ? 'Change workflow' : 'New workflow' }}</h1>
        <p class="text-sm text-surface-400 mt-1">A browser test: a real browser opens your site, does what the steps say, and checks it worked — as often as you choose.</p>
    </div>

    {{-- Stages --}}
    <ol class="flex flex-wrap gap-2 mb-6">
        @foreach (\App\Livewire\Workflows\Wizard::STAGES as $number => $title)
            <li>
                <button type="button" wire:click="goTo({{ $number }})" wire:loading.attr="disabled" wire:target="goTo({{ $number }})"
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium transition disabled:opacity-50 {{ $stage === $number ? 'bg-accent-500 text-white' : ($stage > $number ? 'bg-accent-500/15 text-accent-400' : 'bg-surface-800 text-surface-400') }}">
                    <span class="font-mono">{{ $number }}</span> {{ $title }}
                    <x-spinner wire:loading wire:target="goTo({{ $number }})" class="h-3 w-3" />
                </button>
            </li>
        @endforeach
    </ol>

    <div class="card-os p-6">
        @if ($stage === 1)
            <div class="space-y-5 max-w-2xl">
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" wire:model="name" class="mt-1" placeholder="Lets-talk form reaches us" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="baseUrl" value="Start URL" />
                    <x-text-input id="baseUrl" wire:model="baseUrl" class="mt-1 font-mono" placeholder="https://dashcore.com" />
                    <p class="text-xs text-surface-500 mt-1">The site the steps run on. A step that opens <code class="font-mono">/lets-talk</code> opens it here.</p>
                    <x-input-error :messages="$errors->get('baseUrl')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="description" value="What it proves (optional)" />
                    <textarea id="description" wire:model="description" rows="3" class="{{ $input }} mt-1" placeholder="Someone can send the contact form and gets the thank-you."></textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>
            </div>
        @elseif ($stage === 2)
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <p class="text-sm text-surface-400">Steps run top to bottom. Each must work for the run to pass.</p>
                <button type="button" wire:click="useContactFormTemplate" wire:loading.attr="disabled" wire:target="useContactFormTemplate"
                    @if (count($steps) > 1) wire:confirm="Replace these steps with the contact-form template?" @endif
                    class="inline-flex items-center gap-2 text-xs font-medium text-accent-400 hover:text-accent-500 disabled:opacity-50">
                    <x-spinner wire:loading wire:target="useContactFormTemplate" class="h-3 w-3" />
                    Start from a contact-form template
                </button>
            </div>

            <ol class="space-y-3">
                @foreach ($steps as $i => $step)
                    @php $kind = $kinds[$step['do']] ?? $kinds['click']; @endphp
                    <li wire:key="step-{{ $i }}-{{ $step['do'] }}" class="rounded-lg border border-surface-700/60 bg-surface-900/40 p-3">
                        <div class="flex flex-wrap items-start gap-3">
                            <span class="mt-2 w-6 shrink-0 text-right font-mono text-xs text-surface-500">{{ $i + 1 }}</span>
                            <div class="w-48 shrink-0">
                                <select wire:model.live="steps.{{ $i }}.do" aria-label="Step {{ $i + 1 }} kind" class="{{ $input }}">
                                    @foreach ($kinds as $value => $k)
                                        <option value="{{ $value }}">{{ $k['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="grid gap-2 {{ $kind['value'] ? 'sm:grid-cols-2' : '' }}">
                                    <div>
                                        <input type="text" wire:model="steps.{{ $i }}.{{ $kind['field'] }}" placeholder="{{ $kind['hint'] }}" aria-label="Step {{ $i + 1 }} {{ $kind['field'] }}" class="{{ $input }} font-mono">
                                        <x-input-error :messages="$errors->get('steps.'.$i.'.'.$kind['field'])" class="mt-1" />
                                    </div>
                                    @if ($kind['value'])
                                        <div>
                                            <input type="text" wire:model="steps.{{ $i }}.value" placeholder="{{ $step['do'] === 'select' ? 'Option value' : 'Text, or a token like [[email]]' }}" aria-label="Step {{ $i + 1 }} value" class="{{ $input }}">
                                            <x-input-error :messages="$errors->get('steps.'.$i.'.value')" class="mt-1" />
                                        </div>
                                    @endif
                                </div>
                                <input type="text" wire:model="steps.{{ $i }}.label" placeholder="Label in plain words (optional)" aria-label="Step {{ $i + 1 }} label" class="{{ $input }} text-xs">
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" wire:click="moveStep({{ $i }}, -1)" wire:loading.attr="disabled" wire:target="moveStep" @disabled($i === 0) class="rounded p-1.5 text-surface-400 hover:bg-surface-800 hover:text-surface-200 disabled:opacity-30" aria-label="Move step {{ $i + 1 }} up">&uarr;</button>
                                <button type="button" wire:click="moveStep({{ $i }}, 1)" wire:loading.attr="disabled" wire:target="moveStep" @disabled($i === count($steps) - 1) class="rounded p-1.5 text-surface-400 hover:bg-surface-800 hover:text-surface-200 disabled:opacity-30" aria-label="Move step {{ $i + 1 }} down">&darr;</button>
                                <button type="button" wire:click="removeStep({{ $i }})" wire:loading.attr="disabled" wire:target="removeStep" @disabled(count($steps) === 1) class="rounded p-1.5 text-surface-400 hover:bg-red-500/10 hover:text-red-400 disabled:opacity-30" aria-label="Remove step {{ $i + 1 }}">&times;</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
            <x-input-error :messages="$errors->get('steps')" class="mt-2" />

            <div class="mt-4 flex flex-wrap items-center gap-2" x-data="{ kind: 'click' }">
                <select x-model="kind" aria-label="Kind of step to add" class="{{ str_replace('w-full', 'w-56', $input) }}">
                    @foreach ($kinds as $value => $k)
                        <option value="{{ $value }}">{{ $k['name'] }}</option>
                    @endforeach
                </select>
                <x-secondary-button x-on:click="$wire.addStep(kind)" wire:loading.attr="disabled" wire:target="addStep">
                    <x-spinner wire:loading wire:target="addStep" class="mr-2 h-3 w-3" />
                    Add step
                </x-secondary-button>
            </div>

            <p class="mt-4 text-xs text-surface-500">
                Fresh fake data every run:
                @foreach ($tokens as $token)<code class="font-mono text-surface-400">[[{{ $token }}]]</code>@if (! $loop->last), @endif @endforeach.
                <code class="font-mono text-surface-400">[[email]]</code> becomes <code class="font-mono text-surface-400">{{ config('workflows.fake_email') }}</code>.
            </p>
        @elseif ($stage === 3)
            <div class="space-y-5 max-w-2xl">
                <fieldset>
                    <legend class="block text-sm font-medium text-surface-300">How often</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($frequencies as $option)
                            <label class="flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm {{ $frequency === $option->value ? 'border-accent-500 bg-accent-500/10 text-surface-100' : 'border-surface-700/60 text-surface-300' }}">
                                <input type="radio" wire:model.live="frequency" value="{{ $option->value }}" class="text-accent-500 focus:ring-accent-500">
                                {{ $option->label() }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('frequency')" class="mt-1" />
                </fieldset>
                <div>
                    <x-input-label for="notify" value="Who hears when it breaks" />
                    <x-text-input id="notify" wire:model="notify" class="mt-1" placeholder="you@company.com, ops@company.com" />
                    <p class="text-xs text-surface-500 mt-1">One email when it breaks, one when it works again. Leave empty for none.</p>
                    <x-input-error :messages="$errors->get('notify')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="alertAfter" value="Failures in a row before the alert" />
                    <x-text-input id="alertAfter" type="number" min="1" max="20" wire:model="alertAfter" class="mt-1 w-28" />
                    <p class="text-xs text-surface-500 mt-1">2 keeps a one-off blip quiet.</p>
                    <x-input-error :messages="$errors->get('alertAfter')" class="mt-1" />
                </div>
            </div>
        @else
            <dl class="grid gap-4 sm:grid-cols-3 mb-6">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-surface-500">Name</dt>
                    <dd class="mt-1 text-sm text-surface-100">{{ $name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-surface-500">Runs on</dt>
                    <dd class="mt-1 text-sm font-mono text-surface-100 break-all">{{ $baseUrl }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-surface-500">How often</dt>
                    <dd class="mt-1 text-sm text-surface-100">{{ \App\Enums\WorkflowFrequency::from($frequency)->label() }} · alert after {{ $alertAfter }} {{ \Illuminate\Support\Str::plural('failure', $alertAfter) }}</dd>
                </div>
            </dl>
            <h2 class="text-xs font-medium uppercase tracking-wider text-surface-500 mb-2">Steps</h2>
            <ol class="space-y-1 text-sm text-surface-200">
                @foreach ($steps as $i => $step)
                    <li class="flex gap-3">
                        <span class="w-6 shrink-0 text-right font-mono text-xs text-surface-500 pt-0.5">{{ $i + 1 }}</span>
                        <span>{{ ($step['label'] ?? '') !== '' ? $step['label'] : \App\Workflows\Steps::describe($step + ['path' => '', 'target' => '', 'text' => '', 'key' => '', 'value' => '']) }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="mt-6 text-xs text-surface-500">Every run does these steps for real on {{ $baseUrl }}: a form it sends is a real submission there, with whatever that site does next.</p>
        @endif
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($stage > 1)
                <x-secondary-button wire:click="back" wire:loading.attr="disabled" wire:target="back">
                    <x-spinner wire:loading wire:target="back" class="mr-2 h-3 w-3" />
                    Back
                </x-secondary-button>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($stage < 4)
                <x-primary-button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next">
                    <x-spinner wire:loading wire:target="next" class="mr-2 h-3 w-3" />
                    Next
                </x-primary-button>
            @else
                <x-secondary-button wire:click="save(false)" wire:loading.attr="disabled" wire:target="save">
                    <x-spinner wire:loading wire:target="save(false)" class="mr-2 h-3 w-3" />
                    Save{{ $workflowId ? '' : ', switched off' }}
                </x-secondary-button>
                <x-primary-button type="button" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save">
                    <x-spinner wire:loading wire:target="save(true)" class="mr-2 h-3 w-3" />
                    Save and switch on
                </x-primary-button>
            @endif
        </div>
    </div>
</div>
