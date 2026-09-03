@props([
    'title' => null,
    'close' => 'cancel',
    'maxWidth' => 'max-w-2xl',
])

<div class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4" wire:click.self="{{ $close }}"
    x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
    <div {{ $attributes->merge(['class' => "w-full {$maxWidth} rounded-xl bg-white shadow-xl"]) }}
        x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>
                <button type="button" wire:click="{{ $close }}" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endif

        {{ $slot }}
    </div>
</div>
