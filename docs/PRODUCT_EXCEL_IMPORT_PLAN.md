# Plan de auditoría e importación del Excel de productos

La última base Excel de productos se considera la base semilla del proceso. Es un insumo operativo local: el archivo y la carpeta `excel basico/` no se guardan en el repositorio.

El flujo previsto es:

```text
Excel → auditoría → staging → sugerencias → preview → aprobación futura → master_products
```

La auditoría implementada en esta etapa solo lee el Excel y genera un reporte. No importa filas, no escribe en `product_staging_rows`, no modifica `master_products` y no crea `product_change_logs`.

Ya existe también un importador seguro desde Excel hacia `product_staging_rows`. Debe ejecutarse primero con `--dry-run`; la importación real conserva sin transformar los datos originales y marca los problemas operativos mediante `requires_review`. Los SKU duplicados no bloquean staging, `master_products` permanece intacta y no se generan `product_change_logs`. Una vez cargado staging, el flujo puede continuar con `ProductStagingAnalyzer` y `ProductStagingPreviewComposer`.

Antes de la revisión o aprobación, el compositor limpia espacios múltiples, tabs, saltos internos y extremos únicamente en los previews normalizados. `nombre_sku_original`, `marca_original` y `raw_data` mantienen exactamente el contenido importado desde Excel.

Como paso final del preview, `descripcion_catalogo` elimina la marca cuando coincide como token o frase completa con `marca_homologada` o `marca_original`. La marca se conserva separada para las salidas de InDesign, Shopify y la app; esta limpieza no altera los datos originales ni implica aprobación.

Filament incluye el módulo de solo lectura **Revisión de Productos Importados** para consultar filas de staging, datos originales, previews normalizados y sugerencias asociadas. La UI permite buscar, filtrar y revisar, pero no crea, edita, elimina, aprueba ni aplica registros. `master_products` permanece protegido hasta una etapa posterior de aprobación real; recién entonces podrán generarse `product_change_logs`.

La UI también vincula imágenes PNG por `codigo_producto_original`: busca `{codigo}.png` en la carpeta externa configurada mediante `PRODUCT_IMAGES_BASE_PATH` y muestra una miniatura o el estado correspondiente. Laravel sirve cada imagen al panel mediante una ruta autenticada, sin usar enlaces `file://`, sin guardar binarios o rutas en MySQL y sin copiar imágenes al repositorio. La referencia operativa prevista es `\\10.179.50.14\Dgrafico\BANCO DE IMAGENES CENTRAL`; debe configurarse únicamente en el entorno de ejecución. El comando read-only `app:audit-product-images` permite auditar cobertura por batch y límite.

Las miniaturas son únicamente representaciones visuales de la imagen original: no se generan archivos thumbnail físicos. El listado limita la representación a `80x64px` y el detalle a `260x260px` mediante estilos inline, con `object-fit: contain`; el enlace autenticado permite abrir la imagen completa en otra pestaña.

Cuando todas las filas ya existen por `row_hash`, una segunda importación devuelve `already_imported` y no crea `ImportBatch` ni `ImportFile` vacíos. El comando `app:process-product-staging-rows` permite ejecutar analyzer y preview composer sobre staging sin tocar `master_products`; se recomienda comenzar con `--dry-run` y continuar con un `--limit` pequeño antes de procesar el batch completo.

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
- Las unidades se comparan como tokens completos asociados a un número. Variantes como `500 Grs`, `1 KGS`, `750 CC.` y `1 LTS` producen previews `500GR`, `1KG`, `750CC` y `1LT`, sin sufijos parciales; `MX` dentro de `80MX4UN` no se interpreta como abreviatura.

El comando de auditoría es:

```shell
php artisan app:audit-product-excel "excel basico/SKU Daniel 8mil final.xlsx"
php artisan app:audit-product-excel "excel basico/SKU Daniel 8mil final.xlsx" --json
php artisan app:import-product-excel-to-staging "excel basico/SKU Daniel 8mil final.xlsx" --dry-run
php artisan app:import-product-excel-to-staging "excel basico/SKU Daniel 8mil final.xlsx"
php artisan app:process-product-staging-rows --batch-id=263 --limit=50 --dry-run
php artisan app:process-product-staging-rows --batch-id=263 --limit=50 --only=all
```
