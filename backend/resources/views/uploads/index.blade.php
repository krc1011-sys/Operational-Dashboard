<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Uploads</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-upload-freshness :overdue="$overdue" />

            {{-- Step 1: choose the type. Step 2: upload. Never auto-detected. --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg">Upload a file</h3>
                <p class="text-sm text-gray-600 mt-1">
                    Choose exactly what the file is, then upload it <strong>as you received it</strong> —
                    no renaming, no converting to CSV, no cleaning up. If the file doesn't match
                    what you chose, it is rejected and nothing is imported.
                </p>

                <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data"
                      class="mt-4 space-y-4" x-data="{ type: '{{ old('upload_type') }}' }">
                    @csrf

                    <div>
                        <x-input-label for="upload_type" value="1. What is this file?" />
                        <select id="upload_type" name="upload_type" x-model="type" required
                                class="mt-1 block w-full border-gray-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="">— choose the file type —</option>
                            @foreach($allowedTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('upload_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('upload_type')" class="mt-2" />
                    </div>

                    {{-- What we will check, shown before they upload. --}}
                    @foreach($definitions as $value => $definition)
                        @continue(! array_key_exists($value, $allowedTypes))
                        <div x-show="type === '{{ $value }}'" x-cloak
                             class="text-sm bg-gray-50 border border-gray-200 rounded p-4 space-y-1">
                            <p><strong>Expected:</strong> a {{ $definition->extensionsLabel() }} file,
                                reading {{ $definition->sheetLabel() }}.</p>
                            <p><strong>Required columns:</strong>
                                {{ implode(', ', array_keys($definition->requiredHeaders)) }}</p>
                            @if($definition->expectedFilename)
                                <p class="text-gray-500">Usually named
                                    <code>{{ $definition->expectedFilename }}</code>
                                    (the name is informational — only the contents are checked).</p>
                            @endif
                            @if($definition->notes)
                                <p class="text-gray-600">{{ $definition->notes }}</p>
                            @endif
                        </div>
                    @endforeach

                    {{-- The single-PO export carries no PO column (§C). --}}
                    <div x-show="type === '{{ \App\Enums\UploadType::AmazonPoSingle->value }}'" x-cloak>
                        <x-input-label for="po_number" value="PO number (only if the filename doesn't contain it)" />
                        <x-text-input id="po_number" name="po_number" type="text" class="mt-1 block w-full"
                                      :value="old('po_number')" placeholder="e.g. 6QT4G44D" />
                        <p class="text-xs text-gray-500 mt-1">
                            This format has no PO column inside the file. If the filename is just
                            <code>PurchaseOrder.xlsx</code>, type the PO number here.
                        </p>
                        <x-input-error :messages="$errors->get('po_number')" class="mt-2" />

                        {{-- Nor does it carry the order date, which turnaround is measured from (§L). --}}
                        <x-input-label for="order_date" value="PO date (optional)" class="mt-4" />
                        <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full"
                                      :value="old('order_date')" />
                        <p class="text-xs text-gray-500 mt-1">
                            This format has no order date either — only a future delivery window. Turnaround
                            is measured from the day the PO was raised, so without this the PO will show its
                            completion date but no day count. The bulk export does carry it, so leave this
                            blank if the same PO also arrives that way.
                        </p>
                        <x-input-error :messages="$errors->get('order_date')" class="mt-2" />

                    <div>
                        <x-input-label for="file" value="2. Choose the file" />
                        <input id="file" name="file" type="file" required
                               accept=".xls,.xlsx,.csv"
                               class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4
                                      file:rounded file:border-0 file:bg-teal-50 file:text-teal-700
                                      hover:file:bg-teal-100" />
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Upload and check</x-primary-button>
                        @can('upload-cancelled-items')
                            <a href="{{ route('uploads.cancellation-template') }}"
                               class="text-sm text-teal-700 underline">
                                Download the blank cancellations template
                            </a>
                        @endcan
                    </div>
                </form>
            </div>

            {{-- The audit log. --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg mb-4">Upload history</h3>

                @if($recent->isEmpty())
                    <p class="text-sm text-gray-500">Nothing uploaded yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pr-4">When</th>
                                    <th class="py-2 pr-4">Type</th>
                                    <th class="py-2 pr-4">File</th>
                                    <th class="py-2 pr-4">By</th>
                                    <th class="py-2 pr-4">Result</th>
                                    <th class="py-2 pr-4 text-right">Rows</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($recent as $file)
                                    <tr>
                                        <td class="py-2 pr-4 whitespace-nowrap text-gray-600">
                                            {{ $file->created_at->diffForHumans() }}
                                        </td>
                                        <td class="py-2 pr-4">{{ $file->upload_type->label() }}</td>
                                        <td class="py-2 pr-4">
                                            <a href="{{ route('uploads.show', $file) }}"
                                               class="text-teal-700 underline">{{ $file->original_filename }}</a>
                                        </td>
                                        <td class="py-2 pr-4 text-gray-600">{{ $file->uploadedBy?->name ?? '—' }}</td>
                                        <td class="py-2 pr-4">
                                            <x-upload-status :status="$file->status" />
                                        </td>
                                        <td class="py-2 pr-4 text-right text-gray-600">
                                            {{ $file->rows_imported ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">{{ $recent->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
