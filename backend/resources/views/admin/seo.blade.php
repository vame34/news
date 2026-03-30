@extends('admin.layout')

@section('content')
<div class="card">
    <h3>Upsert SEO</h3>
    <form method="post" action="/admin/seo">
        @csrf
        <div class="row">
            <input name="entity_type" value="match" placeholder="entity_type">
            <input name="entity_slug" placeholder="entity_slug" required>
        </div>
        <input name="title" placeholder="title" style="width:100%; margin-top:8px;">
        <textarea name="description" placeholder="description"></textarea>
        <input name="h1" placeholder="h1" style="width:100%; margin-top:8px;">
        <input name="canonical" placeholder="canonical" style="width:100%; margin-top:8px;">
        <input name="robots" value="index,follow" placeholder="robots" style="width:100%; margin-top:8px;">
        <div class="row" style="margin-top:8px;"><button type="submit">Save SEO</button></div>
    </form>
</div>
<div class="card">
    <h3>SEO Rows</h3>
    <table>
        <thead><tr><th>Type</th><th>Slug</th><th>Title</th><th>Robots</th></tr></thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td>{{ $item['entity_type'] ?? '' }}</td>
                <td>{{ $item['entity_slug'] ?? '' }}</td>
                <td>{{ $item['title'] ?? '' }}</td>
                <td>{{ $item['robots'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
