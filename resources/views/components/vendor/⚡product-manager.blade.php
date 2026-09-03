<?php

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Store;
use App\Services\Vendor\PackageLimitService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('layouts.vendor', ['title' => 'Products'])]
class extends Component
{
    use WithFileUploads, WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $filterStoreId = null;

    public string $name = '';

    public ?int $storeId = null;

    public ?int $categoryId = null;

    public string $description = '';

    public float $basePrice = 0;

    public string $sku = '';

    public bool $isActive = true;

    public int $initialStock = 0;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $newImage = null;

    public ?string $flashMessage = null;

    public string $flashType = 'success';

    public ?int $flashAt = null;

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
        $this->flashAt = now()->timestamp;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;
        $limitService = app(PackageLimitService::class);
        return [
            'products' => $vendor->products()
                ->with(['store', 'category', 'images'])
                ->when($this->filterStoreId, fn ($q) => $q->where('store_id', $this->filterStoreId))
                ->latest()
                ->paginate(15),
            'stores' => $vendor->stores()->where('is_active', true)->get(),
            'categories' => $vendor->categories()->where('is_active', true)->get(),
            'canAddProduct' => $vendor->status === 'approved' && $limitService->canAddProduct($vendor),
            'remainingProductSlots' => $limitService->remainingProductSlots($vendor),
            'isApproved' => $vendor->status === 'approved',
        ];
    }

    public function create(): void
    {
        $vendor = Auth::user()->vendor;

        if ($vendor->status !== 'approved') {
            abort(403, 'Your vendor account must be approved before you can add products.');
        }

        $this->authorize('create', Product::class);

        if (! app(PackageLimitService::class)->canAddProduct($vendor)) {
            $this->addError('name', 'You have reached your package\'s product limit.');

            return;
        }

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $product = Auth::user()->vendor->products()->findOrFail($id);
        $this->authorize('update', $product);

        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->storeId = $product->store_id;
        $this->categoryId = $product->category_id;
        $this->description = (string) $product->description;
        $this->basePrice = (float) $product->base_price;
        $this->sku = (string) $product->sku;
        $this->isActive = $product->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $vendor = Auth::user()->vendor;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'storeId' => ['required', 'exists:stores,id'],
            'categoryId' => ['nullable', 'exists:product_categories,id'],
            'basePrice' => ['required', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:100'],
            'newImage' => ['nullable', 'image', 'max:2048'],
        ]);

        // Product must belong to one of THIS vendor's own stores.
        $store = $vendor->stores()->findOrFail($this->storeId);

        if ($this->categoryId) {
            $vendor->categories()->findOrFail($this->categoryId);
        }

        if ($this->editingId) {
            $product = $vendor->products()->findOrFail($this->editingId);
            $this->authorize('update', $product);
        } else {
            $this->authorize('create', Product::class);

            if (! app(PackageLimitService::class)->canAddProduct($vendor)) {
                $this->addError('name', 'You have reached your package\'s product limit.');

                return;
            }
        }

        $wasEditing = (bool) $this->editingId;

        try {
            DB::transaction(function () use ($vendor, $store) {
                $data = [
                    'vendor_id' => $vendor->id,
                    'store_id' => $store->id,
                    'category_id' => $this->categoryId,
                    'name' => $this->name,
                    'description' => $this->description ?: null,
                    'base_price' => $this->basePrice,
                    'sku' => $this->sku ?: null,
                    'is_active' => $this->isActive,
                ];

                if ($this->editingId) {
                    $product = Product::withoutGlobalScope('vendor')->findOrFail($this->editingId);
                    $product->update($data);
                } else {
                    $data['slug'] = Str::slug($this->name).'-'.Str::random(6);
                    $product = Product::create($data);

                    Inventory::create([
                        'store_id' => $store->id,
                        'product_id' => $product->id,
                        'quantity' => $this->initialStock,
                    ]);
                }

                if ($this->newImage) {
                    $path = $this->newImage->store('product-images', 'public');

                    $primaryImage = $product->images()->where('is_primary', true)->first();

                    if ($primaryImage) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($primaryImage->path);
                        $primaryImage->delete();
                    }

                    ProductImage::create([
                        'product_id' => $product->id,
                        'path' => $path,
                        'is_primary' => true,
                        'sort_order' => 0,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
            $this->flash('Something went wrong while saving the product. Please try again.', 'error');

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        $this->flash($wasEditing ? 'Product updated successfully.' : 'Product created successfully.');
    }

    public function toggleActive(int $id): void
    {
        $product = Auth::user()->vendor->products()->findOrFail($id);
        $this->authorize('update', $product);

        $product->update(['is_active' => ! $product->is_active]);

        $this->flash($product->is_active ? 'Product activated.' : 'Product deactivated.');
    }

    public function deleteImage(int $imageId): void
    {
        $image = ProductImage::whereHas('product', fn ($q) => $q->where('vendor_id', Auth::user()->vendor_id))
            ->findOrFail($imageId);

        \Illuminate\Support\Facades\Storage::disk('public')->delete($image->path);
        $image->delete();

        $this->flash('Image removed.');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'storeId', 'categoryId', 'description',
            'basePrice', 'sku', 'initialStock', 'newImage',
        ]);
        $this->isActive = true;
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Products</h2>
            <p class="mt-1 text-sm text-slate-500">
                {{ $remainingProductSlots === PHP_INT_MAX ? 'Unlimited products' : $remainingProductSlots.' product slot(s) remaining' }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="filterStoreId" class="sm:w-48">
                <option value="">All stores</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                @endforeach
            </x-ui.select>
            @if ($isApproved)
                <button wire:click="create" @disabled(! $canAddProduct)
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New product
                </button>
            @endif
        </div>
    </div>

    @if ($flashMessage)
        <div wire:key="flash-{{ $flashAt }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)"
            x-show="show" x-transition class="mb-4">
            <x-ui.alert-banner :color="$flashType === 'success' ? 'emerald' : 'red'"
                :icon="$flashType === 'success'
                    ? 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'
                    : 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'">
                {{ $flashMessage }}
            </x-ui.alert-banner>
        </div>
    @endif

    @unless ($isApproved)
        <div class="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            Your account must be approved before you can add products.
        </div>
    @endunless

    @error('name') <p class="mb-4 text-sm text-red-600">{{ $message }}</p> @enderror

    @if ($showForm)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4" wire:click.self="cancel">
        <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <h2 class="text-base font-semibold text-slate-900">{{ $editingId ? 'Edit product' : 'New product' }}</h2>
                <button type="button" wire:click="cancel" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form wire:submit="save" class="grid max-h-[75vh] grid-cols-2 gap-5 overflow-y-auto px-6 py-5">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Product name <span class="text-red-500">*</span></label>
                    <input wire:model="name" type="text" placeholder="e.g. Chicken Karahi (Half)"
                        class="w-full h-10 px-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                    @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Store <span class="text-red-500">*</span></label>
                    <select wire:model="storeId"
                        class="w-full h-10 px-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                        <option value="">Select a store</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}">{{ $store->name }}</option>
                        @endforeach
                    </select>
                    @error('storeId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
                    <select wire:model="categoryId"
                        class="w-full h-10 px-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                        <option value="">Uncategorized</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                    <textarea wire:model="description" rows="3" placeholder="Short description customers will see"
                        class="w-full px-3 py-2 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Price <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 text-sm">{{ auth()->user()->vendor->default_currency ?? '$' }}</span>
                        <input wire:model="basePrice" type="number" step="0.01" min="0" placeholder="0.00"
                            class="w-full h-10 pl-12 pr-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                    </div>
                    @error('basePrice') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">SKU</label>
                    <input wire:model="sku" type="text" placeholder="Optional"
                        class="w-full h-10 px-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                </div>

                @unless ($editingId)
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Initial stock</label>
                        <input wire:model="initialStock" type="number" min="0" placeholder="0"
                            class="w-full h-10 px-3 rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                    </div>
                @endunless

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Image</label>
                    <input wire:model="newImage" type="file" accept="image/*"
                        class="w-full text-sm text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition">
                    @error('newImage') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 flex items-center gap-6 pt-1">
                    <x-ui.checkbox wire:model="isActive" label="Active" />
                </div>

                <div class="col-span-2 mt-1 flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="cancel"
                        class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
                        <svg wire:loading wire:target="save" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Save
                    </button>
                </div>
            </form>
        </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Image</th>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Store</th>
                        <th class="px-5 py-3">Category</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3">
                                @if ($product->images->first())
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->images->first()->path) }}" class="h-10 w-10 rounded-lg object-cover ring-1 ring-slate-200">
                                @else
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 16.5V4.5A2.25 2.25 0 015.25 2.25h13.5A2.25 2.25 0 0121 4.5v12M3 16.5A2.25 2.25 0 005.25 18.75h13.5A2.25 2.25 0 0021 16.5m-18 0V21m18-4.5V21m-18 0h18M9.75 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                                        </svg>
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $product->name }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $product->store->name }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $product->category?->name ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ number_format($product->base_price, 2) }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $product->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-200' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="edit({{ $product->id }})" title="Edit"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                        <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 9.75l-4.5-4.5" />
                                        </svg>
                                    </button>
                                    <button wire:click="toggleActive({{ $product->id }})"
                                        wire:confirm="{{ $product->is_active ? 'Deactivate' : 'Activate' }} this product?"
                                        title="{{ $product->is_active ? 'Deactivate' : 'Activate' }}"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg {{ $product->is_active ? 'text-red-600 hover:bg-red-50' : 'text-emerald-600 hover:bg-emerald-50' }}">
                                        @if ($product->is_active)
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        @else
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        @endif
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No products yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $products->links() }}
    </div>
</div>
