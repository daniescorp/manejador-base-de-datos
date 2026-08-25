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

`ExternalPriceFormatter` es un servicio puro: no consulta ni modifica la base y no conoce `master_products` ni `product_change_logs`. `IndesignTxtExportService` ya consume su resultado para `PRECIOLISTA`, `PRECIOOFERTA` y `PRECIOTACHADO`: acepta un mapa externo opcional por `CODIGO`, deja vacíos los precios ausentes y devuelve warnings estructurados para cualquier precio con `requires_review = true`. Un precio en revisión no produce valor exportable en su columna.

Mientras no exista un importador que entregue ese mapa externo, el comando conserva `prices_source = external_pending` y genera las tres columnas vacías. Cuando se proporcionan precios al servicio, informa `prices_source = external_provided`; los precios nunca se leen ni persisten en `master_products`.

### Construcción del mapa externo de precios

`ExternalPriceMapBuilder` recibe filas ya parseadas y construye un mapa estable por `CODIGO`, compatible con `IndesignTxtExportService`. Reconoce `CODIGO`/`Código`/`codigo` y `SKU` en distintas capitalizaciones, además de `PRECIOLISTA`, `PRECIOOFERTA`, `PRECIOTACHADO` y sus variantes con espacios o guion bajo. Las columnas comerciales auxiliares se ignoran.

El builder usa `ExternalPriceFormatter` para los tres precios y devuelve `price_map`, warnings estructurados y contadores de valores formateados, vacíos, códigos inválidos, duplicados, revisiones y bloqueos. No lee archivos directamente, no consulta la base y no persiste el resultado. Un precio con centavos reales queda vacío en el mapa y genera `price_requires_review`.

Sólo los códigos numéricos simples ingresan al mapa normal. `VARIOS`, los compuestos completos y los incompletos se reportan respectivamente como `grouped_varios_not_mapped`, `composite_code_not_mapped` e `incomplete_composite_code`. Un código ausente o inválido tampoco se agrega.

Si un código se repite con los mismos precios ya normalizados, se conserva una sola entrada. Si sus precios difieren, se mantiene el primer registro para preservar el orden y se emite `duplicate_price_code` con severidad `blocked`; nunca se elige automáticamente entre ambos. La futura UI deberá mostrar todos los warnings y exigir la resolución de revisiones/bloqueos antes de exportar.

## Lector externo read-only

`ExternalRowsReader` convierte archivos TXT tabulados y XLSX en filas asociativas sin persistir datos ni modificar el archivo recibido. Cada fila conserva número, archivo, hoja, `workflow_type` y un bloque `data`; `rowsForPriceMap()` entrega directamente esos datos a `ExternalPriceMapBuilder`.

Para TXT detecta TAB, convierte Windows-1252 a UTF-8 y conserva las 15 columnas. Los encabezados comerciales relevantes se normalizan a `CODIGO`, `PRECIOLISTA`, `PRECIOOFERTA` y `PRECIOTACHADO`. Encabezados auxiliares repetidos reciben sufijos estables, por ejemplo `@folder_2`, `@IMAGENES_3` o `Conca_2`, sin perder columnas.

Para XLSX utiliza PhpSpreadsheet y la detección de bloques del auditor. Sólo lee la tabla principal de cada hoja: en `Libro3.xlsx` el bloque secundario informativo queda fuera de las filas automáticas. En `catalog_body`, las secciones duplicadas se devuelven como warnings bloqueados; el lector no elige ni mezcla los bloques de Importados.

El flujo futuro es: recibir archivo, leer filas, clasificar códigos/secciones, construir `price_map`, diagnosticar warnings, previsualizar y exportar solamente cuando no existan bloqueos.

## Relación con workflows y líneas especiales

- En `catalog_body`, cada línea exportable debe ser `product`; `VARIOS`, `composite_code` e `incomplete_composite_code` requieren revisión, reciben `invalid_for_catalog_body` y no se exportan automáticamente.
- En `promo_tapa`, `promo_diaria` y ofertas varias, `VARIOS` puede ser una agrupación comercial válida si la plantilla lo permite. No se busca como producto maestro.
- El formateo de precio es idéntico en todos los workflows; la validez de la línea continúa dependiendo de las reglas de su workflow.

### Códigos compuestos en promociones

Un compuesto completo de `promo_tapa`, como `40104 - 40105` o `260161 - 260179`, se conserva como una línea comercial revisable y nunca como SKU simple. El auditor informa `line_type = composite_code`, sus `component_codes`, `requires_review = true`, `manual_allowed = true` y `exportable_automatically = false`. No busca el texto completo en `master_products.codigo_producto`, no crea un producto maestro artificial, no mezcla los componentes ni elige uno automáticamente.

La futura UI deberá permitir mantener la línea comercial tal cual, elegir un producto principal, separarla en varias líneas, corregir el código o descartarla. Hasta que exista una decisión explícita, el compuesto completo no continúa al exportador automático.

Un valor como `60157 -` es distinto: falta un componente y se clasifica como `incomplete_composite_code`. Aunque se reporten los dígitos parseables, recibe `missing_component = true`, `manual_allowed = false`, `severity = blocked` y recomendación `correct_code_manually`. No se completa, corrige ni exporta por inferencia; primero debe corregirse el archivo recibido.

## Secciones duplicadas en el cuerpo de catálogo

En `catalog_body`, cada categoría debe apuntar a una única sección de salida. Si dos hojas, solapas o bloques terminan en la misma clave normalizada de exportación, el auditor informa `duplicate_catalog_section`. Son equivalentes, por ejemplo, `Importados`, `IMPORTADOS INT` e `Importados Interior`; también se contemplan alias conocidos como `Alimentos`/`ALMACÉN INT` y `Bebidas C/Alcohol`/`BEBIDAS CON AL INT`.

Un duplicado es un error estructural del archivo recibido, distinto de un SKU repetido, `VARIOS`, un código compuesto o una promoción agrupada. La detección registra el archivo, hoja, rango y cantidad de filas de cada origen, y aplica estas decisiones exclusivamente a la sección afectada:

- estado `requires_review` y severidad `blocked`;
- no elegir automáticamente un bloque;
- no mezclar sus filas;
- no exportar automáticamente esa categoría;
- permitir que las demás secciones válidas continúen procesándose.

El caso real que motivó la regla fue una duplicación humana de Importados en el libro de cuerpo general. La futura UI deberá presentar un mensaje como “Se detectaron 2 bloques para Importados. Elegí cuál usar.” y mantener bloqueada esa salida hasta que una persona seleccione el origen válido. Esta regla no se aplica como bloqueo de catálogo a `promo_tapa`.
