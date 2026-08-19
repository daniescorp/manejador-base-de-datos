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

## Escala y estrategia de saneamiento

El saneamiento de una base de aproximadamente 8.000 productos debe ser masivo, progresivo y controlado. El sistema debe evitar que la calidad de los datos dependa de corregir manualmente cada producto de forma aislada y debe permitir trabajar por tandas, filtros, estados y patrones repetidos.

Los datos originales deben conservarse separados de los datos homologados para mantener trazabilidad, comparar cambios y revisar las correcciones antes de aprobarlas. Las futuras reglas de homologación deberán normalizar de manera consistente descripciones, gramajes, unidades, abreviaturas, marcas, categorías, ortografía y formatos, sin sobrescribir ni perder la información recibida.

El sistema debe normalizar medidas y unidades para evitar inconsistencias como GRS/G/GR, KGS/K/KG y LTS/L/LT.

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

## Tipos de salida previstos

La arquitectura funcional debe admitir múltiples destinos sin quedar acoplada a uno solo:

- TXT delimitado por `;` para Adobe InDesign;
- CSV o XLSX para Shopify;
- Excel para reportes internos;
- JSON o API para una futura aplicación móvil;
- XML u otros formatos cuando un proceso lo requiera.

Cada salida deberá responder a una plantilla y a reglas de proceso configurables, evitando exportadores rígidos o dependencias innecesarias entre canales.

## Orden estratégico de construcción

El desarrollo funcional debe avanzar en este orden:

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

## Criterio de evolución

Toda nueva funcionalidad debe reforzar a la base maestra como fuente de verdad, mantener separadas las reglas específicas de cada canal y permitir que nuevos formatos o integraciones se incorporen sin degradar la calidad del dato central.
