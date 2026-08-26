@props(['headers'])

<div>
    <div class="mb-1 text-sm text-zinc-400">Headers</div>

    @if (empty($headers))
        <div class="text-xs text-zinc-400">None</div>
    @else
        <table class="w-full text-left text-xs">
            <tbody>
                @foreach ($headers as $name => $values)
                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                        <td class="py-1 pr-3 align-top font-mono text-zinc-400">{{ $name }}</td>
                        <td class="py-1 font-mono break-all">{{ is_array($values) ? implode(', ', $values) : $values }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
