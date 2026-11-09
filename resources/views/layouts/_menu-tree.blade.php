@if(!in_array($item->id, $visited, true) && $item->canBeRenderedFor(auth()->user()))
    @php($visited = [...$visited, $item->id])
    @php($children = $item->children->filter(fn($child) => $child->canBeRenderedFor(auth()->user())))
    @if($children->isEmpty())
        <a class="nav-link {{ $item->route_name && request()->routeIs($item->route_name) ? 'active' : '' }}" href="{{ $item->route_name ? route($item->route_name) : '#' }}"><span>{{ $item->icon ?? '•' }}</span>{{ $item->title }}</a>
    @else
        <div class="nav-group"><span>{{ $item->title }}</span></div>
        @foreach($children as $child)
            @include('layouts._menu-tree', ['item' => $child, 'visited' => $visited])
        @endforeach
    @endif
@endif
