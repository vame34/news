@extends('admin.layout')

@section('content')
@foreach($pages as $page)
<div class="card">
    <h3>Page #{{ $page['id'] }} ({{ $page['entity_type'] }}/{{ $page['entity_slug'] }})</h3>
    <p>Status: {{ $page['status'] }} | Version: {{ $page['version'] }}</p>
    <form method="post" action="/admin/content/{{ $page['id'] }}/publish">
        @csrf
        <input name="title" value="{{ $page['title'] }}" style="width:100%;">
        <textarea name="body">{{ $page['body'] }}</textarea>
        <div class="row">
            <button type="submit">Publish</button>
        </div>
    </form>
    <div class="row" style="margin-top:8px;">
        <form method="post" action="/admin/content/{{ $page['id'] }}/reindex">
            @csrf
            <button type="submit">Queue Reindex</button>
        </form>
        @for($v = $page['version']; $v >= 1; $v--)
            <form method="post" action="/admin/content/{{ $page['id'] }}/rollback/{{ $v }}">
                @csrf
                <button type="submit">Rollback v{{ $v }}</button>
            </form>
        @endfor
    </div>
</div>
@endforeach

<div class="card">
    <h3>Reindex Queue</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Page</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Queued</th>
                <th>Started</th>
                <th>Finished</th>
            </tr>
        </thead>
        <tbody>
            @foreach($jobs as $job)
            <tr>
                <td>{{ $job['id'] }}</td>
                <td>{{ $job['page_id'] }}</td>
                <td>{{ $job['status'] }}</td>
                <td>{{ $job['attempts'] ?? 0 }}</td>
                <td>{{ $job['queued_at'] }}</td>
                <td>{{ $job['processing_started_at'] ?? '-' }}</td>
                <td>{{ $job['finished_at'] ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
