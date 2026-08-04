<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Upload result</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="font-semibold text-lg">{{ $file->original_filename }}</h3>
                        <p class="text-sm text-gray-600">
                            {{ $file->upload_type->label() }} ·
                            uploaded by {{ $file->uploadedBy?->name ?? 'unknown' }}
                            {{ $file->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <x-upload-status :status="$file->status" />
                </div>

                @if($file->status === \App\Enums\SourceFileStatus::Rejected)
                    <div class="bg-red-50 border border-red-200 text-red-800 rounded p-4 text-sm">
                        <p class="font-medium mb-1">This file was rejected. Nothing was imported.</p>
                        <p>{{ $file->rejection_reason }}</p>
                    </div>
                @elseif($file->status === \App\Enums\SourceFileStatus::Failed)
                    <div class="bg-red-50 border border-red-200 text-red-800 rounded p-4 text-sm">
                        <p class="font-medium mb-1">The import failed part-way.</p>
                        <p>{{ $file->rejection_reason }}</p>
                    </div>
                @elseif($file->status === \App\Enums\SourceFileStatus::Validated)
                    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded p-4 text-sm">
                        <p class="font-medium mb-1">✅ This is the right kind of file — it passed every check.</p>
                        <p>
                            Nothing has been imported yet: the reader for
                            <strong>{{ $file->upload_type->label() }}</strong> is built at
                            <strong>M3</strong>. The file is saved, so it will be picked up then —
                            you do not need to upload it again.
                        </p>
                    </div>
                @elseif($file->status === \App\Enums\SourceFileStatus::Imported)
                    <div class="bg-green-50 border border-green-200 text-green-900 rounded p-4 text-sm">
                        <p class="font-medium">Imported.</p>
                        <p>
                            {{ number_format($file->rows_imported) }} rows imported
                            of {{ number_format($file->rows_read) }} read.
                            @if($file->rows_skipped)
                                {{ number_format($file->rows_skipped) }} skipped.
                            @endif
                            @if($file->rows_unmatched)
                                {{ number_format($file->rows_unmatched) }} waiting for their PO —
                                these are stored and link up automatically once that PO is uploaded.
                            @endif
                        </p>
                    </div>
                @endif
            </div>

            @if($file->summary)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-3">What we read</h3>
                    <dl class="text-sm space-y-2">
                        @if(data_get($file->summary, 'sheet'))
                            <div class="flex gap-3">
                                <dt class="w-40 text-gray-500">Tab</dt>
                                <dd>{{ data_get($file->summary, 'sheet') }}</dd>
                            </div>
                        @endif
                        @if(data_get($file->summary, 'header_row'))
                            <div class="flex gap-3">
                                <dt class="w-40 text-gray-500">Header row</dt>
                                <dd>row {{ data_get($file->summary, 'header_row') }}</dd>
                            </div>
                        @endif
                        @if(data_get($file->summary, 'headers_found'))
                            <div class="flex gap-3">
                                <dt class="w-40 text-gray-500">Columns found</dt>
                                <dd class="text-gray-700">
                                    {{ implode(', ', data_get($file->summary, 'headers_found')) }}
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            @if($file->warnings)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold mb-3">Worth knowing</h3>
                    <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                        @foreach($file->warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <a href="{{ route('uploads.index') }}" class="text-sm text-teal-700 underline">
                    ← Back to uploads
                </a>
                @if($file->stored_path)
                    <a href="{{ route('uploads.download', $file) }}" class="text-sm text-gray-600 underline">
                        Download the file as uploaded
                    </a>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
