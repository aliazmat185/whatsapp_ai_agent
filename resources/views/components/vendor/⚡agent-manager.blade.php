<?php

use App\Models\Agent;
use App\Services\Ai\AgentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.vendor', ['title' => 'Agents'])]
class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $persona = '';

    public string $sales_goal = 'full_order_closing';

    public string $language = 'English';

    public string $escalation_rules = '';

    public function with(): array
    {
        return [
            'agents' => Auth::user()->vendor->agents()->latest()->get(),
        ];
    }

    public function openForm(): void
    {
        $this->reset('editingId', 'name', 'persona', 'sales_goal', 'language', 'escalation_rules');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $agent = Auth::user()->vendor->agents()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $agent->id;
        $this->name = $agent->name;
        $this->persona = (string) $agent->persona;
        $this->sales_goal = $agent->sales_goal;
        $this->language = $agent->language;
        $this->escalation_rules = (string) $agent->escalation_rules;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('editingId', 'name', 'persona', 'sales_goal', 'language', 'escalation_rules');
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'persona' => ['nullable', 'string', 'max:2000'],
            'sales_goal' => ['required', 'in:faq_only,upsell,full_order_closing'],
            'language' => ['required', 'string', 'max:100'],
            'escalation_rules' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->editingId) {
            Auth::user()->vendor->agents()->findOrFail($this->editingId)->update($data);
        } else {
            Auth::user()->vendor->agents()->create($data);
        }

        $this->showForm = false;
        $this->reset('editingId', 'name', 'persona', 'sales_goal', 'language', 'escalation_rules');
    }

    public function activate(int $id, AgentService $agentService): void
    {
        $agent = Auth::user()->vendor->agents()->findOrFail($id);
        $agentService->activate($agent);
    }

    public function deactivate(int $id, AgentService $agentService): void
    {
        $agent = Auth::user()->vendor->agents()->findOrFail($id);
        $agentService->deactivate($agent);
    }

    public function delete(int $id): void
    {
        Auth::user()->vendor->agents()->findOrFail($id)->delete();
    }
};
?>

<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-slate-900">Agents</h2>
            <p class="mt-1 text-sm text-slate-500">Configure the AI sales agent that replies on your WhatsApp number. Only one agent can be active at a time.</p>
        </div>
        <button wire:click="openForm"
            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Agent
        </button>
    </div>

    {{-- Create/edit agent modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit agent' : 'New agent'" close="closeForm" maxWidth="max-w-xl">
            <form wire:submit="save" class="max-h-[75vh] overflow-y-auto px-6 py-5">
                <div class="space-y-5">
                    <div>
                        <x-ui.input wire:model="name" name="name" type="text" placeholder="e.g. Sana" required autofocus label="Agent name" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-800">Persona &amp; tone</label>
                        <p class="mb-2 text-xs text-slate-500">How the agent should sound in its replies.</p>
                        <x-ui.textarea wire:model="persona" name="persona" rows="3" placeholder="e.g. Warm, casual, uses light Urdu-English mix, always polite." />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-800">Sales goal <span class="text-red-500">*</span></label>
                        <div class="grid gap-2">
                            @foreach ([
                                ['value' => 'faq_only', 'label' => 'FAQ only', 'description' => 'Answers questions, never sells or pushes checkout.'],
                                ['value' => 'upsell', 'label' => 'Upsell', 'description' => 'Answers questions and suggests extra or higher-value items.'],
                                ['value' => 'full_order_closing', 'label' => 'Full order closing', 'description' => 'Takes the customer all the way through cart and checkout.'],
                            ] as $goal)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-3.5 py-3 text-sm transition {{ $sales_goal === $goal['value'] ? 'border-indigo-500 bg-indigo-50/60 ring-1 ring-inset ring-indigo-500' : 'border-slate-200 hover:border-slate-300' }}">
                                    <input type="radio" wire:model="sales_goal" value="{{ $goal['value'] }}" class="mt-0.5 h-4 w-4 border-slate-300 text-indigo-600 focus:ring-indigo-500/25">
                                    <span>
                                        <span class="block font-medium text-slate-900">{{ $goal['label'] }}</span>
                                        <span class="block text-xs text-slate-500">{{ $goal['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('sales_goal') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <x-ui.input wire:model="language" name="language" type="text" placeholder="e.g. English, Urdu" required label="Language" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-800">Escalation rules</label>
                        <p class="mb-2 text-xs text-slate-500">Extra cases this agent should hand off to you, beyond the default ones.</p>
                        <x-ui.textarea wire:model="escalation_rules" name="escalation_rules" rows="2" placeholder="e.g. Always escalate refund or complaint requests." />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="closeForm"
                        class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Add Agent' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Sales goal</th>
                        <th class="px-5 py-3">Language</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($agents as $agent)
                        <tr class="odd:bg-white even:bg-slate-50/50">
                            <td class="px-5 py-3.5 font-medium text-slate-900">{{ $agent->name }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ str_replace('_', ' ', $agent->sales_goal) }}</td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $agent->language }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $agent->is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200' }}">
                                    {{ $agent->is_active ? 'Active — bound to your WhatsApp number' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($agent->is_active)
                                        <button wire:click="deactivate({{ $agent->id }})" wire:confirm="Deactivate {{ $agent->name }}? Your WhatsApp number will have no active agent until you activate another."
                                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">Deactivate</button>
                                    @else
                                        <button wire:click="activate({{ $agent->id }})" wire:confirm="Activate {{ $agent->name }}? This replaces whichever agent is currently bound to your WhatsApp number."
                                            class="rounded-lg px-3 py-1.5 text-xs font-medium text-indigo-600 hover:bg-indigo-50">Activate</button>
                                    @endif
                                    <button wire:click="edit({{ $agent->id }})" class="rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">Edit</button>
                                    <button wire:click="delete({{ $agent->id }})" wire:confirm="Delete {{ $agent->name }}? This cannot be undone."
                                        class="rounded-lg px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <p class="text-sm font-medium text-slate-500">No agents yet</p>
                                <p class="mt-1 text-sm text-slate-500">Add an agent and activate it to start replying on WhatsApp.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
