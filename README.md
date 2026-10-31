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

1. **Supervisado (Predicción de Inasistencias / No-Show):**
   - **Algoritmo:** `RandomForestClassifier` optimizado dentro de un `Pipeline` con `StandardScaler`.
   - **Variables:** Edad del paciente, días de anticipación de reserva, historial de cancelaciones previas, día de la semana, hora de la cita y nivel de complejidad del tratamiento.
   - **Salida:** Probabilidad de inasistencia (0.0 a 1.0), clasificación de riesgo (`BAJO`, `MEDIO`, `ALTO`) y recomendación operativa de sobrecupo o confirmación preventiva.

2. **No Supervisado (Segmentación de Pacientes):**
   - **Algoritmo:** `K-Means Clustering` ($k=3$) con **centroides ordenados por peso semántico** (`total_citas * gasto_total_usd`).
   - **Garantía Determinística:** Pacientes de alta frecuencia y compromiso siempre son asignados al Clúster 0 ("Paciente Frecuente / Alto Compromiso"), mientras que perfiles de abandono son asignados al Clúster 2 ("Paciente en Riesgo de Deserción").

3. **No Supervisado (Clasificación ABC de Inventario):**
   - **Algoritmo:** `K-Means Clustering` ($k=3$) con ordenamiento por valor monetario de rotación (`consumo_mensual * costo_unitario`).
   - **Garantía Determinística:** Clúster 0 representa Categoría A (Alto Valor / Rotación Crítica), Clúster 1 Categoría B y Clúster 2 Categoría C.

---

## 📡 Catálogo de Endpoints REST (`/api/agendamiento/**`)

### Agenda y Citas
- `GET /api/agendamiento/citas`: Listar citas registradas.
- `POST /api/agendamiento/citas`: Crear cita con `Idempotency-Key`, validación de turnos del odontólogo y prevención de colisiones (`409 Conflict`).
- `PATCH /api/agendamiento/citas/{id}/cancelar`: Cancelar una cita existente.
- `PUT /api/agendamiento/citas/{id}/reprogramar`: Reprogramar a un nuevo horario verificando disponibilidad.
- `PATCH /api/agendamiento/citas/{id}/completar`: Completar cita y descontar stock atómicamente en DynamoDB.
- `PATCH /api/agendamiento/citas/{id}/no-asistio`: Registrar inasistencia del paciente (`NO_ASISTIO`).

### Horarios, Inventario y Kardex
- `GET /api/agendamiento/horarios`: Consultar horarios y turnos de odontólogos.
- `GET /api/agendamiento/stock`: Consultar catálogo de insumos y alertas de reposición.
- `POST /api/agendamiento/stock/movimientos`: Registrar movimientos manuales de inventario (`ENTRADA_COMPRA`, `SALIDA_PROCEDIMIENTO`, `AJUSTE`).
- `GET /api/agendamiento/stock/{id}/kardex`: Consultar historial de movimientos (Kardex) de un insumo.

### Machine Learning
- `GET /api/agendamiento/ml/health`: Estado del motor de inferencia y modelos cargados en memoria.
- `POST /api/agendamiento/ml/predict-no-show`: Estimar probabilidad de inasistencia de una cita.
- `POST /api/agendamiento/ml/cluster-paciente`: Segmentación de comportamiento de paciente.
- `POST /api/agendamiento/ml/cluster-stock`: Clasificación ABC de criticidad de insumos.

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
