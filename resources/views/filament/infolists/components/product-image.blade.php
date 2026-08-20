@php($image = $getState())

<div class="flex flex-col items-center gap-3 sm:items-start">
    @if (($image['status'] ?? null) === 'found')
        <div
            class="flex items-center justify-center rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900"
            style="width: 260px; height: 260px; max-width: 260px; max-height: 260px;"
        >
            <img
                src="{{ $image['url'] }}"
                alt="Imagen del producto {{ $image['code'] }}"
                class="max-h-full max-w-full object-contain"
                style="width: 260px; height: 260px; max-width: 260px; max-height: 260px; object-fit: contain; display: block; margin: auto; border-radius: 0.75rem;"
                loading="lazy"
            >
        </div>

        <a
            href="{{ $image['url'] }}"
            target="_blank"
            rel="noopener noreferrer"
            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
            Ver imagen completa
        </a>
    @else
        <p class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            {{ $image['label'] ?? 'Sin imagen' }}
        </p>
    @endif
</div>
