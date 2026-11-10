"""
Tests for the updated ML sidecar (S10 / S11).
Run with: ml_service/.venv/Scripts/pytest ml_service/test_ml_service_v2.py -v
"""
import sys
import os
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import pytest
from fastapi.testclient import TestClient
from ml_service.main import app


@pytest.fixture(scope="module")
def client():
    with TestClient(app) as c:
        yield c


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

def test_health_check(client):
    res = client.get("/health")
    assert res.status_code == 200
    data = res.json()
    assert data["status"] == "UP"
    assert "no_show" in data["models_loaded"]


# ---------------------------------------------------------------------------
# S10 – No-Show: required camelCase fields in response
# ---------------------------------------------------------------------------

BASE_NO_SHOW = {
    "dias_anticipacion": 14,
    "cancelaciones_previas": 2,
    "dia_semana": 1,
    "hora_cita": 10,
    "tipo_tratamiento_riesgo": 1,
    "edad": 35,
    "duracion_minutos": 30,
    "banda_horaria": 0,
}


def test_no_show_returns_probabilidadNoShow(client):
    res = client.post("/predict/no-show", json=BASE_NO_SHOW)
    assert res.status_code == 200
    data = res.json()
    assert "probabilidadNoShow" in data, "Must return camelCase probabilidadNoShow"
    assert 0.0 <= data["probabilidadNoShow"] <= 1.0


def test_no_show_returns_nivelRiesgo(client):
    res = client.post("/predict/no-show", json=BASE_NO_SHOW)
    assert res.status_code == 200
    data = res.json()
    assert "nivelRiesgo" in data
    assert data["nivelRiesgo"] in ["BAJO", "MEDIO", "ALTO"]


def test_no_show_returns_accionRecomendada(client):
    res = client.post("/predict/no-show", json=BASE_NO_SHOW)
    assert res.status_code == 200
    data = res.json()
    assert "accionRecomendada" in data
    assert len(data["accionRecomendada"]) > 0


def test_no_show_returns_featuresImputadas(client):
    res = client.post("/predict/no-show", json=BASE_NO_SHOW)
    assert res.status_code == 200
    data = res.json()
    assert "featuresImputadas" in data
    assert isinstance(data["featuresImputadas"], list)


def test_no_show_keeps_legacy_snake_case_fields(client):
    """Additive contract: existing consumers must not break."""
    res = client.post("/predict/no-show", json=BASE_NO_SHOW)
    assert res.status_code == 200
    data = res.json()
    assert "probabilidad_inasistencia" in data, "Legacy alias must remain"
    assert "nivel_riesgo" in data, "Legacy alias must remain"
    assert "recomendacion_operativa" in data, "Legacy alias must remain"


# ---------------------------------------------------------------------------
# S10 – No-Show: threshold boundaries (Python layer verifies its own mapping)
# ---------------------------------------------------------------------------
# We rely on a mock-like approach: craft inputs that drive the model toward
# extreme probabilities. These assertions use wide bands to stay robust
# against synthetic-data model variance.  The definitive boundary test for
# the exact 0.35 / 0.65 cutoff is in the PHP layer (MockML).
# ---------------------------------------------------------------------------

def test_no_show_low_risk_bajo_threshold(client):
    """Low-risk input should produce BAJO (< 0.35) or at most MEDIO."""
    low_risk = {
        "dias_anticipacion": 1,
        "cancelaciones_previas": 0,
        "dia_semana": 3,
        "hora_cita": 11,
        "tipo_tratamiento_riesgo": 0,
        "edad": 40,
        "duracion_minutos": 30,
        "banda_horaria": 0,
    }
    res = client.post("/predict/no-show", json=low_risk)
    assert res.status_code == 200
    data = res.json()
    assert data["nivelRiesgo"] in ["BAJO", "MEDIO"]


def test_no_show_high_risk_alto_threshold(client):
    """High-risk input should produce ALTO."""
    high_risk = {
        "dias_anticipacion": 40,
        "cancelaciones_previas": 10,
        "dia_semana": 1,
        "hora_cita": 17,
        "tipo_tratamiento_riesgo": 2,
        "edad": 20,
        "duracion_minutos": 90,
        "banda_horaria": 1,
    }
    res = client.post("/predict/no-show", json=high_risk)
    assert res.status_code == 200
    data = res.json()
    # With high cancellations and high lead_time the model must output ALTO
    assert data["nivelRiesgo"] == "ALTO", f"Expected ALTO, got {data['nivelRiesgo']}"


def test_threshold_0_65_maps_to_medio_not_alto(client):
    """
    Verify the Python sidecar maps probability == 0.65 to MEDIO (not ALTO).
    We test this by calling the internal _map_risk_level helper directly.
    """
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.65)
    assert result["nivelRiesgo"] == "MEDIO", "0.65 must be MEDIO (spec: > 0.65 => ALTO)"


def test_threshold_0_6501_maps_to_alto(client):
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.6501)
    assert result["nivelRiesgo"] == "ALTO"


def test_threshold_0_35_maps_to_medio(client):
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.35)
    assert result["nivelRiesgo"] == "MEDIO"


def test_threshold_0_3499_maps_to_bajo(client):
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.3499)
    assert result["nivelRiesgo"] == "BAJO"


def test_alto_accion_mentions_telefono(client):
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.9)
    assert "teléfono" in result["accionRecomendada"].lower() or "confirmar" in result["accionRecomendada"].lower()
    assert "24h" in result["accionRecomendada"]


def test_bajo_accion_mentions_seguro(client):
    from ml_service.main import _map_risk_level
    result = _map_risk_level(0.1)
    assert "seguro" in result["accionRecomendada"].lower()


# ---------------------------------------------------------------------------
# S10 – No-Show: batch predict endpoint
# ---------------------------------------------------------------------------

def test_batch_predict_no_show_endpoint(client):
    payload = {
        "citas": [
            {"cita_id": "c1", **BASE_NO_SHOW},
            {"cita_id": "c2", "dias_anticipacion": 1, "cancelaciones_previas": 0,
             "dia_semana": 3, "hora_cita": 9, "tipo_tratamiento_riesgo": 0,
             "edad": 30, "duracion_minutos": 30, "banda_horaria": 0},
        ]
    }
    res = client.post("/predict/no-show/batch", json=payload)
    assert res.status_code == 200, f"Got {res.status_code}: {res.text}"
    data = res.json()
    assert "predicciones" in data
    assert len(data["predicciones"]) == 2
    for pred in data["predicciones"]:
        assert "cita_id" in pred
        assert "probabilidadNoShow" in pred
        assert "nivelRiesgo" in pred
        assert "accionRecomendada" in pred


# ---------------------------------------------------------------------------
# S11 – Patient clusters: exact spec names
# ---------------------------------------------------------------------------

PATIENT_FRECUENTE = {
    "paciente_id": "pac-001",
    "total_citas": 15,
    "tasa_asistencia": 0.95,
    "dias_promedio_entre_citas": 30.0,
    "gasto_total_estimado": 850.0,
}

PATIENT_RIESGO = {
    "paciente_id": "pac-002",
    "total_citas": 1,
    "tasa_asistencia": 0.20,
    "dias_promedio_entre_citas": 350.0,
    "gasto_total_estimado": 40.0,
}


def test_patient_cluster_frecuente_y_puntual(client):
    res = client.post("/cluster/patient", json=PATIENT_FRECUENTE)
    assert res.status_code == 200
    data = res.json()
    assert data["segmento"] == "Frecuente y Puntual", f"Got: {data['segmento']}"


def test_patient_cluster_riesgo_de_abandono(client):
    res = client.post("/cluster/patient", json=PATIENT_RIESGO)
    assert res.status_code == 200
    data = res.json()
    assert data["segmento"] in [
        "Riesgo de Abandono / Inconstante",
        "Atención Esporádica / Urgencias",
    ], f"Got: {data['segmento']}"


def test_patient_cluster_names_are_spec_compliant(client):
    """All returned segmento values must be one of the three spec names."""
    spec_names = {"Frecuente y Puntual", "Riesgo de Abandono / Inconstante", "Atención Esporádica / Urgencias"}
    for payload in [PATIENT_FRECUENTE, PATIENT_RIESGO]:
        res = client.post("/cluster/patient", json=payload)
        assert res.status_code == 200
        assert res.json()["segmento"] in spec_names, f"Non-spec name: {res.json()['segmento']}"


# ---------------------------------------------------------------------------
# S11 – Stock clusters: exact spec names
# ---------------------------------------------------------------------------

STOCK_ALTA = {
    "insumo_id": "ins-001",
    "consumo_mensual": 120.0,
    "costo_unitario": 80.0,
    "dias_reabastecimiento": 5,
}

STOCK_BAJA = {
    "insumo_id": "ins-002",
    "consumo_mensual": 2.0,
    "costo_unitario": 2.5,
    "dias_reabastecimiento": 25,
}


def test_stock_cluster_alta_rotacion_critica(client):
    res = client.post("/cluster/stock", json=STOCK_ALTA)
    assert res.status_code == 200
    data = res.json()
    assert data["clasificacion"] == "Alta Rotación Crítica", f"Got: {data['clasificacion']}"


def test_stock_cluster_baja_rotacion(client):
    res = client.post("/cluster/stock", json=STOCK_BAJA)
    assert res.status_code == 200
    data = res.json()
    assert data["clasificacion"] == "Baja Rotación", f"Got: {data['clasificacion']}"


def test_stock_cluster_names_are_spec_compliant(client):
    spec_names = {"Alta Rotación Crítica", "Consumo Estándar", "Baja Rotación"}
    for payload in [STOCK_ALTA, STOCK_BAJA]:
        res = client.post("/cluster/stock", json=payload)
        assert res.status_code == 200
        data = res.json()
        assert data["clasificacion"] in spec_names, f"Non-spec name: {data['clasificacion']}"


# ---------------------------------------------------------------------------
# S11 – Batch cluster endpoint
# ---------------------------------------------------------------------------

def test_batch_cluster_endpoint(client):
    payload = {
        "pacientes": [PATIENT_FRECUENTE, PATIENT_RIESGO],
        "insumos": [STOCK_ALTA, STOCK_BAJA],
    }
    res = client.post("/batch-cluster", json=payload)
    assert res.status_code == 200, f"Got {res.status_code}: {res.text}"
    data = res.json()

    assert "pacientes" in data
    assert "insumos" in data

    # pacientes section
    pac = data["pacientes"]
    assert "centroides" in pac
    assert "distribucion" in pac
    assert "asignaciones" in pac
    assert len(pac["asignaciones"]) == 2

    # insumos section
    ins = data["insumos"]
    assert "centroides" in ins
    assert "distribucion" in ins
    assert "asignaciones" in ins
    assert len(ins["asignaciones"]) == 2

    # Cluster names in centroides must be spec-compliant
    pac_names = {c["cluster"] for c in pac["centroides"]}
    spec_pac = {"Frecuente y Puntual", "Riesgo de Abandono / Inconstante", "Atención Esporádica / Urgencias"}
    assert pac_names == spec_pac, f"Patient centroid names wrong: {pac_names}"

    ins_names = {c["cluster"] for c in ins["centroides"]}
    spec_ins = {"Alta Rotación Crítica", "Consumo Estándar", "Baja Rotación"}
    assert ins_names == spec_ins, f"Stock centroid names wrong: {ins_names}"

    # S8 metadata assertions
    assert "featuresImputadas" in pac["asignaciones"][0]
    assert "featuresEstimadas" in pac["asignaciones"][0]
    assert "gasto_total_estimado" in pac["asignaciones"][0]["featuresEstimadas"]

    assert "featuresImputadas" in ins["asignaciones"][0]
    assert "featuresEstimadas" in ins["asignaciones"][0]


def test_batch_cluster_metadata(client):
    payload = {
        "pacientes": [PATIENT_FRECUENTE],
        "insumos": [STOCK_ALTA],
    }
    res = client.post("/batch-cluster", json=payload)
    assert res.status_code == 200
    data = res.json()
    assert "modeloVersion" in data
    assert data["datosSinteticos"] is True
    assert data["datosEntrenamientoSinteticos"] is True
