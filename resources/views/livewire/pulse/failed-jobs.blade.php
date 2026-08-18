<x-pulse::card id="pulse-failed-jobs" :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Failed jobs" details="safe manual retry only">
        <x-slot:icon>
            <x-pulse::icons.bug-ant />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.15s="">
        @if ($retryMessage)
            <p class="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                {{ $retryMessage }}
            </p>
        @endif

        @if ($failedJobs === [])
            <x-pulse::no-results />
        @else
            <div class="grid gap-3">
                @foreach ($failedJobs as $failedJob)
                    <article wire:key="failed-job-{{ $failedJob['id'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-700 dark:text-gray-200">{{ $failedJob['name'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $failedJob['summary'] }}</p>
                                @if ($failedJob['failed_at'])
                                    <time class="mt-1 block text-xs text-gray-400 dark:text-gray-500" datetime="{{ $failedJob['failed_at'] }}">
                                        {{ $failedJob['failed_at'] }}
                                    </time>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="showDetails('{{ $failedJob['id'] }}')" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-100 disabled:opacity-50 dark:border-gray-700 dark:hover:bg-gray-800" wire:loading.attr="disabled">
                                    Details
                                </button>

                                @if ($failedJob['retryable'] && $pendingRetryUuid !== $failedJob['id'])
                                    <button type="button" wire:click="requestRetry('{{ $failedJob['id'] }}')" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" wire:loading.attr="disabled">
                                        Retry
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($selectedDetailsUuid === $failedJob['id'] && is_array($selectedContext) && is_array($selectedExport))
                            <div class="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-950">
                                <dl class="grid grid-cols-1 gap-3 @md:grid-cols-2">
                                    <div><dt class="text-xs text-gray-500">Job</dt><dd class="font-semibold">{{ $selectedContext['job']['class'] ?? 'Unsupported background job' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Queue</dt><dd class="font-semibold">{{ $selectedContext['job']['connection'] ?? 'unknown' }} / {{ $selectedContext['job']['queue'] ?? 'unknown' }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Exception</dt><dd class="font-semibold">{{ $selectedContext['exception']['class'] }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">Release</dt><dd class="font-semibold">{{ $selectedContext['release'] }}</dd></div>
                                </dl>

                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $selectedContext['exception']['message'] }}</p>

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
                                    <button type="button" data-copy-context data-copy-source="failed-job-context-markdown-{{ $failedJob['id'] }}" class="rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                        <span data-copy-label>Copy Markdown</span>
                                    </button>
                                    <button type="button" data-copy-context data-copy-source="failed-job-context-json-{{ $failedJob['id'] }}" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">
                                        <span data-copy-label>Copy JSON</span>
                                    </button>
                                    <button type="button" wire:click="closeDetails" class="rounded-md border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-100 dark:border-gray-700 dark:hover:bg-gray-800">Close</button>
                                </div>

                                <textarea id="failed-job-context-markdown-{{ $failedJob['id'] }}" class="hidden" tabindex="-1" aria-hidden="true">{{ $selectedExport['markdown'] }}</textarea>
                                <textarea id="failed-job-context-json-{{ $failedJob['id'] }}" class="hidden" tabindex="-1" aria-hidden="true">{{ $selectedExport['json'] }}</textarea>
                            </div>
                        @endif

                        @if ($pendingRetryUuid === $failedJob['id'])
                            <div class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                <p class="font-medium">Confirm retry of this one job?</p>
                                <p class="mt-1 text-xs">The original task will be queued again. No other failed jobs are affected.</p>
                                <div class="mt-3 flex gap-2">
                                    <button type="button" wire:click="confirmRetry" class="rounded-md bg-amber-700 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-600 disabled:opacity-50" wire:loading.attr="disabled">Confirm retry</button>
                                    <button type="button" wire:click="cancelRetry" class="rounded-md border border-amber-300 px-3 py-2 text-xs font-semibold hover:bg-amber-100 dark:border-amber-800 dark:hover:bg-amber-900" wire:loading.attr="disabled">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
