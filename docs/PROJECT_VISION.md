# Manejador de Base de Datos Comercial

## Misión

Construir una base maestra limpia, homologada y administrable de productos que permita reducir tiempos, errores, costos y trabajo manual en los procesos comerciales, gráficos y digitales de la organización.

El alcance estimado es de aproximadamente 8.000 productos, muchos de los cuales pueden contener errores o inconsistencias en descripciones, gramajes, abreviaturas, ortografía, categorías, marcas y formatos.

## Visión

Convertir la base maestra en una fuente de verdad reutilizable, capaz de abastecer de forma consistente diferentes procesos y canales:

- Adobe InDesign;
- Shopify;
- reportes internos;
- futuras aplicaciones móviles;
- procesos comerciales diarios;
- exportaciones personalizadas.

El sistema no se concibe como un simple importador o exportador de archivos. Su propósito es centralizar, homologar y gobernar la información de productos para que cada salida utilice datos confiables y reglas adecuadas a su proceso.

Los archivos Excel reales de Administración o base maestra son insumos externos de carga y no forman parte del código fuente de la aplicación.

## Principio central de los datos

La responsabilidad sobre los datos se divide de la siguiente manera:

- La base maestra manda en descripción, gramaje, marca, categoría, imagen y demás datos homologados del producto.
- Los archivos administrativos diarios mandan en precios, identificadores y datos operativos vigentes.
- Los procesos determinan qué productos se incluyen, qué reglas se aplican y en qué formato se genera cada salida.

Esta separación protege la calidad de la información maestra sin perder la vigencia de los datos operativos diarios.

El CODIGO DEL PRODUCTO será la clave operativa principal para cotejar archivos diarios y procesos de Marketing. En los archivos base recibidos este dato puede aparecer bajo la columna `Sku`.

Los productos con datos operativos incompletos, como `UXB = 0`, deben conservarse para su revisión, pero permanecer fuera de los procesos activos y las exportaciones hasta que el dato sea corregido.

El gobierno del dato será controlado: la IA y las reglas sugieren, pero el usuario aprueba y el sistema registra cada cambio sin destruir el dato original.

## Usuarios y administración inicial

En la primera etapa, el sistema funcionará con un esquema multiusuario simple. Habrá varios usuarios administradores habilitados, todos con el mismo nivel de acceso administrativo.

No se implementarán roles ni permisos avanzados inicialmente. Si el proyecto crece y aparecen nuevas necesidades operativas, podrán incorporarse perfiles como:

- operador de carga;
- revisor;
- aprobador;
- solo lectura;
- administrador general.

El principio para esta etapa es: primero simplicidad operativa; después, roles si el proyecto lo requiere.

## Escala y estrategia de saneamiento

El saneamiento de una base de aproximadamente 8.000 productos debe ser masivo, progresivo y controlado. El sistema debe evitar que la calidad de los datos dependa de corregir manualmente cada producto de forma aislada y debe permitir trabajar por tandas, filtros, estados y patrones repetidos.

Los datos originales deben conservarse separados de los datos homologados para mantener trazabilidad, comparar cambios y revisar las correcciones antes de aprobarlas. Las futuras reglas de homologación deberán normalizar de manera consistente descripciones, gramajes, unidades, abreviaturas, marcas, categorías, ortografía y formatos, sin sobrescribir ni perder la información recibida.

El sistema debe normalizar medidas y unidades para evitar inconsistencias como GRS/G/GR, KGS/K/KG y LTS/L/LT.

La corrección debe apoyarse en reglas reutilizables, con aplicación por lote para casos seguros y revisión humana para sugerencias o excepciones.

Este enfoque busca reducir el tiempo, los costos y el trabajo operativo necesarios para depurar y mantener la base maestra, permitiendo que el equipo concentre la revisión manual en excepciones y casos que realmente requieran criterio humano.

## Flujo conceptual

```text
Base maestra limpia
    → archivo administrativo diario
    → cotejo por ID/código
    → validación y homologación
    → selección de proceso
    → plantilla de salida
    → archivo final
```

El cotejo debe combinar la calidad descriptiva de la base maestra con los valores operativos vigentes, registrando diferencias y errores antes de producir una salida.

Este flujo conceptual describe el cotejo operativo una vez que existe una base maestra activa. El ingreso inicial de datos sigue un flujo diferente de *staging*, normalización, previsualización, aprobación e incorporación al maestro.

El proyecto se implementará por capas: *staging*, normalización, productos maestros, exportaciones e IA futura.

La [arquitectura física](DATABASE_PHYSICAL_DESIGN_PLAN.md) se implementará de forma incremental, manteniendo una única base maestra y capas separadas para *staging*, reglas, sugerencias, auditoría y exportaciones.

La implementación técnica evitará duplicar la base maestra y mantendrá trazabilidad entre el dato original, la sugerencia, la aprobación y cada salida generada.

## Salidas múltiples por destino

El sistema deberá generar diferentes salidas a partir de la misma base maestra limpia y no depender de una descripción universal ni de un único formato final. La base conserva los datos maestros limpios, estructurados y separados; cada destino define cómo se arma y presenta la salida.

Ejemplo de dato maestro:

```text
Marca: PETAKON
Producto: Vodka
Variedad: frutos rojos
Contenido: 750
Unidad: CC
```

### InDesign

- Es la salida principal para el catálogo editorial.
- Puede requerir TXT delimitado por punto y coma.
- Puede separar Marca y Descripción en columnas distintas.
- `descripcion_catalogo` no debe repetir la marca si esta ya se entrega separada.
- Las medidas deben ser compactas para evitar cortes de línea: `750CC`, `500GR`, `1LT`, `1KG` y `4x30MT`.

Ejemplo: `PETAKON;Vodka frutos rojos 750CC`.

### Shopify

- Puede requerir CSV o XLSX.
- Puede utilizar `titulo_shopify` con la marca incluida.
- Puede requerir una descripción comercial más clara.
- Puede requerir *handle*, etiquetas, *vendor*, tipo de producto, imágenes y precio.

Ejemplo: `Vodka Petakon frutos rojos 750CC`.

### App móvil

- Puede consumir JSON o una API.
- Puede mostrar una descripción legible para vendedores o clientes.
- Puede usar marca, descripción, unidad, UXB, imagen, categoría y precio como campos separados.
- Puede requerir búsqueda rápida por `codigo_producto`, marca o descripción.

Ejemplo: `Vodka frutos rojos 750CC - Petakon`.

### Excel interno

- Puede conservar los datos separados por columnas.
- Es útil para revisión, administración, reportes y controles.
- Puede incluir `codigo_producto`, marca, `descripcion_catalogo`, `contenido_valor`, `unidad_normalizada`, `uxb_validado` y `estado_homologacion`.

Ejemplo: `PETAKON | Vodka frutos rojos | 750 | CC`.

### TXT / CSV / XLSX

- Deben generarse mediante plantillas de exportación.
- Cada plantilla define columnas, orden, delimitador y formato.
- El TXT para InDesign puede usar punto y coma.
- CSV y XLSX pueden utilizarse para Shopify o reportes internos.

### JSON/API

- Será útil para la aplicación móvil y otras integraciones futuras.
- Debe exponer datos estructurados, no solamente texto plano.

También podrán incorporarse XML u otros formatos cuando un proceso futuro lo requiera. Una misma base maestra puede generar muchas salidas: el dato maestro manda y el destino define el formato.

## Orden estratégico de construcción

El desarrollo funcional debe avanzar en este orden:

Este orden expresa objetivos funcionales. La secuencia técnica para alcanzarlos comienza por *staging* y trazabilidad antes de consolidar la evolución lógica v2 de `master_products`, según la [arquitectura de staging y normalización](STAGING_AND_NORMALIZATION_ARCHITECTURE.md).

1. Construir y depurar la base maestra.
2. Definir y aplicar la homologación de datos.
3. Implementar el cotejo con los archivos administrativos diarios.
4. Definir plantillas y reglas por proceso.
5. Incorporar exportaciones para múltiples destinos.
6. Agregar automatizaciones e integraciones futuras.

Este orden prioriza la calidad y el gobierno de los datos antes de automatizar su distribución.

## Criterios que se deben evitar

- Tratar el sistema como un simple importador de Excel.
- Usar archivos administrativos como fuente maestra de contenido textual.
- Reemplazar descripciones homologadas con textos incompletos, inconsistentes o mal escritos.
- Diseñar exportadores rígidos y acoplados a un único formato.
- Limitar la arquitectura al TXT utilizado por Adobe InDesign.
- Contaminar la futura aplicación móvil con reglas gráficas propias de la producción de catálogos.

## Incorporación futura de agentes de IA

El sistema podrá incorporar agentes de IA o asistentes inteligentes en una etapa posterior, cuando el flujo base de datos esté consolidado. No se implementarán de una sola vez: la IA se incorporará progresivamente por módulos y solo cuando el sistema lo requiera.

El principio central será: **la IA sugiere, el sistema valida y el usuario aprueba**. La IA será una capa asistente sobre el sistema, no la fuente de verdad.

### Agentes posibles a futuro

Los siguientes agentes son posibilidades futuras, no módulos actuales:

1. Agente normalizador de descripciones:
   - analiza `Nombre Sku`;
   - detecta abreviaturas;
   - sugiere `descripcion_catalogo`;
   - identifica medidas;
   - detecta marca duplicada.
2. Agente revisor de productos:
   - detecta productos dudosos;
   - marca `requiere_revision`;
   - prioriza productos con problemas.
3. Agente de inconsistencias:
   - detecta `codigo_producto` duplicado;
   - detecta `UXB = 0`;
   - detecta EAN inválido;
   - detecta `Marca = 0`;
   - detecta categorías, grupos o familias vacías.
4. Agente generador de salidas:
   - sugiere formato para InDesign;
   - sugiere título para Shopify;
   - sugiere texto para la aplicación móvil;
   - adapta salidas según el destino.
5. Asistente interno de consulta:
   - permite consultar la base en lenguaje natural;
   - por ejemplo: “Mostrame productos con Marca=0”;
   - por ejemplo: “Qué productos tienen RELL.”;
   - por ejemplo: “Qué productos tienen UXB=0”.

### Restricciones de seguridad

- La IA no modifica productos maestros directamente.
- La IA no aplica cambios masivos sin previsualización.
- La IA no reemplaza la aprobación del usuario.
- Todo cambio sugerido debe quedar trazable.
- Toda aplicación aprobada debe registrar el usuario, la fecha y la regla aplicada.
- Si la confianza es baja, el producto debe quedar como `requiere_revision`.

### Orden de incorporación

1. Primero: *staging* de productos.
2. Segundo: evolución lógica v2 de `master_products`.
3. Tercero: reglas de normalización.
4. Cuarto: previsualización y aprobación por lote.
5. Quinto: historial de cambios.
6. Sexto: recién entonces incorporar agentes de IA.
7. Séptimo: agentes más avanzados o consultas en lenguaje natural.

## Criterio de evolución

Toda nueva funcionalidad debe reforzar a la base maestra como fuente de verdad, mantener separadas las reglas específicas de cada canal y permitir que nuevos formatos o integraciones se incorporen sin degradar la calidad del dato central.
