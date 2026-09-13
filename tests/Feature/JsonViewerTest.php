<?php

function renderJsonViewer(mixed $value): string
{
    // Blade's {{ }} escapes quotes to &quot; entities — correct for the browser,
    // but decoded here so assertions can read naturally as literal JSON text.
    return html_entity_decode(
        view('ai-kit::components.chat.partials.json-viewer', ['value' => $value])->render()
    );
}

it('renders scalars as plain json', function () {
    $html = renderJsonViewer(['name' => 'Ada', 'active' => true, 'age' => 36, 'nickname' => null]);

    expect($html)->toContain('"name": "Ada",');
    expect($html)->toContain('"active": true,');
    expect($html)->toContain('"age": 36,');
    expect($html)->toContain('"nickname": null');
});

it('omits the trailing comma on the last entry', function () {
    $html = renderJsonViewer(['a' => 1, 'b' => 2]);

    expect($html)->toContain('"a": 1,');
    expect($html)->toContain('"b": 2</div>');
    expect($html)->not->toContain('"b": 2,');
});

it('renders a sequential array as brackets rather than braces', function () {
    $html = renderJsonViewer(['red', 'green']);

    expect($html)->toContain('>[<');
    expect($html)->toContain(']');
    expect($html)->not->toContain('>{<');
});

it('renders an associative array as braces', function () {
    $html = renderJsonViewer(['color' => 'red']);

    expect($html)->toContain('>{<');
});

it('nests object properties with 2 additional spaces of indentation per level', function () {
    $html = renderJsonViewer(['outer' => ['inner' => 'value']]);

    expect($html)->toContain('padding-left: 0ch');
    expect($html)->toContain('padding-left: 2ch');
});

it('gives each collapsible node its own independent open state', function () {
    $html = renderJsonViewer(['a' => ['x' => 1], 'b' => ['y' => 2]]);

    // The root value itself is one collapsible node, plus one each for 'a' and 'b'.
    expect(substr_count($html, 'x-data="{ open: true }"'))->toBe(3);
});

it('renders the collapsed form of an empty array with no children', function () {
    $html = renderJsonViewer(['empty' => []]);

    expect($html)->toContain('[ ... ]');
});

it('includes a search input and no other visible controls', function () {
    $html = renderJsonViewer(['a' => 1]);

    expect($html)->toContain('placeholder="Search…"');
    expect($html)->not->toContain('<button');
});
