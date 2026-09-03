@props([
    'icon' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z',
    'message' => 'Nothing here yet',
])

<div class="px-5 py-16 text-center">
    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
    </svg>
    <p class="mt-3 text-sm font-medium text-slate-500">{{ $message }}</p>
    @isset($slot)
        @if (trim($slot))
            <div class="mt-3">{{ $slot }}</div>
        @endif
    @endisset
</div>
