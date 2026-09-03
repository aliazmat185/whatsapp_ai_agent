<?php

use App\Models\ProductCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts.vendor', ['title' => 'Categories'])]
class extends Component
{
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $newImage = null;

    public ?string $existingImagePath = null;

    public function with(): array
    {
        $categories = Auth::user()->vendor->categories()->withCount('products')->get();

        return [
            'categories' => $categories,
            'activeCount' => $categories->where('is_active', true)->count(),
            'inactiveCount' => $categories->where('is_active', false)->count(),
        ];
    }

    public function openForm(): void
    {
        $this->reset('editingId', 'name', 'newImage', 'existingImagePath');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $category = Auth::user()->vendor->categories()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->newImage = null;
        $this->existingImagePath = $category->image_path;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'name', 'newImage', 'existingImagePath');
        $this->resetValidation();
    }

    public function removeImage(): void
    {
        if ($this->editingId) {
            $category = Auth::user()->vendor->categories()->findOrFail($this->editingId);

            if ($category->image_path) {
                Storage::disk('public')->delete($category->image_path);
                $category->update(['image_path' => null]);
            }
        }

        $this->existingImagePath = null;
        $this->newImage = null;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'newImage' => ['nullable', 'image', 'max:2048'],
        ]);

        $data = ['name' => $this->name];

        if ($this->newImage) {
            $data['image_path'] = $this->newImage->store('category-images', 'public');
        }

        if ($this->editingId) {
            $category = Auth::user()->vendor->categories()->findOrFail($this->editingId);

            if ($this->newImage && $category->image_path) {
                Storage::disk('public')->delete($category->image_path);
            }

            $category->update($data);
        } else {
            $data['slug'] = Str::slug($this->name).'-'.Str::random(4);
            Auth::user()->vendor->categories()->create($data);
        }

        $this->reset('editingId', 'name', 'newImage', 'existingImagePath');
        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $category = Auth::user()->vendor->categories()->findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
    }
};
?>

<div>
    {{-- Page header --}}
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Categories</h2>
            <p class="mt-1 text-sm text-slate-500">Organize your products into categories.</p>
        </div>
        <button wire:click="openForm"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Category
        </button>
    </div>

    {{-- Stat cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3">
        @php
            $statCards = [
                ['label' => 'Total categories', 'value' => $categories->count(), 'icon' => 'M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122', 'iconBg' => 'bg-indigo-50 text-indigo-600', 'valueClass' => 'text-slate-900'],
                ['label' => 'Active', 'value' => $activeCount, 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'iconBg' => 'bg-emerald-50 text-emerald-600', 'valueClass' => 'text-emerald-600'],
                ['label' => 'Inactive', 'value' => $inactiveCount, 'icon' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636', 'iconBg' => 'bg-slate-100 text-slate-500', 'valueClass' => 'text-slate-500'],
            ];
        @endphp
        @foreach ($statCards as $stat)
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-500">{{ $stat['label'] }}</p>
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $stat['iconBg'] }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stat['icon'] }}" />
                        </svg>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-bold tracking-tight {{ $stat['valueClass'] }}">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Create/edit category modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4" wire:click.self="closeForm">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h2 class="text-base font-semibold text-slate-900">{{ $editingId ? 'Edit category' : 'New category' }}</h2>
                    <button type="button" wire:click="closeForm" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="save" class="max-h-[75vh] overflow-y-auto px-6 py-5">
                    <x-ui.input wire:model="name" name="name" type="text" placeholder="Enter category name..." required autofocus label="Category name" />

                    <div class="mt-5">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Category image</label>

                        <div class="flex items-center gap-4">
                            @if ($newImage)
                                <img src="{{ $newImage->temporaryUrl() }}" class="h-14 w-14 rounded-lg object-cover ring-1 ring-slate-200">
                            @elseif ($existingImagePath)
                                <img src="{{ Storage::disk('public')->url($existingImagePath) }}" class="h-14 w-14 rounded-lg object-cover ring-1 ring-slate-200">
                            @else
                                <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-slate-100 text-slate-300">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 16.5V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5v12M3 16.5A2.25 2.25 0 005.25 18.75h13.5A2.25 2.25 0 0021 16.5m-18 0V21m18-4.5V21m-18 0h18M9.75 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                                    </svg>
                                </div>
                            @endif

                            <div class="flex-1">
                                <input wire:model="newImage" type="file" accept="image/*"
                                    class="w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition">
                                @if ($existingImagePath && ! $newImage)
                                    <button type="button" wire:click="removeImage" class="mt-1 text-xs font-medium text-red-600 hover:text-red-700">
                                        Remove image
                                    </button>
                                @endif
                                @error('newImage') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <button type="button" wire:click="closeForm"
                            class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                            <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Add Category' }}</span>
                            <span wire:loading wire:target="save">{{ $editingId ? 'Saving…' : 'Adding…' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Table / empty state --}}
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Image</th>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Products</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($categories as $category)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3">
                                @if ($category->image_path)
                                    <img src="{{ Storage::disk('public')->url($category->image_path) }}" class="h-10 w-10 rounded-lg object-cover ring-1 ring-slate-200">
                                @else
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 16.5V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5v12M3 16.5A2.25 2.25 0 005.25 18.75h13.5A2.25 2.25 0 0021 16.5m-18 0V21m18-4.5V21m-18 0h18M9.75 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $category->name }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                                    {{ $category->products_count }} {{ Str::plural('product', $category->products_count) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $category->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200' }}">
                                    {{ $category->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="edit({{ $category->id }})" title="Edit category"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                        <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 9.75l-4.5-4.5" />
                                        </svg>
                                    </button>
                                    <button wire:click="toggleActive({{ $category->id }})"
                                        wire:confirm="{{ $category->is_active ? 'Deactivate' : 'Activate' }} this category?"
                                        title="{{ $category->is_active ? 'Deactivate' : 'Activate' }}"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg {{ $category->is_active ? 'text-red-600 hover:bg-red-50' : 'text-emerald-600 hover:bg-emerald-50' }}">
                                        @if ($category->is_active)
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        @else
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        @endif
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0118 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 004.5 9v.878m13.5-3A2.25 2.25 0 0119.5 9v.878m0 0a2.246 2.246 0 00-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0121 12v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6c0-.98.626-1.813 1.5-2.122" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No categories found</p>
                                <p class="mt-1 text-sm text-slate-500">Add a category to start organizing your products.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
