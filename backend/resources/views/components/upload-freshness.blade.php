{{--
    The §J upload-freshness nudge: "DFS not uploaded in 9 days — upload the latest".

    Only file types with an expected cadence appear here. Event-driven files (POs arrive
    Mon/Wed plus ad-hoc) are deliberately left out so the banner stays meaningful.
--}}
@props(['overdue' => []])

@if(count($overdue))
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
        <p class="font-medium text-amber-900 text-sm mb-2">Some files are due for an update</p>
        <ul class="text-sm text-amber-900 space-y-1">
            @foreach($overdue as $item)
                <li>
                    <strong>{{ $item['type']->label() }}</strong> —
                    @if($item['last'] === null)
                        never uploaded (expected every {{ $item['cadence'] }} days).
                    @else
                        last uploaded {{ $item['days'] }} days ago
                        (expected every {{ $item['cadence'] }} days).
                    @endif
                    @can($item['type']->permission())
                        <a href="{{ route('uploads.index') }}" class="underline">Upload the latest</a>.
                    @endcan
                </li>
            @endforeach
        </ul>
    </div>
@endif
