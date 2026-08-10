@use('Illuminate\Support\Number')
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Media disks" details="health and usable capacity">
        <x-slot:icon>
            <x-pulse::icons.circle-stack />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @if ($configurationError)
            <p class="rounded-md bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-950 dark:text-rose-300">{{ $configurationError }}</p>
        @elseif ($disks === [])
            <x-pulse::no-results />
        @else
            <div class="grid gap-3">
                @foreach ($disks as $disk)
                    <article class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex items-center justify-between gap-4">
                            <h3 class="font-semibold text-gray-700 dark:text-gray-200">{{ $disk['label'] }}</h3>
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $disk['health'] === 'healthy' && $disk['eligible'],
                                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $disk['health'] === 'healthy' && ! $disk['eligible'],
                                'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => $disk['health'] === 'unhealthy',
                            ])>
                                {{ $disk['health'] === 'healthy' && $disk['eligible'] ? 'Eligible' : ($disk['health'] === 'healthy' ? 'Reserve reached' : 'Unhealthy') }}
                            </span>
                        </div>

                        @if ($disk['free_bytes'] !== null)
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs text-gray-500">Free</dt><dd class="font-semibold">{{ Number::fileSize($disk['free_bytes']) }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Usable</dt><dd class="font-semibold">{{ Number::fileSize($disk['usable_bytes'] ?? 0) }}</dd></div>
                            </dl>
                        @endif

                        @foreach ($disk['reasons'] as $reason)
                            <p class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $reason['message'] }}</p>
                        @endforeach
                    </article>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
