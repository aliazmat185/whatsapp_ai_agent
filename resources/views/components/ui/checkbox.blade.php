@props([
    'label' => null,
    'description' => null,
])

<label class="group flex cursor-pointer items-start gap-3">
    <span class="relative mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
        <input type="checkbox" {{ $attributes->except('class') }}
            class="peer h-5 w-5 shrink-0 cursor-pointer appearance-none rounded-md border border-slate-300 bg-white shadow-sm transition checked:border-indigo-600 checked:bg-indigo-600 hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-1">
        <svg class="pointer-events-none absolute h-3.5 w-3.5 text-white opacity-0 transition peer-checked:opacity-100" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
    </span>
    @if ($label)
        <span class="text-sm leading-5">
            <span class="font-medium text-slate-700">{{ $label }}</span>
            @if ($description)
                <span class="block text-xs text-slate-400">{{ $description }}</span>
            @endif
        </span>
    @endif
</label>
