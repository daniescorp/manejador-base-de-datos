<?php

namespace App\Services\ExternalFiles;

use DomainException;

class ExternalIndesignHeaderFormatter
{
    public const COLUMN_COUNT = 15;

    /** @var list<string> */
    public const HEADERS = [
        'CATEGORIA',
        'GRUPO',
        'CODIGO',
        'MARCA',
        'DESCRIPCION',
        'UXB',
        '@IMAGENES',
        'PRECIOLISTA',
        '@IMAGENES',
        '   PRECIOOFERTA  ',
        ' PRECIOTACHADO ',
        '@IMAGENES',
        '@IMAGENES',
        'Conca',
        'Conca',
    ];

    /**
     * @param list<string> $safeHeaders
     * @param list<string> $rawHeaders
     * @return list<string>
     */
    public function format(array $safeHeaders, array $rawHeaders): array
    {
        if (count($safeHeaders) !== self::COLUMN_COUNT
            || count($rawHeaders) !== self::COLUMN_COUNT) {
            throw new DomainException(
                'La estructura de catálogo para InDesign debe tener exactamente 15 columnas.',
            );
        }

        return self::HEADERS;
    }
}
