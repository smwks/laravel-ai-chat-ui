@props(['payload'])

<div
    wire:ignore
    wire:key="raw-{{ md5(json_encode($payload)) }}"
    x-data
    x-init="customElements.whenDefined('json-viewer').then(() => { try { const jv = $el.querySelector('json-viewer'); if (jv) jv.collapseAll(); } catch (e) {} })"
>
    <json-viewer data="{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}"></json-viewer>
</div>
