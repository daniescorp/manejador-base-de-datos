# Procesos de archivos externos

## Autoridad de datos

`master_products` no guarda precios. El maestro aporta marca y descripción homologadas, medida, clasificación base, UXB, imagen esperada y demás datos limpios. Los archivos externos determinan qué productos participan en cada salida y aportan precios, orden, stock/oferta, solapas, contenedores y cucardas.

Esta separación aplica a los workflows `catalog_body`, `promo_tapa`, `promo_diaria` y ofertas varias. El cruce con el maestro se realiza por `CODIGO`/SKU únicamente cuando la línea representa un producto individual.

## Formato de precios para exportación

Los campos externos `PRECIOLISTA`, `PRECIOOFERTA` y `PRECIOTACHADO` se normalizan solamente al construir una exportación. El formato final obligatorio usa signo `$`, un espacio, miles separados con punto y ningún decimal:

```text
$ 3.699
```

Entradas equivalentes como `3699`, `3.699`, `$3.699`, `$ 3.699`, `3699,00` y `3.699,00` producen `$ 3.699`. Un precio vacío o `null` permanece vacío.

Los centavos reales nunca se redondean silenciosamente. Por ejemplo, `1699,50` devuelve un resultado `requires_review`, sin valor formateado exportable y con un warning explícito. Un valor inválido recibe el mismo tratamiento seguro.

`ExternalPriceFormatter` es un servicio puro: no consulta ni modifica la base y no conoce `master_products` ni `product_change_logs`. La integración con importadores o exportadores futuros debe consumir su resultado estructurado y bloquear o reportar cualquier precio con `requires_review = true`.

## Relación con workflows y líneas especiales

- En `catalog_body`, cada línea exportable debe ser `product`; `VARIOS` y códigos compuestos requieren revisión y no se exportan automáticamente.
- En `promo_tapa`, `promo_diaria` y ofertas varias, `VARIOS` puede ser una agrupación comercial válida si la plantilla lo permite. No se busca como producto maestro.
- El formateo de precio es idéntico en todos los workflows; la validez de la línea continúa dependiendo de las reglas de su workflow.
