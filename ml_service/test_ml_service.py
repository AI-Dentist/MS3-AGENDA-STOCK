import sys
import os

# Ensure repository root is in sys.path
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from fastapi.testclient import TestClient
from ml_service.main import app

def test_ml_sidecar():
    with TestClient(app) as client:
        # 1. Health check
        res_health = client.get("/health")
        assert res_health.status_code == 200
        data_health = res_health.json()
        assert data_health["status"] == "UP"
        assert "no_show" in data_health["models_loaded"]
        print("[PASS] /health check passed")

        # 2. Supervised Prediction: No-Show
        no_show_payload = {
            "edad": 35,
            "dias_anticipacion": 14,
            "cancelaciones_previas": 2,
            "dia_semana": 1,
            "hora_cita": 10,
            "tipo_tratamiento_riesgo": 1
        }
        res_pred = client.post("/predict/no-show", json=no_show_payload)
        assert res_pred.status_code == 200
        data_pred = res_pred.json()
        assert "probabilidad_inasistencia" in data_pred
        assert 0.0 <= data_pred["probabilidad_inasistencia"] <= 1.0
        assert data_pred["nivel_riesgo"] in ["BAJO", "MEDIO", "ALTO"]
        assert "recomendacion_operativa" in data_pred
        print(f"[PASS] /predict/no-show: Risk={data_pred['nivel_riesgo']}, Prob={data_pred['probabilidad_inasistencia']}")

        # 3. Unsupervised Clustering: Patient Segmentation (Deterministic Centroids)
        # High profile: Frequent / High commitment
        patient_loyal = {
            "total_citas": 15,
            "tasa_asistencia": 0.95,
            "dias_promedio_entre_citas": 30.0,
            "gasto_total_usd": 850.0
        }
        res_cluster = client.post("/cluster/patient", json=patient_loyal)
        assert res_cluster.status_code == 200
        data_cluster = res_cluster.json()
        assert data_cluster["cluster_id"] == 0
        assert data_cluster["segmento"] == "Paciente Frecuente / Alto Compromiso"
        print(f"[PASS] /cluster/patient (Loyal): Segment={data_cluster['segmento']}")

        # Low profile: At risk / Deserter
        patient_risk = {
            "total_citas": 1,
            "tasa_asistencia": 0.20,
            "dias_promedio_entre_citas": 350.0,
            "gasto_total_usd": 40.0
        }
        res_cluster_risk = client.post("/cluster/patient", json=patient_risk)
        assert res_cluster_risk.status_code == 200
        data_cluster_risk = res_cluster_risk.json()
        assert data_cluster_risk["cluster_id"] == 2
        assert data_cluster_risk["segmento"] == "Paciente en Riesgo de Deserción / Alta Cancelación"
        print(f"[PASS] /cluster/patient (Risk): Segment={data_cluster_risk['segmento']}")

        # 4. Unsupervised Clustering: Stock Classification (Deterministic ABC Centroids)
        # Category A: High value and consumption
        stock_cat_a = {
            "consumo_mensual": 120.0,
            "costo_unitario": 80.0,
            "dias_reabastecimiento": 5
        }
        res_stock = client.post("/cluster/stock", json=stock_cat_a)
        assert res_stock.status_code == 200
        data_stock = res_stock.json()
        assert data_stock["cluster_id"] == 0
        assert "Categoría A" in data_stock["clasificacion_abc"]
        print(f"[PASS] /cluster/stock (Cat A): ABC={data_stock['clasificacion_abc']}")

        # Category C: Low value and consumption
        stock_cat_c = {
            "consumo_mensual": 2.0,
            "costo_unitario": 2.5,
            "dias_reabastecimiento": 25
        }
        res_stock_c = client.post("/cluster/stock", json=stock_cat_c)
        assert res_stock_c.status_code == 200
        data_stock_c = res_stock_c.json()
        assert data_stock_c["cluster_id"] == 2
        assert "Categoría C" in data_stock_c["clasificacion_abc"]
        print(f"[PASS] /cluster/stock (Cat C): ABC={data_stock_c['clasificacion_abc']}")

if __name__ == "__main__":
    test_ml_sidecar()
    print("ALL ML SIDECAR TESTS PASSED SUCCESSFULLY!")
