@extends('admin.layout')

@section('content')
<div class="card">
    <h3>DeepSeek Key Rotation</h3>
    <form method="post" action="/admin/credentials/rotate" class="row">
        @csrf
        <input name="label" placeholder="Label" value="rotated">
        <input name="secret" placeholder="New secret" required style="min-width:320px;">
        <button type="submit">Rotate</button>
    </form>
</div>
<div class="card">
    <h3>Credentials</h3>
    <table>
        <thead><tr><th>ID</th><th>Label</th><th>Active</th><th>Masked</th><th>Updated</th></tr></thead>
        <tbody>
        @foreach($credentials as $item)
            <tr>
                <td>{{ $item['id'] ?? '' }}</td>
                <td>{{ $item['label'] ?? '' }}</td>
                <td>{{ !empty($item['is_active']) ? 'yes' : 'no' }}</td>
                <td>{{ $item['secret_masked'] ?? '' }}</td>
                <td>{{ $item['updated_at'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
