# Reglas de Normalización de Descripciones

## Corrección asistida

Las reglas de corrección no deben depender de la memoria humana. El sistema deberá guardar reglas reutilizables para que los mismos patrones reciban un tratamiento consistente y trazable.

El asistente podrá ayudar a detectar patrones y proponer reglas, pero el usuario deberá poder revisarlas y aprobarlas. Las reglas seguras podrán aplicarse por lote; las reglas dudosas deberán permanecer como sugerencias, y los casos especiales podrán corregirse individualmente. El dato original nunca debe destruirse y debe conservarse separado del resultado homologado.

Las reglas de normalización no modifican directamente el dato original. Se aplican sobre campos homologados o de salida después de la previsualización y aprobación del usuario, y el sistema debe registrar cada cambio aplicado.

## Uso futuro de IA en normalización

La IA podrá ayudar a detectar y sugerir normalizaciones, pero las reglas confirmadas por el usuario y la previsualización tendrán prioridad. La IA no deberá aplicar cambios automáticos sobre `descripcion_catalogo` sin aprobación.

## Relación entre normalización y salidas

Las reglas de normalización generan datos limpios y estructurados, pero la salida final puede cambiar según el destino:

- InDesign usa medidas compactas, por ejemplo `750CC`;
- Excel interno puede conservar `contenido_valor = 750` y `unidad_normalizada = CC` en columnas separadas;
- Shopify puede reconstruir el título incluyendo la marca;
- la aplicación móvil puede mostrar marca y descripción en campos separados.

## Decisiones de aplicación sobre abreviaturas detectadas

Las reglas detectadas se clasifican en cuatro grupos:

1. Correcciones aplicables.
2. Correcciones aplicables por contexto confirmado.
3. Valores que no se modifican.
4. Abreviaturas con punto aplicables con excepciones.

### Caso 1 - Abreviaturas con "/" aplicables

Las abreviaturas de este caso deben usar la posible corrección definida durante el análisis. Se consideran aplicables, con previsualización obligatoria antes de ejecutarlas por lote.

| Valor detectado | Valor homologado |
| --- | --- |
| `D/P` | DOYPACK |
| `C/` | con, cuando funcione como abreviatura de "con" |
| `S/` | sin, cuando funcione como abreviatura de "sin" |
| `P/` | para, cuando funcione como abreviatura de "para" |
| `DES/AMBIENTE` | Desodorante de ambiente |
| `S/CAROZO` | sin carozo |
| `S/SAL` | sin sal |
| `C/SAL` | con sal |
| `C/LECHE` | con leche |
| `P/HORNO` | para horno |
| `P/DILUIR` | para diluir |
| `C/CHIPS` | con chips |
| `C/ALAS` | con alas |
| `C/MIEL` | con miel |
| `C/HIERBAS` | con hierbas |
| `DCE/LECHE` | dulce de leche |
| `C/DDL` | con dulce de leche |
| `C/LIMON` | con limón |
| `C/NARANJA` | con naranja |
| `S/AZUCAR` | sin azúcar |
| `S/TACC` | sin TACC |
| `S/OLOR` | sin olor |
| `C/STEVIA` | con stevia |
| `P/FREEZER` | para freezer |
| `CAFE/COGN` | café al cognac |
| `CAFE/COGNA` | café al cognac |

Las abreviaturas `C/`, `S/` y `P/` deben validarse por contexto para evitar falsos positivos.

### Caso 2 - Sabores y variantes con "/" confirmadas

El usuario confirmó la aplicación de estas interpretaciones:

| Valor detectado | Valor homologado |
| --- | --- |
| `CAFE/RON` | café al ron |
| `VAIN/DDL` | vainilla / dulce de leche |
| `CHOC/CARAM` | chocolate / caramelo |
| `CREM/CEB` | crema y cebolla |
| `FR/BOSQUE` | frutos del bosque |
| `FRT/ROJ` | frutos rojos |
| `NAR/DURAZ` | naranja/durazno |
| `NJA/LIMA` | naranja/lima |
| `AVE/CHIPS` | avena/chips |
| `PESC/ARROZ` | pescado/arroz |
| `CAR/PESC/ARR` | carne/pescado/arroz |

Estas reglas se aplican como interpretaciones confirmadas, pero deben mantener una previsualización cuando se ejecuten por lote.

## Valores con "/" que no deben modificarse automáticamente

Los valores con `/` usados como rangos, formatos comerciales o medidas no deben recibir una corrección textual automática.

Ejemplos:

- `900/800`;
- `50/40`;
- `300/250`;
- `200/225`;
- `700/750`;
- `100/90`;
- `180/170`;
- `90/100`;
- `125/130`;
- `250/260`.

Estos valores deben conservarse como `medida_original` o `presentacion_original` y marcarse como `requiere_revision` cuando haga falta.

## Abreviaturas con punto

El Caso 4 comprende abreviaturas con punto cuyas correcciones pueden aplicarse, junto con excepciones específicas definidas por el usuario.

| Valor detectado | Valor homologado |
| --- | --- |
| `FID.` | Fideos |
| `LIQ.` | Líquido |
| `MERM.` | Mermelada |
| `ACOND.` | Acondicionador |
| `ALIM.GATO` | Alimento gato |
| `ALIM.PERRO` | Alimento perro |
| `BIZC.` | Bizcochuelo |
| `GELAT.` | Gelatina |
| `P.FRITAS` | Papas fritas |
| `P.HIGIENICO` | Papel Higiénico |
| `M.COCIDO` | Mate cocido |
| `PROT.SOLAR` | Protector solar |
| `DESINF.` | Desinfectante |
| `INSECT.` | Insecticida |
| `RVA.` | Reserva |
| `BCO.DULCE` | Blanco dulce |
| `DCE.LECHE` | dulce de leche |
| `PREM.` | Premium |
| `PVO.` | Polvo |
| `POM.ROSADO` | Pomelo rosado |
| `ANTIBACT.` | Antibacterial |
| `LIMP.PISOS` | Limpiador pisos |
| `JAB.POLVO` | Jabón en polvo |
| `JABON.LIQ` | Jabón líquido |

Excepciones y correcciones específicas:

| Valor detectado | Valor homologado |
| --- | --- |
| `CHAMP.` | Espumantes |
| `Champagne` | Espumantes |
| `Champaña` | Espumantes |
| `T.FEMENINA` | Toalla femenina |
| `T.HUMEDAS` | Toallas húmedas |
| `DESM.` | Descremada |
| `RELL.` | `requiere_revision` sin reemplazo automático |

## Regla para RELL.

`RELL.` no debe corregirse automáticamente. Puede significar:

- Relleno;
- Rellena;
- Rellenos;
- Rellenas.

Como la corrección depende del género y número del producto, aplicarla automáticamente o por lote puede introducir errores. Por ese riesgo, `RELL.` queda como una regla de revisión individual.

Regla:

- conservar `RELL.` en el dato original;
- marcar el registro como `requiere_revision`;
- no aplicar la corrección por lote;
- no reemplazar el valor automáticamente;
- permitir la corrección manual producto por producto.

Ejemplo:

```text
ACEITUNA TIYUCA RELL. AJO 200 GR
```

No aplicar automáticamente `RELL.` → `Rellena`. El producto debe quedar pendiente de revisión individual y conservar su valor original hasta que el usuario lo corrija manualmente.

## Regla especial para Champ.

Aunque el valor detectado sea `CHAMP.`, `Champagne` o `Champaña`, la base homologada del proyecto debe usar `Espumantes`.

Ejemplos:

- `CHAMP.` → Espumantes;
- `Champagne` → Espumantes;
- `Champaña` → Espumantes.

## Confirmación de aplicación

Las reglas confirmadas por el usuario podrán incorporarse al futuro motor de normalización como reglas aplicables. Sin embargo, antes de modificar registros por lote, el sistema deberá mostrar una previsualización de los productos afectados.

## Reglas para papel higiénico

| Valor detectado | Valor homologado | Nivel | Observación |
| --- | --- | --- | --- |
| `P.HIGIENICO` | Papel Higiénico | automática | Corrección segura |
| `P HIGIENICO` | Papel Higiénico | automática | Corrección segura |
| `PAPEL HIGIENICO` | Papel Higiénico | automática | Agregar tilde |
| `HIGIENICO` | HIGIÉNICO | automática | Corrección ortográfica |
| `HS` | Hoja Simple | sugerida | Solo en contexto de papel higiénico/productos de papel |
| `DH` | Doble Hoja | sugerida | Solo en contexto de papel higiénico/productos de papel |
| `TH` | Triple Hoja | sugerida | Solo en contexto de papel higiénico/productos de papel |

Estas reglas no deben aplicarse globalmente sobre toda la base. Deben validarse contra el contexto del producto, por ejemplo:

- presencia de `P.HIGIENICO`;
- presencia de `PAPEL HIGIENICO`;
- familia, grupo o categoría relacionada con papel o higiene;
- descripción original asociada a rollos, metros o unidades.

## Marca duplicada dentro de Nombre Sku

La columna `Nombre Sku` suele contener el tipo de producto, la marca, la variedad, el sabor, la presentación, la medida y distintas abreviaturas. Como la marca también existe en la columna `Marca`, el sistema debe poder separarla de la descripción para evitar duplicaciones en catálogo, sin eliminarla del dato original ni del sistema.

Ejemplo:

```text
Nombre original: VODKA PETAKON FRUTOS ROJOS 750 CC
Marca: PETAKON
Descripción catálogo sugerida: Vodka frutos rojos 750CC
Título Shopify sugerido: Vodka Petakon frutos rojos 750CC
```

### Regla para descripcion_catalogo

Si `Marca` está informada y aparece dentro de `Nombre Sku`, se debe poder remover la marca de `descripcion_catalogo`. Toda remoción por lote deberá ofrecer una previsualización de los productos afectados.

```text
PETAKON + VODKA PETAKON FRUTOS ROJOS 750 CC
→ Vodka frutos rojos 750CC
```

### Regla para titulo_shopify

Shopify puede requerir un título comercial con la marca incluida. Por eso, la marca no se elimina del sistema: se conserva en `marca_original` y `marca_homologada`, separada de la descripción, para reconstruir salidas como `Vodka Petakon frutos rojos 750CC`.

### Regla para InDesign

Si InDesign recibe la marca en una columna separada, `descripcion_catalogo` no debe repetirla.

Ejemplo correcto:

```text
MARCA: PETAKON
DESCRIPCION: Vodka frutos rojos 750CC
```

Ejemplo a evitar:

```text
MARCA: PETAKON
DESCRIPCION: Vodka Petakon frutos rojos 750CC
```

### Marca = 0 o vacía

Si la columna `Marca` contiene `0`, está vacía o no es confiable:

- no remover una posible marca automáticamente;
- inferir la marca solamente como sugerencia;
- marcar `requiere_revision` cuando no exista confianza suficiente;
- no sobrescribir `descripcion_catalogo` sin aprobación.

### Marcas compuestas

Las marcas de más de una palabra deben detectarse como una frase completa.

```text
Marca: TRES PLUMAS
Nombre Sku: ACEITE TRES PLUMAS GIRASOL 900 CC
Descripción catálogo sugerida: Aceite girasol 900CC
Título Shopify sugerido: Aceite Tres Plumas girasol 900CC
```

### Marcas que podrían confundirse con descripción

Cuando una marca coincide con una palabra común o genera ambigüedad, no debe removerse automáticamente:

- confianza alta: sugerir o aplicar con previsualización;
- confianza baja: marcar `requiere_revision`.

### Campos futuros sugeridos para marca y nombre

- `marca_original`;
- `marca_homologada`;
- `marca_detectada_en_nombre`;
- `nombre_original`;
- `nombre_sin_marca`;
- `descripcion_catalogo`;
- `titulo_shopify`;
- `contenido_valor`;
- `unidad_normalizada`;
- `medida_catalogo`;
- `requiere_revision_marca`;
- `nivel_confianza_marca`.

### Ejemplos adicionales

```text
Nombre original: P.HIGIENICO HIGI HS PLUS 30Mx4 Un
Marca: HIGI
Descripción catálogo: Papel Higiénico Hoja Simple Plus 4x30MT
Título Shopify: Papel Higiénico Higi Hoja Simple Plus 4x30MT

Nombre original: ACEITE TRES PLUMAS GIRASOL 900 CC
Marca: TRES PLUMAS
Descripción catálogo: Aceite girasol 900CC
Título Shopify: Aceite Tres Plumas girasol 900CC
```

## Unidades y metros

- `M` → `MT` cuando está asociado a una medida numérica;
- `MT` → `MT`;
- `MTS` → `MT`;
- `MTS.` → `MT`;
- `METROS` → `MT`.

Ejemplos:

- `30M` → `30MT`;
- `30 M` → `30MT`;
- `30Mx4 Un` → `4x30MT`;
- `30M x 4 Un` → `4x30MT`.

## Regla para Un / Unidad / Unidades

`Un` significa Unidad o Unidades según la cantidad:

- si la cantidad es mayor que `1`, usar `UNIDADES`;
- si la cantidad es igual a `1` o no hay una cantidad explícita, usar `UNIDAD`;
- en `descripcion_catalogo` puede omitirse `UNIDADES` cuando la presentación se expresa como pack compacto.

Ejemplos:

- `1 Un` → `1 UNIDAD`;
- `4 Un` → `4 UNIDADES`;
- `x4 Un` → `4 UNIDADES`;
- `30Mx4 Un` → `4x30MT`.

## Formato compacto de medidas para InDesign

En `descripcion_catalogo`, toda medida destinada a InDesign debe quedar pegada al número para evitar que el valor y la unidad se separen en dos líneas. En packs, también se eliminan los espacios alrededor de `x`.

Internamente, el sistema podrá conservar datos estructurados en campos separados, entre ellos:

- `contenido_valor`;
- `unidad_normalizada`;
- `cantidad_unidades`;
- `medida_valor`;
- `medida_catalogo`.

La salida visual de catálogo deberá usar formato compacto. Para packs, el formato recomendado es `cantidad x medida + unidad`, sin espacios.

Ejemplos:

- `750 CC` → `750CC`;
- `500 GR` → `500GR`;
- `900 GR` → `900GR`;
- `1 LT` → `1LT`;
- `1 KG` → `1KG`;
- `30 MT` → `30MT`;
- `4x30 MT` → `4x30MT`;
- `4 x 30 MT` → `4x30MT`;
- `12 x 50 GR` → `12x50GR`;
- `3 x 1 LT` → `3x1LT`;
- `30Mx4 Un` → `4x30MT`;
- `30M x 4 Un` → `4x30MT`;
- `4 Un de 30M` → `4x30MT`;
- `6 Un de 20M` → `6x20MT`;
- `12 Un de 50GR` → `12x50GR`;
- `3 Un de 1LT` → `3x1LT`.

Esta regla aplica a `descripcion_catalogo` y a las salidas para InDesign. Para otros destinos, como Shopify o la aplicación móvil, podrá definirse si corresponde un formato compacto o uno más descriptivo. El dato estructurado no debe perderse aunque la salida visual sea compacta.

## Ejemplo completo

Original:

```text
P.HIGIENICO HIGI HS PLUS 30Mx4 Un
```

Campos:

```text
marca_original: HIGI
descripcion_original: P.HIGIENICO HIGI HS PLUS 30Mx4 Un
```

Reglas aplicadas:

- `P.HIGIENICO` → Papel Higiénico;
- remover la marca `HIGI` de la descripción;
- `HS` → Hoja Simple;
- `30Mx4 Un` → `4x30MT`.

Resultado catálogo:

```text
Papel Higiénico Hoja Simple Plus 4x30MT
```

Otros ejemplos completos:

```text
Original: P.HIGIENICO HIGI DH PLUS 30Mx4 Un
Resultado catálogo: Papel Higiénico Doble Hoja Plus 4x30MT

Original: P.HIGIENICO HIGI TH PLUS 30Mx4 Un
Resultado catálogo: Papel Higiénico Triple Hoja Plus 4x30MT
```
