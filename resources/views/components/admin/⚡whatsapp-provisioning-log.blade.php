<?php

use App\Models\WhatsappAccount;
use App\Services\WhatsApp\WhatsAppProvisioningService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.admin', ['title' => 'WhatsApp Numbers'])]
class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function mount(): void
    {
        if (! auth()->user()->hasAnyRole(['super_admin', 'admin_staff'])) {
            abort(403);
        }
    }

    public function with(): array
    {
        $base = WhatsappAccount::query();

        return [
            'accounts' => (clone $base)->with('vendor')
                ->when($this->statusFilter, fn ($q) => $q->where('onboarding_status', $this->statusFilter))
                ->latest()
                ->paginate(15),
            'statusCounts' => [
                '' => (clone $base)->count(),
                'active' => (clone $base)->where('onboarding_status', 'active')->count(),
                'failed' => (clone $base)->where('onboarding_status', 'failed')->count(),
            ],
        ];
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function retry(int $id, WhatsAppProvisioningService $service): void
    {
        $service->retry(WhatsappAccount::findOrFail($id));
    }
};
?>

<div>
    @php
        $statusStyles = [
            'active' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            'number_submitted' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'verifying' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'registered' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
            'failed' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
            'disabled' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200',
        ];
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">WhatsApp Numbers</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $statusCounts[''] }} connected numbers across all vendors</p>
    </div>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach (['' => 'All', 'active' => 'Active', 'failed' => 'Failed'] as $value => $label)
            <button wire:click="$set('statusFilter', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition
                    {{ $statusFilter === $value
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
                <span class="rounded-full px-1.5 py-0.5 text-xs {{ $statusFilter === $value ? 'bg-white/20' : 'bg-slate-100 text-slate-500' }}">
                    {{ $statusCounts[$value] }}
                </span>
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" wire:loading.class="opacity-60" wire:target="statusFilter">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Vendor</th>
                        <th class="px-5 py-3">Number</th>
                        <th class="px-5 py-3">Onboarding status</th>
                        <th class="px-5 py-3">Error</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($accounts as $account)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold uppercase text-indigo-700">
                                        {{ mb_substr($account->vendor->business_name, 0, 1) }}
                                    </div>
                                    <span class="font-medium text-slate-900">{{ $account->vendor->business_name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-xs text-slate-600">{{ $account->display_phone_number }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$account->onboarding_status] ?? '' }}">
                                    {{ $account->onboarding_status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-red-600">{{ $account->rejection_reason }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end">
                                    @if ($account->onboarding_status === 'failed')
                                        <button wire:click="retry({{ $account->id }})" wire:confirm="Retry provisioning for this number?" title="Retry"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                            <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No WhatsApp connections yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $accounts->links() }}
    </div>
</div>
