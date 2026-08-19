# Estrategia física de base de datos

Este documento define cómo llevar la arquitectura lógica a tablas reales de MySQL mediante etapas incrementales. Algunas etapas iniciales ya cuentan con implementación; las restantes conservan el orden y las condiciones de seguridad aquí documentadas.

## Decisión sobre master_products_v2

`master_products_v2` será un concepto lógico de evolución y no, por defecto, una tabla física nueva. La tabla física recomendada para la base maestra final seguirá siendo:

```text
master_products
```

Motivos:

- ya existe en el sistema;
- debe mantenerse como única fuente de verdad de los productos homologados;
- evita duplicar productos entre `master_products` y una eventual `master_products_v2`;
- permite evolucionar el esquema mediante migraciones incrementales;
- mantiene *staging* y sugerencias en tablas separadas de la base maestra final.

Cuando la documentación mencione `master_products_v2` o “productos maestros v2”, debe entenderse como la evolución futura del modelo y de la tabla `master_products`. Solo se reconsiderará una tabla física distinta si durante el diseño de migraciones aparece una razón técnica fuerte y documentada.

## Tablas principales futuras

| N.º | Tabla | Situación | Responsabilidad |
| --- | --- | --- | --- |
| 1 | `import_batches` | Ya existe | Representa un lote de importación. |
| 2 | `import_files` | Ya existe | Representa cada archivo importado y su metadata. |
| 3 | `import_rows` | Ya existe | Puede conservarse como registro crudo general de filas importadas. |
| 4 | `product_staging_rows` | Nueva tabla futura | Zona de análisis específica para productos; guarda columnas originales y no es la base maestra. |
| 5 | `master_products` | Existe y debe evolucionar | Tabla maestra final con campos originales, homologados y de salida. |
| 6 | `normalization_rules` | Nueva tabla futura | Guarda reglas reutilizables. |
| 7 | `normalization_suggestions` | Nueva tabla futura | Guarda sugerencias antes de su aprobación. |
| 8 | `product_change_logs` | Nueva tabla futura | Mantiene la auditoría de cambios. |
| 9 | `export_templates` | Nueva tabla futura | Define destino, formato, delimitador y reglas de salida. |
| 10 | `export_template_fields` | Nueva tabla futura | Define columnas, orden y mapeos de cada plantilla. |
| 11 | `export_jobs` | Ya existe | Representa exportaciones ejecutadas. |

## Relación recomendada entre tablas

- `import_batches` tiene muchos `import_files`.
- `import_files` tiene muchas `import_rows`.
- `import_batches` puede tener muchas `product_staging_rows`.
- `import_files` puede tener muchas `product_staging_rows`.
- Cada `product_staging_row` puede generar o actualizar un registro de `master_products`.
- `normalization_rules` puede generar muchas `normalization_suggestions`.
- Cada `product_staging_row` puede tener muchas `normalization_suggestions`.
- Cada registro de `master_products` puede tener muchas `normalization_suggestions`.
- Cada registro de `master_products` puede tener muchos `product_change_logs`.
- `export_templates` puede tener muchos `export_template_fields`.
- Cada `export_job` puede usar un `export_template`.

Las claves foráneas, reglas de borrado e índices definitivos se resolverán durante el diseño de migraciones. La trazabilidad histórica debe preservarse, por lo que no se deben elegir eliminaciones en cascada que borren auditoría o aprobaciones sin una justificación explícita.

## Decisiones técnicas pendientes cerradas

Las siguientes decisiones cierran la estrategia técnica previa al diseño de migraciones. Todavía no representan una implementación.

### 1. Vínculos de staging

`product_staging_rows` deberá relacionarse mediante:

- `import_batch_id`;
- `import_file_id`;
- `import_row_id` nullable;
- `master_product_id` nullable.

Reglas:

- `import_batch_id` identifica el lote.
- `import_file_id` identifica el archivo de origen.
- `import_row_id` puede vincular la fila cruda general cuando exista.
- `master_product_id` se completa únicamente cuando la fila genera o actualiza un producto maestro.

`product_staging_rows` es la capa de trabajo específica para productos, mientras `import_rows` puede conservar datos crudos generales. Además, deberá garantizarse que `import_file_id` pertenezca al mismo `import_batch_id`, mediante una restricción viable o validación transaccional.

### 2. Cardinalidad de sugerencias

Una fila de *staging* puede tener muchas `normalization_suggestions`. Una misma fila puede recibir sugerencias independientes para:

- marca;
- descripción;
- medida;
- categoría;
- UXB;
- EAN;
- estado de revisión.

Relaciones:

- `product_staging_rows` tiene muchas `normalization_suggestions`.
- `master_products` puede tener muchas `normalization_suggestions`.
- `normalization_rules` puede generar muchas `normalization_suggestions`.

No debe existir una única sugerencia global por producto. Cada sugerencia se registra por campo y por regla. Durante el diseño de migraciones se definirá una restricción que exija al menos un destino válido —*staging* o maestro— sin crear asociaciones ambiguas.

### 3. Relación con validation_errors

La tabla existente `validation_errors` seguirá registrando errores de validación general.

Usos de `validation_errors`:

- errores de archivo;
- errores de fila;
- estructura inválida;
- campos obligatorios faltantes;
- valores imposibles;
- problemas de importación.

Usos de `normalization_suggestions`:

- propuestas de corrección;
- mejoras de descripción;
- abreviaturas;
- medidas;
- marca duplicada;
- acentos;
- formato de salida.

La regla conceptual es: `validation_errors` informa problemas y `normalization_suggestions` propone soluciones. Por ejemplo, `UXB = 0` puede generar un error o marca de revisión y el estado `pendiente_uxb`, pero no necesariamente una sugerencia automática.

### 4. export_template_id

`export_jobs` deberá poder vincularse en el futuro con una plantilla mediante:

```text
export_template_id nullable
```

Reglas:

- `export_templates` define el destino y el formato.
- `export_jobs` representa una ejecución concreta.
- `export_template_fields` define las columnas y campos de la plantilla.

Ejemplo:

```text
export_template: indesign_catalogo_txt
export_job: exportación ejecutada el día X usando esa plantilla
```

La relación será nullable para conservar compatibilidad con exportaciones históricas. Todavía no debe implementarse.

### 5. Transición de campos heredados en master_products

La tabla `master_products` ya existe con campos simples:

- `sku`;
- `barcode`;
- `name`;
- `brand`;
- `category`;
- `status`;
- `source_reference`;
- `data`;
- `last_import_batch_id`.

Transición sugerida:

- `sku` podrá mantenerse como alias técnico o campo de compatibilidad.
- `codigo_producto` será la clave funcional futura.
- `barcode` podrá convivir con `ean_original` y `ean_validado`.
- `name` podrá convivir con `nombre_original` y `nombre_homologado`.
- `brand` podrá convivir con `marca_original` y `marca_homologada`.
- `category` podrá convivir con `categoria_original` y `categoria_homologada`.
- `data` podrá utilizarse temporalmente, pero no reemplaza campos estructurados.
- `source_reference` podrá mantenerse como referencia de origen.
- `status` operativo deberá diferenciarse de `estado_homologacion`.

No se borrarán campos heredados en la primera etapa. Primero se agregarán campos nuevos; luego se migrarán o mapearán datos; finalmente se decidirá cuáles permanecen por compatibilidad.

### 6. Unificación de nombres

La convención oficial será usar nombres en español para los campos de negocio del dominio comercial.

Ejemplos oficiales:

- `codigo_producto`;
- `nombre_original`;
- `nombre_homologado`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- `descripcion_app`;
- `marca_original`;
- `marca_homologada`;
- `categoria_original`;
- `categoria_homologada`;
- `unidad_normalizada`;
- `medida_catalogo`;
- `uxb_validado`;
- `estado_homologacion`;
- `requiere_revision`.

Los nombres técnicos en inglés se conservarán cuando ya existan o sean convenciones del sistema, por ejemplo:

- `id`;
- `status`;
- `created_at`;
- `updated_at`;
- `deleted_at`;
- `import_batch_id`;
- `export_job_id`.

Debe evitarse usar dos nombres para el mismo concepto. En particular:

- `sku` y `codigo_producto` no deben tratarse como claves distintas;
- `brand` y `marca_homologada` no representan simultáneamente la misma salida;
- `name` y `descripcion_catalogo` no son equivalentes.

La columna `Sku` del Excel equivale a `codigo_producto`; no debe interpretarse como un SKU comercial separado.

### 7. Reglas de implementación incremental

Las futuras migraciones deberán seguir este criterio:

1. Crear primero las tablas auxiliares.
2. No romper la tabla `master_products` actual.
3. Agregar columnas nuevas sin borrar campos heredados.
4. Poblar los campos nuevos mediante procesos aprobados.
5. Validar con datos reales.
6. Recién después evaluar la limpieza de campos antiguos.

### 8. Impacto en Filament

Cuando se implemente, Filament deberá permitir:

- ver staging;
- ver datos originales;
- ver sugerencias por campo;
- aprobar o rechazar sugerencias;
- administrar reglas;
- ver historial;
- exportar por plantilla.

Estas pantallas y recursos no se implementan en esta etapa.

## Evolución incremental de master_products

La tabla `master_products` actual es flexible, pero insuficiente para representar por separado datos originales, homologados, control editorial y salidas por destino.

### Campos actuales útiles

- `sku`;
- `barcode`;
- `name`;
- `brand`;
- `category`;
- `status`;
- `source_reference`;
- `data`;
- `last_import_batch_id`.

### Identificación

- `codigo_producto`;
- `codigo_original`;
- `sku_original`;
- `ean_original`;
- `ean_validado`.

### Descripciones

- `nombre_original`;
- `nombre_sin_marca`;
- `nombre_homologado`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- `descripcion_app`;
- `descripcion_interna`.

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
- `cantidad_unidades`;
- `medida_valor`;
- `medida_catalogo`;
- `medida_requiere_revision`.

### UXB

- `uxb_original`;
- `uxb_validado`;
- `uxb_requiere_revision`.

### Control

- `estado_homologacion`;
- `requiere_revision`;
- `observaciones`;
- `approved_by_id`;
- `approved_at`.

### Estado actual de la ampliación v2

La primera ampliación estructural v2 de `master_products` incorporó estos campos originales, homologados, de salida y control mediante una migración incremental. Se conservaron los campos heredados por compatibilidad, no se creó una tabla `master_products_v2`, no se realizó *backfill* y `codigo_producto` permanece sin unicidad hasta resolver los duplicados en *staging*.

Esta etapa solo agrega capacidad estructural. La carga, homologación, aprobación y aplicación de datos continuarán en etapas funcionales posteriores.

## Clave funcional

`codigo_producto` será la clave funcional principal de los productos. La columna `Sku` del Excel equivale a `codigo_producto` y no representa un SKU comercial separado dentro de este proyecto.

La columna `id` seguirá siendo la clave primaria física de `master_products`. `codigo_producto` será la clave funcional para cotejos y relaciones de negocio, con una restricción de unicidad futura solamente después de sanear duplicados.

EAN queda como un dato secundario validable y no debe usarse como clave principal porque la fuente contiene valores vacíos, inválidos y duplicados. Antes de imponer unicidad definitiva sobre `codigo_producto`, los duplicados de la base semilla deben resolverse en *staging*.

La tabla actual define `sku` como único. La migración futura deberá planificar el *backfill* o mapeo `sku` → `codigo_producto`, resolver la coexistencia temporal de ambos campos y revisar la restricción existente sin perder datos ni habilitar una nueva unicidad prematuramente.

## Estrategia de staging

`product_staging_rows` debe conservar:

- valores originales del archivo;
- resultado detectado;
- sugerencia normalizada;
- estado de procesamiento;
- motivos de revisión.

Esta tabla nunca debe reemplazar a `master_products`: funciona como zona temporal de análisis, previsualización y aprobación.

## Estrategia de reglas

`normalization_rules` debe permitir:

- reglas automáticas de detección o sugerencia;
- reglas contextuales;
- reglas solamente sugeridas;
- reglas prohibidas para aplicación por lote;
- reglas que requieren revisión.

Ejemplos:

- `D/P` → `DOYPACK`;
- `CAFE` → `CAFÉ`;
- `750 CC` → `750CC`;
- `CHAMP.` → `Espumantes`;
- `RELL.` → `requiere_revision`;
- rangos con `/` → no modificar automáticamente.

Una regla automática puede detectar o sugerir, pero no escribir autónomamente sobre la base maestra.

## Estrategia de sugerencias

`normalization_suggestions` será la capa entre detección y aplicación. Nada debe cambiarse masivamente sin pasar por una sugerencia, previsualización y aprobación del usuario.

Las sugerencias aprobadas podrán actualizar campos homologados o de salida; los valores originales permanecen intactos.

## Estrategia de auditoría

`product_change_logs` debe guardar:

- campo cambiado;
- valor anterior;
- valor nuevo;
- regla aplicada;
- usuario que aprobó o ejecutó;
- fecha;
- origen del cambio;
- lote de importación, cuando corresponda.

La auditoría no debe eliminarse al actualizar o desactivar un producto.

## Estrategia inicial de usuarios

La tabla `users` seguirá siendo la base de autenticación. En esta etapa, todos los usuarios habilitados serán administradores con el mismo nivel de acceso.

No se crearán tablas de roles ni permisos. Tampoco se instalará Spatie Permission ni otro paquete similar durante esta etapa.

Los campos de auditoría seguirán apuntando a `users` para registrar a los responsables de cada acción:

- `approved_by_id`;
- `created_by_id`;
- `updated_by_id`;
- `reviewed_by_id`;
- `changed_by_id`.

Estos campos no implican roles diferentes. Solo registran qué administrador creó, aprobó, revisó o modificó cada dato.

## Estrategia de exportaciones

`export_templates` y `export_template_fields` permitirán generar salidas para:

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

InDesign puede usar medidas compactas y el delimitador `;`. Shopify puede incluir la marca en `titulo_shopify`. La aplicación móvil puede consumir datos estructurados y Excel interno puede conservar columnas separadas.

`export_jobs` registrará cada ejecución y deberá poder referenciar la plantilla utilizada.

## Orden recomendado de migraciones futuras

Este orden es solamente una guía para una etapa posterior; no autoriza implementar migraciones ahora.

1. Crear `product_staging_rows`.
2. Crear `normalization_rules`.
3. Crear `normalization_suggestions`.
4. Crear `product_change_logs`.
5. Crear `export_templates`.
6. Crear `export_template_fields`.
7. Continuar la evolución de `master_products` con las etapas funcionales del concepto v2.
8. Agregar índices y restricciones.
9. Crear recursos Filament.
10. Crear servicios de análisis y previsualización.
11. Crear aprobación por lote.
12. Crear exportadores por destino.

## Reglas de seguridad para futuras migraciones

- No borrar columnas existentes sin respaldo.
- No sobrescribir datos existentes.
- Implementar migraciones incrementales.
- Mantener campos originales e históricos.
- Probar en local antes de producción.
- No importar archivos Excel reales al repositorio.
- No usar `git add .`.
- Versionar solamente código, documentación y migraciones; no archivos reales de base.
