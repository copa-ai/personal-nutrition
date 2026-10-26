# personal-nutrition


# Personal Nutrition: descripción del proyecto

**Idea general**
Un bot que planifica las comidas de cada día. Recibe información personal (gustos, historial, stock, etc.) y genera un menú semanal con recetas y tiempos de preparación.

**Entradas (IN)**
- **Feedback:** lo que me gusta y lo que no.
- **Historial:** lo que he comido, para no repetir y mantener variedad.
- **Stock:** lo que tengo disponible en casa.
- **Modelo:** qué es lo mejor, es decir, el criterio nutricional o de optimización que guía las decisiones.
- **Calendario:** lo que ya se ha sugerido anteriormente.
- **Tiempos:** cuánto tiempo hay para cocinar en el momento, para cada día de la semana.

**Salidas (OUT)**
Una lista de días (`Dia[]`). Cada día contiene:
- **Fecha.**
- **Comida / cena.**
- **Categorías de comida** (por ejemplo: carne roja, huevo, etc.).
- **Ejemplo de recetas**, cada una con:
  - Ingredientes.
  - Duración previa (preparación anticipada).
  - Duración al momento (cocinado en el momento).

**Flujo**
`IN → BOT → OUT`

.