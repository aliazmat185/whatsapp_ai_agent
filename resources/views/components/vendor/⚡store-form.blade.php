<?php

use App\Models\Store;
use App\Models\StoreLocation;
use App\Services\Vendor\PackageLimitService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Stores'])]
class extends Component
{
    public ?int $storeId = null;

    public string $name = '';

    public bool $isActive = true;

    public bool $isPrimary = false;

    public string $addressLine = '';

    public string $city = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?float $deliveryRadiusKm = 5.0;

    // Not persisted (store_locations has no country column) — purely a UI
    // convenience so the map opens already panned to roughly the right part
    // of the world instead of a global zoomed-out view.
    public string $country = 'Pakistan';

    /** @var array<string, array{open: string, close: string, enabled: bool}> */
    public array $workingHours = [];

    public array $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const COUNTRIES = [
        'Pakistan', 'India', 'Bangladesh', 'Sri Lanka', 'Nepal',
        'United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Oman', 'Bahrain',
        'United Kingdom', 'United States', 'Canada', 'Australia',
        'Turkey', 'Malaysia', 'Indonesia', 'Egypt', 'China', 'France',
    ];

    public function mount(?Store $store = null): void
    {
        foreach ($this->days as $day) {
            $this->workingHours[$day] = ['open' => '09:00', 'close' => '21:00', 'enabled' => true];
        }

        if ($store) {
            $store->load('location');
            $this->authorize('update', $store);

            $this->storeId = $store->id;
            $this->name = $store->name;
            $this->isActive = $store->is_active;
            $this->isPrimary = $store->is_primary;
            $this->addressLine = $store->location?->address_line ?? '';
            $this->city = $store->location?->city ?? '';
            $this->latitude = $store->location ? (float) $store->location->latitude : null;
            $this->longitude = $store->location ? (float) $store->location->longitude : null;
            $this->deliveryRadiusKm = $store->location?->delivery_radius_km !== null
                ? (float) $store->location->delivery_radius_km
                : 5.0;

            $hours = $store->working_hours ?? [];
            foreach ($this->days as $day) {
                $this->workingHours[$day] = [
                    'open' => $hours[$day]['open'] ?? '09:00',
                    'close' => $hours[$day]['close'] ?? '21:00',
                    // Stores created before this toggle existed have no
                    // 'enabled' key at all — default them to open, matching
                    // their prior (always-open) behavior rather than
                    // surprise-closing them.
                    'enabled' => $hours[$day]['enabled'] ?? true,
                ];
            }

            return;
        }

        $vendor = Auth::user()->vendor;

        // Belt-and-suspenders with the 'vendor.approved' route middleware —
        // keeping the check here too means it still holds if this component
        // is ever reached another way, and keeps it directly unit-testable.
        if ($vendor->status !== 'approved') {
            abort(403, 'Your vendor account must be approved before you can create a store.');
        }

        $this->authorize('create', Store::class);

        if (! app(PackageLimitService::class)->canAddStore($vendor)) {
            session()->flash('error', 'You have reached your package\'s store limit.');
            $this->redirect(route('vendor.stores'));
        }
    }

    public function with(): array
    {
        return [
            'countries' => self::COUNTRIES,
            'isEditing' => $this->storeId !== null,
        ];
    }

    public function save(): void
    {
        $vendor = Auth::user()->vendor;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'addressLine' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'deliveryRadiusKm' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ]);

        if ($this->storeId) {
            $store = $vendor->stores()->findOrFail($this->storeId);
            $this->authorize('update', $store);
        } else {
            $this->authorize('create', Store::class);

            if (! app(PackageLimitService::class)->canAddStore($vendor)) {
                $this->addError('name', 'You have reached your package\'s store limit.');

                return;
            }
        }

        DB::transaction(function () use ($vendor) {
            $data = [
                'vendor_id' => $vendor->id,
                'name' => $this->name,
                'slug' => Str::slug($this->name).'-'.Str::random(4),
                'is_active' => $this->isActive,
                'is_primary' => $this->isPrimary,
                'working_hours' => $this->workingHours,
            ];

            if ($this->storeId) {
                $store = Store::withoutGlobalScope('vendor')->findOrFail($this->storeId);
                unset($data['slug']); // keep original slug on edit
                $store->update($data);
            } else {
                $store = Store::create($data);
            }

            // Only one primary store per vendor.
            if ($this->isPrimary) {
                Store::withoutGlobalScope('vendor')
                    ->where('vendor_id', $vendor->id)
                    ->where('id', '!=', $store->id)
                    ->update(['is_primary' => false]);
            }

            StoreLocation::updateOrCreate(
                ['store_id' => $store->id],
                [
                    'address_line' => $this->addressLine,
                    'city' => $this->city,
                    'latitude' => $this->latitude,
                    'longitude' => $this->longitude,
                    'delivery_radius_km' => $this->deliveryRadiusKm,
                ]
            );
        });

        $this->redirect(route('vendor.stores'));
    }

    /**
     * Copies Monday's open/close times onto every other day — a convenience
     * for the common case of identical hours all week, without forcing the
     * vendor to re-enter the same two times seven times.
     */
    public function applyToAllDays(): void
    {
        $source = $this->workingHours['mon'] ?? ['open' => '09:00', 'close' => '21:00', 'enabled' => true];

        foreach ($this->days as $day) {
            $this->workingHours[$day] = $source;
        }
    }
};
?>

<div>
    {{-- Page header --}}
    <div class="mb-6">
        <nav class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-400">
            <a href="{{ route('vendor.stores') }}" class="hover:text-slate-600">Stores</a>
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
            <span class="text-slate-500">{{ $isEditing ? 'Edit Store' : 'New Store' }}</span>
        </nav>
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Stores</h2>
    </div>

    @error('name')
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <form wire:submit="save" x-data="{ dirty: false }" @input="dirty = true" @change="dirty = true">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start">
            {{-- Store information & working hours --}}
            <div class="space-y-6 lg:col-span-3">
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h3 class="font-semibold text-slate-900">Store Information</h3>
                        <p class="text-sm text-slate-500">Update your store details and availability.</p>
                    </div>

                    <div class="space-y-5 px-5 py-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-ui.input wire:model="name" name="name" type="text" placeholder="e.g. Downtown Branch" label="Store name" />
                            </div>
                            <div>
                                <x-ui.input wire:model="city" name="city" type="text" placeholder="e.g. Karachi" label="City" />
                            </div>
                            <div class="relative sm:col-span-2" x-data="addressAutocomplete()" x-init="init()" @click.outside="open = false">
                                <x-ui.input wire:model="addressLine" name="addressLine" type="text" placeholder="e.g. 123 Main Street, Block 4" label="Address" autocomplete="off" />

                                <ul x-show="open" x-cloak
                                    class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
                                    <template x-for="result in results" :key="result.place_id">
                                        <li @click="select(result)"
                                            class="cursor-pointer px-3 py-2 text-slate-700 transition hover:bg-indigo-50 hover:text-indigo-700"
                                            x-text="result.display_name"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-x-8 gap-y-4 rounded-lg bg-slate-50 px-4 py-3.5">
                            <x-ui.toggle wire:model="isActive" label="Active" />
                            <x-ui.toggle wire:model="isPrimary" label="Primary store" description="Fallback when no nearby match" />
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="font-semibold text-slate-900">Working Hours</h3>
                            <p class="text-sm text-slate-500">Set when this store accepts orders.</p>
                        </div>
                        <button type="button" wire:click="applyToAllDays"
                            class="shrink-0 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50">
                            Apply Monday to all days
                        </button>
                    </div>

                    <div class="divide-y divide-slate-100 px-5">
                        @foreach ($days as $day)
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                                <span class="w-10 shrink-0 text-sm font-medium capitalize text-slate-700">{{ $day }}</span>

                                @if ($workingHours[$day]['enabled'] ?? true)
                                    <div class="flex flex-1 items-center gap-2">
                                        <input wire:model="workingHours.{{ $day }}.open" type="time"
                                            class="h-9 flex-1 rounded-lg border-slate-300 bg-white px-3 text-sm shadow-sm transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 sm:flex-none">
                                        <span class="text-sm text-slate-300">–</span>
                                        <input wire:model="workingHours.{{ $day }}.close" type="time"
                                            class="h-9 flex-1 rounded-lg border-slate-300 bg-white px-3 text-sm shadow-sm transition focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 sm:flex-none">
                                    </div>
                                @else
                                    <span class="flex-1 text-sm italic text-slate-400">Closed</span>
                                @endif

                                <x-ui.toggle wire:model="workingHours.{{ $day }}.enabled" class="ml-auto" />
                            </div>
                        @endforeach
                    </div>
                    <div class="h-1"></div>
                </div>
            </div>

            {{-- Location & delivery radius --}}
            <div class="space-y-6 lg:col-span-2">
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h3 class="font-semibold text-slate-900">Store Location</h3>
                        <p class="text-sm text-slate-500">Set the exact location customers will use for delivery.</p>
                    </div>

                    <div class="space-y-4 px-5 py-5">
                        <div>
                            <x-ui.select wire:model.live="country" label="Country">
                                @foreach ($countries as $countryOption)
                                    <option value="{{ $countryOption }}">{{ $countryOption }}</option>
                                @endforeach
                            </x-ui.select>
                            <p class="mt-1.5 text-xs text-slate-400">Pans the map below to this country — pick the exact spot with the pin.</p>
                        </div>

                        <div wire:ignore x-data="storeLocationMap()" x-init="init()" class="relative">
                            <div x-ref="mapEl" class="h-72 w-full rounded-lg border border-slate-200"></div>
                            <button type="button" x-ref="locateBtn"
                                class="absolute right-3 top-3 z-[1000] inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white/95 px-2.5 py-1.5 text-xs font-medium text-slate-600 shadow-md backdrop-blur transition hover:bg-white">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                </svg>
                                Locate me
                            </button>
                        </div>
                        <p class="text-xs leading-relaxed text-slate-500">Click the map or drag the pin to set the exact location — the address above fills in automatically.</p>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">Latitude</span>
                                <div class="h-9 w-full truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">
                                    {{ $latitude !== null ? number_format((float) $latitude, 6) : '—' }}
                                </div>
                            </div>
                            <div>
                                <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">Longitude</span>
                                <div class="h-9 w-full truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">
                                    {{ $longitude !== null ? number_format((float) $longitude, 6) : '—' }}
                                </div>
                            </div>
                        </div>
                        @error('latitude')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                        @error('longitude')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-semibold text-slate-900">Delivery Radius</h3>
                    <p class="mb-4 text-sm text-slate-500">Orders outside this distance won't be routed to this store.</p>

                    <div class="flex items-center gap-4">
                        <div class="flex-1">
                            <div class="flex items-center justify-between text-xs text-slate-400">
                                <span>0.5 km</span>
                                <span>50 km</span>
                            </div>
                            <input wire:model.live="deliveryRadiusKm" type="range" min="0.5" max="50" step="0.5"
                                class="h-2 w-full cursor-pointer appearance-none rounded-full bg-slate-200 accent-indigo-600">
                        </div>
                        <div class="flex shrink-0 items-baseline gap-1 rounded-lg bg-indigo-50 px-3 py-1.5">
                            <input wire:model.live="deliveryRadiusKm" type="number" min="0.5" max="50" step="0.5"
                                class="w-12 border-0 bg-transparent p-0 text-right text-lg font-bold text-indigo-600 focus:ring-0">
                            <span class="text-xs font-semibold text-indigo-600">km</span>
                        </div>
                    </div>
                    @error('deliveryRadiusKm')
                        <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 z-10 mt-6 flex items-center justify-end gap-3 rounded-xl border border-slate-200 bg-white/95 px-5 py-3.5 shadow-sm backdrop-blur">
            <span x-show="dirty" x-cloak class="mr-auto text-xs font-medium text-amber-600">Unsaved changes</span>
            <a href="{{ route('vendor.stores') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">Cancel</a>
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">Save Changes</button>
        </div>
    </form>
</div>

@script
<script>
    // ISO 3166-1 alpha-2 codes for the $countries list above (COUNTRIES
    // const in the PHP class) — keep the two in sync if that list changes.
    // Nominatim's countrycodes filter needs these; it doesn't accept the
    // plain English name the <select> shows.
    const COUNTRY_CODES = {
        'Pakistan': 'pk',
        'India': 'in',
        'Bangladesh': 'bd',
        'Sri Lanka': 'lk',
        'Nepal': 'np',
        'United Arab Emirates': 'ae',
        'Saudi Arabia': 'sa',
        'Qatar': 'qa',
        'Kuwait': 'kw',
        'Oman': 'om',
        'Bahrain': 'bh',
        'United Kingdom': 'gb',
        'United States': 'us',
        'Canada': 'ca',
        'Australia': 'au',
        'Turkey': 'tr',
        'Malaysia': 'my',
        'Indonesia': 'id',
        'Egypt': 'eg',
        'China': 'cn',
        'France': 'fr',
    };

    Alpine.data('storeLocationMap', () => ({
        map: null,
        marker: null,
        circle: null,
        geocodeRequestId: 0,

        init() {
            const fallbackLat = 24.8607;
            const fallbackLng = 67.0011;
            const lat = Number(this.$wire.latitude) || fallbackLat;
            const lng = Number(this.$wire.longitude) || fallbackLng;

            this.map = window.L.map(this.$refs.mapEl).setView([lat, lng], this.$wire.latitude ? 15 : 5);

            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(this.map);

            this.marker = window.L.marker([lat, lng], { draggable: true }).addTo(this.map);

            this.circle = window.L.circle([lat, lng], {
                radius: (Number(this.$wire.deliveryRadiusKm) || 5) * 1000,
                color: '#4f46e5',
                weight: 1.5,
                fillColor: '#6366f1',
                fillOpacity: 0.12,
            }).addTo(this.map);

            this.marker.on('dragend', () => this.setLatLng(this.marker.getLatLng()));
            this.map.on('click', (e) => this.setLatLng(e.latlng));

            this.$watch('$wire.deliveryRadiusKm', (value) => {
                this.circle.setRadius((Number(value) || 0) * 1000);
            });

            this.$watch('$wire.latitude', () => this.syncFromWire());
            this.$watch('$wire.longitude', () => this.syncFromWire());

            // Only fires on an actual change to the dropdown (Alpine's
            // $watch doesn't run on initial bind) — so opening the form with
            // its default country never yanks the map away from an already-
            // saved pin, only an explicit re-selection does.
            this.$watch('$wire.country', (value) => this.panToCountry(value));

            // A brand-new store has no saved pin yet — pan/bound to the
            // default-selected country immediately instead of the generic
            // fallback view. An existing store's saved location always wins.
            if (!this.$wire.latitude) {
                this.panToCountry(this.$wire.country);
            }

            this.$refs.locateBtn.addEventListener('click', () => this.useMyLocation());

            // The map container can be hidden (display:none) at first paint
            // while the form's transition runs, which makes Leaflet compute
            // a zero-size viewport — force a resize once it's visible.
            setTimeout(() => this.map.invalidateSize(), 200);
        },

        setLatLng(latlng) {
            const lat = Number(latlng.lat.toFixed(7));
            const lng = Number(latlng.lng.toFixed(7));

            this.marker.setLatLng([lat, lng]);
            this.circle.setLatLng([lat, lng]);
            this.$wire.set('latitude', lat);
            this.$wire.set('longitude', lng);
            this.reverseGeocode(lat, lng);
        },

        // Looks up the street address for wherever the pin was just dropped
        // so the vendor doesn't have to type it in by hand. Best-effort only
        // — lat/lng (already set above) are the fields that actually matter,
        // so a failed/slow lookup here never blocks anything.
        async reverseGeocode(lat, lng) {
            const requestId = ++this.geocodeRequestId;

            try {
                const response = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`,
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok || requestId !== this.geocodeRequestId) {
                    return;
                }

                const data = await response.json();
                const addr = data.address ?? {};

                const line = [addr.house_number, addr.road, addr.suburb ?? addr.neighbourhood]
                    .filter(Boolean)
                    .join(', ') || data.display_name;

                const city = addr.city ?? addr.town ?? addr.village ?? addr.county ?? null;

                if (line) {
                    this.$wire.set('addressLine', line);
                }

                if (city) {
                    this.$wire.set('city', city);
                }
            } catch (e) {
                // Reverse geocoding is a convenience, not a required step —
                // the vendor can still type/edit the address by hand.
            }
        },

        syncFromWire() {
            const lat = Number(this.$wire.latitude);
            const lng = Number(this.$wire.longitude);

            if (Number.isNaN(lat) || Number.isNaN(lng)) {
                return;
            }

            const current = this.marker.getLatLng();
            if (current.lat === lat && current.lng === lng) {
                return;
            }

            this.marker.setLatLng([lat, lng]);
            this.circle.setLatLng([lat, lng]);
            this.map.panTo([lat, lng]);
        },

        // Best-effort: pans/zooms to the selected country's bounding box so
        // the vendor isn't hunting for their country on a world-zoomed map.
        // The pin itself doesn't move until they actually click/drag it.
        async panToCountry(countryName) {
            if (!countryName) {
                return;
            }

            try {
                const response = await fetch(
                    `https://nominatim.openstreetmap.org/search?country=${encodeURIComponent(countryName)}&format=jsonv2&limit=1`,
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok) {
                    return;
                }

                const results = await response.json();
                const bbox = results[0]?.boundingbox;

                if (!bbox) {
                    return;
                }

                const [south, north, west, east] = bbox.map(Number);
                this.map.fitBounds([[south, west], [north, east]]);
            } catch (e) {
                // Convenience only — the vendor can still pan/zoom by hand.
            }
        },

        useMyLocation() {
            if (!navigator.geolocation) {
                return;
            }

            navigator.geolocation.getCurrentPosition((position) => {
                const { latitude, longitude } = position.coords;
                this.map.setView([latitude, longitude], 15);
                this.setLatLng({ lat: latitude, lng: longitude });
            });
        },
    }));

    Alpine.data('addressAutocomplete', () => ({
        results: [],
        open: false,
        debounceTimer: null,
        suppressNextWatch: false,

        init() {
            // $wire.addressLine updates optimistically on every keystroke
            // (Livewire's debounced network sync is separate from this),
            // so watching it doubles as a live "as you type" hook without
            // needing a native input listener of our own.
            this.$watch('$wire.addressLine', (value) => {
                // select() below writes the chosen result's own text back
                // into this same field — without this guard, that write
                // would immediately re-trigger a search and pop the
                // dropdown back open right after the vendor picked one.
                if (this.suppressNextWatch) {
                    this.suppressNextWatch = false;
                    return;
                }

                clearTimeout(this.debounceTimer);
                const query = (value ?? '').trim();

                if (query.length < 3) {
                    this.results = [];
                    this.open = false;
                    return;
                }

                this.debounceTimer = setTimeout(() => this.search(query), 400);
            });
        },

        async search(query) {
            const params = new URLSearchParams({
                q: query,
                format: 'jsonv2',
                addressdetails: '1',
                limit: '5',
            });

            // Scopes suggestions to whichever country is currently selected
            // in the Location card, so typing "Main Street" doesn't surface
            // matches from every country on earth. countrycodes filters by
            // Nominatim's actual administrative boundary for the country —
            // unlike a viewbox (a lat/lng-aligned rectangle), which leaks
            // results from any neighbor that overlaps the box near a border
            // (e.g. Pakistan's bounding rectangle also covers parts of India).
            const code = COUNTRY_CODES[this.$wire.country];

            if (code) {
                params.set('countrycodes', code);
            }

            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/search?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                this.results = await response.json();
                this.open = this.results.length > 0;
            } catch (e) {
                this.results = [];
                this.open = false;
            }
        },

        select(result) {
            const addr = result.address ?? {};
            const line = [addr.house_number, addr.road, addr.suburb ?? addr.neighbourhood]
                .filter(Boolean)
                .join(', ') || result.display_name;
            const city = addr.city ?? addr.town ?? addr.village ?? addr.county ?? null;

            this.suppressNextWatch = true;
            this.$wire.set('addressLine', line);

            if (city) {
                this.$wire.set('city', city);
            }

            this.$wire.set('latitude', Number(parseFloat(result.lat).toFixed(7)));
            this.$wire.set('longitude', Number(parseFloat(result.lon).toFixed(7)));

            this.results = [];
            this.open = false;
        },
    }));
</script>
@endscript
