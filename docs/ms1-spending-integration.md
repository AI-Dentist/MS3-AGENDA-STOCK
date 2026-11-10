# Integración de Gasto Real de Pacientes con MS1 ERP (S2 & S4)

## 1. Arquitectura de Integración

El microservicio **MS3-AGENDA-STOCK** consume la información financiera de pacientes expuesta por **MS1 ERP** vía GraphQL para alimentar la segmentación no supervisada (K-Means) de pacientes con datos reales de facturación/cobro en Bolivianos (BOB):

```
┌────────────────────────┐         Authorization: Bearer <JWT>
│  Frontend Shell / Vue  │ ──────────────────────────────────────────────┐
└───────────┬────────────┘                                               │
            │ GET /api/agendamiento/ml/clusters                          │
            ▼                                                            │
┌────────────────────────┐                                               │
│ MS3 — Laravel Host     │                                               │
│  (MachineLearningCtrl) │                                               │
│           │            │                                               │
│           ▼            │                                               │
│    FastApiMlService    │ ─── [Ms1GraphQLBillingClient]                 │
│           │            │             │                                 │
│           │            │             │ POST /graphql (Forward Bearer)  │
│           │            │             ▼                                 ▼
│           │            │     ┌──────────────────────────────────────────┐
│           │            │     │           MS1 ERP (GraphQL)              │
│           │            │     │  - Roles: ADMIN, CAJERO, RECEPCIONISTA   │
│           │            │     │  - obtenerGastoPagadoPaciente(UUID!)     │
│           │            │     │    { pacienteId moneda completo          │
│           │            │     │      totalPagadoBs }                     │
│           │            │     └──────────────────────────────────────────┘
│           ▼            │
│  HTTP /batch-cluster   │
│           ▼            │
│  FastAPI ML Sidecar    │
│  (K-Means Pacientes)   │
└────────────────────────┘
```

---

## 2. Contrato T5 y Semántica de Verificación de Efectivo

1. **Consulta GraphQL de Gasto Verificado (T5):**
   ```graphql
   query ObtenerGastoPagadoPaciente($pacienteId: UUID!) {
     obtenerGastoPagadoPaciente(pacienteId: $pacienteId) {
       pacienteId
       moneda
       completo
       totalPagadoBs
     }
   }
   ```

2. **Invariantes y Reglas de Validación:**
   - **Identidad de Paciente:** El campo `pacienteId` de la respuesta debe coincidir unívocamente con el UUID consultado (`strcasecmp`).
   - **Moneda:** Debe ser estrictamente `"BOB"`.
   - **Completitud (`completo: true`):**
     * `true`: Historial completo y verificado (movimientos de caja y recibos en efectivo coincidentes, o historial sin cobros).
     * `false`: Historial incompleto (presencia de pagos QR demostrativos escalados `/1000`, inconsistencias o cobros sin liquidación bancaria demostrable).
   - **Monto (`totalPagadoBs`):** Valor numérico finito, no negativo.
   - **Fail-Closed a Estimado:** Si `completo === false`, `null`, ausente, error GraphQL, timeout, HTTP 401/403, o discrepancia de identidad, el cliente recurre **tajantemente al fallback estimado** (`gasto_total_real: null`, `gasto_real_observado: false`, `gasto_origen: "estimado_fallback"`).
   - **Cero Observado Verificado:** Si `completo === true` y `totalPagadoBs === 0.00`, se establece un cero observado verificado (`gasto_total_real: 0.0`, `gasto_real_observado: true`, `gasto_origen: "real_ms1_pagado"`).
   - **Gasto Real Verificado:** Si `completo === true` y `totalPagadoBs > 0`, se consolida en `gasto_total_real` con `gasto_real_observado: true`.
   - **Preservación Honesta del Estimador:** `gasto_total_estimado` se conserva siempre en `featuresEstimadas` en tanto se retorne dicho campo en la respuesta, garantizando que nunca se disfrace un estimado de real ni un real de estimado.

---

## 3. Seguridad y Límites de Acceso

1. **Reenvío de Token Bearer (Sin Bypass Anónimo):**
   - El token Bearer del usuario autenticado en MS3 se reenvía directamente hacia MS1 en la cabecera `Authorization: Bearer <token>`.
   - Si la petición no cuenta con token Bearer, el cliente **no realiza llamadas anónimas ni bypasses**, recurriendo de inmediato al estimado con trazabilidad.
   - Roles requeridos en MS1: `ADMIN`, `CAJERO` o `RECEPCIONISTA` (política `hasAnyRole('ADMIN','CAJERO','RECEPCIONISTA')`).

2. **Privacidad de Datos:**
   - Queda terminantemente prohibido registrar en logs los tokens Bearer, identificadores de recibos, números de cuotas o datos privados de pacientes.
   - Las excepciones de red o timeout se capturan sin concatenar URLs ni mensajes externos que puedan fugar credenciales.
   - No existe persistencia de tokens ni cachés compartidas entre usuarios (`no token persistence/cross-user caches`).

---

## 4. Contrato JSON Aditivo para Frontend (Vue)

El endpoint `GET /api/agendamiento/ml/clusters` preserva todos los campos existentes de forma retrocompatible e incorpora los nuevos metadatos de gasto real:

```json
{
  "success": true,
  "data": {
    "pacientes": {
      "centroides": [
        {
          "cluster": "Frecuente y Puntual",
          "cluster_id": 0,
          "valores": {
            "total_citas": 12.0,
            "tasa_asistencia": 0.95,
            "dias_promedio_entre_citas": 25.0,
            "gasto_total_estimado": 720.0
          }
        }
      ],
      "distribucion": {
        "Frecuente y Puntual": 1
      },
      "asignaciones": [
        {
          "paciente_id": "c1a2b3c4-0000-0000-0000-000000000001",
          "cluster": "Frecuente y Puntual",
          "cluster_id": 0,
          "features": {
            "total_citas": 3,
            "tasa_asistencia": 1.0,
            "dias_promedio_entre_citas": 20.0,
            "gasto_total_estimado": 180.0,
            "gasto_total_real": 240.0,
            "gasto_moneda": "BOB",
            "gasto_origen": "real_ms1_pagado",
            "gasto_real_observado": true
          },
          "featuresImputadas": [],
          "featuresEstimadas": [
            "gasto_total_estimado"
          ],
          "datosEntrenamientoSinteticos": true,
          "gasto_total_real": 240.0,
          "gasto_total_estimado": 180.0,
          "gasto_moneda": "BOB",
          "gasto_origen": "real_ms1_pagado",
          "gasto_real_observado": true
        }
      ]
    },
    "insumos": {
      "centroides": [
        {
          "cluster": "Alta Rotación Crítica",
          "cluster_id": 0,
          "valores": {
            "consumo_mensual": 45.0,
            "costo_unitario": 12.5,
            "dias_reabastecimiento": 3.0
          }
        }
      ],
      "distribucion": {
        "Alta Rotación Crítica": 1
      },
      "asignaciones": [
        {
          "insumo_id": "ins-1",
          "cluster": "Alta Rotación Crítica",
          "cluster_id": 0,
          "featuresImputadas": [],
          "featuresEstimadas": []
        }
      ]
    },
    "datosEntrenamientoSinteticos": true
  },
  "timestamp": "2026-11-10T03:20:00+00:00"
}
```

### Reglas para Consumo en Frontend:
- **Honestidad de Estimadores:** `gasto_total_estimado` se conserva siempre etiquetado dentro de `featuresEstimadas` en tanto se retorne dicho valor en el objeto, reflejando de forma transparente que esa cifra proviene de una estimación previa, incluso cuando se dispone de un `gasto_total_real` observado.
- **Gasto Real Observado (`gasto_real_observado === true`):** El valor en `gasto_total_real` (ej. `240.0` o `0.0` Bs) refleja el total efectivamente verificado en caja (`gasto_origen: "real_ms1_pagado"`). El frontend de Vue debe priorizar este valor verificado para la presentación al usuario.
- **Fallback Estimado (`gasto_real_observado === false`):** `gasto_total_real` es `null` y `gasto_origen` es `"estimado_fallback"`. El frontend debe presentar `gasto_total_estimado` (ej. `180.0` Bs) advirtiendo explícitamente que se trata de un estimado de referencia.
- **Nomenclatura del Sidecar:** La sección de inventario/stock utiliza la clave canónica del sidecar **`insumos`** (con `insumo_id` en las asignaciones), preservando la compatibilidad establecida en S11.
- **Retrocompatibilidad:** El campo `gasto_total_estimado` se conserva en todos los payloads para clientes heredados y pipelines de clustering.

---

## 5. Límites de Prueba en Producción

- En entornos desconectados o cuando Keycloak/MS1 no están desplegados en la misma red privada, el sistema opera en modo resiliente: responde HTTP 200 con la asignación estimada y transparencia de imputación.
