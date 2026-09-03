@props([
    'color' => 'slate',
])

@php
    $colorClasses = [
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'slate' => 'bg-slate-100 text-slate-600 ring-slate-200',
    ];
    $classes = $colorClasses[$color] ?? $colorClasses['slate'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $slot }}
</span>
