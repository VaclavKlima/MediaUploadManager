<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Movie pipeline" details="current operational state">
        <x-slot:icon>
            <x-pulse::icons.queue-list />
        </x-slot:icon>
    </x-pulse::card-header>

    <div class="grid grid-cols-2 gap-3 @md:grid-cols-3" wire:poll.15s="">
        @foreach ($metrics as $metric)
            <div @class([
                'rounded-lg border p-3',
                'border-rose-200 bg-rose-50 dark:border-rose-900 dark:bg-rose-950' => $metric['warning'],
                'border-gray-200 dark:border-gray-700' => ! $metric['warning'],
            ])>
                <p class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($metric['value']) }}</p>
                <p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['name'] }}</p>
            </div>
        @endforeach
    </div>
</x-pulse::card>
