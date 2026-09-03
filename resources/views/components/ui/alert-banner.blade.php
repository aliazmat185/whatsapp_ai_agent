@props([
    'color' => 'amber',
    'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
])

@php
    $colorClasses = [
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'indigo' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
        'sky' => 'border-sky-200 bg-sky-50 text-sky-800',
    ];
    $classes = $colorClasses[$color] ?? $colorClasses['amber'];
@endphp

<div {{ $attributes->merge(['class' => "flex items-center gap-2 rounded-lg border px-4 py-3 text-sm {$classes}"]) }}>
    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" />
    </svg>
    <span class="flex-1">{{ $slot }}</span>
</div>
