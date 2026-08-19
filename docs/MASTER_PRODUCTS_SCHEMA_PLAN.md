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
- `product_audit_logs`.

Estos campos, estados y tablas son alternativas para el análisis futuro; no constituyen todavía una definición del esquema ni implican implementar lógica de homologación en esta etapa.

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

- `codigo`;
- `sku`;
- `ean` o código de barras;
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

1. Campo clave principal para el cotejo.
2. Campos obligatorios.
3. Campos opcionales.
4. Campos que pueden actualizarse desde Administración.
5. Campos que nunca deben reemplazarse automáticamente.
6. Estados posibles del producto y sus transiciones.
7. Relación entre producto, precio, lote y exportación.
8. Campos necesarios para Adobe InDesign.
9. Campos necesarios para Shopify.
10. Campos necesarios para la futura aplicación móvil.

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
