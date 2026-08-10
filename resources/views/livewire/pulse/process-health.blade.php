<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Process health" details="scheduler, worker, and Pulse">
        <x-slot:icon>
            <x-pulse::icons.command-line />
        </x-slot:icon>
    </x-pulse::card-header>

    <div class="grid gap-3" wire:poll.15s="">
        @foreach ($processes as $process)
            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-200">{{ $process['name'] }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $process['last_seen'] ? 'Last seen '.$process['last_seen'] : 'No heartbeat recorded' }}
                    </p>
                </div>
                <span @class([
                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $process['healthy'],
                    'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => ! $process['healthy'],
                ])>
                    {{ $process['healthy'] ? 'Healthy' : 'Stale' }}
                </span>
            </div>
        @endforeach
    </div>
</x-pulse::card>
