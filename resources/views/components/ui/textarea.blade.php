@props([
    'label' => null,
    'name' => null,
    'required' => false,
])

@php
    $hasError = $name && $errors->has($name);
    $base = 'w-full px-3 py-2 rounded-lg border-slate-300 bg-white shadow-sm placeholder:text-slate-400 transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500';
    $errorClasses = 'border-red-300 focus:border-red-500 focus:ring-red-500';
@endphp

<div>
    @if ($label)
        <label class="mb-1 block text-sm font-medium text-slate-700">
            {{ $label }} @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    <textarea @if ($required) required @endif {{ $attributes->merge(['class' => $base . ($hasError ? ' ' . $errorClasses : ''), 'rows' => 3]) }}></textarea>

    @if ($name)
        @error($name)
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    @endif
</div>
