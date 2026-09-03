@props([
    'label',
    'value',
    'icon' => null,
    'color' => 'indigo',
    'href' => null,
])

@php
    $colorClasses = [
        'indigo' => 'bg-indigo-50 text-indigo-600',
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'red' => 'bg-red-50 text-red-600',
        'sky' => 'bg-sky-50 text-sky-600',
        'violet' => 'bg-violet-50 text-violet-600',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
    $iconClasses = $colorClasses[$color] ?? $colorClasses['indigo'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition {{ $href ? 'hover:border-indigo-200 hover:shadow-md' : '' }}">
    @if ($icon)
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $iconClasses }}">
            <svg class="h-5.5 w-5.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
            </svg>
        </div>
    @endif
    <div class="min-w-0">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
        <p class="mt-0.5 text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
    </div>
</{{ $tag }}>
