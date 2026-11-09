@php($selected = array_filter((array) request($name, []), fn ($v) => $v !== '' && $v !== null))
<div class="multi-filter" data-multi-filter>
    <button type="button" class="multi-filter-button" data-multi-toggle>
        <span data-multi-label>{{ count($selected) ? count($selected).' seçili' : $placeholder }}</span>
        <span class="multi-filter-caret">▾</span>
    </button>
    <div class="multi-filter-pop" data-multi-pop hidden>
        @foreach($items as $item)
            <label><input type="checkbox" name="{{ $name }}[]" value="{{ $item->code }}" @checked(in_array($item->code, $selected, true))> {{ $item->label }}</label>
        @endforeach
    </div>
</div>
