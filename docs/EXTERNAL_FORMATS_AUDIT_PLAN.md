# Diagnóstico de formatos externos de catálogo y promociones

## Alcance

El diagnóstico separa dos flujos externos y es estrictamente *read-only*: no importa filas, no modifica `master_products`, no crea `product_change_logs`, no aprueba staging ni genera una exportación final. Las muestras comerciales permanecen en una carpeta local ignorada por Git.

El comando disponible es:

```shell
php artisan app:audit-external-format-samples \
  --base-path="excel basico/formatos_referencia/2026-08-24-interior" \
  --json
```

`--sample=5` controla la cantidad de ejemplos incluidos. El comando no escribe archivos por defecto. Sólo guarda un JSON cuando se proporciona explícitamente `--output=...`.

Las reglas operativas posteriores al diagnóstico, incluido el formato obligatorio de precios externos, se documentan en [Procesos de archivos externos](EXTERNAL_FILE_PROCESSES.md).

## Flujo de catálogo cuerpo general

La entrada de referencia es `08.Cuerpo Int 24.08.xlsx` y su `workflow_type` es `catalog_body`. El auditor informa hojas, dimensiones, bloques no vacíos, encabezados, columnas, rango y filas útiles del primer cuadro, bloques secundarios, notas/totales, categorías o solapas, columnas de precio y columnas asociadas a imágenes, contenedores o cucardas.

Las salidas esperadas son nueve TXT por categoría o solapa:

- ALMACEN;
- BEBIDAS CON AL;
- BEBIDAS SIN AL;
- DESAYUNO;
- GASTRO;
- IMPORTADOS;
- Limpieza;
- NON FOOD;
- Perfumería.

Para cada TXT se detectan encoding probable, delimitador, encabezado exacto, columnas repetidas, cantidad de columnas y filas, irregularidades, precios, rutas de imagen, rutas `.ai` y ejemplos. Todos los TXT `INT` se infieren como `catalog_body`. El reporte compara además la estructura de los nueve archivos y la correspondencia aproximada entre sus nombres y las hojas/categorías del Excel.

El cuerpo general admite únicamente líneas `product` con un SKU real. `VARIOS` y los códigos compuestos se detectan estructuralmente para poder contarlos, pero reciben `workflow_status = invalid_for_catalog_body`, `requires_review = true` y `exportable_automatically = false`. No pueden convertirse en una línea válida del cuerpo ni continuar automáticamente al exportador.

### Duplicados de sección

El auditor normaliza los nombres de las secciones de `catalog_body` a claves de salida estables. Entre otras equivalencias iniciales, reconoce `Alimentos`/`ALMACÉN INT`, `Bebidas C/Alcohol`/`BEBIDAS CON AL INT`, `Bebidas S/Alcohol`/`BEBIDAS SIN AL INT`, `Importados`/`IMPORTADOS INT`, Gastronomía/GASTRO, Limpieza, Perfumería y Non Food.

Si dos bloques, hojas o solapas resuelven a la misma clave, el reporte agrega `duplicate_catalog_section` con:

- `workflow_type = catalog_body` y la sección normalizada;
- cantidad de bloques y filas por bloque;
- archivo, hoja, rango, etiqueta original y origen de cada bloque;
- `status = requires_review` y `severity = blocked`;
- `exportable_automatically = false`;
- recomendación `manual_selection`.

La regla no elige un bloque ni combina filas. Bloquea solamente la exportación automática de la sección duplicada; las demás secciones conservan su propio estado. Tampoco se activa para `promo_tapa`. El duplicado humano de los dos bloques de Importados en la muestra real es el caso de referencia y no debe considerarse una estructura normal.

La futura UI permitirá elegir explícitamente el bloque válido y mostrará: “Se detectaron 2 bloques para Importados. Elegí cuál usar.” Hasta esa resolución, la sección permanecerá bloqueada.

## Flujo de ofertas, tapas y promociones

La entrada es `Libro3.xlsx` y su `workflow_type` es `promo_tapa`. El primer bloque con encabezados comerciales se considera el cuadro útil; los bloques posteriores se reportan separadamente como cuadros secundarios o información manual. Esto permite excluir notas, totales y columnas auxiliares antes de diseñar una automatización.

La salida es `TAPA AMBA(1).txt`, también inferida como `promo_tapa`. Se audita con las mismas reglas de TXT y se presta especial atención a sus 15 columnas, categoría/grupo vacíos, tres precios, imagen de producto y recursos `.ai` de contenedores o cucardas.

## Clasificación de líneas

### `product`

Un código simple numérico, por ejemplo `30385`, `61267` o `220483`. Es el único tipo que requiere cruce directo contra `master_products.codigo_producto`.

### `composite_code`

Una agrupación completa mediante guiones, por ejemplo `40104 - 40105` o `260161 - 260179`. Sus componentes numéricos se reportan en `component_codes`, pero la línea no se trata como SKU simple ni se busca como una única clave maestra. En `promo_tapa` queda `requires_review`, admite resolución manual y no se exporta automáticamente.

### `incomplete_composite_code`

Un código con guion y algún componente ausente, como `60157 -`. El auditor reporta los componentes detectables, `missing_component = true`, `requires_review = true`, `manual_allowed = false`, `severity = blocked` y la recomendación de corregirlo manualmente. No intenta adivinar el componente faltante.

### `grouped_varios`

`VARIOS` es una detección estructural cuyo tratamiento depende del workflow; no es una excepción global.

- En `catalog_body` no está permitido: se reporta como `grouped_varios` y además como `invalid_for_catalog_body`/`requires_review`; no se exporta automáticamente.
- En `promo_tapa` puede representar una familia o agrupación promocional manual con precio único. No pertenece a `master_products`, no requiere buscar `codigo_producto = VARIOS` y puede exportarse si la plantilla lo permite. El archivo externo manda marca, descripción, UXB, precio e imagen, incluida `.\imagenes\VARIOS.png`.

`composite_code` e `incomplete_composite_code` nunca son SKU simples. En `catalog_body` ambos son inválidos para exportación automática. En `promo_tapa`, un compuesto completo permite decidir manualmente si se conserva, elige un principal, separa, corrige o descarta; uno incompleto permanece bloqueado hasta corregir el archivo. Ninguno genera productos maestros ni provoca una búsqueda por el texto compuesto completo.

### `empty` e `invalid`

Una celda de código vacía se reporta como `empty`. Cualquier valor que no cumpla las reglas anteriores se informa como `invalid` para revisión, sin importarlo ni convertirlo en SKU.

## Autoridad de datos

`master_products` no guarda precios. Para líneas `product`, el maestro manda en marca y descripción homologadas, medida, clasificación base, imagen esperada y demás datos limpios. El archivo externo manda qué productos salen, precios, stock u oferta, orden, solapa de salida, contenedores y cucardas.

El exportador futuro cruzará por `CODIGO`/SKU únicamente las líneas `product`. Los códigos compuestos se resolverán como agrupaciones. `VARIOS` sólo podrá exportarse con los valores comerciales del archivo externo en workflows promocionales que lo permitan, sin crear ni exigir un producto maestro artificial; queda bloqueado en `catalog_body`.

## Fuente externa de precios por código

Las filas que el diagnóstico o un futuro lector XLSX/TXT produzcan pueden entregarse a `ExternalPriceMapBuilder`. Este servicio puro normaliza alias de `CODIGO`/`SKU` y de los tres campos de precio, aplica `ExternalPriceFormatter` y genera la estructura que consume `IndesignTxtExportService`. `master_products` sigue sin almacenar precios.

El resultado incluye `price_map`, `warnings`, `requires_review`, `blocked_count`, `review_count`, `formatted_count`, `empty_price_count`, `duplicate_code_count` e `invalid_code_count`. Cada warning identifica, cuando corresponde, código, número de fila, campo, valor original, issue, severidad y recomendación.

`VARIOS`, `composite_code` e `incomplete_composite_code` no ingresan al mapa automático de productos. Los centavos reales tampoco producen un valor exportable. Para duplicados, precios normalizados idénticos se deduplican; precios diferentes conservan el primer registro y generan `duplicate_price_code` bloqueado. Una UI futura deberá presentar estos diagnósticos antes de habilitar la exportación.

## Lectura read-only de filas externas

`ExternalRowsReader` es la entrada común para TXT y XLSX. No usa modelos ni DB, no escribe reportes y no mueve ni modifica las muestras. Devuelve filas con trazabilidad de archivo, hoja y número original, además de metadata de formato, workflow, encoding, delimitador, columnas, filas y bloques ignorados.

- TXT: detecta el delimitador TAB, soporta Windows-1252 y normaliza encabezados repetidos mediante sufijos seguros sin perder las 15 posiciones.
- XLSX: usa PhpSpreadsheet y lee los primeros cuadros útiles identificados por el auditor.
- `promo_tapa`: `Libro3.xlsx` entrega sólo su tabla principal; los bloques informativos secundarios se cuentan pero no ingresan al resultado automático.
- `catalog_body`: las secciones duplicadas, incluido el caso humano de Importados, se reportan con sus orígenes y permanecen sin resolver.

Las filas `data` pueden pasarse sin adaptadores adicionales a `ExternalPriceMapBuilder`. La cadena prevista es lectura, clasificación, mapa de precios, diagnóstico, vista previa y exportación condicionada a la ausencia de bloqueos.

## Integración con el comando de exportación

`app:export-indesign-txt` acepta `--prices-file=...`. Cuando se proporciona, lee el XLSX/TXT, construye el mapa por código y lo entrega al exportador sin persistirlo. El JSON reporta `price_file`, `price_reader_metadata`, `price_map_count`, `price_warnings`, `price_review_count`, `price_blocked_count` y `price_requires_review`.

Sin la opción continúa `external_pending`, con precios vacíos. Con la opción se informa `external_file`. Un dry-run no se interrumpe por warnings y sirve como diagnóstico; una ejecución real con bloqueos aborta antes de escribir el TXT y devuelve `price_file_has_blocking_warnings`. La futura UI deberá permitir corregir o resolver esos diagnósticos antes de reintentar.

## Comando de diagnóstico por archivo

`app:diagnose-external-export` materializa la etapa read-only previa a la exportación. Recibe `--file`, `--workflow` y `--json`, lee sólo el XLSX/TXT indicado y devuelve `ok`, `review_required` o `blocked`. No crea reportes en disco, no genera el TXT de InDesign y no accede a `master_products`, `product_change_logs` ni otras tablas.

El JSON expone `workflow_type`, archivo, formato, delimitador, encoding, columnas, filas, cantidad de precios mapeados, warnings y los conteos de revisión/bloqueo. El resumen separa productos normales, `VARIOS`, códigos compuestos completos e incompletos, secciones duplicadas y warnings de precio que requieren revisión. Sólo `status = ok` produce `can_export_automatically = true`; la decisión todavía no inicia ninguna exportación.

El comando sirve como contrato para una futura UI de diagnóstico, pero este bloque no presenta ni persiste decisiones. Las secciones duplicadas siguen requiriendo selección manual, los compuestos completos una decisión explícita y un código incompleto como `60157 -` permanece bloqueado hasta corregir el archivo comercial.

El informe mantiene conteos separados por `workflow_type` para `product`, `composite_code`, `incomplete_composite_code`, `grouped_varios`, `invalid_for_catalog_body` y `requires_review`. Los tipos de línea describen qué se detectó; los estados expresan la decisión contextual, por lo que un código compuesto de cuerpo puede incrementar tanto su tipo como `invalid_for_catalog_body` y `requires_review`.

## Muestras locales

Las muestras reales deben residir en:

```text
excel basico/formatos_referencia/2026-08-24-interior/
```

La carpeta y sus XLSX/TXT no se agregan al repositorio, no se copian a `storage/public` y no se modifican durante la auditoría. Un reporte `partial` indica que faltan una o más de las doce muestras esperadas; no es una autorización para sustituirlas con archivos de negocio distintos.
