@extends('admin.layout')

@section('content')
<div class="card">
    <h3>Dashboard</h3>
    <p>Матчей: <strong>{{ $matchesCount }}</strong></p>
    <p>Новостей: <strong>{{ $newsCount }}</strong></p>
</div>
<div class="card">
    <h3>Reindex Jobs</h3>
    <table>
        <thead><tr><th>ID</th><th>Page</th><th>Status</th><th>Queued</th></tr></thead>
        <tbody>
        @foreach($jobs as $job)
            <tr>
                <td>{{ $job['id'] ?? '' }}</td>
                <td>{{ $job['page_id'] ?? '' }}</td>
                <td>{{ $job['status'] ?? '' }}</td>
                <td>{{ $job['queued_at'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
