<x-operon-page title="Uploads">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">

            <x-upload-freshness :overdue="$overdue" />

            {{-- Step 1: choose the type. Step 2: upload. Never auto-detected. --}}
            <div class="panel">
                <h3 class="font-semibold text-lg">Upload a file</h3>
                <p class="text-sm mt-1" style="color:var(--muted)">
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
                             class="text-sm border border-gray-200 rounded p-4 space-y-1">
                            <p><strong>Expected:</strong> a {{ $definition->extensionsLabel() }} file,
                                reading {{ $definition->sheetLabel() }}.</p>
                            <p><strong>Required columns:</strong>
                                {{ implode(', ', array_keys($definition->requiredHeaders)) }}</p>
                            @if($definition->expectedFilename)
                                <p style="color:var(--faint)">Usually named
                                    <code>{{ $definition->expectedFilename }}</code>
                                    (the name is informational — only the contents are checked).</p>
                            @endif
                            @if($definition->notes)
                                <p style="color:var(--muted)">{{ $definition->notes }}</p>
                            @endif
                        </div>
                    @endforeach

                    {{-- The single-PO export carries no PO column (§C). --}}
                    <div x-show="type === '{{ \App\Enums\UploadType::AmazonPoSingle->value }}'" x-cloak>
                        <x-input-label for="po_number" value="PO number (only if the filename doesn't contain it)" />
                        <x-text-input id="po_number" name="po_number" type="text" class="mt-1 block w-full"
                                      :value="old('po_number')" placeholder="e.g. 6QT4G44D" />
                        <p class="text-xs mt-1" style="color:var(--faint)">
                            This format has no PO column inside the file. If the filename is just
                            <code>PurchaseOrder.xlsx</code>, type the PO number here.
                        </p>
                        <x-input-error :messages="$errors->get('po_number')" class="mt-2" />

                        {{-- Nor does it carry the order date, which turnaround is measured from (§L). --}}
                        <x-input-label for="order_date" value="PO date (optional)" class="mt-4" />
                        <x-text-input id="order_date" name="order_date" type="date" class="mt-1 block w-full"
                                      :value="old('order_date')" />
                        <p class="text-xs mt-1" style="color:var(--faint)">
                            This format has no order date either — only a future delivery window. Turnaround
                            is measured from the day the PO was raised, so without this the PO will show its
                            completion date but no day count. The bulk export does carry it, so leave this
                            blank if the same PO also arrives that way.
                        </p>
                        <x-input-error :messages="$errors->get('order_date')" class="mt-2" />
                    </div>

                    {{-- Noon's files carry no real delivery date, only Noon's estimate (§Q). --}}
                    <div x-show="type === '{{ \App\Enums\UploadType::NoonFinalPicking->value }}'
                                 || type === '{{ \App\Enums\UploadType::NoonInterimPicking->value }}'" x-cloak>
                        <x-input-label for="delivery_date" value="Delivery date (optional)" />
                        <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full"
                                      :value="old('delivery_date')" />
                        <p class="text-xs mt-1" style="color:var(--faint)">
                            A Noon picking list carries only Noon's <em>estimated</em> delivery date, which is
                            a plan rather than a fact. Turnaround is measured from the day the PO was raised to
                            the day it actually went out, so type the real date here. Left blank, the delivery
                            shows the upload day and marks it as inferred rather than inventing one — and you
                            can still set it later from the delivery itself.
                        </p>
                        <x-input-error :messages="$errors->get('delivery_date')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="file" value="2. Choose the file" />
                        <input id="file" name="file" type="file" required
                               accept=".xls,.xlsx,.csv"
                               class="mt-1 block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100" style="color:var(--muted)" />
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
            <div class="panel">
                <h3 class="font-semibold text-lg mb-4">Upload history</h3>

                @if($recent->isEmpty())
                    <p class="text-sm" style="color:var(--faint)">Nothing uploaded yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="tbl">
                            <thead >
                                <tr>
                                    <th class="py-2 pr-4">When</th>
                                    <th class="py-2 pr-4">Type</th>
                                    <th class="py-2 pr-4">File</th>
                                    <th class="py-2 pr-4">By</th>
                                    <th class="py-2 pr-4">Result</th>
                                    <th class="py-2 pr-4 text-right">Rows</th>
                                </tr>
                            </thead>
                            <tbody >
                                @foreach($recent as $file)
                                    <tr>
                                        <td class="py-2 pr-4 whitespace-nowrap" style="color:var(--muted)">
                                            {{ $file->created_at->diffForHumans() }}
                                        </td>
                                        <td class="py-2 pr-4">{{ $file->upload_type->label() }}</td>
                                        <td class="py-2 pr-4">
                                            <a href="{{ route('uploads.show', $file) }}"
                                               class="text-teal-700 underline">{{ $file->original_filename }}</a>
                                        </td>
                                        <td class="py-2 pr-4" style="color:var(--muted)">{{ $file->uploadedBy?->name ?? '—' }}</td>
                                        <td class="py-2 pr-4">
                                            <x-upload-status :status="$file->status" />
                                        </td>
                                        <td class="py-2 pr-4 text-right" style="color:var(--muted)">
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
</x-operon-page>
