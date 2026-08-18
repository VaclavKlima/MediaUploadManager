<x-pulse::card id="pulse-exception-context" :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Exception context" details="sanitized samples for AI debugging">
        <x-slot:icon>
            <x-pulse::icons.bug-ant />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.15s="">
        @if ($incidents === [])
            <x-pulse::no-results />
        @else
            <div class="grid gap-3">
                @foreach ($incidents as $incident)
                    <article wire:key="exception-context-{{ $incident['fingerprint'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-700 dark:text-gray-200">{{ $incident['exception']['class'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $incident['exception']['message'] }}</p>
                                <p class="mt-1 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $incident['exception']['location'] ?? 'Application location unavailable' }}</p>
                                <time class="mt-1 block text-xs text-gray-400 dark:text-gray-500" datetime="{{ $incident['occurred_at'] }}">
                                    {{ $incident['occurred_at'] }} · {{ $incident['release'] }}
                                </time>
                            </div>

                            <button type="button" wire:click="select('{{ $incident['fingerprint'] }}')" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" wire:loading.attr="disabled">
                                Details
                            </button>
                        </div>

                        @if ($selectedFingerprint === $incident['fingerprint'] && is_array($selectedContext) && is_array($selectedExport))
                            <div class="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-950">
                                <dl class="grid grid-cols-1 gap-3 @md:grid-cols-2">
                                    <div><dt class="text-xs text-gray-500">Environment</dt><dd class="font-semibold">{{ $selectedContext['environment'] }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Server</dt><dd class="font-semibold">{{ $selectedContext['server'] }}</dd></div>
                                    @if (is_array($selectedContext['request'] ?? null))
                                        <div><dt class="text-xs text-gray-500">Request</dt><dd class="font-semibold">{{ $selectedContext['request']['method'] }} {{ $selectedContext['request']['route_name'] ?? $selectedContext['request']['route_uri'] ?? 'unmatched route' }}</dd></div>
                                        <div><dt class="text-xs text-gray-500">User ID</dt><dd class="font-semibold">{{ $selectedContext['request']['user_id'] ?? 'Unauthenticated' }}</dd></div>
                                    @endif
                                </dl>

                                @if ($selectedContext['exception']['trace'] !== [])
                                    <div class="overflow-x-auto rounded-md border border-gray-200 p-3 dark:border-gray-700">
                                        <p class="mb-2 text-xs font-semibold text-gray-500">Application trace</p>
                                        <ol class="grid gap-1 font-mono text-xs text-gray-600 dark:text-gray-300">
                                            @foreach ($selectedContext['exception']['trace'] as $frame)
                                                <li>{{ $frame['file'] }}{{ $frame['line'] ? ':'.$frame['line'] : '' }}{{ $frame['call'] ? ' — '.$frame['call'] : '' }}</li>
                                            @endforeach
                                        </ol>
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-2">
                                    <button type="button" data-copy-context data-copy-source="exception-context-markdown" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                        <span data-copy-label>Copy Markdown</span>
                                    </button>
                                    <button type="button" data-copy-context data-copy-source="exception-context-json" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                                        <span data-copy-label>Copy JSON</span>
                                    </button>
                                    <button type="button" wire:click="closeDetails" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">Close</button>
                                </div>

                                <textarea id="exception-context-markdown" class="hidden" tabindex="-1" aria-hidden="true">{{ $selectedExport['markdown'] }}</textarea>
                                <textarea id="exception-context-json" class="hidden" tabindex="-1" aria-hidden="true">{{ $selectedExport['json'] }}</textarea>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
