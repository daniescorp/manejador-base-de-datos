# Arquitectura de staging, normalización y productos maestros v2

Este documento describe la arquitectura técnica y su evolución incremental. Las etapas ya implementadas se identifican expresamente; las restantes no autorizan por sí solas migraciones, recursos Filament, importadores ni cambios de base de datos.

El sistema necesita una arquitectura por capas:

1. Archivos importados.
2. Filas crudas o *staging*.
3. Reglas de normalización.
4. Sugerencias de normalización.
5. Previsualización.
6. Aprobación.
7. Productos maestros v2.
8. Historial de cambios.
9. Exportaciones por destino.

Los archivos Excel son insumos externos y la base maestra no debe depender de ellos. El dato original se conserva para trazabilidad, pero el dato homologado vive en el sistema.

## Gobierno del dato y responsabilidad de cambios

Los cambios reales sobre la base de datos serán realizados por el sistema Laravel, no por Codex ni por la IA directamente. Codex sirve para construir el sistema, la IA podrá sugerir, el usuario o administrador aprueba, Laravel ejecuta y MySQL registra.

El dato original nunca se destruye. El dato homologado se genera mediante reglas aprobadas y todo cambio debe ser trazable.

En la primera etapa, el sistema será multiusuario simple: varios administradores podrán operarlo y todos tendrán el mismo nivel de acceso. La trazabilidad se mantendrá por usuario para identificar quién realizó cada acción, mientras que los roles y permisos avanzados quedarán para una etapa futura.

### Codex

- Programa o documenta según las instrucciones recibidas.
- No tiene autoridad funcional sobre los datos reales.
- No debe ejecutar correcciones sobre la base de productos sin que exista un módulo aprobado para ese fin y una instrucción explícita para utilizarlo.

### Sistema Laravel

- Es quien ejecuta las reglas aprobadas.
- Aplica correcciones en campos homologados o de salida.
- No destruye ni sobrescribe los campos originales.
- Debe registrar cada cambio aplicado en `product_change_logs`.

### Usuario / administrador

- Es la autoridad final sobre la base maestra.
- Aprueba reglas por lote después de revisar la previsualización.
- Corrige productos individuales.
- Decide si una sugerencia se acepta o se rechaza.

### IA futura

- Solo sugiere correcciones o prioridades.
- No aplica cambios directamente.
- No modifica productos maestros sin aprobación.
- Si la confianza es baja, marca el producto como `requiere_revision`.

### MySQL

- Conserva el dato original.
- Conserva el dato homologado y aprobado.
- Conserva las sugerencias.
- Conserva el historial de cambios.

## Correcciones ortográficas y normalizaciones

Las correcciones de acentos, abreviaturas, unidades y formato de medidas deben pasar por reglas registradas y aprobadas.

Ejemplos:

- `LIMON` → `LIMÓN`;
- `CAFE` → `CAFÉ`;
- `MAIZ` → `MAÍZ`;
- `HIGIENICO` → `HIGIÉNICO`;
- `750 CC` → `750CC`;
- `D/P` → `DOYPACK`;
- `CAFE/COGN` → `café al cognac`.

Flujo de corrección:

1. El sistema detecta los productos afectados.
2. Genera sugerencias.
3. Muestra una previsualización.
4. El usuario aprueba o rechaza.
5. El sistema aplica la corrección sobre campos homologados o de salida.
6. El dato original queda intacto.
7. El sistema registra el historial.

### Servicio inicial de análisis

Ya existe un servicio inicial que analiza filas de *staging* y genera `normalization_suggestions` desde reglas activas de `normalization_rules`. En esta etapa solo crea propuestas revisables: no modifica productos maestros, no aplica correcciones y no registra cambios como aprobados.

### Diccionario de Normalización

El panel Filament permite crear, consultar, editar, activar y desactivar reglas del Diccionario de Normalización para `descripcion_catalogo` y `marca_homologada`. No ofrece borrado ni borrado masivo, conserva la autoría de creación y actualización, y permite registrar reglas como `MX` → `MAX`, `ARLISTAN` → `Arlistán` o `MANON` → `Manón` con previsualización y revisión.

`ProductStagingAnalyzer` analiza `descripcion_catalogo` a partir de `nombre_sku_original` y también puede generar sugerencias para `marca_homologada` a partir de `marca_original`. Las marcas se cotejan por igualdad exacta, sin distinguir mayúsculas y minúsculas y descartando solo los espacios de los extremos; no se aplican por coincidencia parcial. `marca_original` siempre se conserva intacta y casos como `ARLISTAN` → `Arlistán` o `MANON` → `Manón` se tratan como homologaciones de marca, no como palabras comunes de la descripción.

Las reglas de marca pueden agregar condiciones sobre el nombre original. El caso `ELEGIDO` → `NORTON` usa `context = nombre_sku_contains:NORTON`: solo se activa si `NORTON` está presente como token completo. En `VINO NORTON ELEGIDO CHARDONNAY`, el preview homologa la marca como `NORTON`, remueve ese token de `descripcion_catalogo` y conserva `elegido` como línea comercial. La regla no transforma globalmente todas las marcas `ELEGIDO`. La presentación futura `750CC` se completará manualmente después de aprobar el producto y no se infiere durante este análisis.

### Servicio inicial de composición de previsualización

El sistema cuenta con un servicio que combina las sugerencias aplicables en `product_staging_rows.normalized_preview` sin aprobarlas ni modificar productos maestros. La previsualización mantiene la estructura existente de `descripcion_catalogo` e incorpora `source_brand`, `marca_homologada` y los identificadores de sugerencias de marca separados entre aplicados, pendientes de revisión y bloqueados.

La aprobación definitiva inicial se ejecuta producto por producto desde el detalle de staging. Usa una transacción para crear o actualizar `master_products`, escribir `product_change_logs` y marcar la fila como aprobada; un fallo revierte las tres operaciones. Las filas con `requires_review`, sin código o sin descripción normalizada quedan bloqueadas, y las sugerencias permanecen pendientes.

Los productos aprobados se consultan en **Productos Maestros**, cuya vista de detalle organiza identificación, descripciones, marca, clasificación, medidas, UXB, control y data JSON. El historial asociado muestra valores anteriores y nuevos, origen, motivo, usuario, regla y lote; tanto el producto como sus logs son read-only en esta etapa.

Ejemplo:

```text
Original: ALFAJOR LIMON 50 GR
Sugerido: Alfajor limón 50GR
```

Si se aprueba, `nombre_original` queda sin cambios, `descripcion_catalogo` se actualiza con `Alfajor limón 50GR` y `product_change_logs` registra el valor anterior, el valor nuevo, la regla aplicada, el usuario que aprobó, la fecha y el origen del cambio.

## Campos afectados por correcciones

Las correcciones nunca deben sobrescribir los campos originales.

### Campos originales

- `nombre_sku_original`;
- `marca_original`;
- `uxb_original`;
- `ean_original`;
- `categoria_original`;
- `grupo_original`;
- `familia_original`;
- `raw_data`.

### Campos homologados o de salida

- `nombre_homologado`;
- `nombre_sin_marca`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- `descripcion_app`;
- `marca_homologada`;
- `categoria_homologada`;
- `grupo_homologado`;
- `familia_homologada`;
- `medida_catalogo`.

## Reglas de aprobación

- Las reglas automáticas pueden generar sugerencias masivas.
- Toda aplicación masiva requiere previsualización y aprobación.
- Las reglas sensibles quedan como `requiere_revision`.
- Los cambios individuales se realizan producto por producto.
- Todo cambio aprobado debe registrar usuario, fecha y motivo.

Son reglas o casos sensibles, entre otros:

- `RELL.`;
- `Marca = 0`;
- rangos con `/`;
- marcas ambiguas;
- productos con `UXB = 0`.

## Flujo general

1. Se importa un archivo Excel, CSV o TXT.
2. El sistema registra el archivo dentro de un lote de importación, en almacenamiento operativo externo al repositorio.
3. Cada fila se guarda en *staging*.
4. Se conservan todos los valores originales.
5. Se detectan problemas como:
   - `codigo_producto` duplicado;
   - `UXB = 0`;
   - EAN inválido;
   - `Marca = 0`;
   - clasificación incompleta;
   - abreviaturas;
   - medidas;
   - marca duplicada dentro de `Nombre Sku`.
6. Las reglas se aplican únicamente como sugerencia o previsualización.
7. El usuario aprueba por lote o individualmente.
8. Se genera o actualiza el producto maestro.
9. Se registra el historial de cambios.
10. Se generan salidas según el destino.

## Tabla futura: product_staging_rows

`product_staging_rows` será la zona inicial de trabajo y análisis de cada fila importada.

### Campos sugeridos

- `id`;
- `import_batch_id`;
- `import_file_id`;
- `import_row_id` nullable;
- `master_product_id` nullable;
- `row_number`;
- `codigo_producto_original`;
- `nombre_sku_original`;
- `uxb_original`;
- `ean_original`;
- `categoria_original`;
- `grupo_original`;
- `familia_original`;
- `marca_original`;
- `raw_data`;
- `detected_data`;
- `normalized_preview`;
- `status`;
- `requires_review`;
- `review_reason`;
- `row_hash`;
- `created_at`;
- `updated_at`.

### Estados sugeridos

- `pending`;
- `analyzed`;
- `suggested`;
- `previewed`;
- `approved`;
- `rejected`;
- `imported_to_master`;
- `requires_review`;
- `excluded`.

Esta tabla no es la base maestra. Es una zona de *staging* y análisis en la que se preservan los valores recibidos antes de aprobar su incorporación.

Los campos `import_batch_id` e `import_file_id` referencian las tablas existentes `import_batches` e `import_files`, que representan respectivamente el lote y la metadata del archivo importado. El archivo administrado debe permanecer en almacenamiento operativo externo al repositorio.

## Evolución de master_products (v2)

“Productos maestros v2” será la evolución lógica de la tabla física existente `master_products`, que continuará como única base maestra recomendada. No se creará por defecto una tabla física `master_products_v2`; solo se reconsiderará si aparece una razón técnica fuerte durante el diseño de migraciones. La evolución deberá separar los datos originales, los homologados y los destinados a cada salida.

La primera ampliación estructural v2 ya incorporó campos mínimos originales, homologados, de salida y control sobre `master_products`, conservando los campos heredados. No se realizó *backfill*, no se duplicó la tabla y `codigo_producto` continúa sin unicidad definitiva.

### Identificación

- `id`;
- `codigo_producto`;
- `codigo_original`;
- `sku_original`;
- `barcode`;
- `ean_original`;
- `ean_validado`;
- `status`.

`codigo_producto` es la clave operativa homologada; `codigo_original` conserva el valor recibido. `sku_original` mantiene la procedencia y denominación de la columna fuente cuando sea necesario, sin asumir que representa una clave comercial distinta. `barcode` representa el código de barras normalizado, mientras `ean_original` y `ean_validado` conservan respectivamente el valor recibido y el valor aprobado.

### Nombre y descripción

- `nombre_original`;
- `nombre_sin_marca`;
- `nombre_homologado`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- `descripcion_app`;
- `descripcion_interna`.

Los campos `nombre_*` representan el nombre base y sus transformaciones, mientras las descripciones y títulos de salida se generan para destinos específicos. No reemplazan ni destruyen el valor original.

### Marca

- `marca_original`;
- `marca_homologada`;
- `marca_detectada_en_nombre`;
- `marca_inferida`;
- `requiere_revision_marca`;
- `nivel_confianza_marca`.

### Clasificación

- `categoria_original`;
- `categoria_homologada`;
- `grupo_original`;
- `grupo_homologado`;
- `familia_original`;
- `familia_homologada`.

### Medidas

- `medida_original`;
- `contenido_valor`;
- `unidad_original`;
- `unidad_normalizada`;
- `medida_normalizada`;
- `cantidad_unidades`;
- `medida_valor`;
- `medida_catalogo`;
- `medida_requiere_revision`.

Ejemplos:

```text
750 CC
→ contenido_valor = 750
→ unidad_normalizada = CC
→ medida_catalogo = 750CC

30Mx4 Un
→ cantidad_unidades = 4
→ medida_valor = 30
→ unidad_normalizada = MT
→ medida_catalogo = 4x30MT
```

### UXB

- `uxb_original`;
- `uxb_validado`;
- `uxb_requiere_revision`.

Los productos con `UXB = 0` no ingresan como productos activos. Deben conservarse con el estado `pendiente_uxb`.

`uxb_validado` representa el valor numérico corregido o aprobado; `uxb_requiere_revision` indica si ese valor todavía necesita control.

### Control

- `estado_homologacion`;
- `requiere_revision`;
- `observaciones`;
- `last_import_batch_id`;
- `approved_by_id`;
- `approved_at`;
- `created_at`;
- `updated_at`;
- `deleted_at`.

### Estados sugeridos

- `pendiente_revision`;
- `sugerido_por_sistema`;
- `requiere_correccion`;
- `homologado`;
- `aprobado_catalogo`;
- `pendiente_uxb`;
- `sin_imagen`;
- `sin_precio`;
- `inactivo`;
- `excluido_temporal`;
- `no_exportable`.

La implementación definitiva deberá separar el estado del flujo de homologación de las condiciones de disponibilidad o exportabilidad. Del mismo modo, `requiere_revision` funciona como indicador, mientras los valores como `pendiente_revision` o `requiere_correccion` describen estados del flujo.

## Tabla futura: normalization_rules

`normalization_rules` guardará reglas reutilizables y sus condiciones de aplicación.

### Campos sugeridos

- `id`;
- `rule_name`;
- `detected_value`;
- `replacement_value`;
- `rule_type`;
- `applies_to_field`;
- `context`;
- `priority`;
- `is_automatic`;
- `requires_preview`;
- `requires_review`;
- `confidence_level`;
- `active`;
- `notes`;
- `created_by_id`;
- `updated_by_id`;
- `created_at`;
- `updated_at`.

### Tipos de regla sugeridos

- `abbreviation`;
- `slash_abbreviation`;
- `dotted_abbreviation`;
- `flavor_variant`;
- `measurement`;
- `brand_removal`;
- `capitalization`;
- `accent`;
- `spacing`;
- `packaging`;
- `exclusion`;
- `manual_review`.

### Ejemplos

- `D/P` → `DOYPACK`;
- `CAFE/COGN` → `café al cognac`;
- `P.HIGIENICO` → `Papel Higiénico`;
- `CHAMP.` → `Espumantes`;
- `750 CC` → `750CC` para `descripcion_catalogo`;
- `RELL.` → `requiere_revision`.

`RELL.` no debe configurarse como regla automática. Debe usar el tipo `manual_review` y mantener `requires_review` activo.

`is_automatic` solo podrá indicar detección o generación automática de una sugerencia. Nunca habilitará escritura autónoma sobre *staging* aprobado ni sobre productos maestros.

## Tabla futura: normalization_suggestions

`normalization_suggestions` representa las propuestas pendientes de revisión antes de aplicar cualquier cambio.

### Campos sugeridos

- `id`;
- `product_staging_row_id`;
- `master_product_id`;
- `normalization_rule_id`;
- `field_name`;
- `original_value`;
- `suggested_value`;
- `suggestion_reason`;
- `confidence_level`;
- `status`;
- `reviewed_by_id`;
- `reviewed_at`;
- `applied_at`;
- `created_at`;
- `updated_at`.

### Estados sugeridos

- `pending`;
- `approved`;
- `rejected`;
- `applied`;
- `ignored`;
- `requires_review`.

Nada masivo debe aplicarse sin previsualización y aprobación.

## Tabla futura: product_change_logs

`product_change_logs` garantizará el historial y la auditoría de las modificaciones aprobadas.

### Campos sugeridos

- `id`;
- `master_product_id`;
- `changed_by_id`;
- `source`;
- `field_name`;
- `old_value`;
- `new_value`;
- `change_reason`;
- `normalization_rule_id`;
- `import_batch_id`;
- `created_at`.

### Fuentes sugeridas

- `manual`;
- `batch_approval`;
- `import`;
- `system_rule`;
- `ai_suggestion`.

La fuente `ai_suggestion` identifica un cambio originado en una sugerencia de IA que fue previsualizada y aprobada por un usuario; no representa una modificación autónoma.

## Previsualización por lote

Antes de aplicar una regla por lote, el sistema debe mostrar:

- cantidad de productos afectados;
- campo afectado;
- valor original;
- valor sugerido;
- regla aplicada;
- nivel de confianza;
- productos que requieren revisión;
- productos excluidos.

Ejemplo:

```text
Regla: D/P → DOYPACK

Previsualización:
- 168 productos afectados
- 160 aplicables
- 8 requieren revisión
```

## Reglas de seguridad

- Nunca destruir el dato original.
- No aplicar cambios masivos sin previsualización.
- No modificar productos con `Marca = 0` sin revisión.
- No activar productos con `UXB = 0`.
- No usar EAN como clave principal.
- No modificar rangos con `/` automáticamente.
- No corregir `RELL.` automáticamente.
- No aplicar reglas de papel fuera del contexto de papel higiénico o productos de papel.
- No remover una marca si la confianza es baja.
- Registrar todo cambio aplicado.

## Relación con salidas

La exportación para InDesign parte únicamente de `master_products` con estado activo, homologación `aprobado_catalogo`, sin revisión pendiente y con marca y descripción completas. El comando `app:export-indesign-txt` genera en `storage/app/exports` el formato tabulado TAPA AMBA de 15 columnas, con ruta de imagen `.\imagenes\{CODIGO}.png`; no crea `ExportJob` ni modifica datos. El anterior formato simple `MARCA;Descripción catálogo` queda descartado.

`master_products` **no guarda precios**. Los campos `PRECIOLISTA`, `PRECIOOFERTA` y `PRECIOTACHADO` de TAPA AMBA salen vacíos en esta etapa. En el flujo futuro, el usuario aportará un archivo administrativo de pedido, promoción o lista; el sistema leerá códigos SKU y precios, cruzará `CODIGO`/SKU contra productos maestros aprobados, combinará marca/descripción/UXB/imagen del maestro con precios de esa fuente y reportará códigos faltantes antes de exportar. Los casos `VARIOS`, combos, orden de salida y variantes AMBA/Interior quedan para iteraciones posteriores.

La medida/presentación estable sí pertenece a `master_products`. Una aprobación sin `medida_catalogo` marca `medida_requiere_revision`; la vista de detalle permite completarla manualmente o registrar en `data.measurement` una excepción no aplicable con motivo, usuario, fecha y logs. La exportación omite los productos aprobados sin medida ni excepción y devuelve `skipped_missing_measure` con sus códigos; las excepciones válidas se exportan y se informan mediante `exported_measure_exceptions`. El caso controlado `30385` queda documentado con presentación `240GR`.

La evolución v2 de `master_products` deberá alimentar:

- InDesign;
- Shopify;
- aplicación móvil;
- Excel interno;
- TXT;
- CSV;
- XLSX;
- JSON/API;
- XML;
- otros destinos futuros.

Cada salida se genera desde datos estructurados y plantillas de exportación específicas por destino.

## Orden técnico recomendado

1. Crear la documentación final y el commit correspondiente.
2. Diseñar las migraciones de *staging*.
3. Crear `product_staging_rows`.
4. Crear `normalization_rules`.
5. Crear `normalization_suggestions`.
6. Crear `product_change_logs`.
7. Continuar la evolución funcional de `master_products`; reconsiderar una tabla nueva solo ante una razón técnica fuerte.
8. Crear recursos Filament para revisar *staging*.
9. Ampliar el Diccionario de Normalización y su integración con nuevos campos cuando corresponda.
10. Crear la previsualización por lote.
11. Crear la aprobación por lote.
12. Crear plantillas de exportación por destino.
13. Incorporar IA cuando el flujo base esté estable.

## Decisiones físicas relacionadas

Ver también [Estrategia física de base de datos](DATABASE_PHYSICAL_DESIGN_PLAN.md).

- *Staging* se vincula con lotes, archivos, filas crudas y productos maestros.
- Las sugerencias se registran por campo y por regla.
- `validation_errors` informa problemas y `normalization_suggestions` propone soluciones.
- `master_products` evoluciona sin duplicarse como una tabla física `master_products_v2`.
- `export_jobs` podrá vincularse con `export_templates` mediante `export_template_id` nullable.
- Los campos comerciales usarán nombres en español y los nombres técnicos en inglés se conservarán cuando sean convenciones o compatibilidad existente.
