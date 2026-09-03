<?php

use App\Actions\KnowledgeBase\DeleteDocumentAction;
use App\Actions\KnowledgeBase\UploadDocumentAction;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('layouts.vendor', ['title' => 'Knowledge Base'])]
class extends Component
{
    use WithFileUploads, WithPagination;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $newFile = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function with(): array
    {
        $vendor = Auth::user()->vendor;

        return [
            'documents' => $vendor->knowledgeBases()
                ->with(['documents' => fn ($q) => $q->latest()])
                ->get()
                ->pluck('documents')
                ->flatten()
                ->sortByDesc('created_at')
                ->values(),
        ];
    }

    public function upload(): void
    {
        $this->authorize('create', Document::class);

        $this->validate([
            'newFile' => ['required', 'file', 'max:20480', 'mimes:pdf,docx,txt,md'],
        ]);

        $vendor = Auth::user()->vendor;

        $knowledgeBase = $vendor->knowledgeBases()->firstOrCreate(
            ['type' => 'documents'],
            ['name' => 'Documents']
        );

        try {
            app(UploadDocumentAction::class)->execute($knowledgeBase, $this->newFile);
        } catch (\RuntimeException $e) {
            $this->addError('newFile', $e->getMessage());

            return;
        }

        $this->reset('newFile');
    }

    public function delete(int $documentId): void
    {
        $document = Auth::user()->vendor->documents()->findOrFail($documentId);
        $this->authorize('delete', $document);

        app(DeleteDocumentAction::class)->execute($document);
    }
};
?>

<div wire:poll.5s>
    <div class="mb-6">
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Knowledge Base</h2>
        <p class="mt-1 text-sm text-slate-500">Documents the AI reads to answer customer questions about your store.</p>
    </div>

    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold text-slate-900">Upload a document</h3>

        <form wire:submit="upload" class="flex items-end gap-4">
            <div class="flex-1">
                <label class="mb-1 block text-sm font-medium text-slate-700">PDF, DOCX, TXT, or Markdown (max 20MB)</label>
                <input wire:model="newFile" type="file" accept=".pdf,.docx,.txt,.md"
                    class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                @error('newFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="newFile,upload"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-indigo-500/30 transition hover:bg-indigo-700 disabled:opacity-60">
                <svg wire:loading wire:target="newFile,upload" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Upload
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">File</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Uploaded</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($documents as $document)
                        <tr class="odd:bg-white even:bg-slate-50/50 hover:bg-indigo-50/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                        <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                    </div>
                                    <span class="font-medium text-slate-900">{{ $document->original_filename }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                @php
                                    $statusColors = ['ready' => 'emerald', 'failed' => 'red', 'pending' => 'slate'];
                                    $color = $statusColors[$document->status] ?? 'amber';
                                @endphp
                                <x-ui.badge :color="$color">{{ ucfirst($document->status) }}</x-ui.badge>
                                @if ($document->status === 'failed' && $document->error_message)
                                    <p class="mt-1 text-xs text-red-500">{{ $document->error_message }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-slate-400">{{ $document->created_at->diffForHumans() }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <button wire:click="delete({{ $document->id }})" wire:confirm="Delete this document?" title="Delete"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-red-600 hover:bg-red-50">
                                    <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <x-ui.empty-state message="No documents yet."
                                    icon="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
