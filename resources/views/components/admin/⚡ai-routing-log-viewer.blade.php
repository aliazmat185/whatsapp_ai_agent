<?php

use App\Models\AiRoutingLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.admin', ['title' => 'AI Routing Logs'])]
class extends Component
{
    use WithPagination;

    public string $escalatedFilter = '';

    public ?int $viewingId = null;

    public function mount(): void
    {
        if (! auth()->user()->hasAnyRole(['super_admin', 'admin_staff'])) {
            abort(403);
        }
    }

    public function with(): array
    {
        $base = AiRoutingLog::query();

        return [
            'logs' => (clone $base)
                ->with(['conversation.vendor'])
                ->when($this->escalatedFilter !== '', fn ($q) => $q->where('escalated', $this->escalatedFilter === '1'))
                ->latest('created_at')
                ->paginate(20),
            'totalCount' => (clone $base)->count(),
            'escalatedCount' => (clone $base)->where('escalated', true)->count(),
            'avgLatency' => (int) (clone $base)->avg('latency_ms'),
            'viewing' => $this->viewingId ? AiRoutingLog::find($this->viewingId) : null,
        ];
    }

    public function updatingEscalatedFilter(): void
    {
        $this->resetPage();
    }

    public function showPayload(int $id): void
    {
        $this->viewingId = $id;
    }

    public function closeDetail(): void
    {
        $this->viewingId = null;
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">AI Routing Logs</h2>
        <p class="mt-1 text-sm text-slate-500">Every inbound message the AI agent routed, with latency and escalation outcome.</p>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-ui.stat-card label="Total routed" :value="number_format($totalCount)" color="indigo"
            icon="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
        <x-ui.stat-card label="Escalated to human" :value="number_format($escalatedCount)" color="red"
            icon="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        <x-ui.stat-card label="Avg latency" value="{{ number_format($avgLatency) }}ms" color="sky"
            icon="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach (['' => 'All', '0' => 'Resolved by AI', '1' => 'Escalated'] as $value => $label)
            <button wire:click="$set('escalatedFilter', '{{ $value }}')"
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition
                    {{ $escalatedFilter === $value
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/30'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" wire:loading.class="opacity-60" wire:target="escalatedFilter">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Time</th>
                        <th class="px-5 py-3">Vendor</th>
                        <th class="px-5 py-3">Intent</th>
                        <th class="px-5 py-3">Latency</th>
                        <th class="px-5 py-3">Escalated</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5 text-slate-500">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $log->conversation->vendor->business_name }}</td>
                            <td class="px-5 py-3.5">
                                <x-ui.badge color="slate">{{ $log->detected_intent ?? 'unknown' }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">{{ number_format($log->latency_ms) }}ms</td>
                            <td class="px-5 py-3.5">
                                @if ($log->escalated)
                                    <x-ui.badge color="red">Yes</x-ui.badge>
                                @else
                                    <x-ui.badge color="emerald">No</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <button wire:click="showPayload({{ $log->id }})" title="View payload"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state message="No AI routing activity yet."
                                    icon="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">
        {{ $logs->links() }}
    </div>

    @if ($viewing)
        <x-ui.modal title="Routing detail — log #{{ $viewing->id }}" close="closeDetail" maxWidth="max-w-3xl">
            <div class="max-h-[75vh] overflow-y-auto px-6 py-5">
                <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    <x-ui.badge color="slate">{{ $viewing->detected_intent ?? 'unknown' }}</x-ui.badge>
                    <span class="text-slate-400">·</span>
                    <span class="text-slate-600">{{ number_format($viewing->latency_ms) }}ms</span>
                    @if ($viewing->escalated)
                        <x-ui.badge color="red">Escalated</x-ui.badge>
                    @endif
                </div>
                <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">Request</p>
                <pre class="mb-4 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-emerald-300">{{ json_encode($viewing->claude_request_payload, JSON_PRETTY_PRINT) }}</pre>
                <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">Response</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-emerald-300">{{ json_encode($viewing->claude_response_payload, JSON_PRETTY_PRINT) }}</pre>
            </div>
        </x-ui.modal>
    @endif
</div>
