@php($image = $getState())

<div
    class="flex h-16 w-20 items-center justify-center rounded-lg border border-gray-200 bg-white p-1 shadow-sm dark:border-gray-700 dark:bg-gray-900"
    style="width: 80px; height: 64px; max-width: 80px; max-height: 64px;"
>
    @if (($image['status'] ?? null) === 'found')
        <img
            src="{{ $image['url'] }}"
            alt="Imagen del producto {{ $image['code'] }}"
            class="h-full w-full rounded object-contain"
            style="width: 80px; height: 64px; max-width: 80px; max-height: 64px; object-fit: contain; display: block; margin: auto; border-radius: 0.375rem;"
            loading="lazy"
        >
    @else
        <span class="text-center text-xs leading-tight text-gray-500 dark:text-gray-400">
            {{ $image['label'] ?? 'Sin imagen' }}
        </span>
    @endif
</div>
