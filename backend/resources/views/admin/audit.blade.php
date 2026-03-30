@extends('admin.layout')

@section('content')
<div class="card">
    <h3>Audit Log</h3>
    <table>
        <thead><tr><th>At</th><th>Actor</th><th>Action</th><th>IP</th><th>Payload</th></tr></thead>
        <tbody>
        @foreach($logs as $log)
            <tr>
                <td>{{ $log['created_at'] ?? '' }}</td>
                <td>{{ $log['actor'] ?? '' }}</td>
                <td>{{ $log['action'] ?? '' }}</td>
                <td>{{ $log['ip'] ?? '' }}</td>
                <td><code>{{ $log['payload_json'] ?? '' }}</code></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
