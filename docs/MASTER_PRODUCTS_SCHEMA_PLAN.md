# Diseño de Base Maestra de Productos

## Objetivo

Diseñar una base maestra limpia, homologada y administrable de productos que funcione como fuente de verdad para los procesos diarios de cotejo y para las salidas destinadas a Adobe InDesign, Shopify, reportes internos y una futura aplicación móvil.

Este documento delimita los datos y las decisiones que deben analizarse. No define todavía la estructura definitiva ni autoriza cambios de esquema antes de revisar la base maestra real.

## Principio central

La base maestra manda en:

- descripción limpia;
- gramaje;
- unidad;
- marca;
- categoría;
- imagen;
- datos homologados;
- estado editorial o comercial.

Los archivos administrativos diarios mandan en:

- código o ID;
- precios;
- precios de oferta;
- datos operativos vigentes;
- datos variables del lote.

La integración entre ambas fuentes debe preservar el contenido homologado y actualizar solamente la información operativa que corresponda a cada proceso.

## Código de producto como clave operativa

Marketing recibe la información por CODIGO. En la base recibida `SKU Daniel 8mil(1).xlsx`, la columna `Sku` representa ese CODIGO DEL PRODUCTO y no un SKU comercial separado.

El sistema deberá mapear `Sku` → `codigo_producto`. `codigo_producto` será la clave funcional principal para cotejar la base maestra contra los archivos administrativos y ejecutar los procesos de Marketing. No se debe depender de `Ean` ni de `Nombre Sku` para cruzar información: `Ean` queda como dato secundario validable y `Nombre Sku` como descripción o nombre original administrativo. `Marca` tampoco constituye una clave.

Los valores duplicados de `codigo_producto` detectados en *staging* deben resolverse antes de consolidar la base maestra activa e imponer una restricción de unicidad definitiva. Hasta completar esa depuración, la base semilla no debe asumirse única por CODIGO/SKU.

## Archivos fuente externos

La base `SKU Daniel 8mil(1).xlsx` es un insumo externo de análisis y carga. No forma parte de la aplicación, no debe almacenarse dentro del repositorio, versionarse en Git ni subirse a GitHub.

El sistema deberá permitir cargar archivos similares en el futuro mediante procesos de importación y *staging*. La base maestra limpia y operativa debe vivir en MySQL, sin depender de que el archivo Excel permanezca dentro de la aplicación.

El Excel original puede conservarse como respaldo operativo fuera del repositorio. Los datos originales necesarios para trazabilidad deberán preservarse en *staging* o en futuras tablas de importación, no como un archivo fijo del código fuente.

## Saneamiento y homologación masiva

La base maestra puede contener aproximadamente 8.000 productos con errores o inconsistencias en descripciones, gramajes, abreviaturas, ortografía, categorías, marcas y formatos. Por esa escala, el proceso no debe depender de corregir producto por producto.

El sistema debe permitir importar los datos originales sin perderlos y diferenciarlos claramente de los datos homologados. En particular, debe conservar la descripción original y mantener por separado la descripción homologada, de modo que exista trazabilidad y que ninguna corrección elimine el valor recibido de origen.

Ejemplo:

```text
descripcion_original: LICOR PETACA CAFE/COGNA
descripcion_homologada: Licor petaca café al cognac
```

El saneamiento debe poder organizarse por tandas, filtros y estados. También debe contemplar futuras reglas de normalización y ayudar a detectar patrones repetidos, como abreviaturas, errores ortográficos, unidades o formatos recurrentes, para aplicar criterios consistentes a grupos de productos. La corrección debe avanzar de manera progresiva y controlada, con revisión de excepciones y aprobación antes de considerar homologado un dato.

### Posibles campos futuros

- `descripcion_original`;
- `descripcion_homologada`;
- `descripcion_catalogo`;
- `marca_original`;
- `marca_homologada`;
- `gramaje_original`;
- `gramaje_normalizado`;
- `unidad_normalizada`;
- `estado_homologacion`;
- `requiere_revision`.

### Posibles estados

- `pendiente_revision`;
- `sugerido_por_sistema`;
- `requiere_correccion`;
- `homologado`;
- `aprobado_catalogo`;
- `sin_imagen`;
- `sin_precio`;
- `inactivo`.

### Posibles tablas futuras

- `normalization_rules`;
- `brands`;
- `categories`;
- `product_prices`;
- `product_images`;
- `product_change_logs`.

Estos campos, estados y tablas son alternativas para el análisis futuro; no constituyen todavía una definición del esquema ni implican implementar lógica de homologación en esta etapa.

## Normalización de medidas y unidades

El objetivo es detectar y normalizar las medidas presentes en `Nombre Sku` para construir descripciones limpias y campos reutilizables, conservando siempre la medida original separada de la normalizada. Las correcciones deberán poder aplicarse por lote y revisarse también de forma individual.

### Equivalencias de unidades

| Tipo | Valores originales | Unidad normalizada |
| --- | --- | --- |
| Peso en gramos | `GRS`, `GRS.`, `GR`, `GR.`, `G`, `G.` | `GR` |
| Peso en kilogramos | `KGS`, `KGS.`, `KG`, `KG.`, `K`, `K.` | `KG` |
| Volumen en litros | `LTS`, `LTS.`, `LT`, `LT.`, `L`, `L.` | `LT` |

### Regla de detección

Una unidad solo debe detectarse cuando está asociada a un número. Esta condición evita interpretar la `G` de palabras como `GATO` como gramos o la letra `K` como kilogramos cuando no forma parte de una medida numérica.

Ejemplos de normalización:

- `500 G` → `500 GR`;
- `500G` → `500 GR`;
- `500 Grs` → `500 GR`;
- `1 KGS` → `1 KG`;
- `1K` → `1 KG`;
- `1.5K` → `1.5 KG`;
- `1 LTS` → `1 LT`;
- `1.5LT` → `1.5 LT`.

### Ejemplo real

Para el valor original `ALIM.GATO CAT CHOW ADULTO PES/POLLO 1K`, el sistema deberá derivar:

```text
medida_original: 1K
contenido_valor: 1
unidad_original: K
unidad_normalizada: KG
medida_normalizada: 1 KG
```

### Campos futuros sugeridos

- `medida_original`;
- `contenido_valor`;
- `unidad_original`;
- `unidad_normalizada`;
- `medida_normalizada`;
- `medida_requiere_revision`.

### Casos especiales

Medidas compuestas, combos, rangos o variantes decimales como `9X170G`, `300/310G`, `1.5K` y `1,5 LTS` pueden requerir reglas adicionales. Cuando una regla automática no sea suficiente o el resultado sea ambiguo, el producto deberá marcarse con `medida_requiere_revision` para su control individual.

### Relación con la homologación

La medida normalizada deberá alimentar de forma consistente:

- `descripcion_homologada`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- la futura aplicación móvil o API.

### Presentaciones de papel para catálogo

Las abreviaturas `HS`, `DH` y `TH` podrán homologarse respectivamente como Hoja Simple, Doble Hoja y Triple Hoja únicamente cuando el contexto corresponda a papel higiénico o productos de papel. En `descripcion_catalogo`, los packs deberán admitir un formato compacto como `4x30MT`, mientras los valores de cantidad, medida y unidad normalizada se conservan en campos estructurados separados. Las reglas completas se detallan en [Reglas de normalización de descripciones](DESCRIPTION_NORMALIZATION_RULES.md).

El diccionario de homologación ya contempla reglas confirmadas para abreviaturas con `/`, sabores y variantes, abreviaturas con punto y excepciones como `CHAMP.`, `T.FEMENINA`, `T.HUMEDAS` y `DESM.`. Los rangos, medidas o formatos comerciales que contienen `/` no deben modificarse automáticamente.

`RELL.` queda excluida de las correcciones automáticas: debe marcarse como `requiere_revision`, conservarse en el dato original y corregirse solamente mediante revisión individual, sin aplicación por lote.

### Separación de marca y descripción por destino

El esquema deberá separar `marca_original`, `marca_homologada`, `nombre_original`, `nombre_sin_marca`, `descripcion_catalogo` y `titulo_shopify`, conservando siempre el dato original. Cuando la marca de la columna `Marca` también aparezca dentro de `Nombre Sku`, se podrá remover de `descripcion_catalogo` con previsualización para evitar duplicaciones, mientras Shopify u otros destinos podrán reconstruir el título con la marca.

Para InDesign, `descripcion_catalogo` deberá usar medidas compactas como `750CC`, `500GR`, `1LT`, `1KG` y `4x30MT`. Los valores estructurados de contenido, unidad, cantidad y medida deberán conservarse por separado aunque la salida visual sea compacta.

## Regla inicial para UXB = 0

La base maestra real analizada contiene aproximadamente 8.815 registros y cerca de 1.265 productos con `UXB = 0`. Por decisión funcional, estos productos no serán tomados por ahora como productos activos del sistema, pero tampoco deben eliminarse.

Los registros con `UXB = 0` deben conservarse como pendientes de revisión, con el estado sugerido `pendiente_uxb`. Mientras mantengan ese valor, deben quedar excluidos de la base maestra activa inicial y no deben exportarse a Adobe InDesign, Shopify, la futura aplicación móvil ni los reportes operativos.

Estos productos podrán incorporarse más adelante cuando Administración o el área correspondiente corrija el dato `UXB`. En una futura importación o etapa de *staging*, deberán conservarse con todos sus datos originales para permitir su trazabilidad, revisión y posterior incorporación sin pérdida de información.

### Estrategia de carga inicial

- `UXB` mayor a `0`: candidato a producto maestro activo.
- `UXB` igual a `0`: pendiente de revisión, excluido temporalmente de procesos y exportaciones.

## Campos a analizar cuando se reciba la base maestra real

Los siguientes campos son candidatos de análisis, no una definición final del esquema.

### Identificación

- `codigo_producto`, mapeado desde la columna `Sku` del archivo y recomendado como clave funcional principal;
- `sku_original` o `codigo_original`, si se desea conservar el nombre o valor tal como fue recibido;
- `ean` o código de barras, como dato secundario validable;
- código de proveedor;
- estado.

### Datos editoriales

- nombre homologado;
- descripción corta;
- descripción larga;
- gramaje normalizado;
- unidad normalizada;
- presentación;
- marca homologada;
- categoría homologada;
- grupo o familia.

### Datos comerciales

- precio de lista;
- precio de oferta;
- precio tachado;
- tipo de precio;
- vigencia;
- unidades por bulto;
- disponibilidad.

### Datos gráficos y de catálogo

- imagen principal;
- ruta de imagen;
- cucarda;
- contenedor;
- orden de catálogo;
- texto para InDesign;
- observaciones de diseño.

### Datos digitales

- título para Shopify;
- descripción para Shopify;
- etiquetas o tags;
- tipo de producto;
- vendor;
- handle;
- estado de publicación;
- campos futuros para la aplicación móvil o API.

### Datos de control

- homologado;
- requiere revisión;
- última actualización;
- último lote cotejado;
- origen del último cambio;
- usuario que corrigió;
- observaciones internas.

## Reglas pendientes de definir

Estas reglas se definirán cuando se analice la base maestra real:

1. Criterio para resolver en *staging* los duplicados de `codigo_producto` antes de imponer unicidad definitiva.
2. Campos obligatorios.
3. Campos opcionales.
4. Campos que pueden actualizarse desde Administración.
5. Campos que nunca deben reemplazarse automáticamente.
6. Estados posibles del producto y sus transiciones.
7. Relación entre producto, precio, lote y exportación.
8. Campos necesarios para Adobe InDesign.
9. Campos necesarios para Shopify.
10. Campos necesarios para la futura aplicación móvil.

## Preparación futura para sugerencias de IA

El esquema futuro deberá contemplar campos o tablas para sugerencias de IA sin reemplazar los datos aprobados de la base maestra. Toda propuesta deberá pasar por previsualización y aprobación del usuario, y la incorporación de IA ocurrirá después de implementar *staging*, la evolución lógica v2 de `master_products`, reglas de normalización e historial de cambios.

### Posibles campos futuros

- `ai_suggested_description`;
- `ai_suggested_brand`;
- `ai_suggested_category`;
- `ai_confidence`;
- `ai_reason`;
- `ai_status`;
- `ai_reviewed_by_id`;
- `ai_reviewed_at`.

### Posibles estados de sugerencias

- `pendiente_ia`;
- `sugerido_por_ia`;
- `aprobado_por_usuario`;
- `rechazado_por_usuario`;
- `requiere_revision`.

Estos elementos son previsiones documentales y no implican implementar lógica, integraciones externas ni cambios de esquema en esta etapa.

## Plantillas de salida por destino

El esquema futuro deberá contemplar plantillas de exportación por destino. Cada plantilla definirá qué campos se incluyen, su orden y las reglas visuales o técnicas necesarias, sin alterar los datos maestros estructurados.

### Campos o conceptos futuros sugeridos

- `export_destination`;
- `export_template`;
- `export_format`;
- `delimiter`;
- `column_mapping`;
- `field_order`;
- `format_rules`;
- `include_brand`;
- `compact_measurements`;
- `output_filename`;
- `generated_by_id`;
- `generated_at`.

### Destinos posibles

- `indesign`;
- `shopify`;
- `mobile_app`;
- `internal_excel`;
- `txt`;
- `csv`;
- `xlsx`;
- `json_api`.

### Ejemplos de configuración

Para InDesign:

- `include_brand: false` en `descripcion_catalogo` cuando la marca va separada;
- `compact_measurements: true`;
- `delimiter: ;`.

Para Shopify:

- `include_brand: true` en `titulo_shopify`;
- `compact_measurements` configurable;
- formato CSV o XLSX.

Para la aplicación móvil:

- salida JSON o API;
- datos separados por campos;
- descripción legible.

Para Excel interno:

- salida XLSX;
- datos separados y auditables.

## Arquitectura técnica futura por capas

La arquitectura propuesta se detalla en [Arquitectura de staging, normalización y productos maestros v2](STAGING_AND_NORMALIZATION_ARCHITECTURE.md):

- `product_staging_rows` separa y conserva el dato original antes de homologarlo;
- `normalization_rules` contiene reglas reutilizables;
- `normalization_suggestions` permite previsualizar y aprobar antes de aplicar;
- `product_change_logs` garantiza la trazabilidad;
- la evolución lógica v2 de `master_products` será la base maestra limpia que alimentará las salidas por destino.

El esquema deberá diferenciar campos originales, campos homologados, sugerencias y registros de cambio. Las correcciones aprobadas se aplicarán únicamente sobre campos homologados o de salida, mientras `product_change_logs` conservará su trazabilidad.

La evolución física recomendada es extender `master_products` como tabla maestra final y única fuente de verdad. *Staging*, reglas, sugerencias, historial y plantillas de salida vivirán en tablas separadas, según la [estrategia física de base de datos](DATABASE_PHYSICAL_DESIGN_PLAN.md).

Se adopta `codigo_producto` como nombre funcional. Los campos heredados de `master_products` no se eliminarán inicialmente: la evolución será incremental y las sugerencias se gestionarán por campo y por regla.

## Orden recomendado

1. Analizar la base maestra real.
2. Detectar columnas útiles, sucias, duplicadas o faltantes.
3. Definir la estructura definitiva de `master_products`.
4. Determinar si conviene crear tablas auxiliares, entre ellas:
   - `brands`;
   - `categories`;
   - `product_prices`;
   - `product_images`;
   - `export_templates`;
   - `process_templates`.
5. Recién después, crear las migraciones necesarias.
6. Adaptar los recursos Filament.
7. Incorporar el cotejo.
8. Finalmente, incorporar las exportaciones.

## Importante

No se debe implementar la estructura definitiva hasta analizar la base maestra real, identificar sus reglas de negocio y acordar qué datos pertenecen al dominio maestro y cuáles son operativos o específicos de cada canal.
