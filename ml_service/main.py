"""
FastAPI Micro-Sidecar for Dental Clinic MS3 Machine Learning Services.
Provides endpoints for:
- Supervised Prediction: No-Show Probability (Random Forest).
- Unsupervised Clustering: Patient Segmentation (K-Means).
- Unsupervised Clustering: Stock / Inventory ABC Analysis (K-Means).
- Batch Prediction and Batch Clustering for the Laravel host service.

NOTE: All models are trained on DATOS SINTÉTICOS (synthetic data). This is stated
explicitly in every response metadata field. This is a portfolio/non-commercial project.
"""

import os
from contextlib import asynccontextmanager
from typing import Dict, Any, List, Optional
import joblib
import pandas as pd
import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

MODELS_DIR = os.path.join(os.path.dirname(__file__), "models")

models: Dict[str, Any] = {}

# Spec-exact cluster names (S11)
PATIENT_CLUSTER_NAMES = [
    "Frecuente y Puntual",
    "Riesgo de Abandono / Inconstante",
    "Atención Esporádica / Urgencias",
]
STOCK_CLUSTER_NAMES = [
    "Alta Rotación Crítica",
    "Consumo Estándar",
    "Baja Rotación",
]

# No-show feature column order (must match train_models.py)
NO_SHOW_FEATURE_COLS = [
    "cancelaciones_previas",
    "dias_anticipacion",
    "dia_semana",
    "hora_cita",
    "banda_horaria",
    "duracion_minutos",
    "tipo_tratamiento_riesgo",
    "edad",
]

MODEL_VERSION = "RandomForestClassifier-v2.0"
CLUSTER_VERSION = "K-Means-v2.0"


@asynccontextmanager
async def lifespan(app: FastAPI):
    no_show_path = os.path.join(MODELS_DIR, "no_show_pipeline.joblib")
    patient_cluster_path = os.path.join(MODELS_DIR, "patient_clustering_pipeline.joblib")
    stock_cluster_path = os.path.join(MODELS_DIR, "stock_clustering_pipeline.joblib")

    if not os.path.exists(no_show_path) or not os.path.exists(patient_cluster_path):
        raise RuntimeError("ML model binaries not found. Run train_models.py first.")

    models["no_show"] = joblib.load(no_show_path)
    models["patient_clustering"] = joblib.load(patient_cluster_path)
    models["stock_clustering"] = joblib.load(stock_cluster_path)
    print("All ML Pipelines loaded into memory.")
    yield
    models.clear()


app = FastAPI(
    title="MS3 Machine Learning Micro-Service",
    description=(
        "Dedicated micro-sidecar for Supervised & Unsupervised ML inference. "
        "NOTA: Todos los modelos fueron entrenados con DATOS SINTÉTICOS."
    ),
    version="2.0.0",
    lifespan=lifespan,
)


# ---------------------------------------------------------------------------
# Shared threshold helper
# ---------------------------------------------------------------------------

def _map_risk_level(prob: float) -> Dict[str, str]:
    """
    Map a no-show probability to nivelRiesgo + accionRecomendada.
    Thresholds (S10):
      BAJO  : prob < 0.35
      MEDIO : 0.35 <= prob <= 0.65   (0.65 => MEDIO, NOT ALTO)
      ALTO  : prob > 0.65
    """
    if prob > 0.65:
        return {
            "nivelRiesgo": "ALTO",
            "accionRecomendada": "Confirmar por teléfono/WhatsApp 24h antes",
        }
    if prob >= 0.35:
        return {
            "nivelRiesgo": "MEDIO",
            "accionRecomendada": "Enviar recordatorio por WhatsApp 48h antes",
        }
    return {
        "nivelRiesgo": "BAJO",
        "accionRecomendada": "Horario seguro",
    }


# ---------------------------------------------------------------------------
# Pydantic Schemas
# ---------------------------------------------------------------------------

class NoShowRequest(BaseModel):
    """
    Features for no-show prediction.
    duracion_minutos and banda_horaria are optional; when omitted they use
    defaults and are listed in featuresImputadas.
    """
    edad: int = Field(..., ge=0, le=120)
    dias_anticipacion: int = Field(..., ge=0, description="Lead time in days")
    cancelaciones_previas: int = Field(..., ge=0, description="Prior cancellations/no-shows")
    dia_semana: int = Field(..., ge=1, le=7, description="1=Mon … 7=Sun")
    hora_cita: int = Field(..., ge=7, le=22, description="Hour 24h format")
    tipo_tratamiento_riesgo: int = Field(..., ge=0, le=2, description="0=Low, 1=Med, 2=High")
    duracion_minutos: Optional[int] = Field(None, ge=5, le=240, description="Appointment duration in minutes")
    banda_horaria: Optional[int] = Field(None, ge=0, le=1, description="0=mañana, 1=tarde")


class NoShowResponse(BaseModel):
    # New camelCase fields (S10)
    probabilidadNoShow: float
    nivelRiesgo: str
    accionRecomendada: str
    featuresImputadas: List[str]
    # Legacy snake_case aliases (additive contract)
    probabilidad_inasistencia: float
    prediccion_inasistencia: bool
    nivel_riesgo: str
    recomendacion_operativa: str
    modelo: str = MODEL_VERSION
    datosSinteticos: bool = True


class BatchCitaItem(BaseModel):
    cita_id: str
    edad: int = Field(..., ge=0, le=120)
    dias_anticipacion: int = Field(..., ge=0)
    cancelaciones_previas: int = Field(..., ge=0)
    dia_semana: int = Field(..., ge=1, le=7)
    hora_cita: int = Field(..., ge=7, le=22)
    tipo_tratamiento_riesgo: int = Field(..., ge=0, le=2)
    duracion_minutos: Optional[int] = Field(None)
    banda_horaria: Optional[int] = Field(None)


class BatchNoShowRequest(BaseModel):
    citas: List[BatchCitaItem]


class BatchNoShowPrediction(BaseModel):
    cita_id: str
    probabilidadNoShow: float
    nivelRiesgo: str
    accionRecomendada: str
    featuresImputadas: List[str]


class BatchNoShowResponse(BaseModel):
    predicciones: List[BatchNoShowPrediction]
    modelo: str = MODEL_VERSION
    datosSinteticos: bool = True


class PatientClusterRequest(BaseModel):
    paciente_id: Optional[str] = Field("pac-unknown", description="Identifier passed through for tracking")
    total_citas: int = Field(..., ge=0)
    tasa_asistencia: float = Field(..., ge=0.0, le=1.0)
    dias_promedio_entre_citas: float = Field(..., ge=0.0)
    gasto_total_estimado: Optional[float] = Field(None, ge=0.0, description="Estimated total spend (imputado if unknown)")
    gasto_total_usd: Optional[float] = Field(None, ge=0.0, description="Legacy alias for gasto_total_estimado")


class PatientClusterResponse(BaseModel):
    paciente_id: str
    cluster_id: int
    segmento: str
    algoritmo: str = CLUSTER_VERSION
    datosSinteticos: bool = True


class StockClusterRequest(BaseModel):
    insumo_id: Optional[str] = Field("ins-unknown", description="Identifier passed through for tracking")
    consumo_mensual: float = Field(..., ge=0.0)
    costo_unitario: float = Field(..., ge=0.0)
    dias_reabastecimiento: int = Field(..., ge=0)


class StockClusterResponse(BaseModel):
    insumo_id: str
    cluster_id: int
    clasificacion: str
    clasificacion_abc: Optional[str] = None
    algoritmo: str = CLUSTER_VERSION
    datosSinteticos: bool = True


class BatchPatientItem(BaseModel):
    paciente_id: str
    total_citas: int = Field(..., ge=0)
    tasa_asistencia: float = Field(..., ge=0.0, le=1.0)
    dias_promedio_entre_citas: float = Field(..., ge=0.0)
    gasto_total_estimado: float = Field(..., ge=0.0)


class BatchStockItem(BaseModel):
    insumo_id: str
    consumo_mensual: float = Field(..., ge=0.0)
    costo_unitario: float = Field(..., ge=0.0)
    dias_reabastecimiento: int = Field(..., ge=0)


class ClusterCentroid(BaseModel):
    cluster: str
    cluster_id: int
    valores: Dict[str, float]


class BatchClusterRequest(BaseModel):
    pacientes: List[BatchPatientItem]
    insumos: List[BatchStockItem]


class PatientAssignment(BaseModel):
    paciente_id: str
    cluster: str
    cluster_id: int


class StockAssignment(BaseModel):
    insumo_id: str
    cluster: str
    cluster_id: int


class PatientClustersResult(BaseModel):
    centroides: List[Dict[str, Any]]
    distribucion: Dict[str, int]
    asignaciones: List[PatientAssignment]


class StockClustersResult(BaseModel):
    centroides: List[Dict[str, Any]]
    distribucion: Dict[str, int]
    asignaciones: List[StockAssignment]


class BatchClusterResponse(BaseModel):
    pacientes: PatientClustersResult
    insumos: StockClustersResult
    modeloVersion: str = CLUSTER_VERSION
    datosSinteticos: bool = True


# ---------------------------------------------------------------------------
# Shared inference helpers
# ---------------------------------------------------------------------------

def _predict_no_show_single(
    edad: int,
    dias_anticipacion: int,
    cancelaciones_previas: int,
    dia_semana: int,
    hora_cita: int,
    tipo_tratamiento_riesgo: int,
    duracion_minutos: Optional[int],
    banda_horaria: Optional[int],
) -> Dict[str, Any]:
    """Run no-show inference for one sample, applying imputation where needed."""
    imputadas: List[str] = []

    if duracion_minutos is None:
        duracion_minutos = 30
        imputadas.append("duracion_minutos (default: 30)")

    if banda_horaria is None:
        banda_horaria = int(hora_cita >= 13)
        # Only flag imputation if not derivable from hora_cita (always derivable here)

    df = pd.DataFrame([{
        col: val for col, val in zip(NO_SHOW_FEATURE_COLS, [
            cancelaciones_previas,
            dias_anticipacion,
            dia_semana,
            hora_cita,
            banda_horaria,
            duracion_minutos,
            tipo_tratamiento_riesgo,
            edad,
        ])
    }])

    pipeline = models["no_show"]
    prob = float(pipeline.predict_proba(df)[0][1])
    prob = round(prob, 4)
    risk = _map_risk_level(prob)

    return {
        "probabilidadNoShow": prob,
        "nivelRiesgo": risk["nivelRiesgo"],
        "accionRecomendada": risk["accionRecomendada"],
        "featuresImputadas": imputadas,
        # Legacy aliases
        "probabilidad_inasistencia": prob,
        "prediccion_inasistencia": bool(prob >= 0.5),
        "nivel_riesgo": risk["nivelRiesgo"],
        "recomendacion_operativa": risk["accionRecomendada"],
    }


def _cluster_patient_single(item: BatchPatientItem) -> PatientAssignment:
    df = pd.DataFrame([{
        "total_citas": item.total_citas,
        "tasa_asistencia": item.tasa_asistencia,
        "dias_promedio_entre_citas": item.dias_promedio_entre_citas,
        "gasto_total_estimado": item.gasto_total_estimado,
    }])
    cluster_id = int(models["patient_clustering"].predict(df)[0])
    name = PATIENT_CLUSTER_NAMES[cluster_id % len(PATIENT_CLUSTER_NAMES)]
    return PatientAssignment(paciente_id=item.paciente_id, cluster=name, cluster_id=cluster_id)


def _cluster_stock_single(item: BatchStockItem) -> StockAssignment:
    df = pd.DataFrame([{
        "consumo_mensual": item.consumo_mensual,
        "costo_unitario": item.costo_unitario,
        "dias_reabastecimiento": item.dias_reabastecimiento,
    }])
    cluster_id = int(models["stock_clustering"].predict(df)[0])
    name = STOCK_CLUSTER_NAMES[cluster_id % len(STOCK_CLUSTER_NAMES)]
    return StockAssignment(insumo_id=item.insumo_id, cluster=name, cluster_id=cluster_id)


def _get_patient_centroids() -> List[Dict[str, Any]]:
    pipeline = models["patient_clustering"]
    scaler = pipeline.named_steps["scaler"]
    kmeans = pipeline.named_steps["kmeans"]
    cols = ["total_citas", "tasa_asistencia", "dias_promedio_entre_citas", "gasto_total_estimado"]
    unscaled = scaler.inverse_transform(kmeans.cluster_centers_)
    result = []
    for i, row in enumerate(unscaled):
        result.append({
            "cluster": PATIENT_CLUSTER_NAMES[i % len(PATIENT_CLUSTER_NAMES)],
            "cluster_id": i,
            "valores": {col: round(float(val), 2) for col, val in zip(cols, row)},
        })
    return result


def _get_stock_centroids() -> List[Dict[str, Any]]:
    pipeline = models["stock_clustering"]
    scaler = pipeline.named_steps["scaler"]
    kmeans = pipeline.named_steps["kmeans"]
    cols = ["consumo_mensual", "costo_unitario", "dias_reabastecimiento"]
    unscaled = scaler.inverse_transform(kmeans.cluster_centers_)
    result = []
    for i, row in enumerate(unscaled):
        result.append({
            "cluster": STOCK_CLUSTER_NAMES[i % len(STOCK_CLUSTER_NAMES)],
            "cluster_id": i,
            "valores": {col: round(float(val), 2) for col, val in zip(cols, row)},
        })
    return result


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.get("/health")
def health_check():
    return {
        "status": "UP",
        "service": "MS3 - Machine Learning Sidecar",
        "version": "2.0.0",
        "models_loaded": list(models.keys()),
        "datosSinteticos": True,
    }


@app.post("/predict/no-show", response_model=NoShowResponse)
def predict_no_show(payload: NoShowRequest):
    if "no_show" not in models:
        raise HTTPException(status_code=503, detail="Modelo No-Show no disponible en memoria.")

    result = _predict_no_show_single(
        edad=payload.edad,
        dias_anticipacion=payload.dias_anticipacion,
        cancelaciones_previas=payload.cancelaciones_previas,
        dia_semana=payload.dia_semana,
        hora_cita=payload.hora_cita,
        tipo_tratamiento_riesgo=payload.tipo_tratamiento_riesgo,
        duracion_minutos=payload.duracion_minutos,
        banda_horaria=payload.banda_horaria,
    )
    return NoShowResponse(**result)


@app.post("/predict/no-show/batch", response_model=BatchNoShowResponse)
def predict_no_show_batch(payload: BatchNoShowRequest):
    if "no_show" not in models:
        raise HTTPException(status_code=503, detail="Modelo No-Show no disponible en memoria.")

    predicciones = []
    for item in payload.citas:
        result = _predict_no_show_single(
            edad=item.edad,
            dias_anticipacion=item.dias_anticipacion,
            cancelaciones_previas=item.cancelaciones_previas,
            dia_semana=item.dia_semana,
            hora_cita=item.hora_cita,
            tipo_tratamiento_riesgo=item.tipo_tratamiento_riesgo,
            duracion_minutos=item.duracion_minutos,
            banda_horaria=item.banda_horaria,
        )
        predicciones.append(BatchNoShowPrediction(
            cita_id=item.cita_id,
            probabilidadNoShow=result["probabilidadNoShow"],
            nivelRiesgo=result["nivelRiesgo"],
            accionRecomendada=result["accionRecomendada"],
            featuresImputadas=result["featuresImputadas"],
        ))

    return BatchNoShowResponse(predicciones=predicciones)


@app.post("/cluster/patient", response_model=PatientClusterResponse)
def cluster_patient(payload: PatientClusterRequest):
    if "patient_clustering" not in models:
        raise HTTPException(status_code=503, detail="Modelo de clustering de pacientes no disponible.")

    gasto = payload.gasto_total_estimado if payload.gasto_total_estimado is not None else (payload.gasto_total_usd or 0.0)
    item = BatchPatientItem(
        paciente_id=payload.paciente_id or "pac-unknown",
        total_citas=payload.total_citas,
        tasa_asistencia=payload.tasa_asistencia,
        dias_promedio_entre_citas=payload.dias_promedio_entre_citas,
        gasto_total_estimado=gasto,
    )
    assignment = _cluster_patient_single(item)
    return PatientClusterResponse(
        paciente_id=assignment.paciente_id,
        cluster_id=assignment.cluster_id,
        segmento=assignment.cluster,
    )


@app.post("/cluster/stock", response_model=StockClusterResponse)
def cluster_stock(payload: StockClusterRequest):
    if "stock_clustering" not in models:
        raise HTTPException(status_code=503, detail="Modelo de clustering de stock no disponible.")

    item = BatchStockItem(
        insumo_id=payload.insumo_id or "ins-unknown",
        consumo_mensual=payload.consumo_mensual,
        costo_unitario=payload.costo_unitario,
        dias_reabastecimiento=payload.dias_reabastecimiento,
    )
    assignment = _cluster_stock_single(item)
    cat_letter = ["A", "B", "C"][assignment.cluster_id] if 0 <= assignment.cluster_id < 3 else "A"
    return StockClusterResponse(
        insumo_id=assignment.insumo_id,
        cluster_id=assignment.cluster_id,
        clasificacion=assignment.cluster,
        clasificacion_abc=f"Categoría {cat_letter} ({assignment.cluster})",
    )


@app.post("/batch-cluster", response_model=BatchClusterResponse)
def batch_cluster(payload: BatchClusterRequest):
    if "patient_clustering" not in models or "stock_clustering" not in models:
        raise HTTPException(status_code=503, detail="Modelos de clustering no disponibles en memoria.")

    # Patient assignments
    pac_asignaciones = [_cluster_patient_single(p) for p in payload.pacientes]
    pac_distribucion: Dict[str, int] = {name: 0 for name in PATIENT_CLUSTER_NAMES}
    for a in pac_asignaciones:
        pac_distribucion[a.cluster] = pac_distribucion.get(a.cluster, 0) + 1

    # Stock assignments
    ins_asignaciones = [_cluster_stock_single(s) for s in payload.insumos]
    ins_distribucion: Dict[str, int] = {name: 0 for name in STOCK_CLUSTER_NAMES}
    for a in ins_asignaciones:
        ins_distribucion[a.cluster] = ins_distribucion.get(a.cluster, 0) + 1

    return BatchClusterResponse(
        pacientes=PatientClustersResult(
            centroides=_get_patient_centroids(),
            distribucion=pac_distribucion,
            asignaciones=pac_asignaciones,
        ),
        insumos=StockClustersResult(
            centroides=_get_stock_centroids(),
            distribucion=ins_distribucion,
            asignaciones=ins_asignaciones,
        ),
    )
