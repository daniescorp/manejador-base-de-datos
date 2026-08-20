# Plan de auditoría e importación del Excel de productos

La última base Excel de productos se considera la base semilla del proceso. Es un insumo operativo local: el archivo y la carpeta `excel basico/` no se guardan en el repositorio.

El flujo previsto es:

```text
Excel → auditoría → staging → sugerencias → preview → aprobación futura → master_products
```

La auditoría implementada en esta etapa solo lee el Excel y genera un reporte. No importa filas, no escribe en `product_staging_rows`, no modifica `master_products` y no crea `product_change_logs`.

## Mapping hacia staging

| Columna Excel | Campo en `product_staging_rows` |
| --- | --- |
| `Sku` | `codigo_producto_original` |
| `Nombre Sku` | `nombre_sku_original` |
| `UXB` | `uxb_original` |
| `Ean` | `ean_original` |
| `Categoria` | `categoria_original` |
| `Grupo` | `grupo_original` |
| `Familia` | `familia_original` |
| `Marca` | `marca_original` |
| Fila completa | `raw_data` |

## Criterios de calidad

- Los SKU duplicados no bloquean una futura importación a staging. Se marcan para revisión porque pueden representar cargas incompletas, EAN mal cargados o filas duplicadas no depuradas.
- EAN no es clave principal. Se auditan vacíos, valores `1` y `2`, longitudes distintas de 8, 12, 13 o 14 dígitos, caracteres sospechosos y duplicados.
- `codigo_producto` no debe ser `unique` todavía, hasta resolver los duplicados y definir la identidad definitiva del producto.
- UXB vacío, no numérico o cero se informa para revisión; no altera datos durante la auditoría.
- Categoría, grupo, familia y marca con valor cero o vacío se contabilizan sin bloquear la auditoría estructural.
- Marcas como `ARLISTAN` y `MANON` deben conservarse en `marca_original` y tratarse mediante `marca_homologada` en el flujo de sugerencias y preview.
- `MX` → `MAX` permanece como una regla contextual de descripción y no como una corrección totalmente automática.

El comando de auditoría es:

```shell
php artisan app:audit-product-excel "excel basico/SKU Daniel 8mil final.xlsx"
php artisan app:audit-product-excel "excel basico/SKU Daniel 8mil final.xlsx" --json
```
