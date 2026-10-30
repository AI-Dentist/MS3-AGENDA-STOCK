"""
FastAPI Micro-Sidecar for Dental Clinic MS3 Machine Learning Services.
Provides endpoints for:
- Supervised Prediction: Dental Appointment No-Show Probability.
- Unsupervised Clustering: Patient Segmentation and Behavior Profiling.
- Unsupervised Clustering: Stock / Inventory ABC Analysis.
"""

import os
from contextlib import asynccontextmanager
from typing import Dict, Any
import joblib
import pandas as pd
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")

models: Dict[str, Any] = {}


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Load serialized pipelines at startup (In-Memory, Zero Cold-Start)
    no_show_path = os.path.join(MODELS_DIR, "no_show_pipeline.joblib")
    patient_cluster_path = os.path.join(MODELS_DIR, "patient_clustering_pipeline.joblib")
    stock_cluster_path = os.path.join(MODELS_DIR, "stock_clustering_pipeline.joblib")

    if not os.path.exists(no_show_path) or not os.path.exists(patient_cluster_path):
        raise RuntimeError("ML model binaries not found. Run train_models.py first.")

    models["no_show"] = joblib.load(no_show_path)
    models["patient_clustering"] = joblib.load(patient_cluster_path)
    models["stock_clustering"] = joblib.load(stock_cluster_path)
    print("All ML Pipelines successfully loaded into memory.")
    yield
    models.clear()


app = FastAPI(
    title="MS3 Machine Learning Micro-Service",
    description="Dedicated micro-sidecar for Supervised & Unsupervised ML inference",
    version="1.0.0",
    lifespan=lifespan
)


# ==========================================
# Pydantic Schemas
# ==========================================

class NoShowRequest(BaseModel):
    edad: int = Field(..., ge=0, le=120, description="Edad del paciente")
    dias_anticipacion: int = Field(..., ge=0, description="Días entre la reserva y la fecha de la cita")
    cancelaciones_previas: int = Field(..., ge=0, description="Historial de citas canceladas o no asistidas")
    dia_semana: int = Field(..., ge=1, le=7, description="1=Lunes, 2=Martes ... 7=Domingo")
    hora_cita: int = Field(..., ge=7, le=22, description="Hora de la cita en formato 24h (ej. 9, 14)")
    tipo_tratamiento_riesgo: int = Field(..., ge=0, le=2, description="0=Bajo (Control/Limpieza), 1=Medio (Caries), 2=Alto (Cirugía/Endodoncia)")


class NoShowResponse(BaseModel):
    probabilidad_inasistencia: float
    prediccion_inasistencia: bool
    nivel_riesgo: str
    recomendacion_operativa: str
    modelo: str = "RandomForestClassifier-v1.0"


class PatientClusterRequest(BaseModel):
    total_citas: int = Field(..., ge=0)
    tasa_asistencia: float = Field(..., ge=0.0, le=1.0)
    dias_promedio_entre_citas: float = Field(..., ge=0.0)
    gasto_total_usd: float = Field(..., ge=0.0)


class PatientClusterResponse(BaseModel):
    cluster_id: int
    segmento: str
    descripcion: str
    estrategia_fidelizacion: str
    algoritmo: str = "K-Means-Clustering"


class StockClusterRequest(BaseModel):
    consumo_mensual: float = Field(..., ge=0.0)
    costo_unitario: float = Field(..., ge=0.0)
    dias_reabastecimiento: int = Field(..., ge=0)


class StockClusterResponse(BaseModel):
    cluster_id: int
    clasificacion_abc: str
    prioridad_reorden: str
    algoritmo: str = "K-Means-Clustering"


# ==========================================
# Endpoints
# ==========================================

@app.get("/health")
def health_check():
    return {
        "status": "UP",
        "service": "MS3 - Machine Learning Sidecar",
        "models_loaded": list(models.keys())
    }


@app.post("/predict/no-show", response_model=NoShowResponse)
def predict_no_show(payload: NoShowRequest):
    pipeline = models.get("no_show")
    if not pipeline:
        raise HTTPException(status_code=500, detail="Modelo No-Show no disponible en memoria.")

    df = pd.DataFrame([{
        "edad": payload.edad,
        "dias_anticipacion": payload.dias_anticipacion,
        "cancelaciones_previas": payload.cancelaciones_previas,
        "dia_semana": payload.dia_semana,
        "hora_cita": payload.hora_cita,
        "tipo_tratamiento_riesgo": payload.tipo_tratamiento_riesgo
    }])

    # Predict probability for class 1 (Inasistencia)
    probabilities = pipeline.predict_proba(df)[0]
    prob_no_show = float(probabilities[1])
    is_no_show = bool(prob_no_show >= 0.5)

    if prob_no_show >= 0.65:
        risk_level = "ALTO"
        recommendation = "Requerir confirmación telefónica 24h antes y habilitar sobrecupo/lista de espera."
    elif prob_no_show >= 0.35:
        risk_level = "MEDIO"
        recommendation = "Enviar recordatorio automatizado por WhatsApp / SMS 48h y 4h antes de la cita."
    else:
        risk_level = "BAJO"
        recommendation = "Cita estándar confirmada con alta probabilidad de asistencia."

    return NoShowResponse(
        probabilidad_inasistencia=round(prob_no_show, 4),
        prediccion_inasistencia=is_no_show,
        nivel_riesgo=risk_level,
        recomendacion_operativa=recommendation
    )


@app.post("/cluster/patient", response_model=PatientClusterResponse)
def cluster_patient(payload: PatientClusterRequest):
    pipeline = models.get("patient_clustering")
    if not pipeline:
        raise HTTPException(status_code=500, detail="Modelo de clustering no disponible en memoria.")

    df = pd.DataFrame([{
        "total_citas": payload.total_citas,
        "tasa_asistencia": payload.tasa_asistencia,
        "dias_promedio_entre_citas": payload.dias_promedio_entre_citas,
        "gasto_total_usd": payload.gasto_total_usd
    }])

    cluster_id = int(pipeline.predict(df)[0])

    cluster_profiles = {
        0: {
            "segmento": "Paciente Frecuente / Alto Compromiso",
            "descripcion": "Alta tasa de asistencia y volumen regular de consultas.",
            "estrategia": "Ofrecer beneficios de fidelización, programas preventivos y prioridad en horarios pico."
        },
        1: {
            "segmento": "Paciente Ocasional / Rutinario",
            "descripcion": "Acude periódicamente para tratamientos específicos o limpiezas anuales.",
            "estrategia": "Enviar campañas semestrales de control preventivo y promociones de profilaxis."
        },
        2: {
            "segmento": "Paciente en Riesgo de Deserción / Alta Cancelación",
            "descripcion": "Baja tasa de asistencia o largo intervalo de inactividad clínica.",
            "estrategia": "Encuesta de satisfacción clínica, reprogramación flexible y seguimiento personalizado."
        }
    }

    profile = cluster_profiles.get(cluster_id % 3, cluster_profiles[1])

    return PatientClusterResponse(
        cluster_id=cluster_id,
        segmento=profile["segmento"],
        descripcion=profile["descripcion"],
        estrategia_fidelizacion=profile["estrategia"]
    )


@app.post("/cluster/stock", response_model=StockClusterResponse)
def cluster_stock(payload: StockClusterRequest):
    pipeline = models.get("stock_clustering")
    if not pipeline:
        raise HTTPException(status_code=500, detail="Modelo de inventario no disponible en memoria.")

    df = pd.DataFrame([{
        "consumo_mensual": payload.consumo_mensual,
        "costo_unitario": payload.costo_unitario,
        "dias_reabastecimiento": payload.dias_reabastecimiento
    }])

    cluster_id = int(pipeline.predict(df)[0])

    stock_profiles = {
        0: {"clasificacion_abc": "Categoría A (Alto Valor / Rotación Crítica)", "prioridad": "Inmediata / Punto de Pedido Estricto"},
        1: {"clasificacion_abc": "Categoría B (Consumo Moderado)", "prioridad": "Revisión Quincenal"},
        2: {"clasificacion_abc": "Categoría C (Bajo Costo / Stock de Seguridad Amplio)", "prioridad": "Compra por Lote Mensual"}
    }
    profile = stock_profiles.get(cluster_id % 3, stock_profiles[1])

    return StockClusterResponse(
        cluster_id=cluster_id,
        clasificacion_abc=profile["clasificacion_abc"],
        prioridad_reorden=profile["prioridad"]
    )
