@props([
    'label' => null,
    'description' => null,
])

<label class="{{ $attributes->get('class') }} inline-flex cursor-pointer items-start gap-3">
    <span class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center">
        <input type="checkbox" {{ $attributes->except('class') }} class="peer sr-only">
        <span class="pointer-events-none absolute inset-0 rounded-full bg-slate-200 transition peer-checked:bg-indigo-600"></span>
        <span class="pointer-events-none absolute left-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"></span>
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
