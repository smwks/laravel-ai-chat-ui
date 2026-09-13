@props(['value', 'depth' => 0, 'keyName' => null, 'isLast' => true])

@php
    $indent = $depth * 2;
    $prefix = $keyName !== null ? json_encode($keyName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': ' : '';
    $comma = $isLast ? '' : ',';
@endphp

@if (is_array($value) || is_object($value))
    @php
        $isList = is_array($value) && array_is_list($value);
        $open = $isList ? '[' : '{';
        $close = $isList ? ']' : '}';
        $entries = is_object($value) ? get_object_vars($value) : $value;
        $count = count($entries);
    @endphp

    <div x-data="{ open: true }">
        <div style="padding-left: {{ $indent }}ch">
            <span>{{ $prefix }}</span><span @click="open = !open" x-show="open" x-cloak class="cursor-pointer select-none">{{ $open }}</span><template x-if="!open"><span @click="open = !open" class="cursor-pointer select-none">{{ $open }} ... {{ $close }}{{ $comma }}</span></template>
        </div>

        @if ($count > 0)
            <div x-show="open" x-cloak>
                @foreach ($entries as $k => $v)
                    @include('ai-kit::components.chat.partials.json-viewer-node', [
                        'value' => $v,
                        'depth' => $depth + 1,
                        'keyName' => $isList ? null : $k,
                        'isLast' => $loop->last,
                    ])
                @endforeach
            </div>
        @endif

        <div x-show="open" x-cloak style="padding-left: {{ $indent }}ch">{{ $close }}{{ $comma }}</div>
    </div>
@else
    <div style="padding-left: {{ $indent }}ch; white-space: pre-wrap">{{ $prefix }}{{ json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}{{ $comma }}</div>
@endif
