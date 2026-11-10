# MS3 — Microservicio de Agenda, Horarios, Inventario y Machine Learning

Microservicio del ecosistema clínico odontológico encargado de la gestión de turnos, agendamiento de citas con prevención de colisiones e idempotencia, ciclo clínico completo, catálogo, movimientos y kardex de inventario con deducción atómica, persistencia en Amazon DynamoDB, seguridad Keycloak JWT y analítica predictiva con Machine Learning Clásico.

---

## 🏗️ Arquitectura de la Solución

El servicio implementa los principios de **Clean / Hexagonal Architecture** en Laravel 11/12 junto a un **Micro-Sidecar en FastAPI (Python 3)** para la ejecución de modelos analíticos en memoria sin penalidad de latencia y persistencia nativa en **Amazon DynamoDB**.

```
                    ┌────────────────────────┐
                    │ API Gateway / Frontend │
                    │    (Shell :4200)       │
                    └───────────┬────────────┘
                                │  HTTP REST (/api/agendamiento/**)
                                ▼
         ┌────────────────────────────────────────────────────────┐
         │              MS3 — Laravel Application (PHP 8.3+)      │
         │  ┌──────────────┐ ┌───────────────┐ ┌───────────────┐  │
         │  │ KeycloakAuth │ │ Use Cases     │ │ DynamoDB Repos│  │
         │  │ (RS256 JWT)  │ │ (Application) │ │ (Persistence) │  │
         │  └──────┬───────┘ └───────┬───────┘ └───────┬───────┘  │
         │         │                 │                 │          │
         │         │                 ▼                 ▼          │
         │         │        Domain Services     Amazon DynamoDB   │
         │         │        (MlInference)       (Tablas & GSIs)   │
         │         │                 │                 │          │
         │         │                 ▼                 ▼          │
         │         │        FastApiMlService    transactWriteItems│
         └─────────┼─────────────────┼────────────────────────────┘
                   │                 │  HTTP Interno (:8001)
                   │                 ▼
         ┌─────────┴──────────────────────────────────────────────┐
         │           MS3 — Machine Learning Sidecar (FastAPI)     │
         │                                                        │
         │  • Modelo Supervisado: Random Forest (No-Show)         │
         │  • Modelo No Supervisado: K-Means (Segmentación)       │
         │  • Modelo No Supervisado: K-Means (Clasificación ABC)  │
         │  • Centroides semánticos ordenados determinísticamente │
         │  • Binarios Serializados (.joblib) en Memoria          │
         └────────────────────────────────────────────────────────┘
```

---

## 💾 Persistencia en Amazon DynamoDB

El microservicio utiliza **Amazon DynamoDB** como motor de almacenamiento NoSQL de alto rendimiento:

1. **Tablas e Índices Secundarios Globales (GSI):**
   - `citas`: Partition Key (`id`), GSI (`idempotency_key-index`), GSI (`odontologo_fecha-index`).
   - `horarios`: Partition Key (`id`), GSI (`odontologo_id-index`).
   - `insumos`: Partition Key (`id`), GSI (`codigo-index`).
   - `movimientos_stock`: Partition Key (`id`), GSI (`insumo_id-index`).

2. **Deducción Atómica (`transactWriteItems`):**
   - La finalización de citas odontológicas (`CompletarCitaYDescontarStockUseCase`) ejecuta transacciones atómicas con expresiones de condición (`attribute_exists(id) AND stock_actual >= :cantidad`) para evitar inventarios negativos y registrar simultáneamente el movimiento en el Kardex.

3. **Comando de Configuración:**
   ```bash
   php artisan dynamodb:setup --seed
   ```

---

## 🔒 Seguridad y JWT Keycloak (RBAC)

- **Verificación Criptográfica Estricta:** Validación de tokens JWT con algoritmos **RS256** (producción) y **HS256** (desarrollo local) mediante `firebase/php-jwt`.
- **Tolerancia a Desfasaje de Reloj (Clock Skew Leeway):** Configuración de una tolerancia de reloj (`KEYCLOAK_JWT_LEEWAY`, por defecto 60s) en la validación de claims temporales (`iat` y `nbf`). El valor se encuentra acotado estrictamente al rango seguro **0..300 segundos** (valores vacíos o no numéricos recurren a 60s). La propiedad estática global `\Firebase\JWT\JWT::$leeway` se aísla por petición resguardando su estado y restaurándolo en bloque `finally` para evitar fugas entre decodificadores. Los tokens expirados (`exp <= now`) se rechazan tajantemente sin tolerancia.
- **Política Estricta de Emisor (iss):** El emisor confiable se deriva de `${KEYCLOAK_BASE_URL}/realms/${KEYCLOAK_REALM}`. Cualquier token con `iss` ausente o no coincidente es rechazado con HTTP 401 (`INVALID_TOKEN`) antes de cualquier mutación o llamada a capas inferiores.
- **Política Estricta de Audiencia de Recurso (aud):** La audiencia autorizada se define mediante la variable `KEYCLOAK_AUDIENCE` (por defecto `ms3-agenda-stock`). La audiencia de recurso **NO es el realm**. Se valida estrictamente pertenencia para valores de tipo string o array (`aud: "ms3-agenda-stock"` o `aud: ["ms3-agenda-stock", "account"]`). Tokens emitidos para otros microservicios o con audiencia genérica son rechazados con HTTP 401.
- **Variables de Configuración (Sin Secretos):**
  - `KEYCLOAK_BASE_URL`: URL base del Identity Provider (ejemplo sintético: `http://localhost:8180`).
  - `KEYCLOAK_REALM`: Nombre del realm de Keycloak (ejemplo sintético: `dental-platform`).
  - `KEYCLOAK_AUDIENCE`: Audiencia de recurso esperada por este microservicio (ejemplo sintético: `ms3-agenda-stock`).
  - `KEYCLOAK_ALGORITHM`: Algoritmo criptográfico utilizado (por defecto: `RS256`).
  - `KEYCLOAK_PUBLIC_KEY`: Clave pública RSA en formato PEM (o base64) para verificar firmas RS256.
  - `KEYCLOAK_DEV_SECRET`: Secreto simétrico reservado exclusivamente para pruebas unitarias y entornos desconectados (HS256).
- **Anti-Spoofing & Contexto:** Rechazo tajante de cabeceras inyectadas `X-User-Roles` sin token Bearer válido. Enlace seguro del usuario autenticado en `$request->attributes` y en el resolver de Laravel (`$request->setUserResolver()`).
- **Control de Acceso por Roles (RBAC):** Normalización de roles eliminando prefijo `ROLE_` y homologando `DENTISTA` con `ODONTOLOGO`. Roles autorizados: `ADMIN`, `RECEPCIONISTA`, `ODONTOLOGO`, `ASISTENTE`.
- **CORS W3C Estricto:** Eliminación de comodines (`*`) con `supports_credentials => true`, permitiendo exclusivamente orígenes confiables (`localhost:4200`, API Gateway).

---

## 🚀 Modelos de Machine Learning Incorporados

> [!NOTE]
> **Datos de Entrenamiento:** Todos los modelos supervisados y no supervisados fueron entrenados con **DATOS SINTÉTICOS** (`datosSinteticos: true`), generado determinísticamente para el entorno de portfolio odontológico.

1. **Supervisado (Predicción de Inasistencias / No-Show - S10):**
   - **Algoritmo:** `RandomForestClassifier` optimizado dentro de un `Pipeline` con `StandardScaler`.
   - **Variables:** Historial de cancelaciones previas, días de anticipación, día de la semana, hora de la cita, banda horaria (mañana/tarde), duración estimada en minutos, tipo de tratamiento / riesgo y edad del paciente. Si un parámetro no se proporciona, se calcula/imputa automáticamente y se registra en `featuresImputadas`.
   - **Salida:** Campos canónicos `probabilidadNoShow` (0.0000 a 1.0000), `nivelRiesgo` (`BAJO`, `MEDIO`, `ALTO`), `accionRecomendada` y `featuresImputadas` (conservando aliases heredados en snake_case para retrocompatibilidad).
   - **Umbrales Canónicos (S10):**
     * `BAJO`: `probabilidad < 0.35` -> Acción: *"Horario seguro"*.
     * `MEDIO`: `0.35 <= probabilidad <= 0.65` (0.65 es MEDIO) -> Acción: *"Enviar recordatorio por WhatsApp 48h antes"*.
     * `ALTO`: `probabilidad > 0.65` -> Acción: *"Confirmar por teléfono/WhatsApp 24h antes"*.

2. **No Supervisado (Segmentación de Pacientes - S11):**
   - **Algoritmo:** `K-Means Clustering` ($k=3$) con centroides ordenados semánticamente por frecuencia y gasto estimado.
   - **Nombres Exactos de Clúster:**
     * `Frecuente y Puntual`: Pacientes de alta asistencia y visitas periódicas.
     * `Riesgo de Abandono / Inconstante`: Pacientes con asistencias irregulares o intervalos prolongados.
     * `Atención Esporádica / Urgencias`: Pacientes con baja frecuencia orientados a urgencias.

3. **No Supervisado (Clasificación ABC de Inventario - S11):**
   - **Algoritmo:** `K-Means Clustering` ($k=3$) con centroides ordenados por valor de rotación (`consumo_mensual * costo_unitario`).
   - **Nombres Exactos de Clúster:**
     * `Alta Rotación Crítica`: Insumos de alto consumo e impacto operativo inmediato (Categoría A).
     * `Consumo Estándar`: Insumos de rotación media y costo moderado (Categoría B).
     * `Baja Rotación`: Insumos de uso ocasional o reposición espaciada (Categoría C).

---

## 📡 Catálogo de Endpoints REST (`/api/agendamiento/**`)

### Agenda y Citas
- `GET /api/agendamiento/citas`: Listar citas registradas. Enriquecido automáticamente con inferencias ML (`probabilidadNoShow`, `nivelRiesgo`, `accionRecomendada`) y metadato `mlDisponible: true`. Si el micro-sidecar no está disponible, responde exitosamente HTTP 200 con campos ML en `null` y `mlDisponible: false`.
- `POST /api/agendamiento/citas`: Crear cita con `Idempotency-Key`, validación de turnos del odontólogo y prevención de colisiones (`409 Conflict`). Opcionalmente admite `duracionEstimadaMin`, `tipoTratamiento` y `reservadaEn` para enriquecimiento de features ML.
- `PATCH /api/agendamiento/citas/{id}/cancelar`: Cancelar una cita existente.
- `PUT /api/agendamiento/citas/{id}/reprogramar`: Reprogramar a un nuevo horario verificando disponibilidad.
- `PATCH /api/agendamiento/citas/{id}/completar`: Completar cita y descontar stock atómicamente en DynamoDB.
  - Ejecuta un único `TransactWriteItems` atómico que transiciona la cita a `COMPLETADA` (`ConditionExpression: attribute_exists(id) AND estado <> :cancelado AND estado <> :completado`), descuenta cada insumo utilizado mediante CAS estricto sobre el snapshot leído (`ConditionExpression: attribute_exists(id) AND stock_actual = :previous AND stock_actual >= :cant`), e inserta cada salida en `movimientos_stock` (`ConditionExpression: attribute_not_exists(id)`).
  - Elimina cualquier escritura incondicional de repositorio post-transacción, protegiendo deltas y previniendo que movimientos concurrentes sean sobreescritos.
  - Garantiza fidelidad del Kardex (`stock_anterior` y `stock_posterior` reflejan el estado efectivamente persistido). Ante conflicto de concurrencia CAS o stock insuficiente, la transacción se cancela atómicamente retornando **400 `COMPLETION_ERROR`** sin efectos secundarios; el cliente debe releer el estado y no reintentar a ciegas.
  - Fail-closed ante caídas de DynamoDB: si DynamoDB no está disponible (`isAvailable() === false` o error de conexión), responde **500 `INTERNAL_TRANSACTION_ERROR`** sin realizar escrituras en memoria ni confirmar la cita en falso. El modo en memoria queda restringido a entornos de prueba unitaria puros o configuración explícita (`DYNAMODB_FALLBACK_MEMORY=true`).
  - Si el array `insumos` suministrado repite el mismo `insumo_id`, se preserva la última cantidad especificada para dicho identificador.
- `PATCH /api/agendamiento/citas/{id}/no-asistio`: Registrar inasistencia del paciente (`NO_ASISTIO`).

### Horarios, Inventario y Kardex
- `GET /api/agendamiento/horarios`: Consultar horarios y turnos de odontólogos (filtro opcional `?odontologo_id=UUID`).
- `POST /api/agendamiento/horarios`: Registrar nuevo turno de atención (exclusivo para rol `ADMIN`). Valida la vigencia activa del profesional y consultorio contra el catálogo maestro de MS1 ERP (`listarOdontologos(soloActivos: true)` con `activo=true` y `listarConsultorios` con estados canónicos `DISPONIBLE` o `MANTENIMIENTO`; `INACTIVO` o estados desconocidos son rechazados) propagando de forma aislada el Bearer token del llamador. Persiste de forma exacta los identificadores canónicos retornados por el master y garantiza enmascaramiento genérico 502 ante fallas upstream preservando la privacidad de URLs, tokens y canarios.
  - Payload JSON: `{ "odontologo_id": "UUID", "consultorio_id": "UUID", "especialidad": "string", "fecha": "YYYY-MM-DD", "hora_inicio": "HH:MM", "hora_fin": "HH:MM", "disponible": true }`.
  - Invariantes de horario: `hora_inicio` y `hora_fin` requieren formato estricto `HH:MM` (24 horas) con regla de negocio `hora_inicio < hora_fin`.
  - Configuración MS1: `MS1_GRAPHQL_URL` (default: `http://127.0.0.1:8081/graphql`), `MS1_TIMEOUT_MS` (default: `2000`).
- `GET /api/agendamiento/stock`: Consultar catálogo de insumos y alertas de reposición.
- `POST /api/agendamiento/stock/movimientos`: Registrar movimientos manuales de inventario (`ENTRADA_COMPRA`, `SALIDA_PROCEDIMIENTO`, `AJUSTE`).
  - Idempotencia **opcional**: enviar `Idempotency-Key` o su alias `X-Idempotency-Key`. Si se envían ambos encabezados deben tener valores idénticos; si difieren, la petición es rechazada con **400 `CONTRADICTORY_IDEMPOTENCY_KEYS`**. Conservar la misma clave para el mismo intento del mismo actor autenticado; una nueva intención requiere otra clave. No hay reintentos automáticos de mutaciones.
  - La clave queda acotada al actor verificado, nunca a `usuario_id` del JSON. Con clave, el Kardex atribuye el movimiento al actor autenticado. Sin clave se mantiene la atribución histórica por `usuario_id` del cuerpo (no constituye identidad verificada) y cada petición es un movimiento independiente.
  - El enlace del payload incluye actor, insumo, tipo, cantidad, motivo, `usuario_id` suministrado y dirección semántica. `AJUSTE` admite `DECREMENTO` sin distinguir mayúsculas; por compatibilidad, cualquier otra dirección u omisión incrementa. Para los otros tipos, la dirección no cambia su significado.
  - Primer éxito y replay devuelven **201 con el mismo cuerpo original**, incluidos ID, stock y timestamp; el replay no recalcula stock ni agrega Kardex. Otra carga con la misma clave del mismo actor devuelve **409 `IDEMPOTENCY_CONFLICT`**, sin escrituras. Otro actor tiene un espacio de claves independiente y nunca recibe ese resultado.
  - Incluso sin clave, stock CAS (valor anterior y control anti-negativos), movimiento único y resultado original se persisten juntos en un único `TransactWriteItems`, usando `insumos` y `movimientos_stock`. Este último conserva metadatos `payload_hash`, `response_status`, `response_json` sin filas auxiliares ni tablas nuevas. No borrar movimientos mientras se necesite replay; no hay TTL automático.
  - Ante colisión CAS o error sin resultado durable confirmado: **503 `STOCK_WRITE_UNAVAILABLE`**. No hay fallback de escritura a memoria ni reintento ciego. Una lectura consistente puede confirmar una respuesta perdida. Un 503 no demuestra que no hubo commit: conservar la clave y consultar; sin clave no existe recuperación idempotente entre peticiones. Se mantienen 404 para insumo ausente y 400 `INSUFFICIENT_STOCK` para sobreconsumo.
  - Alcance: movimientos manuales coordinados con el flujo de finalización de citas (ambos asegurados con CAS y sin escrituras incondicionales posteriores). Las consultas GET siguen siendo las existentes (incluido su fallback y la consistencia eventual del GSI del Kardex). Pruebas con handler sintético no sustituyen integración y concurrencia en DynamoDB real.
- `GET /api/agendamiento/stock/{id}/kardex`: Consultar historial de movimientos (Kardex) de un insumo.

### Machine Learning
- `GET /api/agendamiento/ml/health`: Estado del motor de inferencia y modelos cargados en memoria.
- `POST /api/agendamiento/ml/predict-noshow`: Estimar probabilidad de inasistencia de una cita (S10). Alias retrocompatible: `POST /api/agendamiento/ml/predict-no-show`. Ante caída del sidecar responde **503 `ML_SERVICE_UNAVAILABLE`**.
- `GET /api/agendamiento/ml/clusters`: Obtener centroides, distribución y asignaciones de clústeres para pacientes e inventario desde DynamoDB (S11), enriquecido con gasto real en efectivo desde MS1 ERP vía GraphQL (S2). Transparencia de imputación y estimación (ver [`docs/ms1-spending-integration.md`](docs/ms1-spending-integration.md)). Ante caída del sidecar responde **503 `ML_SERVICE_UNAVAILABLE`**.
- `GET /api/agendamiento/ml/model-card`: Consultar ficha técnica del modelo con métricas de evaluación offline (accuracy, precision, recall, F1, ROC-AUC para No-Show; Silhouette score para clustering de pacientes e inventario) y metadato `datosSinteticos: true` (S9). Alias: `/model_card`.
- `POST /api/agendamiento/ml/cluster-paciente`: Segmentación de comportamiento de paciente.
- `POST /api/agendamiento/ml/cluster-stock`: Clasificación ABC de criticidad de insumos.

### KPIs Ejecutivos
- `GET /api/agendamiento/kpis?desde=YYYY-MM-DD&hasta=YYYY-MM-DD`: Consultar los 5 KPIs ejecutivos de agenda y stock calculados a partir de citas y movimientos de inventario en tiempo real (S6).
  - Autenticación: Exclusivo para rol `ADMIN` (`auth.keycloak:ADMIN`). Llamadas sin token devuelven **401 `UNAUTHORIZED`**; otros roles devuelven **403 `FORBIDDEN_ROLE`**.
  - Parámetros requeridos: `desde` y `hasta` en formato `YYYY-MM-DD` bajo zona horaria `America/La_Paz`. Parámetros faltantes, formatos inválidos, fechas invertidas (`desde > hasta`) o períodos mayores a 366 días devuelven **422 `INVALID_PERIOD`** o **`PERIOD_TOO_LONG`**.
  - Garantía de solo lectura (S0): La consulta no ejecuta escrituras ni mutaciones en el almacén de datos.
  - Métricas calculadas:
    1. `pacientesFrecuentes`: Ranking top 10 de pacientes por volumen de citas (`pacienteId`, `totalCitas`, `completadas`).
    2. `inasistencia`: Tasa de inasistencia observada sobre citas evaluadas (`COMPLETADA` + `NO_ASISTIO`) con detalle de citas no asistidas.
    3. `horariosPico`: Citas por día de la semana (ISO 1-7) y hora (0-23) excluyendo buckets en cero, junto al top 5 con mayor concurrencia.
    4. `insumosCriticos`: Listado completo de insumos ordenado con prioridad `bajoMinimo` (`stockActual <= stockMinimo`) y luego por `salidasPeriodo` (`SALIDA_PROCEDIMIENTO`) descendente.
    5. `productividadOdontologos`: Rendimiento por odontólogo (`citasTotales`, `citasCompletadas`, `tasaCompletadas`).

### Constructor de Reportes (Modo Manual)
- `GET /api/agendamiento/reportes/{entidad}?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&estado=...`: Consultar catálogo tabular y metadatos de columnas para el constructor de reportes manual (S3).
  - Entidades permitidas (`entidad`): `citas` o `insumos` (cualquier otra entidad responde **404 Not Found**).
  - Autenticación: Exclusivo para rol `ADMIN` (`auth.keycloak:ADMIN`). Llamadas sin token devuelven **401 `UNAUTHORIZED`**; otros roles devuelven **403 `FORBIDDEN_ROLE`**.
  - Filtro de período opcional (`desde` y `hasta`): deben enviarse ambos o ninguno. Rango de calendario válido en `America/La_Paz`, máximo 366 días (infracciones devuelven **422 `INVALID_PERIOD`** o **`PERIOD_TOO_LONG`**).
  - Filtro de estado opcional (`estado`): aplica exclusivamente a `citas` con estados válidos del dominio (`RESERVADA`, `EN_ATENCION`, `COMPLETADA`, `CANCELADA`, `REPROGRAMADA`, `NO_ASISTIO`). Enviar `estado` para `insumos` devuelve **422 `INVALID_FILTER`**.
  - Enriquecimiento ML: para `citas`, incluye `probabilidadNoShow` y `nivelRiesgo` (`BAJO`, `MEDIO`, `ALTO`); ante indisponibilidad del sidecar se preservan en `null` con código 200 sin inventar valores.
  - Para `insumos`: incluye `stockActual`, `stockMinimo`, `bajoMinimo` y `salidasPeriodo` (`SALIDA_PROCEDIMIENTO` en el período, o total histórico si no se define período), ordenados con prioridad `bajoMinimo` y luego por salidas descendente.
  - Contrato JSON: `{"success": true, "data": {"entidad": "...", "columnas": [{"clave": "...", "etiqueta": "...", "tipo": "..."}], "filas": [...], "total": N}, "timestamp": "..."}`.
  - Garantía de solo lectura (S0): Estricta operación de lectura sin escrituras ni mutaciones en el almacén de datos.

---

## 🛠️ Ejecución Local

### Opción 1: Con Docker Compose (Recomendado)
```bash
docker-compose up --build
```
- Backend Laravel: `http://localhost:8000`
- ML Sidecar: `http://localhost:8001`
- DynamoDB Local: `http://localhost:8002`

### Opción 2: Ejecución Nativa
```powershell
# 1. Instalar dependencias de PHP y Python
composer install
pip install -r ml_service/requirements.txt

# 2. Entrenar y exportar modelos serializados
python ml_service/train_models.py

# 3. Iniciar servicios simultáneamente
.\run_services.ps1
```

### Ejecutar Pruebas Automatizadas
```bash
# Pruebas de PHP (Unit & Feature con PHPUnit)
php artisan test

# Pruebas del Sidecar de ML (FastAPI TestClient)
python ml_service/test_ml_service.py
```

---

## 🚀 Despliegue en Producción (AWS EC2)

### Arquitectura de Producción
- **Host**: AWS EC2 `100.60.248.245` (`us-east-1`), bajo el stack `dental-ms3`.
- **Red Externa Docker**: `dental-net` (sin puertos expuestos al host; tráfico enrutado vía Caddy reverse proxy `edge`).
- **Base de Datos**: Amazon DynamoDB Nativo en `us-east-1`, autenticado mediante la cadena por defecto del AWS SDK que resuelve el IAM Role de la instancia EC2 vía IMDSv2. Sin credenciales estáticas ni endpoints locales.
- **Microservicios**:
  - `ms3-agenda` (Laravel): puerto interno 8001.
  - `ml-service` (FastAPI sidecar): puerto interno 8000.
- **Autenticación Keycloak**: Sin Keycloak en AWS; el servicio arranca normalmente, valida el emisor mediante `KEYCLOAK_ISSUER_URI`, y responde `401 Unauthorized` ante cualquier petición a rutas protegidas sin token válido.

### Creación Idempotente de Tablas DynamoDB (PAY_PER_REQUEST)
Para aprovisionar las 4 tablas DynamoDB (`citas`, `horarios`, `insumos`, `movimientos_stock`) con facturación por petición (`PAY_PER_REQUEST`) e índices secundarios globales (GSIs):

```bash
# 1. Creación idempotente de tablas
docker compose run --rm ms3-agenda php artisan dynamodb:setup

# 2. (Opcional) Creación y siembra de datos de prueba
docker compose run --rm ms3-agenda php artisan dynamodb:setup --seed
```

### Configuración de Entorno (.env)
En la raíz del repositorio en el servidor de despliegue (`/home/ubuntu/deploy/MS3-AGENDA-STOCK`):
```bash
cp deploy/aws/env.template .env
# Generar o configurar APP_KEY
php artisan key:generate
```
El archivo `.env` define `COMPOSE_FILE=deploy/aws/compose.yml` para que `docker compose up -d --build` opere directamente con la configuración de producción.

### CI/CD con GitHub Actions
El flujo `.github/workflows/deploy-aws.yml` automatiza el despliegue ante cada push a la rama `main`:
1. Realiza checkout del repositorio vía `actions/checkout@v4`.
2. Sincroniza el código fuente hacia la instancia AWS mediante `rsync -az --delete --exclude .git --exclude .env` sobre SSH nativo con el secreto `AWS_SSH_KEY` (sin requerir deploy keys en la VPS para el repositorio privado).
3. Reconstruye e inicia los contenedores con `docker compose up -d --build --remove-orphans`.
