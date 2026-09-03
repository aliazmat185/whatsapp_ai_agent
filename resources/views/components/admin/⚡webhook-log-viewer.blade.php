<?php

use App\Models\WebhookLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.admin', ['title' => 'Webhook Logs'])]
class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public ?int $viewingId = null;

    public function mount(): void
    {
        if (! auth()->user()->hasAnyRole(['super_admin', 'admin_staff'])) {
            abort(403);
        }
    }

    public function with(): array
    {
        return [
            'logs' => WebhookLog::when($this->statusFilter, fn ($q) => $q->where('processing_status', $this->statusFilter))
                ->latest('received_at')
                ->paginate(20),
            'viewing' => $this->viewingId ? WebhookLog::find($this->viewingId) : null,
            'statusCounts' => [
                '' => WebhookLog::count(),
                'received' => WebhookLog::where('processing_status', 'received')->count(),
                'processed' => WebhookLog::where('processing_status', 'processed')->count(),
                'ignored_duplicate' => WebhookLog::where('processing_status', 'ignored_duplicate')->count(),
                'failed' => WebhookLog::where('processing_status', 'failed')->count(),
            ],
        ];
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
    @php
        $tabs = ['' => 'All', 'received' => 'Received', 'processed' => 'Processed', 'ignored_duplicate' => 'Duplicate', 'failed' => 'Failed'];
        $statusStyles = [
            'received' => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            'processed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            'ignored_duplicate' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200',
            'failed' => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
        ];
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Webhook Logs</h2>
        <p class="mt-1 text-sm text-slate-500">{{ $statusCounts[''] }} events received from connected sources</p>
    </div>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
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

    @if ($viewing)
        <div class="fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4" wire:click.self="closeDetail">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-900">Payload — log #{{ $viewing->id }}</h3>
                    <button type="button" wire:click="closeDetail" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto p-6">
                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-emerald-300">{{ json_encode($viewing->raw_payload, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Received</th>
                        <th class="px-5 py-3">Source</th>
                        <th class="px-5 py-3">WA Message ID</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5 text-slate-700">{{ $log->received_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-5 py-3.5 text-slate-700">{{ $log->source }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-slate-500">{{ $log->wa_message_id ?? '—' }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$log->processing_status] ?? '' }}">
                                    {{ $log->processing_status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end">
                                    <button wire:click="showPayload({{ $log->id }})" title="View payload"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-indigo-600 hover:bg-indigo-50">
                                        <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z" />
                                </svg>
                                <p class="mt-3 text-sm font-medium text-slate-500">No webhook events yet</p>
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
</div>
